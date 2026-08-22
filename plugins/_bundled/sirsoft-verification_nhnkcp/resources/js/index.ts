/**
 * NHN KCP 휴대폰 본인확인 plugin — 코어 IDV 모달 슬롯 기반 통합.
 *
 * 설계:
 *  - 코어 IDV 흐름 (428 → 템플릿 launcher → POST /api/identity/challenges → 모달 open) 을 그대로 사용
 *  - 코어 모달의 `identity_provider_ui:provider` Extension Point 슬롯에 본 plugin 의
 *    `resources/extensions/identity_provider_nhnkcp.json` (mode: append) 이 안내 창을 주입
 *  - 안내 창의 "본인확인 시작" 버튼이 본 파일의 핸들러 `startAuth` 를 호출
 *  - 사용자 클릭 직접 호출 → window.open 이 사용자 제스처 컨텍스트 안에서 실행되어 팝업 차단 회피
 *
 * 두 가지 진행 방식:
 *  - PC: 팝업(410×500)에서 KCP 표준창 진행 → 브리지가 부모창에 postMessage → 모달이 닫히고 원 요청 재실행
 *  - 모바일: 현재 페이지가 표준창으로 전환 → 인증 후 브리지가 원래 주소로 복귀 →
 *    본 스크립트가 입력 중이던 값을 복원하고, 복귀 주소의 `verification_token` 을 레이아웃이
 *    그대로 요청 본문에 실어 보낸다 (코어/템플릿 표준 계약).
 *
 * @since 1.0.0
 */

const PLUGIN_IDENTIFIER = 'sirsoft-verification_nhnkcp';
const PROVIDER_ID = 'nhnkcp';
const POPUP_FEATURES = 'width=410,height=500,scrollbars=yes,resizable=yes';
const POPUP_NAME = 'kcp_cert_popup';
const POPUP_CLOSED_POLL_MS = 500;
const MODAL_ID = 'identity-challenge-modal';

/** 코어 IdentityGuardInterceptor 와 공유하는 sessionStorage 키 (브리지가 복귀 주소를 읽는다) */
const REDIRECT_STASH_KEY = 'g7.identity.redirectStash';

/** 모바일 페이지 전환 중 입력값을 보관하는 자체 키 */
const FORM_STASH_KEY = 'sirsoft-verification_nhnkcp.formStash';

/** 입력값 보관 유효 시간 (ms) — 인증을 마치지 못한 채 방치된 값이 되살아나지 않도록 제한 */
const FORM_STASH_TTL_MS = 10 * 60 * 1000;

/** 복귀 후 폼이 렌더될 때까지 복원을 재시도하는 간격/횟수 */
const RESTORE_RETRY_MS = 200;
const RESTORE_RETRY_MAX = 25;

interface KcpChallengePayload {
    call_url: string;
    reg_cert_key: string;
    is_test_mode?: boolean;
}

interface BridgeResult {
    type: 'identity_result';
    verification_token?: string;
    challenge_id?: string;
    identity_error?: string;
    identity_error_message?: string;
}

interface FormStash {
    fields: Record<string, string>;
    stashed_at: number;
}

const logger = {
    info: (...args: unknown[]) => console.info(`[${PLUGIN_IDENTIFIER}]`, ...args),
    warn: (...args: unknown[]) => console.warn(`[${PLUGIN_IDENTIFIER}]`, ...args),
    error: (...args: unknown[]) => console.error(`[${PLUGIN_IDENTIFIER}]`, ...args),
};

function getG7Core(): Record<string, any> | null {
    return ((window as any).G7Core as Record<string, any> | undefined) ?? null;
}

/**
 * verify 실패 코드 → 사용자 안내 메시지 i18n 키 매핑.
 *
 * 브리지 query 에는 실패 코드만 실려오므로(다국어 메시지 미전달) 프론트에서 `$t:` 키로 매핑한다.
 * 코어 toast 핸들러가 `$t:` 접두사를 자동 번역하므로 하드코딩 없이 로케일별 메시지가 표시된다.
 * 매핑에 없는 KCP 응답 코드는 fallback 안내로 처리한다.
 */
