/**
 * 방문자 동의 런타임 진입점
 *
 * 부팅 코드(TemplateApp)는 {@link installConsentRuntime} 하나만 부르고, 내장 핸들러 `refreshConsent` 와
 * `G7Core.consent` 파사드는 {@link getConsentRuntime} 으로 같은 인스턴스를 찾습니다.
 *
 * 런타임은 **사용자 템플릿에서만** 섭니다. 관리자 화면에는 방문자 동의 스냅샷이 필요 없고, 만들지 않으면
 * `getConsentRuntime()` 은 null 입니다.
 *
 * @since engine-v1.78.0
 */

import { getApiClient } from '../api/ApiClient';
import { AuthManager } from '../auth/AuthManager';
import {
    CONSENT_CHANGED_EVENT,
    ConsentRuntime,
    parseConsentPayload,
    type ConsentAuthState,
    type ConsentRuntimeDeps,
    type ConsentSnapshot,
} from './ConsentRuntime';

export {
    CONSENT_CHANGED_EVENT,
    CONSENT_CONTRACT_VERSION,
    ConsentRuntime,
    parseConsentPayload,
    unknownSnapshot,
} from './ConsentRuntime';
export type { ConsentAuthState, ConsentRuntimeDeps, ConsentSnapshot, ConsentStatus } from './ConsentRuntime';

/** 동의 스냅샷 조회 경로 (ApiClient 기준 — `/api` 접두는 클라이언트가 붙인다) */
const CONSENT_ENDPOINT = '/consent';

let runtime: ConsentRuntime | null = null;

/**
 * 현재 동의 런타임을 반환합니다 (관리자 화면이거나 아직 부팅 전이면 null).
 *
 * @returns 런타임 또는 null
 */
export function getConsentRuntime(): ConsentRuntime | null {
    return runtime;
}

/**
 * 기본 의존성을 만듭니다 — 코어 ApiClient(Bearer·401 처리 일원화), 전역 상태 쓰기, 컴포넌트 이벤트, AuthManager.
 *
 * @param writeGlobal `_global.consent` 를 쓰는 함수 (TemplateApp.setGlobalState)
 * @param watchGlobal 전역 상태 변경 구독 (TemplateApp.onGlobalStateChange — 리스너에는 `_global.consent` 를 넘긴다)
 * @returns 의존성
 */
export function defaultConsentRuntimeDeps(
    writeGlobal: (snapshot: ConsentSnapshot) => void,
    watchGlobal?: (listener: (consent: unknown) => void) => () => void,
): ConsentRuntimeDeps {
    const auth = AuthManager.getInstance();

    return {
        // 인증 상태일 때만 코어 ApiClient(Bearer·401 처리)를 쓴다. 비인증이면 토큰 없이 부른다 — 서버에서 폐기된
        // 토큰이 브라우저에 남은 방문자(preloadAuth 가 실패해도 토큰은 지우지 않는다)가 그 토큰으로 부르면 401 →
        // onUnauthorized → 공개 페이지에서 로그인 화면으로 튕긴다(DataSourceManager `auth_mode: optional` 과 같은 판정).
        fetchSnapshot: async () => {
            if (auth.isAuthenticated()) {
                return getApiClient().get(CONSENT_ENDPOINT);
            }

            const response = await fetch(`/api${CONSENT_ENDPOINT}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                throw new Error(`consent lookup failed: ${response.status}`);
            }

            return response.json();
        },
        writeGlobal,
        emit: async (snapshot) => {
            const componentEvent = (window as any).G7Core?.componentEvent;
            if (typeof componentEvent?.emit === 'function') {
                await componentEvent.emit(CONSENT_CHANGED_EVENT, snapshot);
            }
        },
        onAuthChange: (handler) => auth.on('authStateChange', (state: ConsentAuthState) => handler(state)),
        getAuthState: () => auth.getState(),
        watchGlobal,
    };
}

/**
 * 동의 런타임을 만들고 시작합니다. 이미 있으면 그것을 돌려줍니다.
 *
 * @param deps 의존성
 * @param initialPayload 서버가 인라인한 첫 값(`G7Config.consentInitial`, snake_case)
 * @returns 런타임
 */
export function installConsentRuntime(deps: ConsentRuntimeDeps, initialPayload?: unknown): ConsentRuntime {
    if (runtime) return runtime;

    runtime = new ConsentRuntime(deps, parseConsentPayload(initialPayload ?? null));
    void runtime.start();

    return runtime;
}

/**
 * 테스트용 — 런타임을 내립니다.
 *
 * @returns 없음
 */
export function resetConsentRuntime(): void {
    runtime?.stop();
    runtime = null;
}
