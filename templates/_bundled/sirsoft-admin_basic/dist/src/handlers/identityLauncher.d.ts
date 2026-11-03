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
export declare function sirsoftAdminBasicIdentityLauncher(payload: VerificationPayload): Promise<VerificationResult>;
/**
 * [다시 보내기]가 새로 받은 링크 방식 비밀값을 기억합니다 — 인증 창이 새 건의 확인 결과를 받아 가게.
 *
 * @param action 액션 정의 (params.challenge_id · params.claim_secret)
 * @param _context 실행 컨텍스트 (미사용)
 * @return 기억했으면 true
 */
export declare function rememberIdentityClaimHandler(action?: {
    params?: Record<string, unknown>;
} & Record<string, any>, _context?: any): boolean;
/**
 * 부트스트랩 시 코어 인터셉터에 launcher 를 등록합니다.
 */
export declare function registerSirsoftAdminBasicIdentityLauncher(): void;
export {};
