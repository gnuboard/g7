<?php

namespace Tests\Feature\Extension;

use App\Extension\Testing\ExtensionTestAllowlist;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Sirsoft\Gdpr\Http\Middleware\CookieConsentMiddleware;
use Plugins\Sirsoft\Gdpr\Providers\GdprServiceProvider;
use Tests\TestCase;

/**
 * 테스트 환경 확장 격리 회귀 테스트
 *
 * requiredExtensions allowlist 가 ServiceProvider / route / middleware 등록
 * 범위를 통제하는지 검증합니다.
 *
 * - core-only 테스트(requiredExtensions 미선언): GDPR 플러그인 미로드
 * - requiredExtensions = ['plugins/sirsoft-gdpr']: GDPR 플러그인 로드
 *
 * 본 클래스 자체는 requiredExtensions 를 선언하지 않으므로 core-only 시나리오다.
 */
class ExtensionTestIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @effects core_only_test_does_not_register_gdpr_service_provider
     */
    public function test_core_only_test_does_not_register_gdpr_service_provider(): void
    {
        // allowlist 가 활성(testing + 명시적 set)이어야 격리가 동작
        $this->assertTrue(ExtensionTestAllowlist::isActive());
        $this->assertFalse(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-gdpr'));

        $this->assertFalse(
            $this->app->providerIsLoaded(GdprServiceProvider::class),
            'core-only 테스트에서 GdprServiceProvider 가 등록되었습니다 — 격리 실패'
        );
    }

    /**
     * @effects core_only_test_does_not_register_gdpr_middleware_in_web_group, core_only_test_does_not_register_gdpr_middleware_in_api_group
     */
    public function test_core_only_test_does_not_register_gdpr_middleware_in_groups(): void
    {
        $kernel = $this->app->make(HttpKernelContract::class);
        $this->assertInstanceOf(HttpKernel::class, $kernel);

        $groups = $kernel->getMiddlewareGroups();

        $webGroup = $groups['web'] ?? [];
        $apiGroup = $groups['api'] ?? [];

        $this->assertNotContains(
            CookieConsentMiddleware::class,
            $webGroup,
            'core-only 테스트의 web 미들웨어 그룹에 CookieConsentMiddleware 가 개입했습니다'
        );
        $this->assertNotContains(
            CookieConsentMiddleware::class,
            $apiGroup,
            'core-only 테스트의 api 미들웨어 그룹에 CookieConsentMiddleware 가 개입했습니다'
        );
    }

    /**
     * @effects core_only_test_does_not_register_gdpr_plugin_routes
     */
    public function test_core_only_test_does_not_register_gdpr_plugin_routes(): void
    {
        $routes = collect($this->app['router']->getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->filter(fn ($uri) => str_contains($uri, 'plugins/sirsoft-gdpr'));

        $this->assertCount(
            0,
            $routes,
            'core-only 테스트에 GDPR 플러그인 라우트가 등록되었습니다 — 격리 실패'
        );
    }

    /**
     * 테스트는 운영 라우트 캐시로 부팅하지 않는다.
     *
     * 라우트 캐시가 있으면 `RouteServiceProvider::boot()` 이 캐시 로드로 분기해 **라우트 파일이
     * 실행되지 않는다.** 그러면 allowlist 가 라우트 축에서 통째로 무력화되어, core-only 테스트가
     * 굽던 시점에 활성이던 모든 확장의 라우트를 가진 채 부팅한다.
     *
     * 위의 GDPR 라우트 단언만으로는 이 결함을 안정적으로 잡지 못한다 — GDPR 이 그 머신에
     * 설치돼 있어야만 red 가 되기 때문이다. 여기서는 **메커니즘 자체**를 단언한다.
     *
     * @effects tests_do_not_boot_with_production_route_cache
     */
    public function test_tests_do_not_boot_with_the_production_route_cache(): void
    {
        $cachedRoutesPath = $this->app->getCachedRoutesPath();

        // 경로 구분자를 슬래시로 통일해 판정한다 — Windows 는 역슬래시를 섞어 돌려준다.
        $normalized = strtr($cachedRoutesPath, '\\', '/');

        $this->assertStringNotContainsString(
            'bootstrap/cache',
            $normalized,
            '테스트가 운영 라우트 캐시 경로를 본다 — tests/bootstrap.php 의 APP_ROUTES_CACHE '
            .'리다이렉트가 사라졌다. 라우트 파일이 실행되지 않아 확장 격리가 무력화된다.'
        );

        $this->assertFalse(
            $this->app->routesAreCached(),
            '테스트가 캐시된 라우트로 부팅했다 — 라우트 파일이 실행되지 않아 allowlist 가 적용되지 않는다.'
        );
    }

    /**
     * @effects allowlist_inactive_when_never_configured
     */
    public function test_allowlist_is_inactive_when_never_configured(): void
    {
        // 비-테스트 부팅(또는 set 호출 전)에는 가드가 비활성이어야
        // 운영/개발 환경의 확장 전수 로딩이 보존됨
        ExtensionTestAllowlist::reset();

        $this->assertFalse(ExtensionTestAllowlist::isActive());

        // 테스트 격리 보존을 위해 본 클래스의 core-only allowlist 복원
        ExtensionTestAllowlist::set($this->resolveAllowedExtensions());
    }

    /**
     * @effects selfExtension_returns_null_for_core_tests
     */
    public function test_self_extension_returns_null_for_core_tests(): void
    {
        // 본 테스트 클래스는 tests/Feature/ 하위 (코어 테스트) →
        // selfExtension() 의 modules/plugins 경로 패턴에 매칭되지 않아 null 반환
        $this->assertNull($this->selfExtension());
    }
}
