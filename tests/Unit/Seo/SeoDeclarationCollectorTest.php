<?php

namespace Tests\Unit\Seo;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Seo\SeoDeclarationCollector;
use App\Services\LayoutService;
use App\Services\TemplateService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * SeoDeclarationCollector 단위 테스트
 *
 * SEO 선언 수집기의 레이아웃 수집 및 확장별 그룹핑 기능을 테스트합니다.
 */
class SeoDeclarationCollectorTest extends TestCase
{
    private TemplateService|Mockery\MockInterface $templateService;

    private LayoutService|Mockery\MockInterface $layoutService;

    private TemplateManagerInterface|Mockery\MockInterface $templateManager;

    private ModuleManagerInterface|Mockery\MockInterface $moduleManager;

    private PluginManagerInterface|Mockery\MockInterface $pluginManager;

    private SeoDeclarationCollector $collector;

    /**
     * 테스트 초기화 - SeoDeclarationCollector 인스턴스와 의존성 목(Mock)을 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->templateService = Mockery::mock(TemplateService::class);
        $this->layoutService = Mockery::mock(LayoutService::class);
        $this->templateManager = Mockery::mock(TemplateManagerInterface::class);
        $this->moduleManager = Mockery::mock(ModuleManagerInterface::class);
        $this->pluginManager = Mockery::mock(PluginManagerInterface::class);

        // 기본 모듈/플러그인 판별: 알려진 모듈만 매칭
        $this->moduleManager->shouldReceive('getModule')
            ->andReturnUsing(function (string $id) {
                return in_array($id, ['sirsoft-ecommerce', 'sirsoft-board']) ? Mockery::mock(ModuleInterface::class) : null;
            })
            ->byDefault();

        $this->pluginManager->shouldReceive('getPlugin')
            ->andReturn(null)
            ->byDefault();

        $this->collector = new SeoDeclarationCollector(
            $this->templateService,
            $this->layoutService,
            $this->templateManager,
            $this->moduleManager,
            $this->pluginManager,
        );
    }

    /**
     * 테스트 종료 - Mockery 리소스를 정리합니다.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 활성 템플릿과 라우트를 설정하는 헬퍼 메서드
     *
     * @param  array  $routes  라우트 배열
     * @param  string  $templateIdentifier  템플릿 식별자
     */
    private function setupActiveTemplateWithRoutes(array $routes, string $templateIdentifier = 'sirsoft-basic'): void
    {
        $this->templateManager
            ->shouldReceive('getActiveTemplate')
            ->with('user')
            ->once()
            ->andReturn(['identifier' => $templateIdentifier]);

        $this->templateService
            ->shouldReceive('getRoutesDataWithModules')
            ->with($templateIdentifier)
            ->once()
            ->andReturn([
                'success' => true,
                'data' => ['routes' => $routes],
            ]);
    }

    /**
     * 레이아웃 로드 결과를 설정하는 헬퍼 메서드
     *
     * @param  string  $layoutName  레이아웃 이름
     * @param  array  $layoutData  레이아웃 데이터
     * @param  string  $templateIdentifier  템플릿 식별자
     */
    private function mockLayout(string $layoutName, array $layoutData, string $templateIdentifier = 'sirsoft-basic'): void
    {
        $this->layoutService
            ->shouldReceive('getLayout')
            ->with($templateIdentifier, $layoutName)
            ->andReturn($layoutData);
    }

    // ─── collect(): SEO 활성/비활성 레이아웃 수집 ──────────────────────

