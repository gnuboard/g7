/**
 * 방문자 동의 런타임 (코어 소유)
 *
 * 서버의 동의 제공자 계약(`GET /api/consent`)을 브라우저 쪽에서 들고 있습니다. 다른 확장은 전역 상태
 * `_global.consent` 를 읽거나 `G7Core.consent.subscribe()` / 컴포넌트 이벤트 `consent:changed` 로 변경을
 * 받습니다. 어느 확장이 동의를 관리하는지 몰라도 됩니다.
 *
 * 지키는 것:
 * - **세대 가드** — 로그인·로그아웃마다 세대를 올리고, 이전 세대에서 출발한 응답은 버린다. 게스트로
 *   보낸 조회가 로그인 뒤에 도착해 회원의 스냅샷을 덮어쓰지 못하게 한다.
 * - **요청 공유** — 같은 세대에서 겹친 refresh 는 요청 하나를 나눠 쓴다(배너 저장 버튼 더블 클릭 등).
 * - **fail-closed** — 조회 실패·형식 오류·모르는 계약 버전은 `unknown`. 소비자는 동의하지 않은 것으로 본다.
 * - **변경 시에만 통지** — 값이 같으면 전역 상태도 이벤트도 건드리지 않는다.
 *
 * @since engine-v1.78.0
 */

import { createLogger } from '../utils/Logger';

const logger = createLogger('ConsentRuntime');

/** 공개 계약 버전 */
export const CONSENT_CONTRACT_VERSION = 1;

/** 변경 통지 컴포넌트 이벤트 이름 */
export const CONSENT_CHANGED_EVENT = 'consent:changed';

/**
 * `consent:changed` 구독자를 기다리는 최대 시간(ms).
 *
 * refresh 는 구독자가 끝난 뒤 resolve 한다(gdpr 차단 엔진 동기화가 다음 액션보다 먼저 끝나게). 그러나 구독자 하나가
 * 끝나지 않으면 같은 세대의 refresh 대기·로그인 뒤 재조회·판정 대기(isPending)가 함께 멈춘다 — 그래서 이 시간이 지나면
 * 더 기다리지 않고 진행한다. 구독자는 계속 돌 수 있다(취소하지 않는다).
 *
 * @since engine-v1.78.0
 */
export const CONSENT_LISTENER_WAIT_MS = 3000;

/** 표준 카테고리 어휘 */
const STANDARD_CATEGORIES = ['necessary', 'functional', 'analytics', 'marketing'] as const;

/** 스냅샷 상태 */
export type ConsentStatus = 'unknown' | 'ready' | 'none';

/** `_global.consent` 의 모양 (공개 계약) */
export interface ConsentSnapshot {
    /** 계약 버전 (현재 1) */
    contractVersion: number;
    /** `ready` 제공자가 답함 · `unknown` 모름 · `none` 동의 관리 제공자 없음 */
    status: ConsentStatus;
    /** 카테고리 → 동의 여부 (`necessary` 는 항상 true) */
    categories: Record<string, boolean>;
    /** 정책이 바뀌어 다시 동의를 받아야 하는지 */
    needsRenewal: boolean;
    /** 현재 동의 정책 버전 */
    policyVersion: string | null;
}

/** 인증 상태 (AuthManager.getState() 모양 중 필요한 것만) */
export interface ConsentAuthState {
    isAuthenticated: boolean;
    type?: string | null;
    user?: { uuid?: string | null } | null;
}

/** 런타임 의존성 — 테스트에서 바꿔 끼운다 */
export interface ConsentRuntimeDeps {
    /** `/api/consent` 응답 본문(`{ success, data }`)을 돌려준다 */
    fetchSnapshot: () => Promise<unknown>;
    /** `_global.consent` 를 쓴다 */
    writeGlobal: (snapshot: ConsentSnapshot) => void;
    /** `consent:changed` 를 발행한다 (리스너 완료까지 기다린다) */
    emit: (snapshot: ConsentSnapshot) => Promise<unknown>;
    /** 인증 상태 구독 (해제 함수 반환) */
    onAuthChange: (handler: (state: ConsentAuthState) => void) => () => void;
    /** 현재 인증 상태 */
    getAuthState: () => ConsentAuthState;
    /**
     * 전역 상태 변경 구독 (선택, 해제 함수 반환) — 리스너는 지금의 `_global.consent` 를 받는다.
     *
     * 다른 쓰기가 `_global` 사본 전체를 되써(`dataKey="_global.xxx"` 폼 자동 바인딩 등) `consent` 를 옛 값으로
     * 되돌리면, 런타임이 자기 값을 다시 싣는다.
     */
    watchGlobal?: (listener: (consent: unknown) => void) => () => void;
}