const FAILURE_MESSAGE_KEYS: Record<string, string> = {
    '9999': `$t:${PLUGIN_IDENTIFIER}.errors.cancelled`,
    NOT_ADULT: `$t:${PLUGIN_IDENTIFIER}.errors.not_adult`,
    DUPLICATE: `$t:${PLUGIN_IDENTIFIER}.errors.duplicate`,
    REMOTE_CALL_FAILED: `$t:${PLUGIN_IDENTIFIER}.errors.remote_call_failed`,
    DECRYPT_FAILED: `$t:${PLUGIN_IDENTIFIER}.errors.decrypt_failed`,
    INCOMPLETE_IDENTITY: `$t:${PLUGIN_IDENTIFIER}.errors.incomplete_identity`,
    NOT_FOUND: `$t:${PLUGIN_IDENTIFIER}.errors.not_found`,
    ALREADY_CONSUMED: `$t:${PLUGIN_IDENTIFIER}.errors.already_consumed`,
    EXPIRED: `$t:${PLUGIN_IDENTIFIER}.errors.not_found`,
    IDENTITY_BINDING_MISMATCH: `$t:${PLUGIN_IDENTIFIER}.errors.binding_mismatch`,
    STORAGE_FAILED: `$t:${PLUGIN_IDENTIFIER}.errors.storage_failed`,
    PROVIDER_ERROR: `$t:${PLUGIN_IDENTIFIER}.errors.verify_failed`,
};

/** 매핑되지 않은 코드용 일반 실패 안내 키 */
const FAILURE_MESSAGE_FALLBACK = `$t:${PLUGIN_IDENTIFIER}.errors.verify_failed`;

/**
 * "본인확인 자체는 성공했으나 부가 목적(성년·중복 등)을 충족하지 못해" 실패한 코드 집합.
 *
 * 이 부류는 고유 사유를 토스트로 표출하므로, 코어가 원 요청의 generic 가드 토스트
 * ("본인 확인이 필요합니다")를 중복 발화하지 않도록 신호를 남긴다.
 */
const SUPPLEMENTARY_PURPOSE_FAILURE_CODES = new Set<string>(['NOT_ADULT', 'DUPLICATE']);

/**
 * failureCode 를 사용자 안내용 `$t:` 다국어 키로 변환한다.
 *
 * @param failureCode 브리지에서 전달된 실패 코드
 * @returns `$t:` 다국어 키
 */
function resolveFailureMessageKey(failureCode: string): string {
    return FAILURE_MESSAGE_KEYS[failureCode] ?? FAILURE_MESSAGE_FALLBACK;
}

/**
 * 사용자에게 보여줄 실패 안내 문구를 결정한다.
 *
 * 우리말 안내가 준비된 사유는 그 문구를 쓰고, 준비되지 않은 KCP 응답 코드는 KCP 가 돌려준
 * 원문을 `[코드] 메시지` 형태로 그대로 보여준다 — 사용자가 고객센터에 문의할 때 코드가
 * 그대로 전달되도록 한다. 원문이 없으면 일반 실패 안내로 떨어진다.
 *
 * @param failureCode 브리지에서 전달된 실패 코드
 * @param providerMessage KCP 원문 메시지 (없을 수 있음)
 * @returns 토스트에 표시할 문구 (다국어 키 또는 완성된 문장)
 */
function resolveFailureMessage(failureCode: string, providerMessage?: string): string {
    const mapped = FAILURE_MESSAGE_KEYS[failureCode];
    if (mapped) {
        return mapped;
    }

    const message = (providerMessage ?? '').trim();
    if (message === '') {
        return FAILURE_MESSAGE_FALLBACK;
    }

    return failureCode ? `[${failureCode}] ${message}` : message;
}

/**
 * 안내 창의 모바일 문구가 노출되는 폭 경계 (엔진 `portable` breakpoint 의 상한).
 *
 * 안내 창(`resources/extensions/identity_provider_nhnkcp.json`)은 `responsive.portable`
 * 로 "인증 화면으로 이동합니다" 문구를 띄운다. 실제 진행 방식을 고르는 아래 판정이 이 경계와
 * 어긋나면, 화면은 페이지 전환을 안내하는데 실제로는 팝업이 열린다.
 *
 * SSoT: `resources/js/core/template-engine/ResponsiveManager.ts` 의 `portable` 상한(1023).
 */
const PORTABLE_MAX_WIDTH = 1023;