    /**
     * SEO 활성 레이아웃만 수집되는지 확인합니다.
     * meta.seo.enabled=true인 레이아웃만 반환되어야 합니다.
     */
    public function test_collect_returns_only_seo_enabled_layouts(): void
    {
        $this->setupActiveTemplateWithRoutes([
            ['path' => '/', 'layout' => 'home', 'auth_required' => false],
            ['path' => '/about', 'layout' => 'about', 'auth_required' => false],
        ]);

        $this->mockLayout('home', [
            'meta' => [
                'seo' => [
                    'enabled' => true,
                    'priority' => 1.0,
                    'changefreq' => 'daily',
                ],
            ],
            'components' => [],
        ]);

        $this->mockLayout('about', [
            'meta' => [
                'seo' => [
                    'enabled' => true,
                    'priority' => 0.8,
                    'changefreq' => 'weekly',
                ],
            ],
            'components' => [],
        ]);

        $result = $this->collector->collect();

        $this->assertCount(2, $result);
        $this->assertEquals('home', $result[0]['layoutName']);
        $this->assertEquals('/', $result[0]['routePath']);
        $this->assertNull($result[0]['moduleIdentifier']);
        $this->assertEquals('about', $result[1]['layoutName']);
        $this->assertEquals('/about', $result[1]['routePath']);
    }

    /**
     * SEO 비활성 레이아웃이 제외되는지 확인합니다.
     * meta.seo.enabled=false인 레이아웃은 결과에 포함되지 않아야 합니다.
     */
    public function test_collect_excludes_seo_disabled_layouts(): void
    {
        $this->setupActiveTemplateWithRoutes([
            ['path' => '/', 'layout' => 'home', 'auth_required' => false],
            ['path' => '/internal', 'layout' => 'internal', 'auth_required' => false],
        ]);

        $this->mockLayout('home', [
            'meta' => [
                'seo' => ['enabled' => true, 'priority' => 1.0],
            ],
            'components' => [],
        ]);

        $this->mockLayout('internal', [
            'meta' => [
                'seo' => ['enabled' => false],
            ],
            'components' => [],
        ]);

        $result = $this->collector->collect();

        $this->assertCount(1, $result);
        $this->assertEquals('home', $result[0]['layoutName']);
    }

    /**
     * meta.seo 속성이 없는 레이아웃이 제외되는지 확인합니다.
     */
    public function test_collect_excludes_layouts_without_seo_meta(): void
    {
        $this->setupActiveTemplateWithRoutes([
            ['path' => '/', 'layout' => 'home', 'auth_required' => false],
            ['path' => '/plain', 'layout' => 'plain', 'auth_required' => false],
        ]);

        $this->mockLayout('home', [
            'meta' => [
                'seo' => ['enabled' => true, 'priority' => 1.0],
            ],
            'components' => [],
        ]);

        $this->mockLayout('plain', [
            'meta' => [],
            'components' => [],
        ]);

        $result = $this->collector->collect();

        $this->assertCount(1, $result);
        $this->assertEquals('home', $result[0]['layoutName']);
    }

    // ─── collect(): auth_required / guest_only 필터링 ──────────────────

    /**
     * auth_required=true인 라우트가 제외되는지 확인합니다.
     */
    public function test_collect_skips_auth_required_routes(): void
    {
        $this->setupActiveTemplateWithRoutes([
            ['path' => '/', 'layout' => 'home', 'auth_required' => false],
            ['path' => '/mypage', 'layout' => 'mypage', 'auth_required' => true],
        ]);

        $this->mockLayout('home', [
            'meta' => ['seo' => ['enabled' => true]],
            'components' => [],
        ]);

        // mypage는 auth_required=true이므로 getLayout 호출되지 않아야 함
        $this->layoutService
            ->shouldNotReceive('getLayout')
            ->with('sirsoft-basic', 'mypage');

        $result = $this->collector->collect();

        $this->assertCount(1, $result);
        $this->assertEquals('home', $result[0]['layoutName']);
    }

    /**
     * guest_only=true인 라우트가 제외되는지 확인합니다.
     */
    public function test_collect_skips_guest_only_routes(): void
    {
        $this->setupActiveTemplateWithRoutes([
            ['path' => '/', 'layout' => 'home', 'auth_required' => false],
            ['path' => '/login', 'layout' => 'login', 'auth_required' => false, 'guest_only' => true],
        ]);

        $this->mockLayout('home', [
            'meta' => ['seo' => ['enabled' => true]],
            'components' => [],
        ]);

        $result = $this->collector->collect();

        $this->assertCount(1, $result);
    }