/**
 * 카테고리 맵을 정규화합니다 (표준 어휘 채움 + necessary 강제 true).
 *
 * @param raw 원본 맵
 * @returns 정규화한 맵, 형식이 틀리면 null
 */
function normalizeCategories(raw: unknown): Record<string, boolean> | null {
    if (raw === null || typeof raw !== 'object' || Array.isArray(raw)) return null;

    const categories: Record<string, boolean> = {};
    for (const key of STANDARD_CATEGORIES) categories[key] = false;

    for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
        if (typeof value !== 'boolean') return null;
        categories[key] = value;
    }

    categories.necessary = true;

    return categories;
}

/**
 * 판단할 수 없는 스냅샷을 만듭니다.
 *
 * @returns unknown 스냅샷
 */
export function unknownSnapshot(): ConsentSnapshot {
    return {
        contractVersion: CONSENT_CONTRACT_VERSION,
        status: 'unknown',
        categories: normalizeCategories({}) as Record<string, boolean>,
        needsRenewal: false,
        policyVersion: null,
    };
}

/**
 * 서버 응답(snake_case)을 공개 스냅샷(camelCase)으로 바꿉니다. 형식이 틀리면 unknown 입니다.
 *
 * 응답 본문(`{ success, data }`)과 `data` 자체를 모두 받는다 — 인라인 첫 값(`G7Config.consentInitial`)은
 * `data` 모양으로 온다.
 *
 * @param payload 응답 본문 또는 data
 * @returns 공개 스냅샷
 */
export function parseConsentPayload(payload: unknown): ConsentSnapshot {
    const body = payload as Record<string, unknown> | null;
    const data = (body && typeof body === 'object' && 'data' in body ? body.data : body) as Record<string, unknown> | null;

    if (!data || typeof data !== 'object') return unknownSnapshot();
    if (data.contract_version !== CONSENT_CONTRACT_VERSION) return unknownSnapshot();

    const status = data.status;
    if (status !== 'ready' && status !== 'unknown' && status !== 'none') return unknownSnapshot();

    const categories = normalizeCategories(data.categories);
    if (categories === null || typeof data.needs_renewal !== 'boolean') return unknownSnapshot();

    const policyVersion = data.policy_version;
    if (policyVersion !== null && policyVersion !== undefined && typeof policyVersion !== 'string') return unknownSnapshot();

    // 제공자가 없거나 모르는 상태에서는 카테고리가 의미가 없다 — 「필수만」으로 고정한다.
    return {
        contractVersion: CONSENT_CONTRACT_VERSION,
        status,
        categories: status === 'ready' ? categories : (normalizeCategories({}) as Record<string, boolean>),
        needsRenewal: status === 'ready' ? data.needs_renewal : false,
        policyVersion: status === 'ready' ? (policyVersion ?? null) : null,
    };
}

/**
 * 두 스냅샷이 같은지 비교합니다.
 *
 * @param a 스냅샷
 * @param b 스냅샷
 * @returns 같으면 true
 */
function sameSnapshot(a: ConsentSnapshot, b: ConsentSnapshot): boolean {
    if (a.status !== b.status || a.needsRenewal !== b.needsRenewal || a.policyVersion !== b.policyVersion) return false;

    const keys = new Set([...Object.keys(a.categories), ...Object.keys(b.categories)]);
    for (const key of keys) {
        if (a.categories[key] !== b.categories[key]) return false;
    }

    return true;
}

/**
 * 인증 상태의 경계 식별자 — 같은 값이면 같은 사람이다.
 *
 * @param state 인증 상태
 * @returns 경계 키
 */
function authKey(state: ConsentAuthState): string {
    return `${state.isAuthenticated ? 1 : 0}|${state.type ?? ''}|${state.user?.uuid ?? ''}`;
}

/**
 * 방문자 동의 런타임.
 */
export class ConsentRuntime {
    /** 세대 — 인증 경계마다 1 씩 오른다 */
    private generation = 0;

    /** 진행 중인 조회 (같은 세대에서만 공유) */
    private inflight: { generation: number; promise: Promise<ConsentSnapshot> } | null = null;

    /** 현재 스냅샷 */
    private snapshot: ConsentSnapshot;

    /** 직전 인증 경계 키 */
    private lastAuthKey: string | null = null;

    /** 인증 구독 해제 함수 */
    private offAuth: (() => void) | null = null;

    /** 전역 상태 감시 해제 함수 */
    private offGlobal: (() => void) | null = null;

    /** 되싣기 예약 여부 (한 틱에 한 번만) */
    private restoreScheduled = false;

    /** 조회가 끝난 마지막 세대 (-1 = 아직 없음) */
    private settledGeneration = -1;

