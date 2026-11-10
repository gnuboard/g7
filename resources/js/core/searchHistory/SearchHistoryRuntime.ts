/**
 * 최근 검색어 런타임
 *
 * 검색창이 제출될 때 검색어를 기록하고, 검색창 펼침 목록이 범위(scope)별 최근 검색어를 읽고 지운다.
 *
 * - **범위**: 서버가 인라인한 `G7Config.searchScopes`(코어 `core.site` + 활성 확장 선언, 범위 → `{max_count}`)에
 *   있는 범위만 다룬다. 없는 범위(선언 안 됨·확장 꺼짐·기능 꺼짐)는 조용히 건너뛴다(`enabled: false`).
 * - **회원**: 서버(`/api/me/search-history`)에 둔다. 보관 개수·자동 저장은 서버가 판정한다.
 * - **비회원**: localStorage `g7_search_history` 한 칸에 `{v:1, autoSave, items:{범위:[{k, t}]}}` 로 둔다(최신순,
 *   범위별 보관 개수로 자름). 쿠키 동의 플러그인은 이 키를 코어 필수 저장 키로 받는다.
 * - **로그인**: 비회원 → 회원이 되면(부팅 때 이미 회원이어도) 브라우저 목록을 서버에 병합하고, 성공하면 브라우저
 *   칸을 지운다(실패하면 남겨 다음 로그인 때 다시 보낸다).
 * - **자동 저장 끄기**: 기록을 멈추고 목록을 감춘다(저장된 검색어는 남는다). 범위별이 아니라 사람 단위 하나다.
 * - **변경 알림**: 바뀔 때마다 `searchHistory:changed`(`{scope, reason}` — 범위 전체면 scope null)를 낸다.
 *
 * 주소창에 검색어를 직접 넣은 검색(`?q=`)은 기록하지 않는다 — 기록은 검색창 제출 경로(`record`·`recordSearchKeyword`)만 한다.
 *
 * @since engine-v1.87.0
 */

import { createLogger } from '../utils/Logger';

const logger = createLogger('SearchHistoryRuntime');

/** 비회원 최근 검색어 localStorage 키 (코어 필수 저장 키 — `App\Support\CoreNecessaryStorage`) */
export const SEARCH_HISTORY_STORAGE_KEY = 'g7_search_history';

/** 변경 알림 컴포넌트 이벤트 이름 */
export const SEARCH_HISTORY_CHANGED_EVENT = 'searchHistory:changed';

/** 비회원 저장 모양 버전 */
export const SEARCH_HISTORY_STORE_VERSION = 1;

/** 검색어 최대 글자 수 (서버 `SearchHistoryService::KEYWORD_MAX_LENGTH` 와 같다) */
export const SEARCH_HISTORY_KEYWORD_MAX_LENGTH = 100;

/** 한 번에 병합할 수 있는 최대 항목 수 (서버 `SearchHistoryService::MERGE_MAX_ITEMS` 와 같다) */
export const SEARCH_HISTORY_MERGE_MAX_ITEMS = 200;

/** 회원 API 경로 (ApiClient 기준 — `/api` 접두는 클라이언트가 붙인다) */
const ENDPOINT = '/me/search-history';

/** 최근 검색어 한 건 */
export interface SearchHistoryItem {
    /** 서버 행 id (비회원은 null) */
    id: number | null;
    /** 검색어 */
    keyword: string;
    /** 마지막으로 검색한 시각 (밀리초) */
    searchedAt: number;
}

/** 범위 하나의 상태 */
export interface SearchHistoryState {
    /** 범위 id */
    scope: string;
    /** 이 범위를 쓸 수 있는지 (선언·기능 켬) */
    enabled: boolean;
    /** 자동 저장 여부 (끄면 기록 멈춤·목록 감춤) */
    autoSave: boolean;
    /** 최근 검색어 (최신순, 자동 저장을 끄면 빈 배열) */
    items: SearchHistoryItem[];
    /** 보관 개수 */
    maxCount: number;
    /** 회원 목록을 받는 중인지 */
    loading: boolean;
    /** 어디에 저장되는지 */
    source: 'member' | 'guest' | 'none';
}