    // ─── collect(): 모듈 레이아웃 (dotted notation) ──────────────────

    /**
     * 모듈 레이아웃의 moduleIdentifier가 올바르게 추출되는지 확인합니다.
     * 레이아웃명에 '.'이 포함되면 앞부분이 moduleIdentifier, 뒷부분이 actualLayoutName입니다.
     */
    public function test_collect_extracts_module_identifier_from_dotted_layout_name(): void
    {
        $this->setupActiveTemplateWithRoutes([
            ['path' => '/shop/products', 'layout' => 'sirsoft-ecommerce.shop/index', 'auth_required' => false],
        ]);

        $this->mockLayout('sirsoft-ecommerce.shop/index', [
            'meta' => [
                'seo' => ['enabled' => true, 'priority' => 0.9, 'changefreq' => 'daily'],
            ],
            'components' => [],
        ]);

        $result = $this->collector->collect();

        $this->assertCount(1, $result);
        $this->assertEquals('shop/index', $result[0]['layoutName']);
        $this->assertEquals('sirsoft-ecommerce', $result[0]['moduleIdentifier']);
        $this->assertEquals('sirsoft-basic', $result[0]['templateIdentifier']);
        $this->assertEquals('/shop/products', $result[0]['routePath']);
    }

    // ─── collect(): 부모-자식 SEO 병합 (getLayout이 이미 병합) ──────────

