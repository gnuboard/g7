/**
 * IDV (Identity Verification) 공용 타입
 *
 * 코어 인터셉터(IdentityGuardInterceptor) / 코어 핸들러(resolveIdentityChallenge) /
 * 템플릿 launcher / 외부 IDV provider 플러그인이 공유합니다.
 *
 * @since engine-v1.46.0
 */

/**
 * 백엔드가 428 응답의 `verification` 필드로 내려보내는 페이로드.
 *
 * `scope=route`(미들웨어 강제)와 `scope=hook`(이벤트 강제) 모두 동일 형식이며,
 * `return_request` 만 hook 강제에서 null 일 수 있습니다.
 */
export interface VerificationPayload {
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
}

/**
 * 백엔드 428 응답 본문 전체.
 */
export interface IdentityResponse428 {
  success: false;
  error_code: 'identity_verification_required';
  message: string;
  verification: VerificationPayload;
}

/**
 * Launcher / resolveIdentityChallenge 핸들러 / 외부 SDK callback 이
 * 인터셉터에 돌려주는 결과 타입.
 *
 * - `verified` : verify 성공 — `verification_token` 을 `return_request.url` 에 query 로 자동 부착해 재실행.
 * - `pending`  : 비동기 검증 진행 중 (Stripe Identity / 토스인증 push 등 인터페이스 예약).
 *                1차 구현은 `failed` 로 강등 처리. 향후 폴링 루프 도입 시 활용.
 * - `cancelled`: 사용자 취소 — 원 요청 폐기.
 * - `failed`   : verify 실패 — failure_code 별 처리.
 */
export type VerificationResult =
  | { status: 'verified'; token: string; providerData?: Record<string, unknown> }
  | { status: 'pending'; pollUrl: string; pollIntervalMs?: number; expiresAt: string }
  | { status: 'cancelled' }
  | { status: 'failed'; failureCode: string; reason?: string };

/**
 * Launcher 함수 시그니처.
 *
 * 템플릿 부트스트랩에서 `IdentityGuardInterceptor.setLauncher(launcher)` 로 등록합니다.
 * launcher 는 모달 open / 풀페이지 navigate / 외부 SDK 호출 등 UI 진입을 책임지며,
 * 사용자 액션 결과를 `VerificationResult` 로 resolve 합니다.
 */
export type ModalLauncher = (payload: VerificationPayload) => Promise<VerificationResult>;

/**
 * `resolveIdentityChallenge` 핸들러가 받는 params 의 정규화된 형태.
 *
 * 레이아웃 JSON 의 `params.result` 가 짧은 문자열(`'verified' | 'cancelled' | ...`)
 * 이라 핸들러 내부에서 이 타입으로 정리한 뒤 인터셉터의 deferred resolver 에 전달합니다.
 */
export interface ResolveIdentityChallengeParams {
  result: 'verified' | 'cancelled' | 'failed' | 'pending';
  token?: string;
  failureCode?: string;
  reason?: string;
  providerData?: Record<string, unknown>;
  pollUrl?: string;
  pollIntervalMs?: number;
  expiresAt?: string;
}

/**
 * external_redirect 흐름에서 `sessionStorage` 에 보관하는 stash 데이터.
 *
 * key: `IDENTITY_REDIRECT_STASH_KEY` (= `g7.identity.redirectStash`).
 */
export interface IdentityRedirectStash {
  return_url: string;
  payload: VerificationPayload;
  stashed_at: number;
}

/** sessionStorage 키. 코어/템플릿/플러그인 공통. */
export const IDENTITY_REDIRECT_STASH_KEY = 'g7.identity.redirectStash';
