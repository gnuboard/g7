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
 *  - functional 미동의 시 응답의 strictly necessary 허용목록 외 모든 cookie 가 제거되어야 함
 *  - functional 동의 시 모든 cookie 통과
 *  - 잠금 항목 (XSRF-TOKEN / 세션 쿠키 / gdpr_session) 은 **설정이 비어도** 항상 통과
 *  - 운영자 설정에 등재한 cookie 는 통과하고, 뺀 cookie 는 제거된다 (설정이 판정에 쓰인다는 증거)
 *  - 와일드카드(`name_*`)가 저장소 목록과 동일하게 동작한다
 *  - 파기 cookie (cleared) 는 항상 통과 (§117 충돌 회피)
 *
 * 허용목록이 운영자 설정으로 옮겨졌으므로 판정 목록은 config 미러(`g7_settings.plugins`)에서
 * 온다. 그래서 이 테스트는 미러를 직접 세워 "설정대로 갈리는가" 를 본다 — 하드코딩 배열을
 * 검증하던 시절에는 설정을 바꿔도 판정이 안 바뀌는 회귀를 잡을 수 없었다.
 *
 * @scenario scope=cookie, notation=wildcard, locked=locked_item, settings_state=empty, request=valid_item
 *
 * @effects middleware_passes_cookie_listed_in_operator_settings, middleware_removes_cookie_absent_from_settings, middleware_supports_wildcard_in_cookie_allowlist, middleware_locked_cookies_survive_empty_settings, middleware_locked_cookies_cannot_be_removed_via_settings, middleware_session_cookie_name_resolved_at_runtime
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

        // laravel_maintenance 는 잠금 항목이 아니라 출하 카탈로그 항목이다 — 운영자 설정에서 온다.
        $this->setAllowlist(['laravel_maintenance']);

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
     * 운영자가 설정에 등재한 cookie 는 미동의여도 통과한다.
     *
     * 판정 목록이 설정에서 온다는 증거 — 하드코딩 배열이면 이 이름은 통과할 수 없다.
     */
    public function test_passes_cookie_listed_in_operator_settings(): void
    {
        $this->setAllowlist(['operator_added_cookie']);

        $middleware = $this->buildMiddleware(functionalConsent: false);
        $response = $this->runMiddleware($middleware, function (HttpResponse $r) {
            $r->headers->setCookie(Cookie::create('operator_added_cookie', 'v', 0, '/'));
        });

        $names = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());
        $this->assertContains('operator_added_cookie', $names);
    }

    /**
     * 설정에서 뺀 cookie 는 제거된다 (위 테스트의 대조군).
     *
     * 이 축이 없으면 "목록이 통째로 열려 있어도" 위 테스트는 통과한다.
     */
    public function test_removes_cookie_absent_from_operator_settings(): void
    {
        $this->setAllowlist([]);

        $middleware = $this->buildMiddleware(functionalConsent: false);
        $response = $this->runMiddleware($middleware, function (HttpResponse $r) {
            $r->headers->setCookie(Cookie::create('operator_added_cookie', 'v', 0, '/'));
        });

        $names = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());
        $this->assertNotContains('operator_added_cookie', $names);
    }

    /**
     * 쿠키 목록도 와일드카드를 지원한다 (발견 ② 회귀 차단).
     *
     * 저장소 목록만 접두사 매칭을 지원하던 시절, 운영자가 쿠키 카드에 적은 `myplugin_*` 은
     * 아무 효과가 없었고 그 사실이 화면에 드러나지 않았다.
     */
    public function test_supports_wildcard_in_cookie_allowlist(): void
    {
        $this->setAllowlist(['myplugin_*']);

        $middleware = $this->buildMiddleware(functionalConsent: false);
        $response = $this->runMiddleware($middleware, function (HttpResponse $r) {
            $r->headers->setCookie(Cookie::create('myplugin_state', 'v', 0, '/'));
            $r->headers->setCookie(Cookie::create('other_myplugin_state', 'v', 0, '/'));
        });

        $names = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());
        $this->assertContains('myplugin_state', $names, '접두사가 같으면 통과');
        $this->assertNotContains('other_myplugin_state', $names, '접두사가 다르면 차단 — 와일드카드는 앞부분만 매칭한다');
    }

    /**
     * 설정이 비어 있어도 잠금 4항목은 통과한다.
     *
     * 잠금 항목은 설정이 아니라 코드가 정한다 — 설정 조회가 실패하거나 운영자가 목록을
     * 통째로 비워도 사이트가 서야 한다.
     */
    public function test_locked_cookies_survive_empty_settings(): void
    {
        $sessionCookieName = (string) config('session.cookie', 'laravel_session');

        $this->setAllowlist([]);

        $middleware = $this->buildMiddleware(functionalConsent: false);
        $response = $this->runMiddleware($middleware, function (HttpResponse $r) use ($sessionCookieName) {
            $r->headers->setCookie(Cookie::create('XSRF-TOKEN', 'token', 0, '/'));
            $r->headers->setCookie(Cookie::create($sessionCookieName, 'sess', 0, '/'));
            $r->headers->setCookie(Cookie::create('gdpr_session', 'gdpr', 0, '/'));
        });

        $names = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());
        $this->assertContains('XSRF-TOKEN', $names);
        $this->assertContains($sessionCookieName, $names);
        $this->assertContains('gdpr_session', $names);
    }

    /**
     * 운영자가 잠금 항목을 목록에서 지워도 판정에서는 사라지지 않는다.
     *
     * 잠금은 '설정 밖 합집합' 이므로 저장 요청으로 무력화할 수 없어야 한다.
     */
    public function test_locked_cookies_cannot_be_removed_via_settings(): void
    {
        // 운영자 목록에 잠금 항목이 없는 상태 (API 로 지운 것과 동일한 상태)
        $this->setAllowlist(['laravel_maintenance']);

        $middleware = $this->buildMiddleware(functionalConsent: false);
        $response = $this->runMiddleware($middleware, function (HttpResponse $r) {
            $r->headers->setCookie(Cookie::create('XSRF-TOKEN', 'token', 0, '/'));
            $r->headers->setCookie(Cookie::create('gdpr_session', 'gdpr', 0, '/'));
        });

        $names = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());
        $this->assertContains('XSRF-TOKEN', $names);
        $this->assertContains('gdpr_session', $names);
    }

    /**
     * 세션 쿠키 이름은 런타임 설정에서 해석된다.
     *
     * 서버는 `config('session.cookie')` 를 읽는데 클라이언트는 'laravel_session' 을
     * 하드코딩하고 있었다 — `SESSION_COOKIE` 를 지정한 사이트에서는 클라이언트 목록의 그
     * 항목이 죽어 있었고, 두 목록을 대조하는 테스트가 없었다 (발견 ①).
     */
    public function test_session_cookie_name_is_resolved_at_runtime(): void
    {
        config(['session.cookie' => 'g7-session']);
        $this->setAllowlist([]);

        $middleware = $this->buildMiddleware(functionalConsent: false);
        $response = $this->runMiddleware($middleware, function (HttpResponse $r) {
            $r->headers->setCookie(Cookie::create('g7-session', 'sess', 0, '/'));
            $r->headers->setCookie(Cookie::create('laravel_session', 'sess', 0, '/'));
        });

        $names = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());
        $this->assertContains('g7-session', $names, '설정된 세션 쿠키 이름이 통과해야 한다');
        $this->assertNotContains(
            'laravel_session',
            $names,
            '기본 이름이 하드코딩되어 있으면 설정과 무관하게 통과해 이 단언이 깨진다'
        );
    }

    /**
     * 쿠키 허용목록 config 미러를 세웁니다.
     *
     * 운영에서는 `ExtensionSettingsMirror` 가 `PluginSettingsService` 를 경유해 채우는 값이다.
     *
     * @param  array<int, string>  $cookies  운영자 설정의 cookie 스코프 목록
     * @return void
     */
    private function setAllowlist(array $cookies): void
    {
        config([
            'g7_settings.plugins.sirsoft-gdpr.necessary_storage_allowlist' => [
                'localStorage' => [],
                'sessionStorage' => [],
                'cookie' => $cookies,
            ],
        ]);
    }

    /**
     * 미들웨어 인스턴스 생성 — GdprConsentService mock 만 주입.
     *
     * 허용목록은 config 미러에서 오므로 생성자 의존성이 아니다 (setAllowlist 로 세운다).
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