/** 변경 알림 내용 */
export interface SearchHistoryChange {
    /** 바뀐 범위 (여러 범위·전체가 바뀌었으면 null) */
    scope: string | null;
    /** 바뀐 까닭 */
    reason: 'record' | 'remove' | 'clear' | 'autoSave' | 'auth' | 'merge' | 'load';
}

/** 인증 상태 (AuthManager 상태의 필요한 부분) */
export interface SearchHistoryAuthState {
    isAuthenticated: boolean;
}

/** 회원 API 호출부 (코어 ApiClient 와 같은 모양 — 응답 본문을 돌려준다) */
export interface SearchHistoryApi {
    get(url: string, config?: { params?: Record<string, unknown> }): Promise<any>;
    post(url: string, data?: unknown): Promise<any>;
    put(url: string, data?: unknown): Promise<any>;
    delete(url: string, config?: { params?: Record<string, unknown> }): Promise<any>;
}

/** 런타임 의존성 */
export interface SearchHistoryRuntimeDeps {
    /** 선언 범위 → `{max_count}` (`G7Config.searchScopes`) */
    getScopes: () => Record<string, { max_count?: number }>;
    /** 회원 API */
    api: SearchHistoryApi;
    /** 비회원 저장소 (없으면 비회원 기록을 하지 않는다) */
    storage: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> | null;
    /** 변경 알림 */
    emit: (change: SearchHistoryChange) => void;
    /** 인증 변경 구독 (해제 함수를 돌려준다) */
    onAuthChange: (handler: (state: SearchHistoryAuthState) => void) => () => void;
    /** 현재 인증 상태 */
    getAuthState: () => SearchHistoryAuthState;
    /** 지금 시각 (밀리초) */
    now?: () => number;
    /**
     * 레이아웃 편집기 미리보기인지 — 참이면 쓰기(record·remove·clear·setAutoSave·병합)를 하지 않는다. 읽기는 그대로다.
     * 미리보기 모드는 런타임 설치 **뒤에** 켜지므로 쓰기마다 호출 시점에 다시 묻는다.
     */
    isPreview?: () => boolean;
}

/** 비회원 저장 모양 */
interface GuestStore {
    v: number;
    autoSave: boolean;
    items: Record<string, Array<{ k: string; t: number }>>;
}

/**
 * 검색어를 저장 모양으로 정규화합니다 — 연속 공백(줄바꿈 포함)을 하나로, 앞뒤 공백 제거, 최대 100자(코드 포인트).
 *
 * @param keyword 입력 검색어
 * @returns 정규화한 검색어 (비면 빈 문자열)
 */
export function normalizeSearchKeyword(keyword: unknown): string {
    if (typeof keyword !== 'string' && typeof keyword !== 'number') return '';

    const collapsed = String(keyword).replace(/\s+/gu, ' ').trim();

    return Array.from(collapsed).slice(0, SEARCH_HISTORY_KEYWORD_MAX_LENGTH).join('');
}

/**
 * 같은 검색어인지 판정할 비교 키 — 서버 콜레이션(대소문자 구분 없음)과 맞춘다.
 *
 * @param keyword 정규화한 검색어
 * @returns 비교 키
 */
function sameKey(keyword: string): string {
    return keyword.toLocaleLowerCase();
}

/**
 * 최근 검색어 런타임
 */
export class SearchHistoryRuntime {
    private readonly deps: SearchHistoryRuntimeDeps;

    /** 회원 범위별 캐시 (null 이면 다시 받아야 함) */
    private memberCache = new Map<string, SearchHistoryState>();

    /** 범위별 진행 중 조회 */
    private inflight = new Map<string, Promise<SearchHistoryState>>();

    /** 회원 자동 저장 (모르면 null) */
    private memberAutoSave: boolean | null = null;

    /** 마지막으로 본 인증 여부 */
    private lastAuthenticated = false;

    /** 인증 변경 구독 해제 */
    private offAuth: (() => void) | null = null;

    /** 진행 중 병합 */
    private merging: Promise<void> | null = null;

    /** 인증 세대 — 인증이 바뀔 때마다 올린다. 앞 세대에서 출발한 조회 결과는 캐시에 넣지 않는다. */
    private authEpoch = 0;

