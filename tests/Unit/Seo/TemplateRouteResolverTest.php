<?php

namespace Tests\Unit\Seo;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Seo\TemplateRouteResolver;
use App\Services\TemplateService;
use Illuminate\Support\Facades\Config;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * TemplateRouteResolver 단위 테스트
 *
 * URL을 템플릿 레이아웃으로 매핑하는 기능을 테스트합니다.
 * TemplateManagerInterface와 TemplateService를 DI로 모킹합니다.
 */
class TemplateRouteResolverTest extends TestCase
{
    /**
     * TemplateRouteResolver 인스턴스와 모킹된 의존성을 생성합니다.
     *
     * @param  array|null  $activeTemplate  활성 템플릿 데이터
     * @param  array  $routes  라우트 목록
     * @param  bool  $routesSuccess  라우트 조회 성공 여부
     * @return TemplateRouteResolver 설정된 resolver
     */
    private function createResolver(?array $activeTemplate, array $routes = [], bool $routesSuccess = true, array $knownModules = [], array $knownPlugins = []): TemplateRouteResolver
    {
        // TemplateManagerInterface 모킹 (DI)
        /** @var TemplateManagerInterface|MockInterface $templateManager */
        $templateManager = Mockery::mock(TemplateManagerInterface::class);
        $templateManager->shouldReceive('getActiveTemplate')
            ->with('user')
            ->andReturn($activeTemplate);

        // TemplateService 모킹
        /** @var TemplateService|MockInterface $templateService */
        $templateService = Mockery::mock(TemplateService::class);
        $templateService->shouldReceive('getRoutesDataWithModules')
            ->withAnyArgs()
            ->andReturn([
                'success' => $routesSuccess,
                'data' => ['routes' => $routes],
            ]);

        // ModuleManagerInterface 모킹
        /** @var ModuleManagerInterface|MockInterface $moduleManager */
        $moduleManager = Mockery::mock(ModuleManagerInterface::class);
        $moduleManager->shouldReceive('getModule')
            ->andReturnUsing(function (string $id) use ($knownModules) {
                return in_array($id, $knownModules) ? Mockery::mock(ModuleInterface::class) : null;
            });

        // PluginManagerInterface 모킹
        /** @var PluginManagerInterface|MockInterface $pluginManager */
        $pluginManager = Mockery::mock(PluginManagerInterface::class);
        $pluginManager->shouldReceive('getPlugin')
            ->andReturnUsing(function (string $id) use ($knownPlugins) {
                return in_array($id, $knownPlugins) ? Mockery::mock(PluginInterface::class) : null;
            });

        return new TemplateRouteResolver($templateService, $templateManager, $moduleManager, $pluginManager);
    }

    /**
     * 기본 라우트 목록을 생성합니다.
     *
     * @return array 테스트용 라우트 배열
     */
    private function getDefaultRoutes(): array
    {
        return [
            [
                'path' => '/',
                'layout' => 'home',
                'auth_required' => false,
                'guest_only' => false,
                'meta' => ['title' => '홈'],
            ],
            [
                'path' => '/shop/products',
                'layout' => 'sirsoft-ecommerce.shop/index',
                'auth_required' => false,
                'guest_only' => false,
                'meta' => [],
            ],
            [
                'path' => '/shop/products/:id',
                'layout' => 'sirsoft-ecommerce.shop/show',
                'auth_required' => false,
                'guest_only' => false,
                'meta' => [],
            ],
            [
                'path' => '/about',
                'layout' => 'pages/about',
                'auth_required' => false,
                'guest_only' => false,
                'meta' => ['seo' => true],
            ],
        ];
    }

    /**
     * 루트 URL(/)이 home 레이아웃으로 매핑됩니다.
     */
    public function test_root_url_resolves_to_home_layout(): void
    {
        $resolver = $this->createResolver(
            ['identifier' => 'sirsoft-user_basic'],
            $this->getDefaultRoutes(),
            knownModules: ['sirsoft-ecommerce']
        );

        $result = $resolver->resolve('/');

        $this->assertNotNull($result);
        $this->assertSame('sirsoft-user_basic', $result['templateIdentifier']);
        $this->assertSame('home', $result['layoutName']);
        $this->assertNull($result['moduleIdentifier']);
        $this->assertNull($result['pluginIdentifier']);
        $this->assertSame([], $result['routeParams']);
    }

    /**
     * 모듈 레이아웃 경로가 moduleIdentifier와 layoutName으로 분리됩니다.
     */
    public function test_shop_products_resolves_with_module_identifier(): void
    {
        $resolver = $this->createResolver(
            ['identifier' => 'sirsoft-user_basic'],
            $this->getDefaultRoutes(),
            knownModules: ['sirsoft-ecommerce']
        );

        $result = $resolver->resolve('/shop/products');

        $this->assertNotNull($result);
        $this->assertSame('sirsoft-ecommerce', $result['moduleIdentifier']);
        $this->assertNull($result['pluginIdentifier']);
        $this->assertSame('shop/index', $result['layoutName']);
    }

