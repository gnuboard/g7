<?php

namespace Plugins\Sirsoft\Gdpr\Http\Middleware;

use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Gdpr\Concerns\IssuesGuestSessionCookie;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;
use Plugins\Sirsoft\Gdpr\Support\NecessaryAllowlist;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional Cookie 동의 게이팅 미들웨어 (Phase 2 단순화 버전)
 *
 * EDPB Guidelines 2/2023 §16 (사전 차단) 충족:
 *  - functional 미동의 시 응답 Set-Cookie 헤더에서 strictly necessary 허용목록 외 모든 cookie 제거
 *  - 운영자가 등록한 "허용" functional cookie 목록은 사용하지 않음 — GDPR 원칙은
 *    "strictly necessary 외 비-필수는 동의 전 차단" 이므로 등록 표 불필요
 *
 * strictly necessary 판정 목록은 운영자 설정(`necessary_storage_allowlist.cookie`)과 잠금
 * 집합의 합집합이며, 클라이언트 인터셉터와 같은 출처·같은 매칭 규칙을 씁니다
 * (`Support\NecessaryAllowlist`). 서버와 클라이언트가 각자 목록을 들고 있으면 한쪽에서만
 * 살아 있는 항목이 생기는데, 그 어긋남은 예외도 로그도 남기지 않습니다.
 *
 * 파기 cookie (cleared) 는 항상 통과 — EDPB §117 (철회 즉시 파기) 와 본 §16 차단이 충돌하지 않도록.
 *
 * 등록 시점: `plugin.php::getMiddleware()` 선언 (web·api 그룹, `before_core`) — 코어가 그 선언을
 * 읽어 부착합니다. 확장이 Kernel 미들웨어 그룹을 직접 조작하지 않습니다.
 *
 * @since 1.0.0-beta.1 (Phase 2)
 */
class CookieConsentMiddleware
{
    use IssuesGuestSessionCookie;

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
     * @return Response HTTP 응답 (functional cookie 게이팅 적용)
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // 1. functional 동의 여부 조회
        //
        // 자기 자신 (sirsoft-gdpr) 제거 응답 race condition 안전 통과:
        // 본 미들웨어는 plugin.php::getMiddleware() 선언에 따라 web/api 그룹에 부착된다.
        // 운영자가 본 플러그인을 제거하는 요청이면 컨트롤러 단계에서 autoload
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

        // 2. functional 미동의: 응답 Set-Cookie 중 strictly necessary 허용목록 외 모두 제거
        foreach ($response->headers->getCookies() as $cookie) {
            $name = $cookie->getName();

            // strictly necessary 허용목록 — 통과 (운영자 설정 ∪ 잠금 집합)
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
     * @return bool functional 동의 시 true
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
     * 쿠키 값은 서명을 검증한 뒤 신뢰합니다 — 위조된 값은 미식별 게스트로
     * 취급하고 Laravel session ID로 폴백합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string|null 세션 식별자 (회원이거나 식별 불가 시 null)
     */
    private function resolveGuestSessionId(Request $request): ?string
    {
        if ($request->user()) {
            return null;
        }

        $verified = $this->verifyGuestSessionId($request->cookie('gdpr_session'));
        if ($verified !== null) {
            return $verified;
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
     * ePrivacy Art.5(3) 면제 항목 — 운영자가 관리자 화면에서 관리하는 목록과, 지울 수 없는
     * 잠금 집합(XSRF / 세션 / GDPR 동의 관리 cookie)의 합집합으로 판정합니다. 앞부분 매칭
     * (`name_*`) 도 저장소 목록과 동일하게 적용됩니다.
     *
     * @param  string  $name  cookie 이름
     * @return bool strictly necessary 시 true
     */
    private function isStrictlyNecessary(string $name): bool
    {
        return NecessaryAllowlist::matches($name, 'cookie');
    }
}