/**
 * 모바일(페이지 전환) 진행 대상인지 판정한다.
 *
 * KCP 표준창은 모바일에서 페이지 전환 방식을 권장하며, 팝업은 모바일 브라우저에서 탭 전환으로
 * 열려 사용자가 원 페이지로 돌아오지 못하는 경우가 있다. 태블릿도 같은 문제를 겪으므로
 * `portable` 전 구간을 페이지 전환 대상으로 본다.
 *
 * 폭은 `matchMedia` 가 아니라 **`window.innerWidth`** 로 읽는다 — 엔진이 breakpoint 를 정할 때
 * 쓰는 값과 같아야 하기 때문이다. devicePixelRatio 가 정수가 아닌 환경에서 두 값은 경계에서
 * 1px 어긋난다(실측: dPR 1.0000000447 에서 `innerWidth=1023` 인데
 * `matchMedia('(max-width: 1023px)')` 는 false). 그 1px 에서 안내 문구와 실제 동작이 갈렸다.
 *
 * @returns 페이지 전환 방식으로 진행해야 하면 true
 */
function isMobileEnvironment(): boolean {
    if (typeof window === 'undefined') return false;
    const ua = window.navigator?.userAgent ?? '';
    if (/Android|iPhone|iPad|iPod|IEMobile|Opera Mini/i.test(ua)) return true;
    return typeof window.innerWidth === 'number' && window.innerWidth <= PORTABLE_MAX_WIDTH;
}

/**
 * `_global.identityChallenge.providerInProgress` 플래그를 set 한다.
 *
 * 안내 창의 if 분기 — true 면 진행 중 안내(스피너), false 면 시작 버튼이 노출된다.
 */
function setProviderInProgress(value: boolean): void {
    const set = getG7Core()?.state?.set;
    if (typeof set !== 'function') return;
    set({ identityChallenge: { providerInProgress: value } });
}

/**
 * 코어에 "이번 IDV 사이클에서 도메인 안내를 사용자에게 표출했다"는 신호를 남긴다.
 */
function markDomainNoticeShown(): void {
    const mark = getG7Core()?.identity?.markDomainNoticeShown;
    if (typeof mark === 'function') {
        try { mark(); } catch { /* noop */ }
    }
}

/**
 * dispatch 가 가능하면 toast 발행 (best-effort, 실패해도 흐름 유지).
 */
async function safeToast(message: string, type: 'error' | 'warning' | 'success' = 'error'): Promise<void> {
    const dispatch = getG7Core()?.dispatch;
    if (typeof dispatch !== 'function') return;
    try {
        await dispatch({ handler: 'toast', params: { type, message } });
    } catch { /* noop */ }
}

/**
 * KCP 표준창 form 을 동적 생성하여 지정 target 으로 POST 한다.
 *
 * KCP 규격: 거래등록 응답의 `call_url` 로 `reg_cert_key` 와 `kcp_page_submit_yn=Y` 를 POST 한다.
 *
 * @param payload challenge public_payload (call_url / reg_cert_key)
 * @param target form target (팝업 이름 또는 `_self`)
 */
function submitKcpForm(payload: KcpChallengePayload, target: string): void {
    const form = document.createElement('form');
    form.setAttribute('name', 'kcpCertForm');
    form.setAttribute('method', 'POST');
    form.setAttribute('action', payload.call_url);
    form.setAttribute('target', target);
    form.setAttribute('accept-charset', 'UTF-8');
    form.style.display = 'none';

    const fields: Record<string, string> = {
        reg_cert_key: payload.reg_cert_key,
        kcp_page_submit_yn: 'Y',
    };

    for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }

    document.body.appendChild(form);
    form.submit();
    // 페이지 전환(target=_self)이 아닌 경우 form 이 남으므로 다음 tick 에 제거
    window.setTimeout(() => { try { form.remove(); } catch { /* noop */ } }, 0);
}

/**
 * 모바일 페이지 전환 전에 복귀 주소와 입력 중이던 값을 보관한다.
 *
 * - 복귀 주소: 코어와 공유하는 `g7.identity.redirectStash` (브리지가 이 값을 읽어 되돌린다)
 * - 입력값: 화면의 name 있는 입력 요소 값. 비밀번호/파일/숨김 필드는 보관하지 않는다.
 */
