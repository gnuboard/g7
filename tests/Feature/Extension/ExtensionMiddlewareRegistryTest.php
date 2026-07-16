<?php

namespace Tests\Feature\Extension;

use App\Extension\ExtensionMiddlewareRegistry;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Mockery;
use Tests\TestCase;

/**
 * 확장 선언 미들웨어 인덱스 테스트.
 *
 * 활성 확장의 getMiddleware() 선언을 수집해 라우트명·URI 술어로 매칭하는
 * ExtensionMiddlewareRegistry 를 검증합니다 (target 카탈로그 전값 / groups 펼침 /
 * timing 분리 / 비활성 제외 / 검증 거부 / 캐시 무효화).
 */
class ExtensionMiddlewareRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ExtensionMiddlewareRegistry::flush();
    }

    protected function tearDown(): void
    {
        ExtensionMiddlewareRegistry::flush();
        parent::tearDown();
    }

    /**
     * 활성 모듈/플러그인의 미들웨어 선언으로 registry 를 구성하고 새 인스턴스를 반환합니다.
     *
     * @param  array<int, array<string, mixed>>  $moduleMiddleware  모듈 getMiddleware() 반환값
     * @param  array<int, array<string, mixed>>  $pluginMiddleware  플러그인 getMiddleware() 반환값
     * @param  string  $moduleId  모듈 식별자
     * @param  string  $pluginId  플러그인 식별자
     */
    private function makeRegistry(
        array $moduleMiddleware = [],
        array $pluginMiddleware = [],
        string $moduleId = 'sirsoft-shop',
        string $pluginId = 'sirsoft-pay',
    ): ExtensionMiddlewareRegistry {
        // getMiddleware() 는 interface 가 아닌 Abstract 에만 정의되므로(IDV 게터와 동일 컨벤션),
        // registry 는 method_exists 로 게이트한다. interface mock 은 이 메서드를 갖지 않아
        // method_exists=false 로 스킵되므로, getMiddleware/getIdentifier 를 실제로 갖는
        // 경량 픽스처 확장을 사용한다 (실 확장은 Abstract 상속으로 항상 보유).
        $activeModules = [];
        if ($moduleMiddleware !== []) {
            $activeModules[] = new FixtureExtension($moduleId, $moduleMiddleware);
        }

        $activePlugins = [];
        if ($pluginMiddleware !== []) {
            $activePlugins[] = new FixtureExtension($pluginId, $pluginMiddleware);
        }

        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('getActiveModules')->andReturn($activeModules);
        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getActivePlugins')->andReturn($activePlugins);

        $this->app->instance(ModuleManager::class, $moduleManager);
        $this->app->instance(PluginManager::class, $pluginManager);

        ExtensionMiddlewareRegistry::flush();

        return $this->app->make(ExtensionMiddlewareRegistry::class);
    }

    // === self 치환 (모듈·플러그인·web·api prefix) ===

    /**
     * @effects self_target_expands_to_own_extension_prefix_per_group, groups_array_expands_to_per_group_entries
     */
    public function test_self_target_expands_to_own_module_prefix_per_group(): void
    {
        $registry = $this->makeRegistry(moduleMiddleware: [[
            'class' => TestMiddlewareA::class,
            'groups' => ['web', 'api'],
            'targets' => ['self'],
        ]]);

        // web 그룹: web.modules.{id}.* 만 매칭
        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('web.modules.sirsoft-shop.orders.index', 'x', 'web', 'after_core'),
        );
        // api 그룹: api.modules.{id}.* 만 매칭
        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('api.modules.sirsoft-shop.orders.index', 'x', 'api', 'after_core'),
        );
        // web self 는 api 라우트를 잡지 않음
        $this->assertSame(
            [],
            $registry->resolveForRoute('api.modules.sirsoft-shop.orders.index', 'x', 'web', 'after_core'),
        );
        // 타 확장 라우트 miss
        $this->assertSame(
            [],
            $registry->resolveForRoute('web.modules.other.orders.index', 'x', 'web', 'after_core'),
        );
    }

    /**
     * @effects self_target_expands_to_own_extension_prefix_per_group
     */
    public function test_self_target_expands_to_own_plugin_prefix(): void
    {
        $registry = $this->makeRegistry(pluginMiddleware: [[
            'class' => TestMiddlewareA::class,
            'groups' => ['web'],
            'targets' => ['self'],
        ]]);

        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('web.plugins.sirsoft-pay.payment.callback', 'x', 'web', 'after_core'),
        );
        $this->assertSame(
            [],
            $registry->resolveForRoute('web.modules.sirsoft-pay.payment.callback', 'x', 'web', 'after_core'),
        );
    }

    // === all_extensions / core (positive/negative 판별) ===

    /**
     * @effects all_extensions_matches_extension_routes_only
     */
    public function test_all_extensions_matches_extension_routes_only(): void
    {
        $registry = $this->makeRegistry(moduleMiddleware: [[
            'class' => TestMiddlewareA::class,
            'groups' => ['api'],
            'targets' => ['all_extensions'],
        ]]);

        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('api.modules.sirsoft-shop.orders.index', 'x', 'api', 'after_core'),
        );
        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('api.plugins.other.foo', 'x', 'api', 'after_core'),
        );
        // 코어 라우트 miss
        $this->assertSame(
            [],
            $registry->resolveForRoute('api.users.index', 'x', 'api', 'after_core'),
        );
    }

    /**
     * @effects core_target_matches_core_routes_only_negative_predicate
     */
    public function test_core_target_matches_core_routes_only(): void
    {
        $registry = $this->makeRegistry(moduleMiddleware: [[
            'class' => TestMiddlewareA::class,
            'groups' => ['api'],
            'targets' => ['core'],
        ]]);

        // 코어 라우트 hit (negative 판별)
        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('api.users.index', 'x', 'api', 'after_core'),
        );
        // 확장 라우트 miss
        $this->assertSame(
            [],
            $registry->resolveForRoute('api.modules.sirsoft-shop.orders.index', 'x', 'api', 'after_core'),
        );
        // 무명 라우트는 core 로 간주하지 않음 (URI 로 잡을 것)
        $this->assertSame(
            [],
            $registry->resolveForRoute('', 'admin/foo', 'api', 'after_core'),
        );
    }

    // === everything / * (무조건, 무명 포함) ===

    /**
     * @effects everything_and_star_match_all_routes_including_unnamed
     */
    public function test_everything_matches_all_routes_including_unnamed(): void
    {
        $registry = $this->makeRegistry(pluginMiddleware: [[
            'class' => TestMiddlewareA::class,
            'groups' => ['web', 'api'],
            'targets' => ['everything'],
        ]]);

        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('api.users.index', 'x', 'api', 'after_core'),
        );
        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('web.modules.other.foo', 'x', 'web', 'after_core'),
        );
        // 무명 라우트도 hit
        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('', '/', 'web', 'after_core'),
        );
    }

    /**
     * @effects everything_and_star_match_all_routes_including_unnamed
     */
    public function test_star_alias_matches_everything(): void
    {
        $registry = $this->makeRegistry(pluginMiddleware: [[
            'class' => TestMiddlewareA::class,
            'groups' => ['web'],
            'targets' => ['*'],
        ]]);

        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('', 'anything', 'web', 'after_core'),
        );
    }

    // === module:{id} / plugin:{id} ===

    /**
     * @effects module_id_and_plugin_id_target_scope_to_that_extension
     */
    public function test_module_id_target_matches_that_module_only(): void
    {
        $registry = $this->makeRegistry(pluginMiddleware: [[
            'class' => TestMiddlewareA::class,
            'groups' => ['api'],
            'targets' => ['module:sirsoft-shop'],
        ]]);

        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('api.modules.sirsoft-shop.orders.index', 'x', 'api', 'after_core'),
        );
        $this->assertSame(
            [],
            $registry->resolveForRoute('api.modules.sirsoft-board.posts.index', 'x', 'api', 'after_core'),
        );
    }

    /**
     * @effects module_id_and_plugin_id_target_scope_to_that_extension
     */
    public function test_plugin_id_target_matches_that_plugin_only(): void
    {
        $registry = $this->makeRegistry(moduleMiddleware: [[
            'class' => TestMiddlewareA::class,
            'groups' => ['web'],
            'targets' => ['plugin:sirsoft-pay'],
        ]]);

        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('web.plugins.sirsoft-pay.payment.callback', 'x', 'web', 'after_core'),
        );
        $this->assertSame(
            [],
            $registry->resolveForRoute('web.plugins.other.foo', 'x', 'web', 'after_core'),
        );
    }

    // === 원시 glob / brace ===

    /**
     * @effects raw_glob_target_matches_route_name
     */
    public function test_raw_glob_target_matches_route_name(): void
    {
        $registry = $this->makeRegistry(moduleMiddleware: [[
            'class' => TestMiddlewareA::class,
            'groups' => ['api'],
            'targets' => ['api.modules.sirsoft-shop.cart.*'],
        ]]);

        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('api.modules.sirsoft-shop.cart.add', 'x', 'api', 'after_core'),
        );
        $this->assertSame(
            [],
            $registry->resolveForRoute('api.modules.sirsoft-shop.orders.index', 'x', 'api', 'after_core'),
        );
    }

    /**
     * @effects brace_target_expands_to_or_of_names
     */
    public function test_brace_target_expands_to_or_of_names(): void
    {
        $registry = $this->makeRegistry(moduleMiddleware: [[
            'class' => TestMiddlewareA::class,
            'groups' => ['api'],
            'targets' => ['api.modules.sirsoft-shop.{cart,orders}.index'],
        ]]);

        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('api.modules.sirsoft-shop.cart.index', 'x', 'api', 'after_core'),
        );
        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('api.modules.sirsoft-shop.orders.index', 'x', 'api', 'after_core'),
        );
        $this->assertSame(
            [],
            $registry->resolveForRoute('api.modules.sirsoft-shop.checkout.index', 'x', 'api', 'after_core'),
        );
    }

    // === URI 패턴 (무명 라우트) ===

    /**
     * @effects uri_pattern_target_matches_path_not_route_name
     */
    public function test_uri_pattern_target_matches_path_not_route_name(): void
    {
        $registry = $this->makeRegistry(moduleMiddleware: [[
            'class' => TestMiddlewareA::class,
            'groups' => ['web'],
            'targets' => ['/'],
        ]]);

        // 무명 라우트 (routeName='') + path '/' → URI 매칭
        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('', '/', 'web', 'after_core'),
        );
    }

    /**
     * @effects uri_pattern_target_matches_path_not_route_name
     */
    public function test_uri_glob_pattern_matches_path_prefix(): void
    {
        $registry = $this->makeRegistry(pluginMiddleware: [[
            'class' => TestMiddlewareA::class,
            'groups' => ['web'],
            'targets' => ['/admin/*'],
        ]]);

        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('', 'admin/dashboard', 'web', 'after_core'),
        );
        $this->assertSame(
            [],
            $registry->resolveForRoute('', 'user/profile', 'web', 'after_core'),
        );
    }

    // === groups 검증 (펼침 / 거부) ===

    /**
     * @effects empty_or_disallowed_groups_declaration_is_rejected
     */
    public function test_empty_groups_declaration_is_rejected(): void
    {
        $registry = $this->makeRegistry(moduleMiddleware: [[
            'class' => TestMiddlewareA::class,
            'groups' => [],
            'targets' => ['everything'],
        ]]);

        $this->assertSame(
            [],
            $registry->resolveForRoute('api.users.index', 'x', 'api', 'after_core'),
        );
    }

    /**
     * @effects empty_or_disallowed_groups_declaration_is_rejected
     */
    public function test_disallowed_group_is_rejected(): void
    {
        $registry = $this->makeRegistry(moduleMiddleware: [[
            'class' => TestMiddlewareA::class,
            'groups' => ['console'],
            'targets' => ['everything'],
        ]]);

        $this->assertSame(
            [],
            $registry->resolveForRoute('api.users.index', 'x', 'api', 'after_core'),
        );
    }

    // === targets 필수 / class 검증 ===

    /**
     * @effects missing_targets_declaration_is_rejected
     */
    public function test_missing_targets_declaration_is_rejected(): void
    {
        $registry = $this->makeRegistry(moduleMiddleware: [[
            'class' => TestMiddlewareA::class,
            'groups' => ['api'],
            'targets' => [],
        ]]);

        $this->assertSame(
            [],
            $registry->resolveForRoute('api.users.index', 'x', 'api', 'after_core'),
        );
    }

    /**
     * @effects nonexistent_middleware_class_is_skipped
     */
    public function test_nonexistent_class_is_skipped(): void
    {
        $registry = $this->makeRegistry(moduleMiddleware: [[
            'class' => 'App\\NonExistent\\Middleware\\Nope',
            'groups' => ['api'],
            'targets' => ['everything'],
        ]]);

        $this->assertSame(
            [],
            $registry->resolveForRoute('api.users.index', 'x', 'api', 'after_core'),
        );
    }

    // === timing 분리 ===

    /**
     * @effects before_core_and_after_core_timing_are_separated
     */
    public function test_timing_before_and_after_are_separated(): void
    {
        $registry = $this->makeRegistry(moduleMiddleware: [
            [
                'class' => TestMiddlewareA::class,
                'groups' => ['api'],
                'timing' => 'before_core',
                'targets' => ['everything'],
            ],
            [
                'class' => TestMiddlewareB::class,
                'groups' => ['api'],
                'timing' => 'after_core',
                'targets' => ['everything'],
            ],
        ]);

        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('api.users.index', 'x', 'api', 'before_core'),
        );
        $this->assertSame(
            [TestMiddlewareB::class],
            $registry->resolveForRoute('api.users.index', 'x', 'api', 'after_core'),
        );
    }

    /**
     * @effects default_timing_is_after_core
     */
    public function test_default_timing_is_after_core(): void
    {
        $registry = $this->makeRegistry(moduleMiddleware: [[
            'class' => TestMiddlewareA::class,
            'groups' => ['api'],
            'targets' => ['everything'],
        ]]);

        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('api.users.index', 'x', 'api', 'after_core'),
        );
        $this->assertSame(
            [],
            $registry->resolveForRoute('api.users.index', 'x', 'api', 'before_core'),
        );
    }

    // === 캐시 무효화 ===

    /**
     * @effects flush_rebuilds_index, inactive_extension_is_excluded_from_index
     */
    public function test_flush_rebuilds_index(): void
    {
        $registry = $this->makeRegistry(moduleMiddleware: [[
            'class' => TestMiddlewareA::class,
            'groups' => ['api'],
            'targets' => ['everything'],
        ]]);

        $this->assertSame(
            [TestMiddlewareA::class],
            $registry->resolveForRoute('api.users.index', 'x', 'api', 'after_core'),
        );

        // 활성 확장 목록을 비우고 flush → 재빌드 시 빈 인덱스
        $emptyModuleManager = Mockery::mock(ModuleManager::class);
        $emptyModuleManager->shouldReceive('getActiveModules')->andReturn([]);
        $emptyPluginManager = Mockery::mock(PluginManager::class);
        $emptyPluginManager->shouldReceive('getActivePlugins')->andReturn([]);
        $this->app->instance(ModuleManager::class, $emptyModuleManager);
        $this->app->instance(PluginManager::class, $emptyPluginManager);

        ExtensionMiddlewareRegistry::flush();
        $fresh = $this->app->make(ExtensionMiddlewareRegistry::class);

        $this->assertSame(
            [],
            $fresh->resolveForRoute('api.users.index', 'x', 'api', 'after_core'),
        );
    }
}

/**
 * 경량 픽스처 확장 — getIdentifier() + getMiddleware() 만 제공.
 *
 * 실 확장은 AbstractModule/AbstractPlugin 상속으로 이 두 메서드를 보유하며,
 * registry 는 method_exists 로 getMiddleware 존재를 확인 후 호출한다.
 */
class FixtureExtension
{
    /**
     * @param  string  $identifier  확장 식별자
     * @param  array<int, array<string, mixed>>  $middleware  getMiddleware() 반환값
     */
    public function __construct(
        private string $identifier,
        private array $middleware,
    ) {}

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }
}

/** 테스트 픽스처 미들웨어. */
class TestMiddlewareA
{
    public function handle($request, \Closure $next)
    {
        return $next($request);
    }
}

/** 테스트 픽스처 미들웨어. */
class TestMiddlewareB
{
    public function handle($request, \Closure $next)
    {
        return $next($request);
    }
}
