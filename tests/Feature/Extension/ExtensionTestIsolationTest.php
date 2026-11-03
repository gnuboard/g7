<?php

namespace Tests\Feature\Extension;

use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\ExtensionMiddlewareRegistry;
use App\Extension\PluginManager;
use App\Extension\Testing\ExtensionTestAllowlist;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
     * requiredExtensions 를 선언하지 않은 코어 테스트에서 allowlist 가 켜져 있고 GDPR 플러그인의 ServiceProvider 가 로드되지 않았는지 확인한다.
     *
     * @effects core_only_test_does_not_register_gdpr_service_provider
     *
     * @return void 반환값 없음
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
     * 커널의 web, api 미들웨어 그룹 어디에도 GDPR 쿠키 동의 미들웨어가 끼어들지 않았는지 확인한다.
     *
     * @effects core_only_test_does_not_register_gdpr_middleware_in_web_group, core_only_test_does_not_register_gdpr_middleware_in_api_group
     *
     * @return void 반환값 없음
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
     * 등록된 라우트 URI 중 plugins/sirsoft-gdpr 를 담은 것이 하나도 없는지 확인한다.
     *
     * @effects core_only_test_does_not_register_gdpr_plugin_routes
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     * allowlist 를 초기화하면 가드가 비활성으로 돌아가 운영, 개발 부팅의 확장 전수 로딩이 보존되는지 확인하고, 끝에 이 클래스의 코어 전용 allowlist 를 되돌린다.
     *
     * @effects allowlist_inactive_when_never_configured
     *
     * @return void 반환값 없음
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
     * tests/Feature 아래 코어 테스트에서는 selfExtension() 이 모듈, 플러그인 경로 패턴에 맞지 않아 null 을 돌려주는지 확인한다.
     *
     * @effects selfExtension_returns_null_for_core_tests
     *
     * @return void 반환값 없음
     */
    public function test_self_extension_returns_null_for_core_tests(): void
    {
        // 본 테스트 클래스는 tests/Feature/ 하위 (코어 테스트) →
        // selfExtension() 의 modules/plugins 경로 패턴에 매칭되지 않아 null 반환
        $this->assertNull($this->selfExtension());
    }

    /**
     * 확장 미들웨어 **레지스트리**(self-gate 경로)도 allowlist 를 따른다.
     *
     * 형제 검사는 커널 미들웨어 그룹을 보는데, 확장 미들웨어는 SP Kernel 직접 조작이 아니라
     * `getMiddleware()` 선언 → `ExtensionMiddlewareRegistry` 로 간다. 그래서 그 검사는 더 이상
     * 쓰이지 않는 경로를 재고 있었고, 실제 경로에는 allowlist 가드가 아예 없었다.
     *
     * DB 가 비어 있는 동안에는 드러나지 않는다 — 확장이 설치된 데이터베이스로 코어 테스트를
     * 돌리는 순간 그 확장의 미들웨어가 코어 요청에 끼어들어 의존 바인딩 부재로 500 이 된다
     * (G8 실측 2026-09-02 · 계획서 §9.12.5 F11).
     *
     * @effects core_only_test_does_not_register_gdpr_middleware_in_the_extension_registry
     *
     * @return void 반환값 없음
     */
    public function test_core_only_test_does_not_resolve_gdpr_middleware_from_the_registry(): void
    {
        // 조건을 **이 검사가 직접 만든다.** 비어 있는 데이터베이스에서는 활성 확장이 0이라
        // 가드를 꺼도 레지스트리가 빈 배열을 돌려주고, 그러면 이 검사는 아무것도 재지 않는다
        // (처음 작성했을 때 실제로 그 형태였다 — 되돌려도 초록이었다).
        DB::table('plugins')->updateOrInsert(
            ['identifier' => 'sirsoft-gdpr'],
            [
                'vendor' => 'sirsoft',
                'name' => json_encode(['ko' => 'GDPR', 'en' => 'GDPR']),
                'version' => '1.0.0',
                'status' => ExtensionStatus::Active->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->app->make(PluginManager::class)->loadPlugins();
        ExtensionMiddlewareRegistry::flush();

        $registry = $this->app->make(ExtensionMiddlewareRegistryInterface::class);

        $this->assertNotEmpty(
            $this->app->make(PluginManager::class)->getActivePlugins(),
            '활성 플러그인이 하나도 없으면 이 검사는 공허하게 통과한다.'
        );

        $resolved = [];

        foreach (['web', 'api'] as $group) {
            foreach (['before_core', 'after_core'] as $timing) {
                $resolved = array_merge(
                    $resolved,
                    $registry->resolveForRoute('home', '/', $group, $timing)
                );
            }
        }

        $this->assertNotContains(
            CookieConsentMiddleware::class,
            $resolved,
            'core-only 테스트의 확장 미들웨어 레지스트리가 GDPR 미들웨어를 돌려줬습니다 — '
            .'그 플러그인의 ServiceProvider 는 등록되지 않으므로 그 미들웨어는 해석 시점에 죽는다'
        );
    }
}