function stashBeforeRedirect(): void {
    try {
        window.sessionStorage.setItem(REDIRECT_STASH_KEY, JSON.stringify({
            return_url: window.location.href,
            payload: {},
            stashed_at: Date.now(),
        }));
    } catch (e) {
        logger.warn('복귀 주소 보관 실패 — 인증 후 처음 화면으로 돌아갈 수 있습니다.', e);
    }

    try {
        window.sessionStorage.setItem(FORM_STASH_KEY, JSON.stringify({
            fields: collectFormFields(),
            stashed_at: Date.now(),
        } satisfies FormStash));
    } catch (e) {
        logger.warn('입력값 보관 실패', e);
    }
}

/**
 * 화면의 입력 요소에서 복원 대상 값을 수집한다.
 *
 * 비밀번호는 보관하지 않는다 (sessionStorage 평문 잔류 방지) — 복귀 후 사용자가 다시 입력한다.
 *
 * @returns name → value 맵
 */
function collectFormFields(): Record<string, string> {
    const result: Record<string, string> = {};
    const nodes = document.querySelectorAll<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>(
        'input[name], select[name], textarea[name]'
    );

    nodes.forEach((node) => {
        const name = node.getAttribute('name') ?? '';
        if (!name) return;

        const type = (node.getAttribute('type') ?? '').toLowerCase();
        if (type === 'password' || type === 'file' || type === 'hidden') return;

        if (type === 'checkbox' || type === 'radio') {
            if ((node as HTMLInputElement).checked) {
                result[name] = String((node as HTMLInputElement).value ?? 'on');
            }
            return;
        }

        const value = String(node.value ?? '');
        if (value !== '') {
            result[name] = value;
        }
    });

    return result;
}

/**
 * 보관한 입력값을 화면에 되돌린다.
 *
 * React 제어 컴포넌트가 값을 인식하도록 네이티브 setter 로 값을 넣고 input/change 이벤트를
 * 발생시킨다. 복귀 직후에는 폼이 아직 렌더되지 않았을 수 있어 짧은 간격으로 재시도한다.
 *
 * @param fields name → value 맵
 */
function restoreFormFields(fields: Record<string, string>): void {
    const names = Object.keys(fields);
    if (names.length === 0) return;

    let attempts = 0;
    const timer = window.setInterval(() => {
        attempts += 1;
        let restored = 0;

        names.forEach((name) => {
            const node = document.querySelector<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>(
                `[name="${escapeAttributeValue(name)}"]`
            );
            if (!node) return;

            const type = (node.getAttribute('type') ?? '').toLowerCase();
            if (type === 'checkbox' || type === 'radio') {
                const input = node as HTMLInputElement;
                if (input.value === fields[name] && !input.checked) {
                    input.click();
                }
                restored += 1;
                return;
            }

            setNativeValue(node, fields[name]);
            restored += 1;
        });

        if (restored === names.length || attempts >= RESTORE_RETRY_MAX) {
            window.clearInterval(timer);
        }
    }, RESTORE_RETRY_MS);
}

/**
 * 선택자의 속성값에 쓸 수 있도록 문자열을 이스케이프한다.
 *
 * `CSS.escape` 는 일부 실행 환경(구형 브라우저 · 테스트용 DOM 구현)에 없으므로, 없으면
 * 속성 선택자에서 문제가 되는 역슬래시와 큰따옴표만 직접 이스케이프한다.
 *
 * @param value 이스케이프할 값
 * @returns 선택자에 안전한 문자열
 */
function escapeAttributeValue(value: string): string {
    const cssApi = (globalThis as any).CSS;
    if (cssApi && typeof cssApi.escape === 'function') {
        return cssApi.escape(value);
    }

    return value.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
}

/**
 * React 제어 컴포넌트가 인식하는 방식으로 입력값을 설정한다.
 *
 * value 를 직접 대입하면 React 의 값 추적기가 변경을 감지하지 못해 onChange 가 발생하지 않는다.
 * 프로토타입의 네이티브 setter 를 사용해 추적기를 우회한 뒤 이벤트를 발생시킨다.
 *
 * @param node 대상 입력 요소
 * @param value 설정할 값
 */
function setNativeValue(node: HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement, value: string): void {
    const prototype = Object.getPrototypeOf(node);
    const descriptor = Object.getOwnPropertyDescriptor(prototype, 'value');

    if (descriptor?.set) {
        descriptor.set.call(node, value);
    } else {
        (node as HTMLInputElement).value = value;
    }

    node.dispatchEvent(new Event('input', { bubbles: true }));
    node.dispatchEvent(new Event('change', { bubbles: true }));
}