    /**
     * 회원 데이터 변경 세대 — 기록·삭제·전체 삭제·자동 저장 변경·병합이 성공할 때마다 올린다. 그 전에 출발한 목록 조회의
     * 응답은 변경 전 시점의 서버 상태라 캐시·자동 저장 값에 쓰지 않고 다시 받는다(늦은 옛 목록이 방금 바꾼 상태를 덮지 않게).
     * 인증 세대와는 따로 센다 — 인증이 바뀌면 캐시째 버리지만, 데이터가 바뀌면 같은 회원의 최신 목록을 다시 받는다.
     */
    private dataEpoch = 0;

    /**
     * @param deps 의존성
     */
    constructor(deps: SearchHistoryRuntimeDeps) {
        this.deps = deps;
    }

    /**
     * 편집기 미리보기라 쓰기를 막아야 하는지 판정합니다 (판정 오류는 막지 않음으로 본다).
     *
     * @returns 막아야 하면 true
     */
    private writesBlocked(): boolean {
        try {
            return !!this.deps.isPreview?.();
        } catch {
            return false;
        }
    }

    /**
     * 회원 데이터가 바뀌었음을 표시합니다 — 변경 세대를 올리고 진행 중 조회를 떼어 낸다(다음 조회가 옛 요청에 합류하지 않게).
     *
     * @returns 없음
     */
    private markMemberDataChanged(): void {
        this.dataEpoch++;
        this.inflight.clear();
    }

    /**
     * 인증 변경 구독을 시작하고, 이미 회원이면 브라우저 목록을 병합합니다.
     *
     * @returns 첫 병합이 끝나면 풀리는 Promise
     */
    start(): Promise<void> {
        if (this.offAuth) return this.merging ?? Promise.resolve();

        this.lastAuthenticated = !!this.deps.getAuthState()?.isAuthenticated;
        this.offAuth = this.deps.onAuthChange((state) => this.handleAuthChange(state));

        if (this.lastAuthenticated) {
            return this.mergeGuestIntoMember();
        }

        return Promise.resolve();
    }

    /**
     * 구독을 풉니다 (테스트용).
     *
     * @returns 없음
     */
    stop(): void {
        this.offAuth?.();
        this.offAuth = null;
    }

    /**
     * 범위를 쓸 수 있는지 판정합니다.
     *
     * @param scope 범위 id
     * @returns 선언된 범위면 true
     */
    isScopeAvailable(scope: unknown): scope is string {
        if (typeof scope !== 'string' || scope === '') return false;

        const scopes = this.deps.getScopes();

        return !!scopes && Object.prototype.hasOwnProperty.call(scopes, scope);
    }

    /**
     * 범위의 보관 개수를 반환합니다 (모르면 10).
     *
     * @param scope 범위 id
     * @returns 보관 개수
     */
    maxCountOf(scope: string): number {
        const value = Number(this.deps.getScopes()?.[scope]?.max_count);

        return Number.isFinite(value) && value >= 1 ? Math.floor(value) : 10;
    }

    /**
     * 범위의 지금 상태를 동기로 돌려줍니다 (회원 목록을 아직 받지 않았으면 `loading: true` 인 빈 목록).
     *
     * @param scope 범위 id
     * @returns 상태
     */
    getSnapshot(scope: string): SearchHistoryState {
        if (!this.isScopeAvailable(scope)) {
            return this.disabledState(scope);
        }

        if (this.isMember()) {
            const cached = this.memberCache.get(scope);
            if (cached) return { ...cached, items: [...cached.items] };

            return {
                scope,
                enabled: true,
                autoSave: this.memberAutoSave ?? true,
                items: [],
                maxCount: this.maxCountOf(scope),
                loading: true,
                source: 'member',
            };
        }

        return this.guestState(scope);
    }

