/**
 * 코어가 launcher 에 전달하는 verification payload (런타임 형태).
 * 코어 타입 import 를 피하려고 로컬에 정의 — 실제 형식은 `resources/js/core/identity/types.ts` 와 동일.
 */
interface IdentityVerificationTarget {
    email?: string;
    phone?: string;
}
interface VerificationPayload {
    policy_key: string;
    purpose: string;
    provider_id?: string | null;
    render_hint?: string | null;
    challenge_start_url?: string;
    redirect_url?: string;
    return_request?: {
        method: string;
        url: string;
        headers_echo?: string[];
    } | null;
    /** 흐름이 apiCall identity_target 으로 선언한 인증 대상 — 코어 인터셉터가 병합해 전달. */
    target?: IdentityVerificationTarget | null;
}
/**
 * launcher 가 코어에 돌려주는 결과 타입 (런타임 형태).
 */
type VerificationResult = {
    status: 'verified';
    token: string;
    providerData?: Record<string, unknown>;
} | {
    status: 'pending';
    pollUrl: string;
    pollIntervalMs?: number;
    expiresAt: string;
} | {
    status: 'cancelled';
} | {
    status: 'failed';
    failureCode: string;
    reason?: string;
};
/**
 * sirsoft-basic 의 IDV launcher 본체.
 *
 * 테스트에서 직접 호출할 수 있도록 export (런타임 진입점은 registerSirsoftBasicIdentityLauncher).
 */
export declare function sirsoftBasicIdentityLauncher(payload: VerificationPayload): Promise<VerificationResult>;
/**
 * 부트스트랩 시 코어 인터셉터에 launcher 를 등록합니다.
 *
 * `initTemplate()` 의 핸들러 등록 직후에 호출 — `window.G7Core.identity.setLauncher` 를 사용해
 * 코어의 단일 IdentityGuardInterceptor 정적 클래스에 launcher 를 주입합니다.
 */
export declare function registerSirsoftBasicIdentityLauncher(): void;
export {};