/**
 * postMessage 결과 수신 시 호출 — resolveIdentityChallenge + closeModal 을 sequence 로 함께
 * dispatch 하여 모달이 자동으로 닫히도록 한다.
 *
 * resolveIdentityChallenge 만 단독 dispatch 하면 코어 인터셉터의 deferred resolver 만 호출되어
 * 모달이 잔존한다.
 */
function dispatchResolveAndClose(params: Record<string, unknown>): Promise<unknown> {
    const dispatch = getG7Core()?.dispatch;
    if (typeof dispatch !== 'function') return Promise.resolve();
    return dispatch({
        handler: 'sequence',
        params: {
            actions: [
                { handler: 'resolveIdentityChallenge', params },
                { handler: 'closeModal', target: MODAL_ID },
            ],
        },
    }).catch(() => { /* noop */ });
}

/**
 * 이미 사용(성공/실패로 종료)된 challenge 식별자 집합.
 *
 * 같은 거래로 표준창을 다시 열면 KCP 가 거절하므로, 재시도 시 새 거래를 발급받을지 판단하는 데
 * 쓴다. 페이지가 살아 있는 동안만 유지되면 충분하다.
 */
const consumedChallengeIds = new Set<string>();

/**
 * challenge 를 사용 완료로 표시한다.
 *
 * @param challengeId challenge 식별자
 */
function markChallengeConsumed(challengeId?: string): void {
    if (challengeId) {
        consumedChallengeIds.add(challengeId);
    }
}

/**
 * challenge 가 이미 사용되었는지 확인한다.
 *
 * @param challengeId challenge 식별자
 * @returns 사용 완료 여부
 */
function isChallengeConsumed(challengeId?: string): boolean {
    return !!challengeId && consumedChallengeIds.has(challengeId);
}

/**
 * 인증을 마치지 않고 이탈한 challenge 를 서버에도 종료로 알린다.
 *
 * 사용자가 인증창을 그냥 닫으면 브라우저는 그 거래를 끝난 것으로 처리하지만, 서버는 아무 통보를
 * 받지 못해 인증 이력이 `sent` 상태로, 거래 행은 `consumed_at = NULL` 로 남는다. TTL(15분)이
 * 지나면 만료되므로 기능 결함은 아니나, 관리자 이력에서 "보낸 뒤 소식 없는" 행이 쌓이고 그 거래키가
 * 만료 전까지 유효한 상태로 남는다. 코어 취소 엔드포인트를 호출해 두 상태를 함께 닫는다.
 *
 * 실패해도 흐름을 막지 않는다 — 이탈 정리는 부가 작업이고, 재시도는 브라우저측 소비 표시만으로도
 * 정상 동작한다(새 거래를 발급받는다).
 *
 * @param challengeId 이탈한 challenge 식별자
 */
async function reportAbandonedChallenge(challengeId?: string): Promise<void> {
    if (!challengeId) return;

    const token = getG7Core()?.api?.getToken?.() ?? null;

    try {
        await fetch(`/api/identity/challenges/${encodeURIComponent(challengeId)}/cancel`, {
            method: 'POST',
            credentials: 'include',
            headers: {
                Accept: 'application/json',
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
            },
        });
    } catch (e) {
        logger.warn('이탈 거래 취소 통보 실패 (재시도 흐름에는 영향 없음)', e);
    }
}

/**
 * 새 challenge 를 발급받아 `_global.identityChallenge` 를 갱신한다.
 *
 * 실패 후 재시도 흐름에서만 호출된다. 코어 challenge 시작 API 를 그대로 사용하므로 거래등록도
 * 서버에서 새로 수행된다.
 *
 * @param current 현재 challenge 상태 (purpose / provider_id / target 승계)
 * @returns 갱신된 challenge 상태 또는 null (발급 실패)
 */
