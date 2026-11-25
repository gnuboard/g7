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
 * 전체화면 본인인증 진입 때, 넘어오기 전 화면이 세션 보관함에 남긴 인증 대상을 화면 상태로 되살립니다.
 *
 * 모달을 열지 못해 전체화면으로 넘어가면 페이지를 새로 읽어 전역 상태(`_global.identityChallenge.target`)가 사라진다.
 * 비회원은 로그인 세션에서 대상을 얻을 수 없어 [코드 재전송] 이 대상 없이 나가 422 로 거절됐다. launcher(와 코어
 * 기본 launcher)가 넘어가기 전 코어 보관함(`G7Core.identity.redirectStashKey`)에 대상을 함께 넣으므로, 그 값의
 * 이메일·전화번호만 꺼내 `_local.identity_target` 에 둔다. 30분이 지났거나 깨진 보관값은 쓰지 않는다.
 *
 * @param _action 액션 정의 (인자 없음)
 * @param _context 실행 컨텍스트 (미사용 — 상태는 G7Core.state 로 쓴다)
 * @return 되살린 대상, 없으면 null
 */
export declare function restoreIdentityTargetHandler(_action?: unknown, _context?: unknown): IdentityVerificationTarget | null;
/**
 * 전체화면 본인인증으로 넘어가기 전에 인증 대상을 세션 보관함에 남깁니다(7.2.1 가입 후 본인인증).
 *
 * 가입 폼은 가입 응답이 인증 건을 돌려주면 코드 입력 화면(`/identity/challenge`)으로 간다. 그 화면은 페이지를 새로 읽고
 * 비회원이라 로그인 세션에서 대상을 얻을 수 없으므로, [코드 다시 받기] 가 가입 이메일을 싣도록 launcher 와 같은 보관함·모양
 * (`{ payload: { purpose, target }, stashed_at }`)으로 남긴다. 이메일·전화번호만 보관하고, 둘 다 없으면 남기지 않는다.
 *
 * @param action 액션 정의 — `params.target`(email·phone) 또는 `params.email`·`params.phone`, 그리고 `params.purpose`
 * @param _context 실행 컨텍스트 (미사용)
 * @return 보관했으면 true
 */
export declare function stashIdentityTargetHandler(action?: {
    params?: {
        target?: Record<string, unknown> | null;
        email?: string;
        phone?: string;
        purpose?: string;
    };
}, _context?: unknown): boolean;
/**
 * 부트스트랩 시 코어 인터셉터에 launcher 를 등록합니다.
 *
 * `initTemplate()` 의 핸들러 등록 직후에 호출 — `window.G7Core.identity.setLauncher` 를 사용해
 * 코어의 단일 IdentityGuardInterceptor 정적 클래스에 launcher 를 주입합니다.
 */
export declare function registerSirsoftBasicIdentityLauncher(): void;
export {};
