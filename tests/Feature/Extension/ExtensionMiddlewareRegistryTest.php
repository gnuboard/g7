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
    /**
     * 각 테스트 전에 미들웨어 인덱스 정적 캐시를 비워 이전 테스트의 선언이 남지 않게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        ExtensionMiddlewareRegistry::flush();
    }

    /**
     * 각 테스트 뒤에 미들웨어 인덱스 정적 캐시를 비워 다음 테스트로 새지 않게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        ExtensionMiddlewareRegistry::flush();
        parent::tearDown();
    }

    /**
     * 픽스처 확장 식별자를 테스트 allowlist 에 넣습니다.
     *
     * 레지스트리는 테스트 allowlist 밖 확장의 선언을 건너뛴다(#609 F11 가드 — allowlist 밖 확장은
     * ServiceProvider 가 등록되지 않아 그 미들웨어를 올리면 요청이 500 이 된다). 픽스처 확장은
     * 실제 디렉터리가 없는 가짜 식별자라, 여기서 허용하지 않으면 모든 선언이 가드에 걸려 빈 결과가 된다.
     * 마이그레이션 대상(`requiredExtensions`)에는 넣지 않는다.
     *
     * @return array<string> 이 테스트에서 로딩을 허용할 확장 식별자 목록
     */
    protected function resolveAllowedExtensions(): array
    {
        return array_values(array_unique([
            ...parent::resolveAllowedExtensions(),
            'modules/sirsoft-shop',
            'plugins/sirsoft-pay',
        ]));
    }

    /**
     * 활성 모듈/플러그인의 미들웨어 선언으로 registry 를 구성하고 새 인스턴스를 반환합니다.
     *
     * @param  array<int, array<string, mixed>>  $moduleMiddleware  모듈 getMiddleware() 반환값
     * @param  array<int, array<string, mixed>>  $pluginMiddleware  플러그인 getMiddleware() 반환값
     * @param  string  $moduleId  모듈 식별자
     * @param  string  $pluginId  플러그인 식별자
     * @return ExtensionMiddlewareRegistry 픽스처 선언으로 새로 만든 레지스트리 인스턴스
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
     * 모듈의 self 대상이 그룹마다 자기 모듈 라우트 접두사로만 펼쳐지는지 검증합니다.
     *
     * @effects self_target_expands_to_own_extension_prefix_per_group, groups_array_expands_to_per_group_entries
     *
     * @return void 반환값 없음
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
     * 플러그인의 self 대상이 자기 플러그인 라우트 접두사로만 펼쳐지는지 검증합니다.
     *
     * @effects self_target_expands_to_own_extension_prefix_per_group
     *
     * @return void 반환값 없음
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
     * all_extensions 대상이 확장 라우트만 잡고 코어 라우트는 놓치는지 검증합니다.
     *
     * @effects all_extensions_matches_extension_routes_only
     *
     * @return void 반환값 없음
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
     * core 대상이 코어 라우트만 잡고 확장 라우트와 무명 라우트는 잡지 않는지 검증합니다.
     *
     * @effects core_target_matches_core_routes_only_negative_predicate
     *
     * @return void 반환값 없음
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
     * everything 대상이 무명 라우트까지 모든 라우트를 잡는지 검증합니다.
     *
     * @effects everything_and_star_match_all_routes_including_unnamed
     *
     * @return void 반환값 없음
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
     * 별표 별칭이 everything 과 같이 무명 라우트까지 잡는지 검증합니다.
     *
     * @effects everything_and_star_match_all_routes_including_unnamed
     *
     * @return void 반환값 없음
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
     * module:{id} 대상이 그 모듈 라우트만 잡는지 검증합니다.
     *
     * @effects module_id_and_plugin_id_target_scope_to_that_extension
     *
     * @return void 반환값 없음
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
     * plugin:{id} 대상이 그 플러그인 라우트만 잡는지 검증합니다.
     *
     * @effects module_id_and_plugin_id_target_scope_to_that_extension
     *
     * @return void 반환값 없음
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
     * 원시 글롭 대상이 라우트명 패턴으로 매칭되는지 검증합니다.
     *
     * @effects raw_glob_target_matches_route_name
     *
     * @return void 반환값 없음
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
     * 중괄호 대상이 나열한 이름들의 OR 로 펼쳐지는지 검증합니다.
     *
     * @effects brace_target_expands_to_or_of_names
     *
     * @return void 반환값 없음
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
     * 슬래시로 시작하는 대상이 라우트명이 아닌 경로로 무명 라우트를 잡는지 검증합니다.
     *
     * @effects uri_pattern_target_matches_path_not_route_name
     *
     * @return void 반환값 없음
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
     * 경로 글롭 대상이 경로 접두사가 맞는 요청만 잡는지 검증합니다.
     *
     * @effects uri_pattern_target_matches_path_not_route_name
     *
     * @return void 반환값 없음
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
     * groups 가 빈 선언은 거부돼 어떤 라우트에도 붙지 않는지 검증합니다.
     *
     * @effects empty_or_disallowed_groups_declaration_is_rejected
     *
     * @return void 반환값 없음
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
     * 허용되지 않은 그룹 이름의 선언은 거부되는지 검증합니다.
     *
     * @effects empty_or_disallowed_groups_declaration_is_rejected
     *
     * @return void 반환값 없음
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
     * targets 가 빈 선언은 거부되는지 검증합니다.
     *
     * @effects missing_targets_declaration_is_rejected
     *
     * @return void 반환값 없음
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
     * 존재하지 않는 미들웨어 클래스 선언은 건너뛰는지 검증합니다.
     *
     * @effects nonexistent_middleware_class_is_skipped
     *
     * @return void 반환값 없음
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
     * before_core 와 after_core 선언이 시점별로 따로 반환되는지 검증합니다.
     *
     * @effects before_core_and_after_core_timing_are_separated
     *
     * @return void 반환값 없음
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
     * timing 을 생략한 선언은 after_core 로 취급되는지 검증합니다.
     *
     * @effects default_timing_is_after_core
     *
     * @return void 반환값 없음
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
     * flush 뒤 다시 만들면 활성 확장 목록 변화가 인덱스에 반영되는지 검증합니다.
     *
     * @effects flush_rebuilds_index, inactive_extension_is_excluded_from_index
     *
     * @return void 반환값 없음
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
     * 픽스처 확장의 식별자와 미들웨어 선언을 받습니다.
     *
     * @param  string  $identifier  확장 식별자
     * @param  array<int, array<string, mixed>>  $middleware  getMiddleware() 반환값
     */
    public function __construct(
        private string $identifier,
        private array $middleware,
    ) {}

    /**
     * 픽스처 확장 식별자를 돌려줍니다.
     *
     * @return string 확장 식별자
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * 생성자로 받은 미들웨어 선언을 그대로 돌려줍니다.
     *
     * @return array<int, array<string, mixed>> 미들웨어 선언 목록
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }
}

/** 테스트 픽스처 미들웨어. */
class TestMiddlewareA
{
    /**
     * 아무 처리 없이 다음 미들웨어로 넘깁니다.
     *
     * @param  mixed  $request  들어온 요청
     * @param  \Closure  $next  다음 미들웨어 호출
     * @return mixed 다음 미들웨어의 응답
     */
    public function handle($request, \Closure $next)
    {
        return $next($request);
    }
}

/** 테스트 픽스처 미들웨어. */
class TestMiddlewareB
{
    /**
     * 아무 처리 없이 다음 미들웨어로 넘깁니다.
     *
     * @param  mixed  $request  들어온 요청
     * @param  \Closure  $next  다음 미들웨어 호출
     * @return mixed 다음 미들웨어의 응답
     */
    public function handle($request, \Closure $next)
    {
        return $next($request);
    }
}