async function reissueChallenge(current: Record<string, any>): Promise<Record<string, any> | null> {
    const G7Core = getG7Core();
    const body: Record<string, unknown> = { purpose: current.purpose };
    if (current.provider_id) body.provider_id = current.provider_id;
    if (current.target) body.target = current.target;

    try {
        const token = G7Core?.api?.getToken?.() ?? null;
        const locale = (() => {
            try {
                return window.localStorage?.getItem('g7_locale');
            } catch {
                return null;
            }
        })();

        const res = await fetch('/api/identity/challenges', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(locale ? { 'Accept-Language': locale } : {}),
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
            },
            body: JSON.stringify(body),
        });

        const json = await res.json().catch(() => null);
        if (!res.ok || !json?.success) {
            logger.error('challenge 재발급 실패', json);
            return null;
        }

        const data = json.data ?? json;
        const next = {
            ...current,
            challenge_id: data.id,
            expires_at: data.expires_at,
            public_payload: data.public_payload ?? {},
            error: null,
        };

        G7Core?.state?.set?.({ identityChallenge: next });

        return next;
    } catch (e) {
        logger.error('challenge 재발급 중 오류', e);
        return null;
    }
}

/**
 * 안내 창의 "본인확인 시작" 버튼이 호출하는 핸들러.
 *
 * 흐름:
 *   1. `_global.identityChallenge.public_payload` 에서 표준창 호출 정보 추출
 *   2. PC — 빈 팝업 생성 후 그 팝업으로 표준창 form POST
 *      모바일 — 복귀 주소/입력값 보관 후 현재 페이지를 표준창으로 전환
 *   3. providerInProgress=true → 안내 창이 진행 중 상태로 전환
 *   4. 브리지 postMessage 수신 또는 팝업 닫힘 감지 시 후처리
 */
async function startAuthHandler(): Promise<void> {
    const G7Core = getG7Core();
    let challenge = G7Core?.state?.getGlobal?.()?.identityChallenge ?? G7Core?.state?.get?.()?.identityChallenge;

    if (!challenge) {
        logger.error('_global.identityChallenge 미설정 — 코어 모달 진입 흐름 확인 필요');
        return;
    }

    // 한 번 사용된 거래는 KCP 쪽에서도 우리 쪽에서도 재사용할 수 없다. 실패 후 다시 시도하는
    // 경우에는 새 거래를 먼저 발급받아야 표준창이 정상적으로 열린다.
    if (isChallengeConsumed(challenge.challenge_id)) {
        const reissued = await reissueChallenge(challenge);
        if (!reissued) {
            await safeToast(`$t:${PLUGIN_IDENTIFIER}.modal.payload_not_ready`);
            return;
        }
        challenge = reissued;
    }

    const payload = challenge.public_payload as KcpChallengePayload | undefined;
    if (!payload?.call_url || !payload?.reg_cert_key) {
        logger.error('public_payload 의 call_url/reg_cert_key 부재 — 거래등록 응답 확인 필요', payload);
        await safeToast(`$t:${PLUGIN_IDENTIFIER}.modal.payload_not_ready`);
        return;
    }

    if (isMobileEnvironment()) {
        stashBeforeRedirect();
        setProviderInProgress(true);
        submitKcpForm(payload, '_self');
        return;
    }

    // 사용자 제스처 컨텍스트 안에서 팝업 열기
    const popup = window.open('', POPUP_NAME, POPUP_FEATURES);
    if (!popup) {
        logger.error('window.open null — 팝업 차단됨');
        await safeToast(`$t:${PLUGIN_IDENTIFIER}.modal.popup_blocked`);
        return;
    }

    setProviderInProgress(true);
    submitKcpForm(payload, POPUP_NAME);
    watchPopup(popup);
}

/**
 * 팝업 결과(postMessage) 와 사용자 강제 닫기(polling)를 감시한다.
 *
 * 둘 중 먼저 발생한 분기에서만 정리하여 이중 dispatch 를 방지한다.
 *
 * @param popup 열린 팝업 창
 */