    /**
     * 부모 seo + 자식 seo가 deep merge된 결과로 수집되는지 확인합니다.
     * getLayout()은 이미 병합된 레이아웃을 반환하므로 수집기는 최종 결과만 판단합니다.
     */
    public function test_collect_uses_merged_seo_from_layout_service(): void
    {
        $this->setupActiveTemplateWithRoutes([
            ['path' => '/products', 'layout' => 'sirsoft-ecommerce.products', 'auth_required' => false],
        ]);

        // getLayout은 부모+자식 병합 결과를 반환
        $this->mockLayout('sirsoft-ecommerce.products', [
            'meta' => [
                'seo' => [
                    'enabled' => true,
                    'priority' => 0.7,
                    'changefreq' => 'weekly',
                    'title' => '상품 목록 - {{site.name}}',
                ],
            ],
            'components' => [],
        ]);

        $result = $this->collector->collect();

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['seo']['enabled']);
        $this->assertEquals(0.7, $result[0]['seo']['priority']);
        $this->assertEquals('weekly', $result[0]['seo']['changefreq']);
        $this->assertEquals('상품 목록 - {{site.name}}', $result[0]['seo']['title']);
    }

    /**
     * 부모만 seo.enabled=true이고 자식이 seo 미정의인 경우,
     * 병합 결과 enabled=true이므로 수집 대상에 포함됩니다.
     */
    public function test_collect_includes_layout_when_parent_seo_enabled_child_undefined(): void
    {
        $this->setupActiveTemplateWithRoutes([
            ['path' => '/page', 'layout' => 'page', 'auth_required' => false],
        ]);

        // 부모 seo.enabled=true, 자식 미정의 → 병합 결과 enabled=true
        $this->mockLayout('page', [
            'meta' => [
                'seo' => ['enabled' => true, 'priority' => 0.5],
            ],
            'components' => [],
        ]);

        $result = $this->collector->collect();

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['seo']['enabled']);
    }

    /**
     * 자식이 seo.enabled=false로 오버라이드하면 (부모 true 상관없이)
     * 병합 결과 enabled=false이므로 수집에서 제외됩니다.
     */
    public function test_collect_excludes_layout_when_child_overrides_seo_enabled_false(): void
    {
        $this->setupActiveTemplateWithRoutes([
            ['path' => '/page', 'layout' => 'page', 'auth_required' => false],
        ]);

        // 부모 enabled=true이지만 자식이 enabled=false로 오버라이드 → 병합 결과 false
        $this->mockLayout('page', [
            'meta' => [
                'seo' => ['enabled' => false, 'priority' => 0.5],
            ],
            'components' => [],
        ]);

        $result = $this->collector->collect();

        $this->assertCount(0, $result);
    }

    // ─── collect(): 엣지 케이스 ──────────────────────────────────────

    /**
     * 라우트가 0개인 빈 템플릿에서 빈 배열이 반환되는지 확인합니다.
     */
    public function test_collect_returns_empty_for_empty_routes(): void
    {
        $this->templateManager
            ->shouldReceive('getActiveTemplate')
            ->with('user')
            ->once()
            ->andReturn(['identifier' => 'sirsoft-basic']);

        $this->templateService
            ->shouldReceive('getRoutesDataWithModules')
            ->with('sirsoft-basic')
            ->once()
            ->andReturn([
                'success' => true,
                'data' => ['routes' => []],
            ]);

        $result = $this->collector->collect();

        $this->assertEmpty($result);
    }

    /**
     * 활성 템플릿이 없을 때 빈 배열이 반환되는지 확인합니다.
     */
    public function test_collect_returns_empty_when_no_active_template(): void
    {
        $this->templateManager
            ->shouldReceive('getActiveTemplate')
            ->with('user')
            ->once()
            ->andReturn(null);

        $result = $this->collector->collect();

        $this->assertEmpty($result);
    }

    /**
     * 활성 템플릿에 identifier가 없을 때 빈 배열이 반환되는지 확인합니다.
     */
    public function test_collect_returns_empty_when_template_has_no_identifier(): void
    {
        $this->templateManager
            ->shouldReceive('getActiveTemplate')
            ->with('user')
            ->once()
            ->andReturn(['name' => 'Some Template']);

        $result = $this->collector->collect();

        $this->assertEmpty($result);
    }

    /**
     * 라우트 데이터 조회 실패 시 빈 배열이 반환되는지 확인합니다.
     */
    public function test_collect_returns_empty_when_routes_fetch_fails(): void
    {
        $this->templateManager
            ->shouldReceive('getActiveTemplate')
            ->with('user')
            ->once()
            ->andReturn(['identifier' => 'sirsoft-basic']);

        $this->templateService
            ->shouldReceive('getRoutesDataWithModules')
            ->with('sirsoft-basic')
            ->once()
            ->andReturn(['success' => false]);

        $result = $this->collector->collect();

        $this->assertEmpty($result);
    }

    /**
     * 레이아웃 로드 중 예외 발생 시 해당 레이아웃을 건너뛰고
     * 나머지는 정상 수집되는지 확인합니다 (graceful skip).
     */
    public function test_collect_gracefully_skips_layout_on_exception(): void
    {
        $this->setupActiveTemplateWithRoutes([
            ['path' => '/', 'layout' => 'home', 'auth_required' => false],
            ['path' => '/broken', 'layout' => 'broken', 'auth_required' => false],
            ['path' => '/about', 'layout' => 'about', 'auth_required' => false],
        ]);

        $this->mockLayout('home', [
            'meta' => ['seo' => ['enabled' => true]],
            'components' => [],
        ]);

        $this->layoutService
            ->shouldReceive('getLayout')
            ->with('sirsoft-basic', 'broken')
            ->andThrow(new \Exception('레이아웃 파일을 찾을 수 없습니다'));

        $this->mockLayout('about', [
            'meta' => ['seo' => ['enabled' => true]],
            'components' => [],
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'SeoDeclarationCollector')
                    && $context['layout'] === 'broken';
            });

        $result = $this->collector->collect();

        $this->assertCount(2, $result);
        $this->assertEquals('home', $result[0]['layoutName']);
        $this->assertEquals('about', $result[1]['layoutName']);
    }

    /**
     * 빈 레이아웃 이름인 라우트가 건너뛰어지는지 확인합니다.
     */
    public function test_collect_skips_routes_with_empty_layout_name(): void
    {
        $this->setupActiveTemplateWithRoutes([
            ['path' => '/', 'layout' => 'home', 'auth_required' => false],
            ['path' => '/empty', 'layout' => '', 'auth_required' => false],
        ]);

        $this->mockLayout('home', [
            'meta' => ['seo' => ['enabled' => true]],
            'components' => [],
        ]);

        $result = $this->collector->collect();

        $this->assertCount(1, $result);
    }

    // ─── collectGroupedByExtension() ──────────────────────────────────

    /**
     * SEO 선언이 확장별로 올바르게 그룹핑되는지 확인합니다.
     * moduleIdentifier가 null이면 'core' 키로 그룹핑됩니다.
     */
    public function test_collect_grouped_by_extension(): void
    {
        $this->setupActiveTemplateWithRoutes([
            ['path' => '/', 'layout' => 'home', 'auth_required' => false],
            ['path' => '/about', 'layout' => 'about', 'auth_required' => false],
            ['path' => '/shop/products', 'layout' => 'sirsoft-ecommerce.shop/index', 'auth_required' => false],
            ['path' => '/shop/cart', 'layout' => 'sirsoft-ecommerce.shop/cart', 'auth_required' => false],
            ['path' => '/board', 'layout' => 'sirsoft-board.board/list', 'auth_required' => false],
        ]);

        // 코어 레이아웃
        $this->mockLayout('home', [
            'meta' => ['seo' => ['enabled' => true, 'priority' => 1.0]],
            'components' => [],
        ]);
        $this->mockLayout('about', [
            'meta' => ['seo' => ['enabled' => true, 'priority' => 0.8]],
            'components' => [],
        ]);

        // 이커머스 모듈 레이아웃
        $this->mockLayout('sirsoft-ecommerce.shop/index', [
            'meta' => ['seo' => ['enabled' => true, 'priority' => 0.9]],
            'components' => [],
        ]);
        $this->mockLayout('sirsoft-ecommerce.shop/cart', [
            'meta' => ['seo' => ['enabled' => true, 'priority' => 0.5]],
            'components' => [],
        ]);

        // 게시판 모듈 레이아웃
        $this->mockLayout('sirsoft-board.board/list', [
            'meta' => ['seo' => ['enabled' => true, 'priority' => 0.7]],
            'components' => [],
        ]);

        $result = $this->collector->collectGroupedByExtension();

        // 코어 그룹: home, about
        $this->assertArrayHasKey('core', $result);
        $this->assertCount(2, $result['core']);

        // 이커머스 그룹: shop/index, shop/cart
        $this->assertArrayHasKey('sirsoft-ecommerce', $result);
        $this->assertCount(2, $result['sirsoft-ecommerce']);

        // 게시판 그룹: board/list
        $this->assertArrayHasKey('sirsoft-board', $result);
        $this->assertCount(1, $result['sirsoft-board']);
    }

    /**
     * 수집된 선언이 없을 때 collectGroupedByExtension()이 빈 배열을 반환하는지 확인합니다.
     */
    public function test_collect_grouped_by_extension_returns_empty_when_no_declarations(): void
    {
        $this->templateManager
            ->shouldReceive('getActiveTemplate')
            ->with('user')
            ->once()
            ->andReturn(null);

        $result = $this->collector->collectGroupedByExtension();

        $this->assertEmpty($result);
    }

    // ─── collect(): 선언 구조 검증 ──────────────────────────────────

    /**
     * 수집된 선언의 구조가 올바른지 확인합니다.
     * 각 선언에는 layoutName, templateIdentifier, moduleIdentifier, seo, routePath가 포함되어야 합니다.
     */
    public function test_collect_declaration_has_correct_structure(): void
    {
        $this->setupActiveTemplateWithRoutes([
            ['path' => '/shop', 'layout' => 'sirsoft-ecommerce.shop/index', 'auth_required' => false],
        ]);

        $seoConfig = [
            'enabled' => true,
            'priority' => 0.9,
            'changefreq' => 'daily',
        ];

        $this->mockLayout('sirsoft-ecommerce.shop/index', [
            'meta' => ['seo' => $seoConfig],
            'components' => [],
        ]);

        $result = $this->collector->collect();

        $this->assertCount(1, $result);
        $declaration = $result[0];

        $this->assertArrayHasKey('layoutName', $declaration);
        $this->assertArrayHasKey('templateIdentifier', $declaration);
        $this->assertArrayHasKey('moduleIdentifier', $declaration);
        $this->assertArrayHasKey('pluginIdentifier', $declaration);
        $this->assertArrayHasKey('seo', $declaration);
        $this->assertArrayHasKey('routePath', $declaration);

        $this->assertEquals('shop/index', $declaration['layoutName']);
        $this->assertEquals('sirsoft-basic', $declaration['templateIdentifier']);
        $this->assertEquals('sirsoft-ecommerce', $declaration['moduleIdentifier']);
        $this->assertNull($declaration['pluginIdentifier']);
        $this->assertEquals($seoConfig, $declaration['seo']);
        $this->assertEquals('/shop', $declaration['routePath']);
    }

    // ─── collect(): 플러그인 레이아웃 (dotted notation) ──────────────────

    /**
     * 플러그인 dot notation에서 pluginIdentifier가 추출되는지 확인합니다.
     */
    public function test_collect_extracts_plugin_identifier_from_dotted_layout(): void
    {
        // 플러그인 판별 설정
        $this->pluginManager->shouldReceive('getPlugin')
            ->with('sirsoft-payment')
            ->andReturn(Mockery::mock(PluginInterface::class));

        $this->setupActiveTemplateWithRoutes([
            ['path' => '/payment/checkout', 'layout' => 'sirsoft-payment.checkout', 'auth_required' => false],
        ]);

        $this->mockLayout('sirsoft-payment.checkout', [
            'meta' => ['seo' => ['enabled' => true, 'priority' => 0.5]],
            'components' => [],
        ]);

        $result = $this->collector->collect();

        $this->assertCount(1, $result);
        $this->assertEquals('checkout', $result[0]['layoutName']);
        $this->assertNull($result[0]['moduleIdentifier']);
        $this->assertEquals('sirsoft-payment', $result[0]['pluginIdentifier']);
    }

    /**
     * collectGroupedByExtension()에서 플러그인 레이아웃이 별도 그룹 키로 분류됩니다.
     */
    public function test_collect_grouped_by_extension_includes_plugins(): void
    {
        // 플러그인 판별 설정
        $this->pluginManager->shouldReceive('getPlugin')
            ->with('sirsoft-payment')
            ->andReturn(Mockery::mock(PluginInterface::class));

        $this->setupActiveTemplateWithRoutes([
            ['path' => '/', 'layout' => 'home', 'auth_required' => false],
            ['path' => '/shop', 'layout' => 'sirsoft-ecommerce.shop/index', 'auth_required' => false],
            ['path' => '/payment', 'layout' => 'sirsoft-payment.checkout', 'auth_required' => false],
        ]);

        $this->mockLayout('home', [
            'meta' => ['seo' => ['enabled' => true]],
            'components' => [],
        ]);
        $this->mockLayout('sirsoft-ecommerce.shop/index', [
            'meta' => ['seo' => ['enabled' => true]],
            'components' => [],
        ]);
        $this->mockLayout('sirsoft-payment.checkout', [
            'meta' => ['seo' => ['enabled' => true]],
            'components' => [],
        ]);

        $result = $this->collector->collectGroupedByExtension();

        $this->assertArrayHasKey('core', $result);
        $this->assertCount(1, $result['core']);

        $this->assertArrayHasKey('sirsoft-ecommerce', $result);
        $this->assertCount(1, $result['sirsoft-ecommerce']);

        $this->assertArrayHasKey('sirsoft-payment', $result);
        $this->assertCount(1, $result['sirsoft-payment']);
    }
}
