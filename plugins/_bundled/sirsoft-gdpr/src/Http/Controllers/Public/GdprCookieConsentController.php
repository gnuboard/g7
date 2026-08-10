<?php

namespace Plugins\Sirsoft\Gdpr\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Gdpr\Concerns\IssuesGuestSessionCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Gdpr\Http\Requests\CookieConsentStatusRequest;
use Plugins\Sirsoft\Gdpr\Http\Requests\StoreCookieConsentRequest;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;

/**
 * GDPR 공개 쿠키 동의 컨트롤러
 *
 * - POST /api/plugins/sirsoft-gdpr/consent/cookie         : 게스트/회원 쿠키 동의 저장
 * - GET  /api/plugins/sirsoft-gdpr/consent/cookie/status  : 게스트/회원 쿠키 동의 현황 조회
 *
 * 인증 없이 접근 가능. 회원이 sanctum 토큰으로 호출하면 user_id 기반으로 저장.
 */
class GdprCookieConsentController extends PublicBaseController
{
    use IssuesGuestSessionCookie;

    /**
     * GdprCookieConsentController 생성자
     *
     * @param  GdprConsentService  $consentService  GDPR 동의 서비스
     */
    public function __construct(
        private readonly GdprConsentService $consentService,
    ) {
        parent::__construct();
    }

    /**
     * 게스트/회원 쿠키 동의를 저장합니다.
     *
     * 회원이면 user_id 기반 status upsert + history INSERT,
     * 게스트면 session_id 기반 history INSERT.
     *
     * @param  StoreCookieConsentRequest  $request  검증된 요청
     * @return JsonResponse
     */
    public function store(StoreCookieConsentRequest $request): JsonResponse
    {
        $consents = (array) $request->validated('consents');
        $source = (string) $request->validated('source');
        // 명시적 거부 신호 (이슈 #430) — 'reject' 이면 선택형 미동의 항목을 is_rejected=true 로 저장.
        $isRejection = $request->validated('intent') === 'reject';

        $userId = $request->user()?->id;
        $sessionId = null;
        $issuedSessionCookie = null;
        if ($userId === null) {
            $sessionId = $this->resolveGuestSessionId($request);
            if ($sessionId === null) {
                $sessionId = $this->issueGuestSessionId();
                $issuedSessionCookie = $sessionId;
            }
        }

        $this->consentService->updateConsents($userId, $sessionId, $consents, $source, $isRejection);

        // 거부(intent=reject)와 일반 동의 저장의 응답 메시지를 구분 (이슈 #430) —
        // 거부인데 "동의가 저장되었습니다" 로 응답하던 오표기 정정. 프론트 토스트도 동일 키 사용.
        $messageKey = $isRejection
            ? 'sirsoft-gdpr::messages.consent.rejected_saved'
            : 'sirsoft-gdpr::messages.consent.granted';

        $response = ResponseHelper::success($messageKey, [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'consents' => $consents,
        ]);

        if ($issuedSessionCookie !== null) {
            $this->attachGuestSessionCookie($response, $issuedSessionCookie);
        }

        // Phase 2 단순화: 동의 철회 시 cookie 파기는 클라이언트 functionalCleaner 가 단독 담당.
        // 서버는 후속 응답에서 CookieConsentMiddleware 가 strictly necessary allowlist 외
        // 모든 Set-Cookie 를 제거 (EDPB §16) — 본 컨트롤러는 cookie 추가 발송 안 함.

        return $response;
    }

    /**
     * 현재 방문자(회원/게스트)의 쿠키 동의 상태를 반환합니다.
     *
     * 배너 표시 여부 결정용. has_consented = 현재 정책 버전으로 동의 완료 여부.
     * 회원이 sanctum 토큰으로 호출하면 user_id 기반, 미인증이면 session_id 기반.
     *
     * @param  CookieConsentStatusRequest  $request  요청
     * @return JsonResponse
     */
    public function status(CookieConsentStatusRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $sessionId = $userId === null ? $this->resolveGuestSessionId($request) : null;

        $hasConsented = $this->consentService->hasCurrentCookieConsent($userId, $sessionId);
        $consents = $this->consentService->getCurrentCookieConsents($userId, $sessionId);
        $currentPolicyVersion = $this->consentService->getCurrentPolicyVersion();

        // needs_renewal: 옛 동의가 1건 이상 존재하는데 현재 정책 버전 미동의 (즉 옛 동의가 신정책에
        // 그대로 쓰이고 있는 상태). 신규 게스트 (동의 row 0건) 는 has_consented=false 지만
        // needs_renewal=false (재확인할 옛 동의 자체가 없음 — 일반 배너만 노출).
        // GDPR Art.6 보수적 차단 트리거 + A-6 배너 사유 안내 트리거 용도.
        $needsRenewal = ! $hasConsented
            && $this->consentService->hasAnyConsentHistory($userId, $sessionId);

        return ResponseHelper::success('common.success', [
            'has_consented' => $hasConsented,
            'consents' => $consents,
            'needs_renewal' => $needsRenewal,
            'current_policy_version' => $currentPolicyVersion,
            // 회원/게스트 식별 SSoT — 배너 layout 이 회원 한정 keep_consent 버튼 분기에 사용.
            // optional.sanctum 미들웨어 통과 후 $request->user() 가 토큰 보유 회원이면 인스턴스 반환, 게스트면 null.
            'is_member' => $userId !== null,
        ]);
    }

    /**
     * 게스트 세션 ID를 결정합니다.
     *
     * 클라이언트가 쿠키로 전달한 session_id는 서명을 검증한 뒤 신뢰합니다
     * (위조된 값은 미식별 게스트로 취급). 쿠키가 없으면 Laravel session ID를
     * fallback으로 사용합니다.
     *
     * @param  Request  $request  요청
     * @return string|null
     */
    private function resolveGuestSessionId($request): ?string
    {
        $verified = $this->verifyGuestSessionId($request->cookie('gdpr_session'));
        if ($verified !== null) {
            return $verified;
        }

        try {
            $sessionId = $request->hasSession() ? $request->session()->getId() : null;
        } catch (\Throwable) {
            $sessionId = null;
        }

        return $sessionId !== null ? substr($sessionId, 0, 100) : null;
    }

    /**
     * 신규 게스트 세션 ID 를 발급합니다 (UUID v4 기반).
     *
     * @return string
     */
    private function issueGuestSessionId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * 응답에 게스트 세션 쿠키 (gdpr_session) 를 첨부합니다.
     *
     * 1년 유효, path=/, SameSite=Lax. HTTPS 환경에서는 Secure 자동 적용.
     * 쿠키 값은 HMAC 서명이 붙어 위변조 시 서버가 거부합니다.
     *
     * @param  JsonResponse  $response  응답
     * @param  string  $sessionId  발급된 세션 ID
     * @return void
     */
    private function attachGuestSessionCookie(JsonResponse $response, string $sessionId): void
    {
        $response->cookie(
            'gdpr_session',
            $this->signGuestSessionId($sessionId),
            60 * 24 * 365,
            '/',
            null,
            request()->isSecure(),
            true,
            false,
            'lax'
        );
    }
}