function watchPopup(popup: Window): void {
    let settled = false;
    let pollHandle: number | null = null;
    let messageHandler: ((ev: MessageEvent<unknown>) => void) | null = null;

    const cleanup = (): void => {
        if (messageHandler) {
            window.removeEventListener('message', messageHandler);
            messageHandler = null;
        }
        if (pollHandle !== null) {
            window.clearInterval(pollHandle);
            pollHandle = null;
        }
    };

    messageHandler = (ev: MessageEvent<unknown>) => {
        if (ev.origin !== window.location.origin) return;
        const data = ev.data as BridgeResult | null;
        if (!data || data.type !== 'identity_result') return;
        if (settled) return;
        settled = true;

        cleanup();
        try { popup.close(); } catch { /* noop */ }
        setProviderInProgress(false);

        if (data.identity_error) {
            // 실패한 인증은 안내 창을 닫지 않는다 — 사유를 안내하고 [본인확인 시작] 으로 다시
            // 시도할 수 있게 남겨 둔다 (와이어프레임 §4.3). 원 요청은 아직 대기 상태이므로,
            // 재시도가 성공하면 그대로 이어서 처리된다. 사용자가 그만두려면 안내 창의 취소를
            // 누르면 되고, 그때 코어가 원 요청을 폐기한다.
            // 콜백이 challenge_id 를 알려주지 않아도 진행 중이던 거래는 끝난 것이 확실하다.
            // 여기서 소비 표시를 놓치면 재시도가 죽은 거래키로 인증창을 열어 "이미 종료된 거래"
            // 로 실패하고, 사용자는 몇 번을 눌러도 진행하지 못한다.
            const finishedChallengeId = data.challenge_id
                || (getG7Core()?.state?.getGlobal?.()?.identityChallenge?.challenge_id as string | undefined);
            markChallengeConsumed(finishedChallengeId);

            // 부가 목적 미달류(성인·중복)는 고유 사유를 여기서 표출하므로, 코어가 원 요청의
            // generic 가드 토스트를 중복 발화하지 않도록 신호를 먼저 남긴다.
            if (SUPPLEMENTARY_PURPOSE_FAILURE_CODES.has(data.identity_error)) {
                markDomainNoticeShown();
            }
            void safeToast(resolveFailureMessage(data.identity_error, data.identity_error_message), 'error');
            return;
        }

        const params = data.verification_token
            ? { result: 'verified', token: data.verification_token }
            : { result: 'cancelled' };

        void dispatchResolveAndClose(params);
    };
    window.addEventListener('message', messageHandler);

    // 사용자가 인증을 마치지 않고 팝업을 닫은 경우 감지 — 모달은 열어 둔 채 재시도 가능하게 한다.
    pollHandle = window.setInterval(() => {
        if (settled) {
            cleanup();
            return;
        }
        if (popup.closed) {
            settled = true;
            cleanup();
            setProviderInProgress(false);

            // 이 거래는 이미 표준창에 제출됐다. KCP 거래키에는 유효 시간이 있어 같은 거래로 다시
            // 열면 한참 뒤의 재시도가 아무 안내 없이 실패할 수 있으므로, 다시 시작할 때 새 거래를
            // 받도록 소비 표시를 남긴다.
            const abandonedId = getG7Core()?.state?.getGlobal?.()?.identityChallenge?.challenge_id as
                | string
                | undefined;
            markChallengeConsumed(abandonedId);

            // 브라우저만 알고 있으면 서버 이력은 `sent` 로, 거래 행은 `consumed_at = NULL` 로
            // 남는다. 코어 취소 엔드포인트로 두 상태를 함께 닫는다.
            void reportAbandonedChallenge(abandonedId);
        }
    }, POPUP_CLOSED_POLL_MS);
}

/**
 * 모바일 페이지 전환 인증에서 복귀했을 때의 후처리.
 *
 * 브리지가 복귀 주소에 붙여준 결과에 따라:
 *  - 성공: 보관한 입력값을 복원하고 완료 안내를 띄운다. `verification_token` query 는 그대로 둔다 —
 *    레이아웃이 요청 본문에 그 값을 실어 보내는 것이 코어/템플릿 표준 계약이다.
 *  - 실패: 실패 사유를 안내하고 입력값을 복원한 뒤 주소에서 실패 코드를 제거한다.
 */
function handleMobileReturn(): void {
    if (typeof window === 'undefined') return;

    const params = new URLSearchParams(window.location.search);
    const token = params.get('verification_token') ?? '';
    const failureCode = params.get('identity_error') ?? '';

    if (token === '' && failureCode === '') return;

    const stash = readFormStash();
    if (stash) {
        restoreFormFields(stash.fields);
    }

    if (token !== '') {
        void safeToast(`$t:${PLUGIN_IDENTIFIER}.modal.mobile_return_success`, 'success');
        return;
    }

    if (SUPPLEMENTARY_PURPOSE_FAILURE_CODES.has(failureCode)) {
        markDomainNoticeShown();
    }
    void safeToast(resolveFailureMessage(failureCode, params.get('identity_error_message') ?? undefined), 'error');

    // 실패 코드는 화면 상태에 영향을 주지 않으므로 주소에서 제거한다 (새로고침 시 재발화 방지).
    params.delete('identity_error');
    params.delete('identity_error_message');
    const query = params.toString();
    const nextUrl = window.location.pathname + (query ? `?${query}` : '') + window.location.hash;
    try {
        window.history.replaceState(null, '', nextUrl);
    } catch { /* noop */ }
}

