/**
 * KG이니시스 본인인증 plugin — 코어 IDV 모달 슬롯 기반 통합.
 *
 * 설계 (Phase E′ 전환):
 *  - 코어 IDV 흐름 (428 → 템플릿 launcher → POST /api/identity/challenges → 모달 open) 을 그대로 사용
 *  - 코어 모달의 `identity_provider_ui:text_code` Extension Point 슬롯을 본 plugin 의
 *    `resources/extensions/identity_provider_inicis.json` (mode: 'replace') 로 교체
 *  - 슬롯 안의 "이니시스 본인인증 시작" 버튼이 본 파일의 핸들러 `startAuth` 를 호출
 *  - 사용자 클릭 직접 호출 → window.open 이 사용자 제스처 컨텍스트 안에서 실행 →
 *    Chrome popup blocker 회피 (자동 호출은 차단됨)
 *  - 인증 완료 시 bridge 페이지가 부모창에 postMessage → resolveIdentityChallenge 핸들러 →
 *    코어 모달이 닫히고 return_request 재실행
 *
 * 이전 setLauncher 덮어쓰기 방식은 popup blocker 차단으로 폐기 (Phase E′-revert).
 *
 * @since 1.0.0-beta.1
 */

const PLUGIN_IDENTIFIER = 'sirsoft-verification_kginicis';
const PROVIDER_ID = 'inicis';
const POPUP_FEATURES = 'width=400,height=640,scrollbars=yes,resizable=yes';
const INICIS_AUTH_URL = 'https://sa.inicis.com/auth';
const POPUP_CLOSED_POLL_MS = 500;
const MODAL_ID = 'identity-challenge-modal';

interface InicisChallengePayload {
    mid: string;
    mtxid: string;
    reqSvcCd: string;
    flgFixedUser: string;
    reservedMsg: string;
    authHash: string;
}

interface BridgeResult {
    type: 'identity_result';
    verification_token?: string;
    challenge_id?: string;
    identity_error?: string;
}

const logger = {
    info: (...args: unknown[]) => console.info(`[${PLUGIN_IDENTIFIER}]`, ...args),
    warn: (...args: unknown[]) => console.warn(`[${PLUGIN_IDENTIFIER}]`, ...args),
    error: (...args: unknown[]) => console.error(`[${PLUGIN_IDENTIFIER}]`, ...args),
};

function getG7Core(): Record<string, any> | null {
    return ((window as any).G7Core as Record<string, any> | undefined) ?? null;
}

function buildSuccessUrl(): string {
    return `${window.location.origin}/plugins/${PLUGIN_IDENTIFIER}/plugin/inicis/callback`;
}

/**
 * 이니시스 본인인증 form 을 동적 생성하여 지정 target 에 POST.
 *
 * KISA 샘플 (sirsoft-verification/inicis/PHP/request.php) 와 동일한 form 구조 + setAttribute 패턴.
 */