    /**
     * 범위의 최근 검색어를 읽습니다 (회원은 서버에서 받아 캐시한다).
     *
     * @param scope 범위 id
     * @param options.force 회원 캐시를 무시하고 다시 받을지
     * @returns 상태
     */
    async list(scope: string, options: { force?: boolean } = {}): Promise<SearchHistoryState> {
        if (!this.isScopeAvailable(scope)) {
            return this.disabledState(scope);
        }

        if (!this.isMember()) {
            return this.guestState(scope);
        }

        if (!options.force) {
            const cached = this.memberCache.get(scope);
            if (cached) return { ...cached, items: [...cached.items] };
        }

        const pending = this.inflight.get(scope);
        if (pending) return pending;

        const request: Promise<SearchHistoryState> = this.fetchMember(scope).finally(() => {
            // 인증이 바뀐 뒤 새로 등록된 같은 범위의 조회를 지우지 않는다.
            if (this.inflight.get(scope) === request) this.inflight.delete(scope);
        });
        this.inflight.set(scope, request);

        return request;
    }

    /**
     * 검색어 하나를 기록합니다. 빈 검색어·선언되지 않은 범위·자동 저장 꺼짐이면 아무것도 하지 않습니다.
     *
     * 오류는 던지지 않는다 — 검색 이동을 막으면 안 되기 때문이다.
     *
     * @param scope 범위 id
     * @param keyword 검색어
     * @returns 기록했으면 true
     */
    async record(scope: string, keyword: unknown): Promise<boolean> {
        const normalized = normalizeSearchKeyword(keyword);
        if (normalized === '' || !this.isScopeAvailable(scope) || this.writesBlocked()) return false;

        try {
            if (this.isMember()) {
                const response = await this.deps.api.post(ENDPOINT, { scope, keyword: normalized });
                const data = response?.data ?? {};
                if (!data.recorded) {
                    // 서버가 기록하지 않았다(자동 저장 꺼짐 등) — 캐시를 비워 다음 조회가 서버 판정을 따르게 한다.
                    this.memberCache.delete(scope);
                    return false;
                }

                this.markMemberDataChanged();

                const item = this.toItem(data.item);
                const cached = this.memberCache.get(scope);
                if (cached && item) {
                    const rest = cached.items.filter((it) => sameKey(it.keyword) !== sameKey(item.keyword));
                    this.memberCache.set(scope, { ...cached, items: [item, ...rest].slice(0, cached.maxCount) });
                } else {
                    this.memberCache.delete(scope);
                }

                this.emit({ scope, reason: 'record' });

                return true;
            }

            const store = this.readGuest();
            if (!store || !store.autoSave) return false;

            const list = (store.items[scope] ?? []).filter((it) => sameKey(it.k) !== sameKey(normalized));
            store.items[scope] = [{ k: normalized, t: this.now() }, ...list].slice(0, this.maxCountOf(scope));

            if (!this.writeGuest(store)) return false;

            this.emit({ scope, reason: 'record' });

            return true;
        } catch (error) {
            logger.warn('record failed', error);

            return false;
        }
    }

    /**
     * 검색어 한 건을 지웁니다 (회원은 id, 비회원은 검색어로 찾는다).
     *
     * @param scope 범위 id
     * @param target 항목·id·검색어
     * @returns 지웠으면 true
     */
    async remove(scope: string, target: SearchHistoryItem | number | string): Promise<boolean> {
        if (!this.isScopeAvailable(scope) || this.writesBlocked()) return false;

        try {
            if (this.isMember()) {
                const id = typeof target === 'number' ? target : typeof target === 'object' ? target?.id : this.memberCache.get(scope)?.items.find((it) => sameKey(it.keyword) === sameKey(normalizeSearchKeyword(target)))?.id;
                if (typeof id !== 'number') return false;

                await this.deps.api.delete(`${ENDPOINT}/${id}`);
                this.markMemberDataChanged();

                const cached = this.memberCache.get(scope);
                if (cached) {
                    this.memberCache.set(scope, { ...cached, items: cached.items.filter((it) => it.id !== id) });
                }

                this.emit({ scope, reason: 'remove' });

                return true;
            }

            const keyword = normalizeSearchKeyword(typeof target === 'object' ? target?.keyword : target);
            const store = this.readGuest();
            if (!store || keyword === '') return false;

            const before = store.items[scope] ?? [];
            const after = before.filter((it) => sameKey(it.k) !== sameKey(keyword));
            if (after.length === before.length) return false;

            store.items[scope] = after;
            if (!this.writeGuest(store)) return false;

            this.emit({ scope, reason: 'remove' });

            return true;
        } catch (error) {
            logger.warn('remove failed', error);

            return false;
        }
    }