/**
 * 보관된 입력값을 읽고 즉시 폐기한다 (1회성 — 다음 방문에 되살아나지 않도록).
 *
 * @returns 유효 시간 내의 보관 값 또는 null
 */
function readFormStash(): FormStash | null {
    let raw: string | null = null;
    try {
        raw = window.sessionStorage.getItem(FORM_STASH_KEY);
        window.sessionStorage.removeItem(FORM_STASH_KEY);
    } catch {
        return null;
    }

    if (!raw) return null;

    try {
        const parsed = JSON.parse(raw) as FormStash;
        if (!parsed?.fields || typeof parsed.stashed_at !== 'number') return null;
        if (Date.now() - parsed.stashed_at > FORM_STASH_TTL_MS) return null;
        return parsed;
    } catch {
        return null;
    }
}

/**
 * Plugin 핸들러를 코어 ActionDispatcher 에 등록한다.
 *
 * 등록되면 extension JSON 의 actions 에서 `handler: "sirsoft-verification_nhnkcp.startAuth"`
 * 식별자로 호출할 수 있다.
 */
function registerHandlers(): boolean {
    const G7Core = getG7Core();
    const getDispatcher = G7Core?.getActionDispatcher;
    if (typeof getDispatcher !== 'function') return false;

    const dispatcher = getDispatcher();
    if (!dispatcher || typeof dispatcher.registerHandler !== 'function') return false;

    dispatcher.registerHandler(`${PLUGIN_IDENTIFIER}.startAuth`, startAuthHandler, {
        category: 'plugin',
        source: PLUGIN_IDENTIFIER,
    });
    logger.info('startAuth handler registered');
    return true;
}

/**
 * ActionDispatcher 가 준비될 때까지 재시도하며 핸들러를 등록한다.
 */
function registerHandlersWithRetry(): void {
    if (registerHandlers()) return;

    let retries = 0;
    const interval = window.setInterval(() => {
        retries++;
        if (registerHandlers() || retries >= 50) {
            window.clearInterval(interval);
            if (retries >= 50) {
                logger.warn('G7Core ActionDispatcher 미준비 — handler 등록 실패');
            }
        }
    }, 100);
}

/**
 * 코어 재초기화 시 호출되는 진입점.
 *
 * 로케일 전환 등으로 TemplateApp 이 ActionDispatcher 를 새로 만들면
 * `TemplateApp.reinitializePluginHandlers()` 가 `window.__[Plugin].initPlugin()` 을 호출한다.
 * 이 이름으로 노출하지 않으면 재초기화 후 startAuth 핸들러가 소실되어
 * 본인확인 시작 버튼이 무반응이 된다.
 *
 * 핸들러 재등록만 수행한다 — 모바일 복귀 처리(handleMobileReturn)는 최초 진입 1회로 충분하다.
 */
function initPlugin(): void {
    registerHandlersWithRetry();
}

function init(): void {
    handleMobileReturn();
    registerHandlersWithRetry();
}

// 테스트 환경에서는 vitest 가 jsdom 으로 window 를 제공하지만 G7Core 를 직접 mock 하므로
// 자동 init 을 건너뛴다.
if (typeof import.meta === 'undefined' || (import.meta as any).env?.MODE !== 'test') {
    init();
}

(window as any).__SirsoftVerificationNhnkcp = {
    identifier: PLUGIN_IDENTIFIER,
    init,
    // 코어 재초기화 시 핸들러 재등록 진입점 — 이름 고정 (TemplateApp.reinitializePluginHandlers)
    initPlugin,
    startAuthHandler,
    handleMobileReturn,
};

// 테스트 / 외부 도구가 import 로 직접 호출할 수 있도록 named export 도 노출
export {
    startAuthHandler,
    handleMobileReturn,
    isMobileEnvironment,
    resolveFailureMessageKey,
    resolveFailureMessage,
    init,
    initPlugin,
    PLUGIN_IDENTIFIER,
    PROVIDER_ID,
    MODAL_ID,
    FORM_STASH_KEY,
    REDIRECT_STASH_KEY,
};