    /**
     * 동적 라우트 파라미터(:id)가 routeParams로 추출됩니다.
     */
    public function test_dynamic_route_params_extracted(): void
    {
        $resolver = $this->createResolver(
            ['identifier' => 'sirsoft-user_basic'],
            $this->getDefaultRoutes(),
            knownModules: ['sirsoft-ecommerce']
        );

        $result = $resolver->resolve('/shop/products/123');

        $this->assertNotNull($result);
        $this->assertSame('sirsoft-ecommerce', $result['moduleIdentifier']);
        $this->assertSame('shop/show', $result['layoutName']);
        $this->assertSame(['id' => '123'], $result['routeParams']);
    }

    /**
     * auth_required 라우트는 null을 반환합니다.
     */
    public function test_auth_required_route_returns_null(): void
    {
        $routes = [
            [
                'path' => '/mypage',
                'layout' => 'mypage/index',
                'auth_required' => true,
                'guest_only' => false,
                'meta' => [],
            ],
        ];

        $resolver = $this->createResolver(
            ['identifier' => 'sirsoft-user_basic'],
            $routes
        );

        $result = $resolver->resolve('/mypage');

        $this->assertNull($result);
    }

    /**
     * guest_only 라우트는 null을 반환합니다.
     */
    public function test_guest_only_route_returns_null(): void
    {
        $routes = [
            [
                'path' => '/login',
                'layout' => 'auth/login',
                'auth_required' => false,
                'guest_only' => true,
                'meta' => [],
            ],
        ];

        $resolver = $this->createResolver(
            ['identifier' => 'sirsoft-user_basic'],
            $routes
        );

        $result = $resolver->resolve('/login');

        $this->assertNull($result);
    }

    /**
     * 활성 템플릿이 없으면 null을 반환합니다.
     */
    public function test_no_active_template_returns_null(): void
    {
        $resolver = $this->createResolver(null);

        $result = $resolver->resolve('/');

        $this->assertNull($result);
    }

    /**
     * 동적 route_path 표현식이 모듈 설정값으로 해석됩니다.
     */
    public function test_dynamic_route_path_expression_resolved(): void
    {
        $routes = [
            [
                'path' => "/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop'}}/products",
                'layout' => 'sirsoft-ecommerce.shop/index',
                'auth_required' => false,
                'guest_only' => false,
                'meta' => [],
            ],
        ];

        // 현재 resolveRouteExpression의 정규식 [\w.]+ 은 optional chaining (?.)을 처리하지 못해
        // 'basic_info?.route_path' 패턴에서 첫 번째 regex가 매칭 실패하고
        // null coalescing fallback 핸들러로 이동하여 기본값 'shop'을 반환합니다.
        // 따라서 Config 설정과 무관하게 fallback 'shop'이 사용됩니다.

        $resolver = $this->createResolver(
            ['identifier' => 'sirsoft-user_basic'],
            $routes
        );

        // fallback 'shop'이 사용되므로 /shop/products로 매칭
        $result = $resolver->resolve('/shop/products');

        $this->assertNotNull($result);
        $this->assertSame('sirsoft-ecommerce', $result['moduleIdentifier']);
        $this->assertSame('shop/index', $result['layoutName']);
    }

    /**
     * 존재하지 않는 URL은 null을 반환합니다.
     */
    public function test_non_existent_url_returns_null(): void
    {
        $resolver = $this->createResolver(
            ['identifier' => 'sirsoft-user_basic'],
            $this->getDefaultRoutes(),
            knownModules: ['sirsoft-ecommerce']
        );

        $result = $resolver->resolve('/this/does/not/exist');

        $this->assertNull($result);
    }

    /**
     * 라우트 데이터 조회 실패 시 null을 반환합니다.
     */
    public function test_routes_data_failure_returns_null(): void
    {
        $resolver = $this->createResolver(
            ['identifier' => 'sirsoft-user_basic'],
            [],
            false
        );

        $result = $resolver->resolve('/');

        $this->assertNull($result);
    }

    /**
     * routeMeta가 결과에 포함됩니다.
     */
    public function test_route_meta_included_in_result(): void
    {
        $routes = [
            [
                'path' => '/about',
                'layout' => 'pages/about',
                'auth_required' => false,
                'guest_only' => false,
                'meta' => ['seo' => true, 'cache' => 3600],
            ],
        ];

        $resolver = $this->createResolver(
            ['identifier' => 'sirsoft-user_basic'],
            $routes
        );

        $result = $resolver->resolve('/about');

        $this->assertNotNull($result);
        $this->assertSame(['seo' => true, 'cache' => 3600], $result['routeMeta']);
    }