    /**
     * 범위의 검색어를 모두 지웁니다 (범위를 비우면 전 범위).
     *
     * @param scope 범위 id (생략하면 전 범위)
     * @returns 지웠으면 true
     */
    async clear(scope?: string | null): Promise<boolean> {
        const target = typeof scope === 'string' && scope !== '' ? scope : null;
        if (target !== null && !this.isScopeAvailable(target)) return false;
        if (this.writesBlocked()) return false;

        try {
            if (this.isMember()) {
                await this.deps.api.delete(ENDPOINT, target ? { params: { scope: target } } : undefined);
                this.markMemberDataChanged();

                if (target) {
                    const cached = this.memberCache.get(target);
                    if (cached) this.memberCache.set(target, { ...cached, items: [] });
                } else {
                    for (const [key, cached] of this.memberCache) {
                        this.memberCache.set(key, { ...cached, items: [] });
                    }
                }

                this.emit({ scope: target, reason: 'clear' });

                return true;
            }

            const store = this.readGuest();
            if (!store) return false;

            if (target) {
                delete store.items[target];
            } else {
                store.items = {};
            }

            if (!this.writeGuest(store)) return false;

            this.emit({ scope: target, reason: 'clear' });

            return true;
        } catch (error) {
            logger.warn('clear failed', error);

            return false;
        }
    }

    /**
     * 자동 저장 여부를 반환합니다 (회원은 마지막으로 받은 값, 모르면 켬).
     *
     * @returns 자동 저장 여부
     */
    getAutoSave(): boolean {
        if (this.isMember()) return this.memberAutoSave ?? true;

        return this.readGuest()?.autoSave ?? true;
    }

    /**
     * 자동 저장 여부를 바꿉니다 (끄면 기록을 멈추고 목록을 감춘다 — 저장된 검색어는 남는다).
     *
     * @param autoSave 자동 저장 여부
     * @returns 바꿨으면 true
     */
    async setAutoSave(autoSave: boolean): Promise<boolean> {
        const value = !!autoSave;
        if (this.writesBlocked()) return false;

        try {
            if (this.isMember()) {
                await this.deps.api.put(`${ENDPOINT}/settings`, { auto_save: value });
                this.markMemberDataChanged();
                this.memberAutoSave = value;
                // 목록 감춤·다시 보임은 서버가 판정한다 — 캐시를 비워 다시 받게 한다.
                this.memberCache.clear();
                this.emit({ scope: null, reason: 'autoSave' });

                return true;
            }

            const store = this.readGuest();
            if (!store) return false;

            store.autoSave = value;
            if (!this.writeGuest(store)) return false;

            this.emit({ scope: null, reason: 'autoSave' });

            return true;
        } catch (error) {
            logger.warn('setAutoSave failed', error);

            return false;
        }
    }

    /**
     * 인증 변경을 처리합니다 — 비회원 → 회원이면 브라우저 목록을 병합하고, 어느 쪽이든 회원 캐시를 비운다.
     *
     * @param state 새 인증 상태
     * @returns 없음
     */
    private handleAuthChange(state: SearchHistoryAuthState): void {
        const authenticated = !!state?.isAuthenticated;
        if (authenticated === this.lastAuthenticated) return;

        this.lastAuthenticated = authenticated;
        this.authEpoch++;
        this.memberCache.clear();
        this.inflight.clear();
        this.memberAutoSave = null;

        if (authenticated) {
            void this.mergeGuestIntoMember();
        } else {
            this.emit({ scope: null, reason: 'auth' });
        }
    }

