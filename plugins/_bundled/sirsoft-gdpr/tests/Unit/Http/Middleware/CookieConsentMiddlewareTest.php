<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Http\Middleware;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Plugins\Sirsoft\Gdpr\Http\Middleware\CookieConsentMiddleware;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * CookieConsentMiddleware Test (Phase 2 단순화)
 *
 * EDPB Guidelines 2/2023 §16 (사전 차단) 검증:
 *  - functional 미동의 시 응답의 strictly necessary allowlist 외 모든 cookie 가 제거되어야 함
 *  - functional 동의 시 모든 cookie 통과
 *  - strictly necessary (XSRF-TOKEN / laravel_session / laravel_maintenance / gdpr_session) 는 항상 통과
 *  - 파기 cookie (cleared) 는 항상 통과 (§117 충돌 회피)
 *
 * Phase 2 등록 표 (functional_cookies 설정) 제거에 따라 PluginSettingsService 의존성 없음.
 */
class CookieConsentMiddlewareTest extends PluginTestCase
{
    /**
     * functional 미동의 + 임의 cookie (allowlist 외) → 모두 제거되어야 함.
     */
    public function test_removes_all_non_allowlist_cookies_when_not_consented(): void
    {
        $middleware = $this->buildMiddleware(functionalConsent: false);
        $response = $this->runMiddleware($middleware, function (HttpResponse $r) {
            $r->headers->setCookie(Cookie::create('app_pref', 'value', 0, '/'));
            $r->headers->setCookie(Cookie::create('_ga', 'tracker', 0, '/'));
            $r->headers->setCookie(Cookie::create('random_cookie', 'v', 0, '/'));
        });

        $names = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());
        $this->assertNotContains('app_pref', $names);
        $this->assertNotContains('_ga', $names);
        $this->assertNotContains('random_cookie', $names);
    }

    /**
     * functional 동의 → 모든 cookie 통과 (allowlist 외도 통과).
     */
    public function test_passes_all_cookies_when_consented(): void
    {
        $middleware = $this->buildMiddleware(functionalConsent: true);
        $response = $this->runMiddleware($middleware, function (HttpResponse $r) {
            $r->headers->setCookie(Cookie::create('app_pref', 'value', 0, '/'));
            $r->headers->setCookie(Cookie::create('random_cookie', 'v', 0, '/'));
        });

        $names = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());
        $this->assertContains('app_pref', $names);
        $this->assertContains('random_cookie', $names);
    }

    /**
     * strictly necessary cookie 는 미동의여도 통과.
     */
    public function test_preserves_strictly_necessary_cookies_even_without_consent(): void
    {
        $sessionCookieName = (string) config('session.cookie', 'laravel_session');

        $middleware = $this->buildMiddleware(functionalConsent: false);
        $response = $this->runMiddleware($middleware, function (HttpResponse $r) use ($sessionCookieName) {
            $r->headers->setCookie(Cookie::create('XSRF-TOKEN', 'token', 0, '/'));
            $r->headers->setCookie(Cookie::create($sessionCookieName, 'sess', 0, '/'));
            $r->headers->setCookie(Cookie::create('gdpr_session', 'gdpr', 0, '/'));
            $r->headers->setCookie(Cookie::create('laravel_maintenance', 'mnt', 0, '/'));
        });

        $names = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());
        $this->assertContains('XSRF-TOKEN', $names);
        $this->assertContains($sessionCookieName, $names);
        $this->assertContains('gdpr_session', $names);
        $this->assertContains('laravel_maintenance', $names);
    }

    /**
     * 파기 cookie (cleared — expires 과거) 는 미동의여도 통과.
     *
     * EDPB §117 (철회 즉시 파기) 와 §16 (사전 차단) 가 충돌하지 않도록.
     * 운영자가 응답에서 cookie 를 파기하려는 의도는 cookie 자체가 cleared 인 경우 보호.
     */
    public function test_passes_cleared_cookies_even_without_consent(): void
    {
        $middleware = $this->buildMiddleware(functionalConsent: false);
        $response = $this->runMiddleware($middleware, function (HttpResponse $r) {
            // expires=1 (1970-01-01 00:00:01) — Symfony Cookie::isCleared 가 true 반환
            $r->headers->setCookie(Cookie::create('app_pref')->withValue('')->withExpires(1)->withPath('/'));
        });

        $names = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());
        $this->assertContains('app_pref', $names, 'cleared cookie 는 미동의여도 통과 (§117 충돌 회피)');
    }

    /**
     * 자기 자신 (sirsoft-gdpr 플러그인) 제거 응답 race condition 회귀 테스트.
     *
     * 시나리오:
     *  - 운영자가 admin UI 에서 sirsoft-gdpr 플러그인 제거 요청 → 라우트 진입 시점에
     *    GdprServiceProvider::boot() 가 web/api 그룹에 CookieConsentMiddleware 를 prepend
     *  - 컨트롤러가 PluginManager::uninstallPlugin() 실행 → autoload 갱신 + 활성 디렉토리
     *    삭제 → Plugins\Sirsoft\Gdpr\Services\GdprPolicyVersionService 매핑 소실
     *  - 응답이 미들웨어 스택을 빠져나오며 CookieConsentMiddleware::handle() post-next
     *    실행 → getCurrentCookieConsents() → lazy app(GdprPolicyVersionService::class) →
     *    BindingResolutionException
     *
     * 기대 동작: 의존 클래스 로드 실패 시 cookie 게이팅을 포기하고 응답을 그대로 통과시킨다.
     * 운영자 입장에서는 제거가 성공적으로 끝났으므로 500 이 아닌 정상 응답이 와야 한다.
     */
    public function test_passes_response_when_dependency_class_missing_after_self_uninstall(): void
    {
        $consentService = $this->createMock(GdprConsentService::class);
        $consentService->method('getCurrentCookieConsents')
            ->willThrowException(new BindingResolutionException(
                'Target class [Plugins\\Sirsoft\\Gdpr\\Services\\GdprPolicyVersionService] does not exist.'
            ));

        $middleware = new CookieConsentMiddleware($consentService);

        $request = Request::create('/');
        $response = $middleware->handle($request, function () {
            $response = new HttpResponse('ok');
            $response->headers->setCookie(Cookie::create('app_pref', 'v', 0, '/'));
            $response->headers->setCookie(Cookie::create('_ga', 'tracker', 0, '/'));

            return $response;
        });

        $this->assertSame('ok', $response->getContent(), '의존 클래스 로드 실패 시 응답 본문은 그대로 통과한다');

        $names = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());
        $this->assertContains('app_pref', $names, '의존 클래스 로드 실패 시 cookie 게이팅을 적용하지 않는다 (안전 통과)');
        $this->assertContains('_ga', $names);
    }

    /**
     * functional 동의 + strictly necessary + 일반 cookie 동시 응답 → 모두 통과.
     */
    public function test_full_pass_under_consent(): void
    {
        $middleware = $this->buildMiddleware(functionalConsent: true);
        $response = $this->runMiddleware($middleware, function (HttpResponse $r) {
            $r->headers->setCookie(Cookie::create('app_pref', 'value', 0, '/'));
            $r->headers->setCookie(Cookie::create('XSRF-TOKEN', 'token', 0, '/'));
        });

        $names = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());
        $this->assertContains('app_pref', $names);
        $this->assertContains('XSRF-TOKEN', $names);
    }

    /**
     * 미들웨어 인스턴스 생성 — GdprConsentService mock 만 주입 (Phase 2 단순화로 PluginSettingsService 의존성 제거).
     *
     * @param  bool  $functionalConsent  functional 동의 여부
     * @return CookieConsentMiddleware
     */
    private function buildMiddleware(bool $functionalConsent): CookieConsentMiddleware
    {
        $consentService = $this->createMock(GdprConsentService::class);
        $consentService->method('getCurrentCookieConsents')
            ->willReturn(['functional' => $functionalConsent]);

        return new CookieConsentMiddleware($consentService);
    }

    /**
     * 미들웨어 실행 — 응답 cookie 를 추가하는 콜백 전달.
     *
     * @param  CookieConsentMiddleware  $middleware
     * @param  callable  $cookieSetter  HttpResponse 에 cookie 를 추가하는 콜백
     * @return HttpResponse
     */
    private function runMiddleware(CookieConsentMiddleware $middleware, callable $cookieSetter): HttpResponse
    {
        $request = Request::create('/');

        return $middleware->handle($request, function () use ($cookieSetter) {
            $response = new HttpResponse('ok');
            $cookieSetter($response);

            return $response;
        });
    }
}