function submitInicisForm(payload: InicisChallengePayload, popupName: string): void {
    const form = document.createElement('form');
    form.setAttribute('name', 'saForm');
    form.setAttribute('method', 'POST');
    form.setAttribute('action', INICIS_AUTH_URL);
    form.setAttribute('target', popupName);
    form.setAttribute('accept-charset', 'UTF-8');
    form.style.display = 'none';

    const fields: Record<string, string> = {
        mid: payload.mid,
        reqSvcCd: payload.reqSvcCd,
        mTxId: payload.mtxid,
        authHash: payload.authHash,
        flgFixedUser: payload.flgFixedUser,
        successUrl: buildSuccessUrl(),
        failUrl: buildSuccessUrl(),
        reservedMsg: payload.reservedMsg,
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
    // form 은 자동 정리되지 않으므로 다음 tick 에 제거
    window.setTimeout(() => { try { form.remove(); } catch { /* noop */ } }, 0);
}

/**
 * _global.identityChallenge.providerInProgress 플래그를 set 한다.
 *
 * 모달 슬롯의 if 분기 — true 면 진행 중 안내 (스피너 + "팝업창에서 진행 중") 노출,
 * false 면 사전 안내 카드 + 시작 버튼 노출.
 */
function setProviderInProgress(value: boolean): void {
    const G7Core = getG7Core();
    const set = G7Core?.state?.set;
    if (typeof set !== 'function') return;
    set({ identityChallenge: { providerInProgress: value } });
}

/**
 * verify 실패 코드 → 사용자 안내 메시지 i18n 키 매핑.
 *
 * 브리지 query 에는 failureCode 만 실려오므로(다국어 메시지 미전달), 프론트에서 코드를
 * `$t:` 다국어 키로 매핑한다. 코어 toast 핸들러가 `$t:` prefix 를 자동 번역하므로
 * 하드코딩 없이 로케일별 메시지가 표시된다.
 *
 * 미성년자 차단(NOT_ADULT) 안내 + 기존 미노출 실패 다건 안내를 일괄 처리한다.
 */
const FAILURE_MESSAGE_KEYS: Record<string, string> = {
    NOT_ADULT: '$t:sirsoft-verification_kginicis.errors.not_adult',
    INVALID_AUTH_URL: '$t:sirsoft-verification_kginicis.errors.invalid_auth_url',
    DECRYPT_FAILED: '$t:sirsoft-verification_kginicis.errors.decrypt_failed',
    REMOTE_CALL_FAILED: '$t:sirsoft-verification_kginicis.errors.remote_call_failed',
    NOT_FOUND: '$t:sirsoft-verification_kginicis.errors.not_found',
    ALREADY_CONSUMED: '$t:sirsoft-verification_kginicis.errors.already_consumed',
    IDENTITY_BINDING_MISMATCH: '$t:sirsoft-verification_kginicis.errors.binding_mismatch',
    INCOMPLETE_IDENTITY: '$t:sirsoft-verification_kginicis.errors.incomplete_identity',
    STORAGE_FAILED: '$t:sirsoft-verification_kginicis.errors.storage_failed',
};

/** 매핑되지 않은 코드용 일반 실패 안내 키 */
const FAILURE_MESSAGE_FALLBACK = '$t:sirsoft-verification_kginicis.errors.verify_failed';

/**
 * "본인확인 자체는 성공했으나 부가 목적(성년 등)을 충족하지 못해" 실패한 코드 집합.
 *
 * 이 부류의 실패는 사용자에게 고유 사유(예: 성인 인증 안내)를 토스트로 표출하므로, 코어가
 * 원 요청의 generic 가드 토스트("본인 확인이 필요합니다")를 중복 발화하지 않도록
 * `G7Core.identity.markDomainNoticeShown()` 신호를 남긴다.
 *
 * 일반 본인인증 실패(NOT_FOUND/INCOMPLETE_IDENTITY 등 본인확인 자체 실패)는 여기에 넣지 않는다 —
 * 그 경우 코어 generic 가드 토스트가 유일한 안내이므로 유지되어야 한다.
 *
 * 부수 목적이 추가되면(예: 실명 1급 인증 등) 그 failureCode 를 이 집합에 추가하기만 하면
 * 코어/타 확장 수정 없이 동일하게 중복 억제가 동작한다.
 */
const SUPPLEMENTARY_PURPOSE_FAILURE_CODES = new Set<string>([
    'NOT_ADULT',
]);

/**
 * failureCode 를 사용자 안내용 `$t:` 다국어 키로 변환한다.
 *
 * @param failureCode 브리지에서 전달된 실패 코드 (빈 값이면 fallback)
 * @returns `$t:` 다국어 키
 */
function resolveFailureMessageKey(failureCode: string): string {
    return FAILURE_MESSAGE_KEYS[failureCode] ?? FAILURE_MESSAGE_FALLBACK;
}

/**
 * 코어에 "이번 IDV 사이클에서 도메인 안내(성인인증 실패 등)를 사용자에게 표출했다"는 신호를 남긴다.
 *
 * 코어 toast 핸들러가 동일 사이클의 generic IDV 가드 토스트("본인 확인이 필요합니다")를 1회 skip 한다.
 * G7Core.identity 미초기화(구버전 코어)면 no-op — 이 경우 가드 토스트가 그대로 떠도 기능엔 영향 없음.
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
 * postMessage 결과 또는 popup.closed 감지 시 호출 — resolveIdentityChallenge + closeModal 을
 * sequence 로 함께 dispatch 하여 모달이 자동으로 닫히도록 한다.
 *
 * resolveIdentityChallenge 만 단독 dispatch 하면 코어 IdentityGuardInterceptor 의 deferred resolver
 * 만 호출되어 모달이 잔존하는 결함 (Phase E′-revert 이전 상태) 을 방지.
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
 * 코어 모달의 "이니시스 본인인증 시작" 버튼이 호출하는 핸들러.
 *
 * 코어 challenge 시작 응답의 `public_payload` 에 mid/mtxid/authHash 가 채워져 있다고 가정.
 * 사용자 직접 클릭 컨텍스트에서 window.open 호출 → Chrome popup blocker 회피.
 *
 * 흐름:
 *   1. _global.identityChallenge.public_payload 에서 페이로드 추출
 *   2. window.open 으로 빈 팝업 생성 (KISA 샘플과 동일 — 빈 URL + 고정 name 'sa_popup')
 *   3. providerInProgress=true 로 set → 모달 슬롯이 진행 중 안내로 전환
 *   4. 동적 form 생성 + form.target=팝업 + POST
 *   5. bridge postMessage 수신 또는 popup.closed 감지 시:
 *      - postMessage 수신 → resolveIdentityChallenge(verified|failed|cancelled) + closeModal sequence
 *      - 사용자 팝업 직접 닫음 → providerInProgress=false 로 set, 모달은 열린 채 유지 (재시도 가능)
 */
async function startAuthHandler(): Promise<void> {
    const G7Core = getG7Core();
    const challenge = G7Core?.state?.getGlobal?.()?.identityChallenge ?? G7Core?.state?.get?.()?.identityChallenge;

    if (!challenge) {
        logger.error('_global.identityChallenge 미설정 — 코어 모달 진입 흐름 확인 필요');
        return;
    }

    const payload = challenge.public_payload as InicisChallengePayload | undefined;
    if (!payload?.mid || !payload?.mtxid || !payload?.authHash) {
        logger.error('public_payload 의 mid/mtxid/authHash 부재 — Provider.requestChallenge 응답 확인 필요', payload);
        await safeToast('본인인증 페이로드가 준비되지 않았습니다. 잠시 후 다시 시도해 주세요.');
        return;
    }

    // 사용자 제스처 컨텍스트 안에서 popup 열기
    const popupName = 'sa_popup';
    const popup = window.open('', popupName, POPUP_FEATURES);
    if (!popup) {
        logger.error('window.open null — 팝업 차단됨');
        await safeToast('팝업이 차단되었습니다. 브라우저 팝업 허용 설정 후 다시 시도해 주세요.');
        return;
    }

    // 진행 중 상태 진입 — 모달 슬롯이 스피너 + 안내로 전환
    setProviderInProgress(true);

    submitInicisForm(payload, popupName);

    // listener 와 polling 은 둘 중 먼저 발생한 분기에서만 cleanup 수행 (이중 dispatch 방지)
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

        const params = data.verification_token
            ? { result: 'verified', token: data.verification_token }
            : data.identity_error
                ? { result: 'failed', failureCode: data.identity_error }
                : { result: 'cancelled' };

        // verify 실패 시 사용자에게 사유 안내 toast 발행.
        // 차단/실패는 동작하나 안내가 누락되던 결함을 NOT_ADULT 포함 전체 실패 코드에 대해 보강.
        if (data.identity_error) {
            // 부가 목적 미달류(성인인증 실패 등)는 고유 사유를 여기서 표출하므로, 코어가 원 요청의
            // generic 가드 토스트("본인 확인이 필요합니다")를 중복 발화하지 않도록 신호를 남긴다.
            // dispatchResolveAndClose → 코어 onError 가 가드 토스트를 띄우기 전에 set 되어야 한다.
            if (SUPPLEMENTARY_PURPOSE_FAILURE_CODES.has(data.identity_error)) {
                markDomainNoticeShown();
            }
            void safeToast(resolveFailureMessageKey(data.identity_error), 'error');
        }

        void dispatchResolveAndClose(params);
    };
    window.addEventListener('message', messageHandler);

    // popup.closed 폴링 — 사용자가 인증 안 끝내고 팝업 X 닫음 감지
    // postMessage 가 먼저 도착하면 settled=true 로 폴링이 무시됨.
    pollHandle = window.setInterval(() => {
        if (settled) {
            cleanup();
            return;
        }
        if (popup.closed) {
            settled = true;
            cleanup();
            // 사용자 직접 닫음 — resolveIdentityChallenge 는 dispatch 안 함.
            // 모달은 열린 채 유지하여 시작 버튼 재클릭으로 재시도 가능.
            setProviderInProgress(false);
        }
    }, POPUP_CLOSED_POLL_MS);
}

/**
 * Plugin 핸들러를 코어 ActionDispatcher 에 등록.
 *
 * 등록되면 extension JSON 의 actions 에서 `handler: "sirsoft-verification_kginicis.startAuth"`
 * 식별자로 호출 가능.
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

function init(): void {
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

// 테스트 환경에서는 vitest 가 jsdom 으로 window 를 제공하지만 G7Core 를 직접 mock 하므로
// 자동 init 을 건너뛰도록 한다 (`import.meta.env.MODE === 'test'` 시).
if (typeof import.meta === 'undefined' || (import.meta as any).env?.MODE !== 'test') {
    init();
}

(window as any).__SirsoftVerificationKginicis = {
    identifier: PLUGIN_IDENTIFIER,
    init,
    startAuthHandler,
};

// 테스트 / 외부 도구가 import 로 직접 호출할 수 있도록 named export 도 노출
export { startAuthHandler, init, PLUGIN_IDENTIFIER, PROVIDER_ID, MODAL_ID };