    /**
     * 브라우저(비회원) 목록을 회원 목록에 병합하고, 성공하면 브라우저 칸을 지웁니다.
     *
     * @returns 끝나면 풀리는 Promise
     */
    mergeGuestIntoMember(): Promise<void> {
        if (this.merging) return this.merging;
        // 편집기 미리보기에서는 병합하지 않고 브라우저 칸도 지우지 않는다(다음 실제 화면의 로그인·부팅 때 옮긴다).
        if (this.writesBlocked()) return Promise.resolve();

        this.merging = (async () => {
            const store = this.readGuest();
            const items: Array<{ scope: string; keyword: string; searched_at: number }> = [];

            for (const [scope, list] of Object.entries(store?.items ?? {})) {
                for (const it of list ?? []) {
                    const keyword = normalizeSearchKeyword(it?.k);
                    if (keyword !== '') {
                        items.push({ scope, keyword, searched_at: Number.isFinite(it?.t) ? Math.floor(it.t) : this.now() });
                    }
                }
            }

            if (items.length > 0) {
                try {
                    // 최신 것부터 상한만큼 보낸다 — 서버도 같은 상한을 422 로 막는다.
                    items.sort((a, b) => b.searched_at - a.searched_at);
                    await this.deps.api.post(`${ENDPOINT}/merge`, { items: items.slice(0, SEARCH_HISTORY_MERGE_MAX_ITEMS) });
                    this.markMemberDataChanged();
                    this.removeGuest();
                } catch (error) {
                    // 실패하면 브라우저 칸을 남겨 다음 로그인 때 다시 보낸다.
                    logger.warn('merge failed — keeping browser history', error);
                }
            } else if (store) {
                // 검색어가 없는 칸(자동 저장 끔 표시만 남은 칸)도 회원이 되면 쓸모가 없다.
                this.removeGuest();
            }

            this.memberCache.clear();
            this.emit({ scope: null, reason: items.length > 0 ? 'merge' : 'auth' });
        })().finally(() => {
            this.merging = null;
        });

        return this.merging;
    }

    /**
     * 회원 목록을 서버에서 받습니다.
     *
     * @param scope 범위 id
     * @returns 상태
     */
    private async fetchMember(scope: string): Promise<SearchHistoryState> {
        const epoch = this.authEpoch;
        const dataEpoch = this.dataEpoch;

        try {
            const response = await this.deps.api.get(ENDPOINT, { params: { scope } });
            const data = response?.data ?? {};
            const state: SearchHistoryState = {
                scope,
                enabled: data.enabled !== false,
                autoSave: data.auto_save !== false,
                items: (Array.isArray(data.items) ? data.items : []).map((raw: unknown) => this.toItem(raw)).filter((it: SearchHistoryItem | null): it is SearchHistoryItem => it !== null),
                maxCount: Number.isFinite(Number(data.max_count)) ? Number(data.max_count) : this.maxCountOf(scope),
                loading: false,
                source: 'member',
            };

            // 받는 사이에 인증이 바뀌었으면(로그아웃·다른 계정 로그인) 캐시·자동 저장 값에 넣지 않는다.
            if (epoch !== this.authEpoch) {
                return { ...state, items: [...state.items] };
            }

            // 받는 사이에 회원 데이터가 바뀌었으면(기록·삭제·자동 저장 변경·병합) 이 응답은 변경 전 상태다 — 쓰지 않고
            // 최신 상태(변경이 다듬어 둔 캐시, 없으면 새 조회 또는 그 조회에 합류)를 돌려준다.
            if (dataEpoch !== this.dataEpoch) {
                return this.list(scope);
            }

            this.memberAutoSave = state.autoSave;
            if (this.isMember()) {
                this.memberCache.set(scope, state);
                this.emit({ scope, reason: 'load' });
            }

            return { ...state, items: [...state.items] };
        } catch (error) {
            logger.warn('list failed', error);

            return { scope, enabled: true, autoSave: this.memberAutoSave ?? true, items: [], maxCount: this.maxCountOf(scope), loading: false, source: 'member' };
        }
    }

    /**
     * 비회원 상태를 만듭니다.
     *
     * @param scope 범위 id
     * @returns 상태
     */
    private guestState(scope: string): SearchHistoryState {
        const store = this.readGuest();
        const autoSave = store?.autoSave ?? true;
        const maxCount = this.maxCountOf(scope);

        return {
            scope,
            enabled: store !== null,
            autoSave,
            items: autoSave
                ? (store?.items[scope] ?? []).slice(0, maxCount).map((it) => ({ id: null, keyword: it.k, searchedAt: it.t }))
                : [],
            maxCount,
            loading: false,
            source: store !== null ? 'guest' : 'none',
        };
    }

