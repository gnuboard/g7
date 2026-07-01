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
    public function test_selfExtension_returns_null_for_core_tests(): void
    {
        // 본 테스트 클래스는 tests/Feature/ 하위 (코어 테스트) →
        // selfExtension() 의 modules/plugins 경로 패턴에 매칭되지 않아 null 반환
        $this->assertNull($this->selfExtension());
    }
}
