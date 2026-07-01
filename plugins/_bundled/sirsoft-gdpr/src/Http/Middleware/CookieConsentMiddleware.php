<?php

namespace Plugins\Sirsoft\Gdpr\Http\Middleware;

use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional Cookie 동의 게이팅 미들웨어 (Phase 2 단순화 버전)
 *
 * EDPB Guidelines 2/2023 §16 (사전 차단) 충족:
 *  - functional 미동의 시 응답 Set-Cookie 헤더에서 strictly necessary allowlist 외 모든 cookie 제거
 *  - 운영자가 등록한 functional cookie 목록은 사용하지 않음 — GDPR 원칙은
 *    "strictly necessary 외 비-필수는 동의 전 차단" 이므로 등록 표 불필요
 *
 * 파기 cookie (cleared) 는 항상 통과 — EDPB §117 (철회 즉시 파기) 와 본 §16 차단이 충돌하지 않도록.
 *
 * 등록 시점: `GdprServiceProvider::boot()` 에서 Laravel 11+ Kernel::prependMiddlewareToGroup('web'|'api') 호출.
 * Kernel.php:347 `array_search` 중복 방지로 매 요청 호출되어도 1회만 등록됨.
 *
 * @since 1.0.0-beta.1 (Phase 2)
 */
class CookieConsentMiddleware
{
    /**
     * CookieConsentMiddleware 생성자
     *
     * @param  GdprConsentService  $consentService  동의 서비스 (현재 방문자 functional 동의 상태 조회)
     */
    public function __construct(
        private readonly GdprConsentService $consentService,
    ) {}

    /**
     * 요청 처리 — 응답 직전 functional cookie 게이팅.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response  HTTP 응답 (functional cookie 게이팅 적용)
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // 1. functional 동의 여부 조회
        //
        // 자기 자신 (sirsoft-gdpr) 제거 응답 race condition 안전 통과:
        // 본 미들웨어는 라우트 진입 시점에 GdprServiceProvider::boot() 가 web/api 그룹에
        // prepend 한다. 운영자가 본 플러그인을 제거하는 요청이면 컨트롤러 단계에서 autoload
        // 갱신·활성 디렉토리 삭제가 발생하므로, 응답이 본 미들웨어로 돌아올 때 의존 클래스
        // (GdprPolicyVersionService 등) 로딩이 실패할 수 있다. 이 경우 cookie 게이팅을
        // 포기하고 응답을 그대로 통과시킨다 — 운영자의 "제거 성공" 흐름이 500 으로 깨지지
        // 않도록 한다. 그 외 일반 요청에서는 의존 클래스가 정상 로드되므로 동작 변경 없음.
        try {
            $hasConsent = $this->hasFunctionalConsent($request);
        } catch (BindingResolutionException) {
            return $response;
        }

        if ($hasConsent) {
            return $response; // 동의 — 통과
        }

        // 2. functional 미동의: 응답 Set-Cookie 중 strictly necessary allowlist 외 모두 제거
        foreach ($response->headers->getCookies() as $cookie) {
            $name = $cookie->getName();

            // strictly necessary allowlist — 통과 (백엔드 cookie 4종)
            if ($this->isStrictlyNecessary($name)) {
                continue;
            }

            // 파기 cookie (cleared) 는 통과 — §117 (철회 즉시 파기) 와 §16 (사전 차단) 충돌 회피.
            // GdprCookieConsentController 가 발송하는 Max-Age=0 cookie 도 본 분기로 통과 (현재는 미사용이나 안전 보장).
            if ($cookie->isCleared()) {
                continue;
            }

            // 응답에서 Set-Cookie 제거 (EDPB §16 — 동의 전 신규 저장 금지)
            $response->headers->removeCookie($name, $cookie->getPath(), $cookie->getDomain());
        }

        return $response;
    }

    /**
     * 현재 방문자 (회원/게스트) 의 functional 동의 상태를 반환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return bool  functional 동의 시 true
     */
    private function hasFunctionalConsent(Request $request): bool
    {
        $userId = $request->user()?->id;
        $sessionId = $this->resolveGuestSessionId($request);

        $consents = $this->consentService->getCurrentCookieConsents($userId, $sessionId);

        return ($consents['functional'] ?? false) === true;
    }

    /**
     * 게스트 세션 식별자를 추출합니다 (gdpr_session cookie 또는 Laravel session ID).
     *
     * @param  Request  $request  HTTP 요청
     * @return string|null  세션 식별자 (회원이거나 식별 불가 시 null)
     */
    private function resolveGuestSessionId(Request $request): ?string
    {
        if ($request->user()) {
            return null;
        }

        $cookieValue = $request->cookie('gdpr_session');
        if (is_string($cookieValue) && $cookieValue !== '') {
            return substr($cookieValue, 0, 100);
        }

        try {
            return $request->hasSession() ? substr($request->session()->getId(), 0, 100) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Strictly Necessary cookie 인지 판정합니다.
     *
     * ePrivacy Art.5(3) 면제 항목 — XSRF/세션/유지보수/GDPR 동의 관리 cookie 는 게이팅 대상 외.
     *
     * @param  string  $name  cookie 이름
     * @return bool  strictly necessary 시 true
     */
    private function isStrictlyNecessary(string $name): bool
    {
        return in_array($name, [
            'XSRF-TOKEN',
            (string) config('session.cookie', 'laravel_session'),
            'laravel_maintenance',
            'gdpr_session',
        ], true);
    }
}