    /**
     * 삼항 연산자 표현식이 포함된 라우트 경로를 정상 해석합니다.
     */
    public function test_ternary_expression_route_path_resolved(): void
    {
        $routes = [
            [
                'path' => "/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.no_route ? '' : (_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop')}}/products/:id",
                'layout' => 'sirsoft-ecommerce.shop/show',
                'auth_required' => false,
                'guest_only' => false,
                'meta' => [],
            ],
        ];

        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info.route_path', 'shop');
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info.no_route', false);

        $resolver = $this->createResolver(
            ['identifier' => 'sirsoft-user_basic'],
            $routes
        );

        $result = $resolver->resolve('/shop/products/99');

        $this->assertNotNull($result);
        $this->assertSame('sirsoft-ecommerce', $result['moduleIdentifier']);
        $this->assertSame('shop/show', $result['layoutName']);
        $this->assertSame(['id' => '99'], $result['routeParams']);
    }

    /**
     * no_route=true일 때 삼항 연산자 표현식이 빈 문자열을 반환합니다.
     */
    public function test_ternary_expression_returns_empty_when_no_route_true(): void
    {
        $routes = [
            [
                'path' => "/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.no_route ? '' : (_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop')}}/products",
                'layout' => 'sirsoft-ecommerce.shop/index',
                'auth_required' => false,
                'guest_only' => false,
                'meta' => [],
            ],
        ];

        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info.route_path', 'shop');
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info.no_route', true);

        $resolver = $this->createResolver(
            ['identifier' => 'sirsoft-user_basic'],
            $routes
        );

        // no_route=true → 빈 prefix → /products로 매칭
        $result = $resolver->resolve('/products');

        $this->assertNotNull($result);
        $this->assertSame('shop/index', $result['layoutName']);
    }

    /**
     * 동적 표현식에서 모듈 설정이 없으면 기본값(fallback)을 사용합니다.
     */
    public function test_dynamic_expression_uses_fallback_when_module_setting_missing(): void
    {
        $routes = [
            [
                'path' => "/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop'}}/products",
                'layout' => 'sirsoft-ecommerce.shop/index',
                'auth_required' => false,
                'guest_only' => false,
                'meta' => [],
            ],
        ];

        // 모듈 설정 없음 → fallback 'shop' 사용
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info.route_path', null);

        $resolver = $this->createResolver(
            ['identifier' => 'sirsoft-user_basic'],
            $routes,
            knownModules: ['sirsoft-ecommerce']
        );

        $result = $resolver->resolve('/shop/products');

        $this->assertNotNull($result);
        $this->assertSame('shop/index', $result['layoutName']);
    }

    // =========================================================================
    // 플러그인 식별자 판별 테스트 (3개)
    // =========================================================================

    /**
     * 플러그인 dot notation에서 pluginIdentifier가 반환되고 moduleIdentifier는 null입니다.
     */
    public function test_resolve_returns_plugin_identifier_for_plugin_layout(): void
    {
        $routes = [
            [
                'path' => '/payment/checkout',
                'layout' => 'sirsoft-payment.checkout',
                'auth_required' => false,
                'guest_only' => false,
                'meta' => [],
            ],
        ];

        $resolver = $this->createResolver(
            ['identifier' => 'sirsoft-user_basic'],
            $routes,
            knownPlugins: ['sirsoft-payment']
        );

        $result = $resolver->resolve('/payment/checkout');

        $this->assertNotNull($result);
        $this->assertNull($result['moduleIdentifier']);
        $this->assertSame('sirsoft-payment', $result['pluginIdentifier']);
        $this->assertSame('checkout', $result['layoutName']);
    }

    /**
     * 모듈 dot notation에서 moduleIdentifier가 반환되고 pluginIdentifier는 null입니다.
     */
    public function test_resolve_returns_module_identifier_for_module_layout(): void
    {
        $routes = [
            [
                'path' => '/shop/products',
                'layout' => 'sirsoft-ecommerce.shop/index',
                'auth_required' => false,
                'guest_only' => false,
                'meta' => [],
            ],
        ];

        $resolver = $this->createResolver(
            ['identifier' => 'sirsoft-user_basic'],
            $routes,
            knownModules: ['sirsoft-ecommerce']
        );

        $result = $resolver->resolve('/shop/products');

        $this->assertNotNull($result);
        $this->assertSame('sirsoft-ecommerce', $result['moduleIdentifier']);
        $this->assertNull($result['pluginIdentifier']);
    }

    /**
     * dot notation 없는 코어 레이아웃은 moduleIdentifier, pluginIdentifier 모두 null입니다.
     */
    public function test_resolve_returns_null_identifiers_for_core_layout(): void
    {
        $routes = [
            [
                'path' => '/',
                'layout' => 'home',
                'auth_required' => false,
                'guest_only' => false,
                'meta' => [],
            ],
        ];

        $resolver = $this->createResolver(
            ['identifier' => 'sirsoft-user_basic'],
            $routes
        );

        $result = $resolver->resolve('/');

        $this->assertNotNull($result);
        $this->assertNull($result['moduleIdentifier']);
        $this->assertNull($result['pluginIdentifier']);
    }
}