    /**
     * 쓸 수 없는 범위의 상태를 만듭니다.
     *
     * @param scope 범위 id
     * @returns 상태
     */
    private disabledState(scope: string): SearchHistoryState {
        return { scope, enabled: false, autoSave: this.getAutoSaveSafe(), items: [], maxCount: 0, loading: false, source: 'none' };
    }

    /**
     * 예외 없이 자동 저장 여부를 읽습니다.
     *
     * @returns 자동 저장 여부
     */
    private getAutoSaveSafe(): boolean {
        try {
            return this.getAutoSave();
        } catch {
            return true;
        }
    }

    /**
     * API 응답 항목을 바꿉니다.
     *
     * @param raw 응답 항목 (`{id, keyword, searched_at_ms}`)
     * @returns 항목 (모양이 틀리면 null)
     */
    private toItem(raw: any): SearchHistoryItem | null {
        if (!raw || typeof raw.keyword !== 'string') return null;

        const at = Number(raw.searched_at_ms);

        return {
            id: typeof raw.id === 'number' ? raw.id : Number.isFinite(Number(raw.id)) ? Number(raw.id) : null,
            keyword: raw.keyword,
            searchedAt: Number.isFinite(at) ? at : this.now(),
        };
    }

    /**
     * 비회원 저장 칸을 읽습니다 (없으면 빈 칸, 저장소를 못 쓰면 null).
     *
     * @returns 저장 칸 또는 null
     */
    private readGuest(): GuestStore | null {
        const storage = this.deps.storage;
        if (!storage) return null;

        let raw: string | null;
        try {
            raw = storage.getItem(SEARCH_HISTORY_STORAGE_KEY);
        } catch {
            return null;
        }

        const empty: GuestStore = { v: SEARCH_HISTORY_STORE_VERSION, autoSave: true, items: {} };
        if (!raw) return empty;

        try {
            const parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object' || parsed.v !== SEARCH_HISTORY_STORE_VERSION) return empty;

            const items: GuestStore['items'] = {};
            for (const [scope, list] of Object.entries(parsed.items ?? {})) {
                if (!Array.isArray(list)) continue;
                items[scope] = list
                    .filter((it: any) => it && typeof it.k === 'string' && it.k !== '')
                    .map((it: any) => ({ k: it.k, t: Number.isFinite(Number(it.t)) ? Number(it.t) : 0 }));
            }

            return { v: SEARCH_HISTORY_STORE_VERSION, autoSave: parsed.autoSave !== false, items };
        } catch {
            return empty;
        }
    }

    /**
     * 비회원 저장 칸을 씁니다.
     *
     * @param store 저장 칸
     * @returns 썼으면 true (저장소가 막혔으면 false)
     */
    private writeGuest(store: GuestStore): boolean {
        try {
            this.deps.storage?.setItem(SEARCH_HISTORY_STORAGE_KEY, JSON.stringify(store));

            return !!this.deps.storage;
        } catch (error) {
            logger.warn('guest storage write failed', error);

            return false;
        }
    }

    /**
     * 비회원 저장 칸을 지웁니다.
     *
     * @returns 없음
     */
    private removeGuest(): void {
        try {
            this.deps.storage?.removeItem(SEARCH_HISTORY_STORAGE_KEY);
        } catch {
            // 저장소가 막혀 있으면 지울 것도 없다.
        }
    }

    /**
     * 지금 회원인지 판정합니다.
     *
     * @returns 회원이면 true
     */
    private isMember(): boolean {
        return !!this.deps.getAuthState()?.isAuthenticated;
    }

    /**
     * 지금 시각을 반환합니다.
     *
     * @returns 밀리초
     */
    private now(): number {
        return this.deps.now ? this.deps.now() : Date.now();
    }

    /**
     * 변경 알림을 냅니다 (리스너 오류는 삼킨다).
     *
     * @param change 변경 내용
     * @returns 없음
     */
    private emit(change: SearchHistoryChange): void {
        try {
            this.deps.emit(change);
        } catch (error) {
            logger.warn('emit failed', error);
        }
    }
}