    /** 조회 완료 리스너 — 값이 같아 `consent:changed` 가 나가지 않아도 판정 대기를 풀어야 하는 소비자용 */
    private readonly settledListeners = new Set<(snapshot: ConsentSnapshot) => void>();

    /**
     * 런타임을 만듭니다.
     *
     * @param deps 의존성
     * @param initial 첫 값 (서버 인라인 — 제공자 없음이면 none, 있으면 unknown)
     */
    constructor(private readonly deps: ConsentRuntimeDeps, initial: ConsentSnapshot = unknownSnapshot()) {
        this.snapshot = initial;
    }

    /**
     * 첫 값을 전역에 싣고, 인증 경계를 구독한 뒤 조회를 시작합니다.
     *
     * 첫 조회를 기다리지 않는다 — 화면 표시를 늦추지 않는다. 두 번 불러도 구독은 하나다.
     *
     * @returns 첫 조회 Promise
     */
    start(): Promise<ConsentSnapshot> {
        this.safeWrite(this.snapshot);

        if (this.offAuth === null) {
            this.lastAuthKey = authKey(this.deps.getAuthState());
            this.offAuth = this.deps.onAuthChange((state) => this.handleAuthChange(state));
            this.offGlobal = this.deps.watchGlobal?.((consent) => this.guardGlobal(consent)) ?? null;
        }

        return this.refresh();
    }

    /**
     * 구독을 해제합니다.
     *
     * @returns 없음
     */
    stop(): void {
        this.offAuth?.();
        this.offAuth = null;
        this.offGlobal?.();
        this.offGlobal = null;
    }

    /**
     * 전역 상태의 `consent` 가 런타임 값과 어긋나면 다음 틱에 되싣습니다.
     *
     * 런타임만 이 키를 쓴다(코어 예약 계약, 쓰기 허용 주체 없음). 다른 쓰기가 옛 사본으로 덮었다면 그것은
     * 의도한 변경이 아니다. 리스너 안에서 곧바로 쓰면 setGlobalState 가 중첩되므로 마이크로태스크로 미룬다.
     *
     * @param consent 지금의 `_global.consent`
     * @returns 없음
     */
    private guardGlobal(consent: unknown): void {
        this.lastSeenGlobal = consent;
        if (this.restoreScheduled || this.matchesCurrent(consent)) return;

        this.restoreScheduled = true;
        queueMicrotask(() => {
            this.restoreScheduled = false;
            if (this.offGlobal !== null && !this.matchesCurrent(this.lastSeenGlobal)) {
                logger.warn('_global.consent 가 다른 쓰기로 바뀌어 런타임 값으로 되돌립니다');
                this.safeWrite(this.snapshot);
            }
        });
    }

    /** 마지막으로 본 `_global.consent` */
    private lastSeenGlobal: unknown = undefined;

    /**
     * 전역 값이 현재 스냅샷과 같은지 비교합니다.
     *
     * @param value 전역 값
     * @returns 같으면 true
     */
    private matchesCurrent(value: unknown): boolean {
        if (!value || typeof value !== 'object') return false;
        const v = value as Partial<ConsentSnapshot>;
        if (!v.categories || typeof v.categories !== 'object') return false;

        return sameSnapshot(this.snapshot, {
            contractVersion: CONSENT_CONTRACT_VERSION,
            status: v.status as ConsentStatus,
            categories: v.categories as Record<string, boolean>,
            needsRenewal: v.needsRenewal === true,
            policyVersion: v.policyVersion ?? null,
        });
    }

    /**
     * 현재 스냅샷의 사본을 반환합니다.
     *
     * @returns 스냅샷 사본
     */
    getSnapshot(): ConsentSnapshot {
        return { ...this.snapshot, categories: { ...this.snapshot.categories } };
    }

    /**
     * 현재 세대의 첫 조회가 아직 끝나지 않았는지 반환합니다.
     *
     * 부팅 직후와 로그인·로그아웃 직후에 true 다. 이때의 `unknown` 은 「모름」이 아니라 「곧 안다」이므로,
     * 방문자 통계처럼 판정을 기다렸다 보내야 하는 소비자는 버리지 말고 들고 있어야 한다.
     *
     * @returns 대기 중이면 true
     */
    isPending(): boolean {
        return this.settledGeneration !== this.generation;
    }

    /**
     * 조회 완료를 구독합니다 (세대가 맞는 조회가 끝날 때마다, 값이 같아도 부른다).
     *
     * @param listener 리스너
     * @returns 해제 함수
     */
    onSettled(listener: (snapshot: ConsentSnapshot) => void): () => void {
        this.settledListeners.add(listener);

        return () => {
            this.settledListeners.delete(listener);
        };
    }

    /**
     * 동의 상태를 다시 조회합니다.
     *
     * 같은 세대에서 진행 중인 조회가 있으면 그것을 돌려준다. 전역 상태 갱신과 `consent:changed`
     * 리스너가 모두 끝난 뒤 resolve 한다 — 레이아웃이 다음 동작(토스트 등) 전에 동기화를 기다릴 수 있다.
     *
     * @returns 최신 스냅샷
     */
    refresh(): Promise<ConsentSnapshot> {
        if (this.inflight && this.inflight.generation === this.generation) {
            return this.inflight.promise;
        }

        const generation = this.generation;
        const promise = this.load(generation).finally(() => {
            if (this.inflight?.promise === promise) this.inflight = null;
        });
        this.inflight = { generation, promise };

        return promise;
    }

    /**
     * 조회하고, 세대가 그대로면 반영합니다.
     *
     * @param generation 출발 세대
     * @returns 반영 후 스냅샷 (세대가 바뀌었으면 현재 스냅샷)
     */
    private async load(generation: number): Promise<ConsentSnapshot> {
        let next: ConsentSnapshot;

        try {
            next = parseConsentPayload(await this.deps.fetchSnapshot());
        } catch (error) {
            logger.warn('동의 상태 조회 실패 — unknown 으로 둡니다', error);
            next = unknownSnapshot();
        }

        if (generation !== this.generation) {
            // 로그인·로그아웃 전에 출발한 응답 — 다른 사람의 답이다.
            return this.getSnapshot();
        }

        await this.apply(next);

        if (generation === this.generation) {
            this.settledGeneration = generation;
            const settled = this.getSnapshot();
            this.settledListeners.forEach((listener) => {
                try {
                    listener(settled);
                } catch (error) {
                    logger.error('조회 완료 리스너 오류', error);
                }
            });
        }

        return this.getSnapshot();
    }

    /**
     * 스냅샷이 바뀌었으면 전역 상태를 쓰고 변경을 알립니다.
     *
     * @param next 새 스냅샷
     * @returns 완료 Promise
     */
    private async apply(next: ConsentSnapshot): Promise<void> {
        if (sameSnapshot(this.snapshot, next)) return;

        this.snapshot = next;
        this.safeWrite(next);

        let timer: ReturnType<typeof setTimeout> | undefined;
        try {
            const timedOut = new Promise<'timeout'>((resolve) => {
                timer = setTimeout(() => resolve('timeout'), CONSENT_LISTENER_WAIT_MS);
            });
            const result = await Promise.race([this.deps.emit(this.getSnapshot()).then(() => 'done' as const), timedOut]);
            if (result === 'timeout') {
                // 구독자가 끝나지 않아도 런타임은 진행한다 — 판정 대기·다음 조회가 함께 멈추지 않게.
                logger.warn(`consent:changed 리스너가 ${CONSENT_LISTENER_WAIT_MS}ms 안에 끝나지 않아 기다리지 않고 진행합니다`);
            }
        } catch (error) {
            // 구독자의 실수가 런타임을 멈추면 안 된다.
            logger.error('consent:changed 리스너 오류', error);
        } finally {
            if (timer !== undefined) clearTimeout(timer);
        }
    }

    /**
     * 인증 경계를 반영합니다 — 세대를 올리고 unknown 으로 비운 뒤 다시 조회합니다.
     *
     * 같은 사람(인증 여부·유형·uuid)의 재발행은 무시한다.
     *
     * @param state 새 인증 상태
     * @returns 없음
     */
    private handleAuthChange(state: ConsentAuthState): void {
        const key = authKey(state);
        if (key === this.lastAuthKey) return;
        this.lastAuthKey = key;

        this.generation += 1;
        this.inflight = null;

        // 동의 관리 제공자가 없는 사이트(none)는 누가 로그인해도 none 이다 — unknown 으로 비웠다 되돌리면 전역
        // 재렌더만 두 번 늘어난다. 다시 조회는 하되(운영자가 그 사이 제공자를 켰을 수 있다) 비우지 않는다.
        if (this.snapshot.status === 'none') {
            void this.refresh();
            return;
        }

        void this.apply(unknownSnapshot()).then(() => this.refresh());
    }

    /**
     * 전역 상태 쓰기 (예외 삼킴).
     *
     * @param snapshot 스냅샷
     * @returns 없음
     */
    private safeWrite(snapshot: ConsentSnapshot): void {
        try {
            this.deps.writeGlobal({ ...snapshot, categories: { ...snapshot.categories } });
        } catch (error) {
            logger.error('_global.consent 쓰기 실패', error);
        }
    }
}
