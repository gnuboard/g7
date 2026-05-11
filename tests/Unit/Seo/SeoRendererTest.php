<?php

namespace Tests\Unit\Seo;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Extension\HookManager;
use App\Seo\ComponentHtmlMapper;
use App\Seo\DataSourceResolver;
use App\Seo\ExpressionEvaluator;
use App\Seo\SeoConfigMerger;
use App\Seo\SeoMetaResolver;
use App\Seo\SeoRenderer;
use App\Seo\TemplateRouteResolver;
use App\Services\LayoutService;
use App\Services\PluginSettingsService;
use App\Services\SettingsService;
use App\Services\TemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SeoRendererTest extends TestCase
{
    private TemplateRouteResolver|MockInterface $routeResolver;

    private LayoutService|MockInterface $layoutService;

    private TemplateService|MockInterface $templateService;

    private DataSourceResolver|MockInterface $dataSourceResolver;

    private SeoMetaResolver|MockInterface $metaResolver;

    private ComponentHtmlMapper|MockInterface $htmlMapper;

    private ExpressionEvaluator|MockInterface $evaluator;

    private SeoConfigMerger|MockInterface $seoConfigMerger;

    private SettingsService|MockInterface $settingsService;

    private PluginSettingsService|MockInterface $pluginSettingsService;

    private ModuleManagerInterface|MockInterface $moduleManager;

    private PluginManagerInterface|MockInterface $pluginManager;

    private SeoRenderer $renderer;

    /**
     * 테스트 초기화 - SeoRenderer와 모든 의존성 Mock을 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->routeResolver = Mockery::mock(TemplateRouteResolver::class);
        $this->layoutService = Mockery::mock(LayoutService::class);
        $this->templateService = Mockery::mock(TemplateService::class);
        $this->dataSourceResolver = Mockery::mock(DataSourceResolver::class);
        $this->metaResolver = Mockery::mock(SeoMetaResolver::class);
        $this->htmlMapper = Mockery::mock(ComponentHtmlMapper::class);
        $this->evaluator = Mockery::mock(ExpressionEvaluator::class);
        $this->seoConfigMerger = Mockery::mock(SeoConfigMerger::class);
        $this->settingsService = Mockery::mock(SettingsService::class);
        $this->pluginSettingsService = Mockery::mock(PluginSettingsService::class);
        $this->moduleManager = Mockery::mock(ModuleManagerInterface::class);
        $this->pluginManager = Mockery::mock(PluginManagerInterface::class);

        // 기본 SettingsService / PluginSettingsService mock
        $this->settingsService->shouldReceive('getFrontendSettings')
            ->andReturn([])
            ->byDefault();
        $this->pluginSettingsService->shouldReceive('getAllActiveSettings')
            ->andReturn([])
            ->byDefault();

        // 기본 metaResolver mock — resolveLocalizedValue 는 다국어 처리 흉내 (array → 첫 키 값)
        $this->metaResolver->shouldReceive('resolveLocalizedValue')
            ->andReturnUsing(function ($value) {
                if (is_string($value)) {
                    return $value;
                }
                if (is_array($value)) {
                    $locale = app()->getLocale();
                    return (string) ($value[$locale] ?? reset($value) ?? '');
                }
                return (string) ($value ?? '');
            })
            ->byDefault();

        // 기본 SeoConfigMerger mock (병합된 config 반환)
        $this->seoConfigMerger->shouldReceive('getMergedConfig')
            ->andReturn([])
            ->byDefault();

        // 기본 TemplateService mock (번역 로드 항상 성공)
        $this->templateService->shouldReceive('getLanguageDataWithModules')
            ->andReturn(['success' => true, 'data' => [], 'error' => null])
            ->byDefault();

        // 기본 evaluator mock (setTranslations, getPipeRegistry 허용)
        $this->evaluator->shouldReceive('setTranslations')
            ->byDefault();
        $pipeRegistry = Mockery::mock(\App\Seo\PipeRegistry::class);
        $pipeRegistry->shouldReceive('setLocale')->byDefault();
        $this->evaluator->shouldReceive('getPipeRegistry')
            ->andReturn($pipeRegistry)
            ->byDefault();
        $this->evaluator->shouldReceive('setSeoOverrides')
            ->byDefault();

        // 기본 htmlMapper mock (seo-config.json 로드 시 호출되는 설정 메서드 허용)
        $this->htmlMapper->shouldReceive('setComponentMap')->byDefault();
        $this->htmlMapper->shouldReceive('setRenderModes')->byDefault();
        $this->htmlMapper->shouldReceive('setSelfClosing')->byDefault();
        $this->htmlMapper->shouldReceive('setTextProps')->byDefault();
        $this->htmlMapper->shouldReceive('setAttrMap')->byDefault();
        $this->htmlMapper->shouldReceive('setAllowedAttrs')->byDefault();

        // 기본 htmlMapper mock (setGlobalResolver 허용 — navigate 링크 생성용)
        $this->htmlMapper->shouldReceive('setGlobalResolver')
            ->byDefault();

        // 기본 htmlMapper mock (setSeoVars 허용 — format 모드 변수 주입용)
        $this->htmlMapper->shouldReceive('setSeoVars')
            ->byDefault();

        $this->renderer = new SeoRenderer(
            $this->routeResolver,
            $this->layoutService,
            $this->templateService,
            $this->dataSourceResolver,
            $this->metaResolver,
            $this->htmlMapper,
            $this->evaluator,
            $this->seoConfigMerger,
            $this->settingsService,
            $this->pluginSettingsService,
            $this->moduleManager,
            $this->pluginManager,
        );
    }

    /**
     * 정상적인 상품 상세 페이지 렌더링 시 title, meta, body를 포함한 HTML을 반환합니다.
     */
    public function test_render_product_detail_returns_complete_html(): void
    {
        $request = Request::create('/products/123');

        $this->setupRouteResolver('/products/123', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '123'],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['product'],
            components: [['component' => 'Div', 'props' => ['text' => '상품 상세']]],
            toggleSetting: '$module_settings:seo.seo_product_detail',
        );

        $this->layoutService
            ->shouldReceive('getLayout')
            ->with('sirsoft-user_basic', 'shop/show')
            ->once()
            ->andReturn($mergedLayout);

        // 모듈 SEO 설정 활성화
        config()->set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product_detail', true);

        $this->dataSourceResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn(['product' => ['data' => ['name' => '에어맥스', 'price' => 129000]]]);

        $this->metaResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(
                title: '에어맥스',
                description: '에어맥스 상품 상세',
            ));

        $this->htmlMapper
            ->shouldReceive('render')
            ->once()
            ->andReturn('<div>상품 상세</div>');

        $expectedHtml = '<!DOCTYPE html><html><head><title>에어맥스</title></head><body><div>상품 상세</div></body></html>';

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn($expectedHtml);

        View::shouldReceive('make')
            ->with('seo', Mockery::on(function ($data) {
                return $data['title'] === '에어맥스'
                    && $data['description'] === '에어맥스 상품 상세'
                    && $data['bodyHtml'] === '<div>상품 상세</div>';
            }))
            ->once()
            ->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
        $this->assertStringContainsString('에어맥스', $result);
    }

    /**
     * meta.seo.enabled=false일 때 null을 반환합니다.
     */
    public function test_render_returns_null_when_seo_disabled(): void
    {
        $request = Request::create('/products/123');

        $this->setupRouteResolver('/products/123', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '123'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(seoEnabled: false);

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        $result = $this->renderer->render($request);

        $this->assertNull($result);
    }

    /**
     * 모듈 SEO가 비활성화된 경우(toggle_setting → false) null을 반환합니다.
     */
    public function test_render_returns_null_when_module_seo_disabled(): void
    {
        $request = Request::create('/products/123');

        $this->setupRouteResolver('/products/123', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '123'],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            toggleSetting: '$module_settings:seo.seo_product_detail',
        );

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        // 모듈 SEO 비활성화
        config()->set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product_detail', false);

        $result = $this->renderer->render($request);

        $this->assertNull($result);
    }

    /**
     * DataSource 실패 시 fallback 데이터로 렌더링합니다.
     */
    public function test_render_with_datasource_failure_renders_with_fallback(): void
    {
        $request = Request::create('/about');

        $this->setupRouteResolver('/about', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'about',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['site_info'],
            components: [['component' => 'Div']],
        );

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        // DataSource 빈 결과 반환 (실패 시 빈 배열)
        $this->dataSourceResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn([]);

        $this->metaResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: '회사 소개'));

        $this->htmlMapper
            ->shouldReceive('render')
            ->once()
            ->andReturn('<div>소개 페이지</div>');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>회사 소개</html>');

        View::shouldReceive('make')
            ->with('seo', Mockery::type('array'))
            ->once()
            ->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * URL에 매칭되는 레이아웃이 없으면 null을 반환합니다.
     */
    public function test_render_returns_null_when_no_layout_for_url(): void
    {
        $request = Request::create('/unknown-page');

        $this->routeResolver
            ->shouldReceive('resolve')
            ->with('/unknown-page')
            ->once()
            ->andReturn(null);

        $result = $this->renderer->render($request);

        $this->assertNull($result);
    }

    /**
     * structured_data가 있으면 JSON-LD가 포함됩니다.
     */
    public function test_render_includes_json_ld_when_structured_data_present(): void
    {
        $request = Request::create('/products/456');

        $this->setupRouteResolver('/products/456', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '456'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['product'],
            components: [],
        );

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        $this->dataSourceResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn(['product' => ['data' => ['name' => '신발']]]);

        $jsonLd = '<script type="application/ld+json">{"@type":"Product","name":"신발"}</script>';

        $this->metaResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: '신발', jsonLd: $jsonLd));

        $this->htmlMapper
            ->shouldReceive('render')
            ->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>'.$jsonLd.'</html>');

        View::shouldReceive('make')
            ->with('seo', Mockery::on(function ($data) use ($jsonLd) {
                return $data['jsonLd'] === $jsonLd;
            }))
            ->once()
            ->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
        $this->assertStringContainsString('application/ld+json', $result);
    }

    /**
     * OG 태그가 뷰 데이터에 포함됩니다.
     */
    public function test_render_includes_og_tags(): void
    {
        $request = Request::create('/products/789');

        $this->setupRouteResolver('/products/789', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '789'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(seoEnabled: true, components: []);

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        $ogTags = '<meta property="og:title" content="운동화">'."\n".'<meta property="og:type" content="product">';

        $this->metaResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: '운동화', ogTags: $ogTags));

        $this->htmlMapper
            ->shouldReceive('render')
            ->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>'.$ogTags.'</html>');

        View::shouldReceive('make')
            ->with('seo', Mockery::on(function ($data) {
                return str_contains($data['ogTags'], 'og:title');
            }))
            ->once()
            ->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
        $this->assertStringContainsString('og:title', $result);
    }

    /**
     * CSS 경로가 뷰 데이터에 포함됩니다.
     */
    public function test_render_includes_css_path(): void
    {
        $request = Request::create('/about');

        $this->setupRouteResolver('/about', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'about',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(seoEnabled: true, components: []);

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        $this->metaResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: '소개'));

        $this->htmlMapper
            ->shouldReceive('render')
            ->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html><link rel="stylesheet"></html>');

        View::shouldReceive('make')
            ->with('seo', Mockery::on(function ($data) {
                return isset($data['cssPath']) && is_string($data['cssPath']);
            }))
            ->once()
            ->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * seo.data_sources 필터링: 지정된 데이터소스만 호출됩니다.
     */
    public function test_render_filters_data_sources_by_seo_config(): void
    {
        $request = Request::create('/products/1');

        $this->setupRouteResolver('/products/1', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $allDataSources = [
            ['id' => 'product', 'endpoint' => '/api/products/{id}'],
            ['id' => 'reviews', 'endpoint' => '/api/products/{id}/reviews'],
            ['id' => 'recommendations', 'endpoint' => '/api/recommendations'],
        ];

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['product'],
            components: [],
        );
        $mergedLayout['data_sources'] = $allDataSources;

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        // seo.data_sources에 'product'만 지정 → resolve에 전달되는 seoDataSourceIds 확인
        $this->dataSourceResolver
            ->shouldReceive('resolve')
            ->with($allDataSources, ['product'], ['id' => '1'], Mockery::any(), Mockery::any())
            ->once()
            ->andReturn(['product' => ['data' => ['name' => '상품']]]);

        $this->metaResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: '상품'));

        $this->htmlMapper
            ->shouldReceive('render')
            ->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>상품</html>');

        View::shouldReceive('make')
            ->with('seo', Mockery::type('array'))
            ->once()
            ->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * 레이아웃 로드 실패 시 null을 반환합니다.
     */
    public function test_render_returns_null_when_layout_load_fails(): void
    {
        $request = Request::create('/products/1');

        $this->setupRouteResolver('/products/1', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andThrow(new \RuntimeException('Layout not found'));

        $result = $this->renderer->render($request);

        $this->assertNull($result);
    }

    // ========================================================
    // seo-config.json 로드 + setComponentMap/setRenderModes/setSelfClosing 검증
    // ========================================================

    /**
     * seo-config.json 존재 시 setComponentMap이 호출됩니다.
     */
    public function test_seo_config_loaded_and_component_map_set(): void
    {
        $this->runSeoConfigTest(
            config: [
                'component_map' => ['Div' => ['tag' => 'div'], 'Span' => ['tag' => 'span']],
            ],
            expectations: function () {
                $this->htmlMapper
                    ->shouldReceive('setComponentMap')
                    ->with(Mockery::on(fn ($map) => isset($map['Div']) && $map['Div']['tag'] === 'div'))
                    ->once();
                $this->htmlMapper->shouldReceive('setRenderModes')->never();
                $this->htmlMapper->shouldReceive('setSelfClosing')->never();
            },
        );
    }

    /**
     * seo-config.json 존재 시 setRenderModes가 호출됩니다.
     */
    public function test_seo_config_loaded_and_render_modes_set(): void
    {
        $this->runSeoConfigTest(
            config: [
                'render_modes' => [
                    'image_gallery' => ['type' => 'iterate', 'source' => '$props_source', 'item_tag' => 'img'],
                ],
            ],
            expectations: function () {
                $this->htmlMapper->shouldReceive('setComponentMap')->never();
                $this->htmlMapper
                    ->shouldReceive('setRenderModes')
                    ->with(Mockery::on(fn ($modes) => isset($modes['image_gallery']) && $modes['image_gallery']['type'] === 'iterate'))
                    ->once();
                $this->htmlMapper->shouldReceive('setSelfClosing')->never();
            },
        );
    }

    /**
     * seo-config.json 존재 시 setSelfClosing이 호출됩니다.
     */
    public function test_seo_config_loaded_and_self_closing_set(): void
    {
        $this->runSeoConfigTest(
            config: [
                'self_closing' => ['img', 'input', 'hr', 'br'],
            ],
            expectations: function () {
                $this->htmlMapper->shouldReceive('setComponentMap')->never();
                $this->htmlMapper->shouldReceive('setRenderModes')->never();
                $this->htmlMapper
                    ->shouldReceive('setSelfClosing')
                    ->with(['img', 'input', 'hr', 'br'])
                    ->once();
            },
        );
    }

    /**
     * seo-config.json의 stylesheets가 View에 전달됩니다.
     */
    public function test_stylesheets_passed_to_view(): void
    {
        $stylesheets = [
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
            '/custom/style.css',
        ];

        $this->runSeoConfigTest(
            config: [
                'stylesheets' => $stylesheets,
            ],
            expectations: function () {
                $this->htmlMapper->shouldReceive('setComponentMap')->never();
                $this->htmlMapper->shouldReceive('setRenderModes')->never();
                $this->htmlMapper->shouldReceive('setSelfClosing')->never();
            },
            viewAssert: function ($data) use ($stylesheets) {
                return $data['stylesheets'] === $stylesheets;
            },
        );
    }

    /**
     * seo-config.json 미존재 시 set* 메서드가 호출되지 않습니다.
     */
    public function test_missing_seo_config_no_calls(): void
    {
        // 존재하지 않는 templateIdentifier 사용 (파일 생성 안함)
        $request = Request::create('/about');

        $this->setupRouteResolver('/about', [
            'templateIdentifier' => 'nonexistent-template',
            'layoutName' => 'about',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(seoEnabled: true, components: []);

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->metaResolver->shouldReceive('resolve')->once()->andReturn($this->buildMetaResult(title: 'Test'));
        $this->htmlMapper->shouldReceive('render')->andReturn('');

        // set* 메서드가 호출되지 않아야 함
        $this->htmlMapper->shouldReceive('setComponentMap')->never();
        $this->htmlMapper->shouldReceive('setRenderModes')->never();
        $this->htmlMapper->shouldReceive('setSelfClosing')->never();

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>Test</html>');

        View::shouldReceive('make')
            ->with('seo', Mockery::on(fn ($data) => ($data['stylesheets'] ?? []) === []))
            ->once()
            ->andReturn($viewMock);

        $result = $this->renderer->render($request);
        $this->assertNotNull($result);
    }

    /**
     * template.json의 assets.css가 stylesheets에 자동 포함됩니다.
     */
    public function test_template_css_auto_included_in_stylesheets(): void
    {
        $templateId = 'test-template-css-'.uniqid();
        $configDir = base_path("templates/{$templateId}");

        if (! is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        // SeoConfigMerger mock으로 병합된 config 반환 (Font Awesome 포함)
        $this->seoConfigMerger->shouldReceive('getMergedConfig')
            ->with($templateId)
            ->andReturn([
                'stylesheets' => ['https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'],
            ]);

        // template.json 생성 (CSS 에셋 포함)
        file_put_contents("{$configDir}/template.json", json_encode([
            'identifier' => $templateId,
            'assets' => [
                'css' => ['dist/css/components.css'],
                'js' => [],
            ],
        ], JSON_PRETTY_PRINT));

        try {
            $request = Request::create('/test-css');

            $this->setupRouteResolver('/test-css', [
                'templateIdentifier' => $templateId,
                'layoutName' => 'test-css',
                'routeParams' => [],
                'moduleIdentifier' => null,
                'routeMeta' => [],
            ]);

            $mergedLayout = $this->buildMergedLayout(seoEnabled: true, components: []);

            $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
            $this->metaResolver->shouldReceive('resolve')->once()->andReturn($this->buildMetaResult(title: 'CSS Test'));
            $this->htmlMapper->shouldReceive('render')->andReturn('');
            $this->htmlMapper->shouldReceive('setComponentMap')->never();
            $this->htmlMapper->shouldReceive('setRenderModes')->never();
            $this->htmlMapper->shouldReceive('setSelfClosing')->never();

            $viewMock = Mockery::mock(\Illuminate\View\View::class);
            $viewMock->shouldReceive('render')->once()->andReturn('<html>CSS Test</html>');

            View::shouldReceive('make')
                ->with('seo', Mockery::on(function ($data) use ($templateId) {
                    $stylesheets = $data['stylesheets'] ?? [];

                    // 템플릿 CSS URL이 포함되어야 함
                    $hasTemplateCss = false;
                    $hasFontAwesome = false;
                    foreach ($stylesheets as $url) {
                        if (str_contains($url, "/api/templates/assets/{$templateId}/css/components.css")) {
                            $hasTemplateCss = true;
                        }
                        if (str_contains($url, 'font-awesome')) {
                            $hasFontAwesome = true;
                        }
                    }

                    return $hasTemplateCss && $hasFontAwesome;
                }))
                ->once()
                ->andReturn($viewMock);

            $result = $this->renderer->render($request);
            $this->assertNotNull($result);
        } finally {
            @unlink("{$configDir}/template.json");
            @rmdir($configDir);
        }
    }

    /**
     * seo-config.json이 빈 객체일 때 set* 메서드가 호출되지 않습니다.
     */
    public function test_empty_seo_config_no_calls(): void
    {
        $this->runSeoConfigTest(
            config: [],
            expectations: function () {
                $this->htmlMapper->shouldReceive('setComponentMap')->never();
                $this->htmlMapper->shouldReceive('setRenderModes')->never();
                $this->htmlMapper->shouldReceive('setSelfClosing')->never();
            },
        );
    }

    /**
     * seo-config.json 테스트를 위한 공통 헬퍼 메서드.
     * SeoConfigMerger mock을 설정하고 SeoRenderer.render()를 실행합니다.
     *
     * @param  array  $config  병합된 SEO config 내용
     * @param  \Closure  $expectations  htmlMapper mock 기대값 설정
     * @param  \Closure|null  $viewAssert  View::make 데이터 검증 (null이면 기본)
     */
    private function runSeoConfigTest(array $config, \Closure $expectations, ?\Closure $viewAssert = null): void
    {
        $templateId = 'test-seo-config-'.uniqid();

        // SeoConfigMerger mock으로 병합된 config 반환
        $this->seoConfigMerger->shouldReceive('getMergedConfig')
            ->with($templateId)
            ->andReturn($config);

        $request = Request::create('/test-page');

        $this->setupRouteResolver('/test-page', [
            'templateIdentifier' => $templateId,
            'layoutName' => 'test-page',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(seoEnabled: true, components: []);

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->metaResolver->shouldReceive('resolve')->once()->andReturn($this->buildMetaResult(title: 'Test'));
        $this->htmlMapper->shouldReceive('render')->andReturn('');

        // mock 기대값 설정
        $expectations();

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>Test</html>');

        if ($viewAssert) {
            View::shouldReceive('make')
                ->with('seo', Mockery::on($viewAssert))
                ->once()
                ->andReturn($viewMock);
        } else {
            View::shouldReceive('make')
                ->with('seo', Mockery::type('array'))
                ->once()
                ->andReturn($viewMock);
        }

        $result = $this->renderer->render($request);
        $this->assertNotNull($result);
    }

    /**
     * routeResolver에 URL을 전달하여 라우트 정보를 반환하도록 설정합니다.
     *
     * @param  string  $url  요청 URL
     * @param  array|null  $routeInfo  반환할 라우트 정보
     */
    private function setupRouteResolver(string $url, ?array $routeInfo): void
    {
        $this->routeResolver
            ->shouldReceive('resolve')
            ->with($url)
            ->once()
            ->andReturn($routeInfo);
    }

    /**
     * 테스트용 병합된 레이아웃을 빌드합니다.
     *
     * @param  bool  $seoEnabled  SEO 활성화 여부
     * @param  array  $seoDataSources  SEO 데이터소스 ID 목록
     * @param  array  $components  컴포넌트 배열
     * @return array 병합된 레이아웃 배열
     */
    private function buildMergedLayout(
        bool $seoEnabled = true,
        array $seoDataSources = [],
        array $components = [],
        ?string $toggleSetting = null,
        ?string $pageType = null,
        ?array $vars = null,
        ?array $initActions = null,
        ?array $structuredData = null,
        ?array $computed = null,
        ?array $extensions = null,
    ): array {
        $seo = [
            'enabled' => $seoEnabled,
            'data_sources' => $seoDataSources,
        ];

        if ($extensions !== null) {
            $seo['extensions'] = $extensions;
        }
        if ($toggleSetting !== null) {
            $seo['toggle_setting'] = $toggleSetting;
        }
        if ($pageType !== null) {
            $seo['page_type'] = $pageType;
        }
        if ($vars !== null) {
            $seo['vars'] = $vars;
        }
        if ($structuredData !== null) {
            $seo['structured_data'] = $structuredData;
        }

        $layout = [
            'meta' => [
                'seo' => $seo,
            ],
            'data_sources' => [],
            'components' => $components,
        ];

        if ($initActions !== null) {
            // LayoutService 병합 결과는 camelCase (initActions) 키를 사용
            $layout['initActions'] = $initActions;
        }

        if ($computed !== null) {
            $layout['computed'] = $computed;
        }

        return $layout;
    }

    /**
     * 테스트용 메타 결과 배열을 빌드합니다.
     *
     * @param  string  $title  타이틀
     * @param  string  $description  설명
     * @param  string  $ogTags  OG 태그 HTML
     * @param  string  $jsonLd  JSON-LD HTML
     * @return array 메타 결과 배열
     */
    private function buildMetaResult(
        string $title = '',
        string $description = '',
        string $ogTags = '',
        string $jsonLd = '',
        array $og = [],
        array $twitter = [],
        ?array $structuredData = null,
        string $twitterTags = '',
    ): array {
        return [
            'title' => $title,
            'titleSuffix' => '',
            'description' => $description,
            'keywords' => '',
            'og' => $og,
            'twitter' => $twitter,
            'structured_data' => $structuredData,
            'extraMetaTags' => [],
            'ogTags' => $ogTags,
            'twitterTags' => $twitterTags,
            'jsonLd' => $jsonLd,
            'googleAnalyticsId' => '',
            'googleVerification' => '',
            'naverVerification' => '',
        ];
    }

    // =========================================================================
    // toggle_setting + config 전달 테스트 (8개)
    // =========================================================================

    /**
     * toggle_setting이 모듈 설정 true이면 렌더링이 진행됩니다.
     */
    public function test_toggle_setting_module_enabled(): void
    {
        $request = Request::create('/products/1');

        $this->setupRouteResolver('/products/1', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['product'],
            components: [],
            toggleSetting: '$module_settings:seo.seo_product_detail',
        );

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);

        // 모듈 SEO 활성화
        config()->set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product_detail', true);

        $this->dataSourceResolver->shouldReceive('resolve')->once()->andReturn([]);
        $this->metaResolver->shouldReceive('resolve')->once()->andReturn($this->buildMetaResult(title: '상품'));
        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>상품</html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * toggle_setting 미선언 시 무조건 렌더링이 진행됩니다.
     */
    public function test_toggle_setting_not_declared_always_renders(): void
    {
        $request = Request::create('/about');

        $this->setupRouteResolver('/about', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'about',
            'routeParams' => [],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'routeMeta' => [],
        ]);

        // toggle_setting 미선언
        $mergedLayout = $this->buildMergedLayout(seoEnabled: true, components: []);

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->metaResolver->shouldReceive('resolve')->once()->andReturn($this->buildMetaResult(title: '소개'));
        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>소개</html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        // toggle_setting 없으므로 모듈 SEO 무조건 활성 → 렌더링
        $this->assertNotNull($result);
    }

    /**
     * toggle_setting이 $core_settings: 접두사일 때 코어 설정으로 판단합니다.
     */
    public function test_toggle_setting_core_settings(): void
    {
        $request = Request::create('/search');

        $this->setupRouteResolver('/search', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'search/index',
            'routeParams' => [],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            components: [],
            toggleSetting: '$core_settings:seo.seo_search',
        );

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);

        // 코어 SEO 비활성화
        config()->set('g7_settings.core.seo.seo_search', false);

        $result = $this->renderer->render($request);

        $this->assertNull($result);
    }

    /**
     * seo-config.json의 text_props가 setTextProps로 전달됩니다.
     */
    public function test_text_props_passed_to_mapper(): void
    {
        $this->runSeoConfigTest(
            config: [
                'text_props' => ['text', 'label', 'value', 'title'],
            ],
            expectations: function () {
                $this->htmlMapper->shouldReceive('setComponentMap')->never();
                $this->htmlMapper->shouldReceive('setRenderModes')->never();
                $this->htmlMapper->shouldReceive('setSelfClosing')->never();
                $this->htmlMapper
                    ->shouldReceive('setTextProps')
                    ->with(['text', 'label', 'value', 'title'])
                    ->once();
                $this->htmlMapper->shouldReceive('setAttrMap')->never();
                $this->htmlMapper->shouldReceive('setAllowedAttrs')->never();
            },
        );
    }

    /**
     * seo-config.json의 attr_map이 setAttrMap으로 전달됩니다.
     */
    public function test_attr_map_passed_to_mapper(): void
    {
        $this->runSeoConfigTest(
            config: [
                'attr_map' => ['className' => 'class', 'htmlFor' => 'for'],
            ],
            expectations: function () {
                $this->htmlMapper->shouldReceive('setComponentMap')->never();
                $this->htmlMapper->shouldReceive('setRenderModes')->never();
                $this->htmlMapper->shouldReceive('setSelfClosing')->never();
                $this->htmlMapper->shouldReceive('setTextProps')->never();
                $this->htmlMapper
                    ->shouldReceive('setAttrMap')
                    ->with(['className' => 'class', 'htmlFor' => 'for'])
                    ->once();
                $this->htmlMapper->shouldReceive('setAllowedAttrs')->never();
            },
        );
    }

    /**
     * seo-config.json의 allowed_attrs가 setAllowedAttrs로 전달됩니다.
     */
    public function test_allowed_attrs_passed_to_mapper(): void
    {
        $allowedAttrs = ['class', 'id', 'href', 'src', 'alt'];

        $this->runSeoConfigTest(
            config: [
                'allowed_attrs' => $allowedAttrs,
            ],
            expectations: function () use ($allowedAttrs) {
                $this->htmlMapper->shouldReceive('setComponentMap')->never();
                $this->htmlMapper->shouldReceive('setRenderModes')->never();
                $this->htmlMapper->shouldReceive('setSelfClosing')->never();
                $this->htmlMapper->shouldReceive('setTextProps')->never();
                $this->htmlMapper->shouldReceive('setAttrMap')->never();
                $this->htmlMapper
                    ->shouldReceive('setAllowedAttrs')
                    ->with($allowedAttrs)
                    ->once();
            },
        );
    }

    /**
     * seo-config.json에 text_props/attr_map/allowed_attrs가 없으면 set 메서드가 호출되지 않습니다.
     */
    public function test_missing_new_config_keys_no_calls(): void
    {
        $this->runSeoConfigTest(
            config: [
                'component_map' => ['Div' => ['tag' => 'div']],
            ],
            expectations: function () {
                $this->htmlMapper
                    ->shouldReceive('setComponentMap')
                    ->once();
                $this->htmlMapper->shouldReceive('setRenderModes')->never();
                $this->htmlMapper->shouldReceive('setSelfClosing')->never();
                $this->htmlMapper->shouldReceive('setTextProps')->never();
                $this->htmlMapper->shouldReceive('setAttrMap')->never();
                $this->htmlMapper->shouldReceive('setAllowedAttrs')->never();
            },
        );
    }

    // ========================================================
    // 다국어 SEO: hreflang + canonical 테스트
    // ========================================================

    /**
     * 기본 로케일(ko)에서 canonical URL에 ?locale 파라미터가 없는지 검증
     */
    public function test_canonical_url_no_locale_param_for_default_locale(): void
    {
        config(['app.locale' => 'ko', 'app.supported_locales' => ['ko', 'en']]);
        app()->setLocale('ko');

        $request = Request::create('/products/123');

        $this->setupRouteResolver('/products/123', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '123'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(seoEnabled: true, components: []);
        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->metaResolver->shouldReceive('resolve')->once()->andReturn($this->buildMetaResult(title: '상품'));
        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>상품</html>');

        View::shouldReceive('make')
            ->with('seo', Mockery::on(function ($data) {
                // canonical에 ?locale 없어야 함
                return ! str_contains($data['canonicalUrl'], '?locale')
                    && str_contains($data['canonicalUrl'], '/products/123');
            }))
            ->once()
            ->andReturn($viewMock);

        $result = $this->renderer->render($request);
        $this->assertNotNull($result);
    }

    /**
     * 비기본 로케일(en)에서 canonical URL에 ?locale=en이 포함되는지 검증
     */
    public function test_canonical_url_includes_locale_param_for_non_default(): void
    {
        config(['app.locale' => 'ko', 'app.supported_locales' => ['ko', 'en']]);
        app()->setLocale('en');

        $request = Request::create('/products/123');
        // SeoMiddleware가 setLocale 전에 저장하는 기본 로케일을 시뮬레이션
        $request->attributes->set('seo_default_locale', 'ko');

        $this->setupRouteResolver('/products/123', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '123'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(seoEnabled: true, components: []);
        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->metaResolver->shouldReceive('resolve')->once()->andReturn($this->buildMetaResult(title: 'Product'));
        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>Product</html>');

        View::shouldReceive('make')
            ->with('seo', Mockery::on(function ($data) {
                return str_contains($data['canonicalUrl'], '?locale=en');
            }))
            ->once()
            ->andReturn($viewMock);

        $result = $this->renderer->render($request);
        $this->assertNotNull($result);
    }

    /**
     * hreflang 태그에 모든 supported_locales가 포함되는지 검증
     */
    public function test_hreflang_tags_include_all_supported_locales(): void
    {
        config(['app.locale' => 'ko', 'app.supported_locales' => ['ko', 'en']]);
        app()->setLocale('ko');

        $request = Request::create('/products/123');

        $this->setupRouteResolver('/products/123', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '123'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(seoEnabled: true, components: []);
        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->metaResolver->shouldReceive('resolve')->once()->andReturn($this->buildMetaResult(title: '상품'));
        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>상품</html>');

        View::shouldReceive('make')
            ->with('seo', Mockery::on(function ($data) {
                $tags = $data['hreflangTags'] ?? '';

                // ko hreflang (기본 = 파라미터 없음)
                $hasKo = str_contains($tags, 'hreflang="ko"')
                    && str_contains($tags, '/products/123"');

                // en hreflang (?locale=en 포함)
                $hasEn = str_contains($tags, 'hreflang="en"')
                    && str_contains($tags, '?locale=en');

                // x-default (기본 로케일 URL)
                $hasDefault = str_contains($tags, 'hreflang="x-default"');

                return $hasKo && $hasEn && $hasDefault;
            }))
            ->once()
            ->andReturn($viewMock);

        $result = $this->renderer->render($request);
        $this->assertNotNull($result);
    }

    /**
     * 로케일 1개뿐이면 hreflang 태그가 빈 문자열인지 검증
     */
    public function test_hreflang_tags_empty_when_single_locale(): void
    {
        config(['app.locale' => 'ko', 'app.supported_locales' => ['ko']]);
        app()->setLocale('ko');

        $request = Request::create('/about');

        $this->setupRouteResolver('/about', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'about',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(seoEnabled: true, components: []);
        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->metaResolver->shouldReceive('resolve')->once()->andReturn($this->buildMetaResult(title: '소개'));
        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>소개</html>');

        View::shouldReceive('make')
            ->with('seo', Mockery::on(function ($data) {
                return ($data['hreflangTags'] ?? '') === '';
            }))
            ->once()
            ->andReturn($viewMock);

        $result = $this->renderer->render($request);
        $this->assertNotNull($result);
    }

    /**
     * vars가 레이아웃 seoConfig에 포함되어 metaResolver에 전달됩니다.
     */
    public function test_vars_passed_to_meta_resolver(): void
    {
        $request = Request::create('/products/1');

        $this->setupRouteResolver('/products/1', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'routeMeta' => [],
        ]);

        $vars = [
            'product_name' => "{{product.data.name ?? ''}}",
            'commerce_name' => '$module_settings:basic_info.shop_name',
        ];

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['product'],
            components: [],
            toggleSetting: '$module_settings:seo.seo_product_detail',
            pageType: 'product',
            vars: $vars,
        );

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        config()->set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product_detail', true);

        $this->dataSourceResolver->shouldReceive('resolve')->once()->andReturn([]);

        // resolveSeoVars에서 표현식 평가를 위해 evaluator.evaluate 허용
        $this->evaluator->shouldReceive('evaluate')
            ->andReturn('')
            ->byDefault();

        // setSeoVars가 해석된 vars로 호출되는지 검증
        config()->set('g7_settings.modules.sirsoft-ecommerce.basic_info.shop_name', '테스트 쇼핑몰');
        $this->htmlMapper
            ->shouldReceive('setSeoVars')
            ->with(Mockery::on(function ($resolved) {
                return isset($resolved['commerce_name'])
                    && $resolved['commerce_name'] === '테스트 쇼핑몰';
            }))
            ->once();

        // metaResolver에 vars가 포함된 seoConfig가 전달되는지 확인
        $this->metaResolver
            ->shouldReceive('resolve')
            ->with(
                Mockery::on(function ($seoConfig) use ($vars) {
                    return ($seoConfig['vars'] ?? []) === $vars
                        && ($seoConfig['page_type'] ?? '') === 'product';
                }),
                Mockery::type('array'),
                'sirsoft-ecommerce',
                null,
                Mockery::type('array')
            )
            ->once()
            ->andReturn($this->buildMetaResult(title: '상품'));

        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>상품</html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    // =========================================================================
    // meta.seo.vars → ComponentHtmlMapper.setSeoVars() 전달 테스트
    // =========================================================================

    /**
     * meta.seo.vars에 $core_settings: 변수가 선언되면 해석 후 setSeoVars()로 전달됩니다.
     */
    public function test_seo_vars_core_settings_passed_to_html_mapper(): void
    {
        $request = Request::create('/products');

        $this->setupRouteResolver('/products', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/index',
            'routeParams' => [],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: [],
            components: [['component' => 'Header', 'props' => []]],
            vars: [
                'site_name' => '$core_settings:general.site_name',
            ],
        );

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        // 코어 설정에서 사이트명 반환
        config()->set('g7_settings.core.general.site_name', 'My Store');

        // setSeoVars에 해석된 vars가 전달되는지 검증
        $this->htmlMapper
            ->shouldReceive('setSeoVars')
            ->with(Mockery::on(function ($vars) {
                return isset($vars['site_name'])
                    && $vars['site_name'] === 'My Store';
            }))
            ->once();

        $this->metaResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: 'Shop'));

        $this->htmlMapper->shouldReceive('render')->andReturn('<header>My Store</header>');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>Shop</html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * meta.seo.vars에 $module_settings: 변수가 선언되면 해석 후 setSeoVars()로 전달됩니다.
     */
    public function test_seo_vars_module_settings_passed_to_html_mapper(): void
    {
        $request = Request::create('/products');

        $this->setupRouteResolver('/products', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/index',
            'routeParams' => [],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: [],
            components: [['component' => 'Header', 'props' => []]],
            vars: [
                'commerce_name' => '$module_settings:basic_info.shop_name',
            ],
        );

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        // 모듈 설정에서 상점명 반환
        config()->set('g7_settings.modules.sirsoft-ecommerce.basic_info.shop_name', '그누보드7 쇼핑몰');

        // setSeoVars에 해석된 vars가 전달되는지 검증
        $this->htmlMapper
            ->shouldReceive('setSeoVars')
            ->with(Mockery::on(function ($vars) {
                return isset($vars['commerce_name'])
                    && $vars['commerce_name'] === '그누보드7 쇼핑몰';
            }))
            ->once();

        $this->metaResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: 'Shop'));

        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>Shop</html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * meta.seo.vars가 없으면 setSeoVars()가 호출되지 않습니다.
     */
    public function test_no_seo_vars_does_not_call_set_seo_vars(): void
    {
        $request = Request::create('/products');

        $this->setupRouteResolver('/products', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/index',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: [],
            components: [['component' => 'Header', 'props' => []]],
            // vars 미설정
        );

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        // setSeoVars가 호출되지 않아야 함
        $this->htmlMapper
            ->shouldReceive('setSeoVars')
            ->never();

        $this->metaResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: 'Shop'));

        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>Shop</html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    // =========================================================================
    // 플러그인 SEO 지원 테스트 (5개)
    // =========================================================================

    /**
     * 플러그인 toggle_setting이 false이면 null을 반환합니다.
     */
    public function test_render_returns_null_when_plugin_seo_disabled(): void
    {
        $request = Request::create('/payment/checkout');

        $this->setupRouteResolver('/payment/checkout', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'checkout',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'pluginIdentifier' => 'sirsoft-payment',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            toggleSetting: '$plugin_settings:seo.enabled',
        );

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        // 플러그인 SEO 비활성화
        config()->set('g7_settings.plugins.sirsoft-payment.seo.enabled', false);

        $result = $this->renderer->render($request);

        $this->assertNull($result);
    }

    /**
     * 플러그인 toggle_setting이 true이면 렌더링이 진행됩니다.
     */
    public function test_render_plugin_seo_enabled_with_toggle_setting(): void
    {
        $request = Request::create('/payment/checkout');

        $this->setupRouteResolver('/payment/checkout', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'checkout',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'pluginIdentifier' => 'sirsoft-payment',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: [],
            components: [['component' => 'Div', 'props' => ['text' => '결제']]],
            toggleSetting: '$plugin_settings:seo.enabled',
        );

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        // 플러그인 SEO 활성화
        config()->set('g7_settings.plugins.sirsoft-payment.seo.enabled', true);

        $this->metaResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: '결제'));

        $this->htmlMapper->shouldReceive('render')->andReturn('<div>결제</div>');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>결제</html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * $plugin_settings: 접두사 변수가 해석되어 setSeoVars()로 전달됩니다.
     */
    public function test_resolve_seo_vars_with_plugin_settings_prefix(): void
    {
        $request = Request::create('/payment/info');

        $this->setupRouteResolver('/payment/info', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'payment/info',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'pluginIdentifier' => 'sirsoft-payment',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: [],
            components: [['component' => 'Header', 'props' => []]],
            vars: [
                'payment_name' => '$plugin_settings:basic.payment_name',
            ],
        );

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        // 플러그인 설정값
        config()->set('g7_settings.plugins.sirsoft-payment.basic.payment_name', '간편결제');

        // setSeoVars에 해석된 vars가 전달되는지 검증
        $this->htmlMapper
            ->shouldReceive('setSeoVars')
            ->with(Mockery::on(function ($vars) {
                return isset($vars['payment_name'])
                    && $vars['payment_name'] === '간편결제';
            }))
            ->once();

        $this->metaResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: 'Payment'));

        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>Payment</html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * globalResolver가 플러그인 설정 패턴(기본값 포함)을 해석합니다.
     */
    public function test_global_resolver_handles_plugin_settings_with_default(): void
    {
        $request = Request::create('/payment/info');

        $this->setupRouteResolver('/payment/info', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'payment/info',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'pluginIdentifier' => 'sirsoft-payment',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: [],
            components: [['component' => 'Div', 'props' => []]],
        );

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        // 플러그인 설정 미존재 → 기본값 'payment' 사용
        config()->set('g7_settings.plugins.sirsoft-payment.basic_info.route_path', null);

        // setGlobalResolver 호출 시 콜백 캡처
        $capturedResolver = null;
        $this->htmlMapper
            ->shouldReceive('setGlobalResolver')
            ->with(Mockery::on(function ($callback) use (&$capturedResolver) {
                $capturedResolver = $callback;

                return true;
            }))
            ->once();

        $this->metaResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: 'Payment'));

        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>Payment</html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $this->renderer->render($request);

        // 캡처된 globalResolver로 플러그인 패턴 테스트
        $this->assertNotNull($capturedResolver);
        $result = $capturedResolver("_global.plugins?.['sirsoft-payment']?.basic_info?.route_path ?? 'payment'");
        $this->assertEquals('payment', $result);
    }

    /**
     * globalResolver가 플러그인 설정 패턴(기본값 없음)을 해석합니다.
     */
    public function test_global_resolver_handles_plugin_settings_without_default(): void
    {
        $request = Request::create('/payment/info');

        $this->setupRouteResolver('/payment/info', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'payment/info',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'pluginIdentifier' => 'sirsoft-payment',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: [],
            components: [['component' => 'Div', 'props' => []]],
        );

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        // 플러그인 설정 존재
        config()->set('g7_settings.plugins.sirsoft-payment.basic_info.name', '카카오페이');

        // setGlobalResolver 호출 시 콜백 캡처
        $capturedResolver = null;
        $this->htmlMapper
            ->shouldReceive('setGlobalResolver')
            ->with(Mockery::on(function ($callback) use (&$capturedResolver) {
                $capturedResolver = $callback;

                return true;
            }))
            ->once();

        $this->metaResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: 'Payment'));

        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>Payment</html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $this->renderer->render($request);

        // 캡처된 globalResolver로 플러그인 패턴 테스트 (기본값 없음)
        $this->assertNotNull($capturedResolver);
        $result = $capturedResolver("_global.plugins?.['sirsoft-payment']?.basic_info?.name");
        $this->assertEquals('카카오페이', $result);
    }

    // =========================================================================
    // 명시적 모듈 ID ($module_settings:MODULE_ID:key) 테스트
    // =========================================================================

    /**
     * 명시적 모듈 ID가 toggle_setting에서 올바르게 해석됩니다.
     * 템플릿 레벨 레이아웃(moduleIdentifier=null)에서도 모듈 설정을 참조합니다.
     */
    public function test_toggle_setting_explicit_module_id_when_module_identifier_null(): void
    {
        $request = Request::create('/shop');

        $this->setupRouteResolver('/shop', [
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'shop/index',
            'routeParams' => [],
            'moduleIdentifier' => null,  // 템플릿 레벨 레이아웃
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: [],
            components: [['component' => 'Div', 'props' => []]],
            toggleSetting: '$module_settings:sirsoft-ecommerce:seo.seo_index',
        );

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        // 명시적 모듈 ID로 설정 조회 — 활성화됨
        config()->set('g7_settings.modules.sirsoft-ecommerce.seo.seo_index', true);

        $this->dataSourceResolver
            ->shouldReceive('resolve')
            ->andReturn([]);

        $this->metaResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: '쇼핑몰'));

        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>쇼핑몰</html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * 명시적 모듈 ID toggle_setting이 false면 null을 반환합니다.
     */
    public function test_toggle_setting_explicit_module_id_disabled_returns_null(): void
    {
        $request = Request::create('/shop');

        $this->setupRouteResolver('/shop', [
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'shop/index',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: [],
            components: [],
            toggleSetting: '$module_settings:sirsoft-ecommerce:seo.seo_index',
        );

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        // 명시적 모듈 ID로 설정 조회 — 비활성화
        config()->set('g7_settings.modules.sirsoft-ecommerce.seo.seo_index', false);

        $result = $this->renderer->render($request);

        $this->assertNull($result);
    }

    /**
     * 명시적 모듈 ID가 resolveSeoVars에서 올바르게 해석됩니다.
     */
    public function test_resolve_seo_vars_explicit_module_id(): void
    {
        $request = Request::create('/shop');

        $this->setupRouteResolver('/shop', [
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'shop/index',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: [],
            components: [['component' => 'Div', 'props' => []]],
            vars: [
                'shopBase' => '$module_settings:sirsoft-ecommerce:basic_info.route_path',
                'commerce_name' => '$module_settings:sirsoft-ecommerce:basic_info.shop_name',
            ],
        );

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        config()->set('g7_settings.modules.sirsoft-ecommerce.basic_info.route_path', '/shop');
        config()->set('g7_settings.modules.sirsoft-ecommerce.basic_info.shop_name', '테스트몰');

        // setSeoVars에 명시적 모듈 ID로 해석된 값이 전달되는지 검증
        $this->htmlMapper
            ->shouldReceive('setSeoVars')
            ->with(Mockery::on(function ($vars) {
                return isset($vars['shopBase']) && $vars['shopBase'] === '/shop'
                    && isset($vars['commerce_name']) && $vars['commerce_name'] === '테스트몰';
            }))
            ->once();

        $this->metaResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: '쇼핑몰'));

        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>쇼핑몰</html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    // =========================================================================
    // _global 컨텍스트 주입 테스트
    // =========================================================================

    /**
     * render()에서 _global.settings에 프론트엔드 설정이 주입됩니다.
     */
    public function test_render_injects_global_settings_into_context(): void
    {
        $request = Request::create('/shop/products');

        $this->setupRouteResolver('/shop/products', [
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'shop/index',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $frontendSettings = [
            'general' => ['site_name' => 'My Store', 'site_logo_url' => '/logo.png'],
            'seo' => ['meta_title_suffix' => 'My Store'],
        ];

        // SettingsService가 프론트엔드 설정을 반환
        $this->settingsService->shouldReceive('getFrontendSettings')
            ->once()
            ->andReturn($frontendSettings);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['products'],
            components: [['component' => 'Div', 'props' => ['text' => '상품']]],
        );

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);

        $this->dataSourceResolver->shouldReceive('resolve')
            ->once()
            ->andReturn(['products' => ['data' => []]]);

        $this->metaResolver->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: '상품목록'));

        // htmlMapper.render에서 context의 _global.settings를 검증
        $this->htmlMapper->shouldReceive('render')
            ->with(Mockery::type('array'), Mockery::on(function ($context) use ($frontendSettings) {
                return isset($context['_global']['settings'])
                    && $context['_global']['settings'] === $frontendSettings;
            }), Mockery::type(ExpressionEvaluator::class))
            ->once()
            ->andReturn('<div>상품</div>');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>상품</html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * render()에서 _global.modules에 모듈 설정이 주입됩니다.
     */
    public function test_render_injects_global_modules_into_context(): void
    {
        $request = Request::create('/shop/products');

        $this->setupRouteResolver('/shop/products', [
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'shop/index',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $moduleSettings = [
            'sirsoft-ecommerce' => ['basic_info' => ['route_path' => 'shop']],
        ];
        config()->set('g7_settings.modules', $moduleSettings);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['products'],
            components: [['component' => 'Div', 'props' => ['text' => '상품']]],
        );

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);

        $this->dataSourceResolver->shouldReceive('resolve')
            ->once()
            ->andReturn(['products' => ['data' => []]]);

        $this->metaResolver->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: '상품목록'));

        // context의 _global.modules 검증
        $this->htmlMapper->shouldReceive('render')
            ->with(Mockery::type('array'), Mockery::on(function ($context) use ($moduleSettings) {
                return isset($context['_global']['modules'])
                    && $context['_global']['modules'] === $moduleSettings;
            }), Mockery::type(ExpressionEvaluator::class))
            ->once()
            ->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * render()에서 _global.plugins에 플러그인 설정이 주입됩니다.
     */
    public function test_render_injects_global_plugins_into_context(): void
    {
        $request = Request::create('/shop/products');

        $this->setupRouteResolver('/shop/products', [
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'shop/index',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $pluginSettings = [
            'sirsoft-payment' => ['is_test_mode' => true],
        ];
        $this->pluginSettingsService->shouldReceive('getAllActiveSettings')
            ->once()
            ->andReturn($pluginSettings);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['products'],
            components: [['component' => 'Div', 'props' => ['text' => '상품']]],
        );

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);

        $this->dataSourceResolver->shouldReceive('resolve')
            ->once()
            ->andReturn(['products' => ['data' => []]]);

        $this->metaResolver->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: '상품목록'));

        // context의 _global.plugins 검증
        $this->htmlMapper->shouldReceive('render')
            ->with(Mockery::type('array'), Mockery::on(function ($context) use ($pluginSettings) {
                return isset($context['_global']['plugins'])
                    && $context['_global']['plugins'] === $pluginSettings;
            }), Mockery::type(ExpressionEvaluator::class))
            ->once()
            ->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * render()에서 initGlobal 문자열 형식 데이터소스의 결과가 _global에 매핑됩니다.
     */
    public function test_render_applies_init_global_string_mapping(): void
    {
        $request = Request::create('/boards/free');

        $this->setupRouteResolver('/boards/free', [
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'boards/list',
            'routeParams' => ['slug' => 'free'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['boards'],
            components: [['component' => 'Div', 'props' => ['text' => '게시판']]],
        );
        // boards 데이터소스에 initGlobal 문자열 형식 설정
        $mergedLayout['data_sources'] = [
            ['id' => 'boards', 'endpoint' => '/api/boards/menu', 'method' => 'GET', 'initGlobal' => 'boardMenus'],
        ];

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);

        // boards API 응답
        $boardsResponse = ['data' => [['id' => 1, 'name' => '자유게시판'], ['id' => 2, 'name' => '공지사항']]];
        $this->dataSourceResolver->shouldReceive('resolve')
            ->once()
            ->andReturn(['boards' => $boardsResponse]);

        $this->metaResolver->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: '게시판'));

        // initGlobal 매핑 검증: _global.boardMenus = response.data
        $this->htmlMapper->shouldReceive('render')
            ->with(Mockery::type('array'), Mockery::on(function ($context) {
                return isset($context['_global']['boardMenus'])
                    && $context['_global']['boardMenus'] === [['id' => 1, 'name' => '자유게시판'], ['id' => 2, 'name' => '공지사항']];
            }), Mockery::type(ExpressionEvaluator::class))
            ->once()
            ->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * render()에서 initGlobal 객체 형식(key + path) 데이터소스의 결과가 _global에 매핑됩니다.
     */
    public function test_render_applies_init_global_object_mapping(): void
    {
        $request = Request::create('/shop/products');

        $this->setupRouteResolver('/shop/products', [
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'shop/index',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['cart'],
            components: [['component' => 'Div', 'props' => ['text' => '상품']]],
        );
        // cart 데이터소스에 initGlobal 객체 형식 설정
        $mergedLayout['data_sources'] = [
            ['id' => 'cart', 'endpoint' => '/api/cart/count', 'method' => 'GET', 'initGlobal' => ['key' => 'cartCount', 'path' => 'count']],
        ];

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);

        // cart API 응답
        $this->dataSourceResolver->shouldReceive('resolve')
            ->once()
            ->andReturn(['cart' => ['data' => ['count' => 5, 'items' => []]]]);

        $this->metaResolver->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: '상품목록'));

        // initGlobal 매핑 검증: _global.cartCount = response.data.count
        $this->htmlMapper->shouldReceive('render')
            ->with(Mockery::type('array'), Mockery::on(function ($context) {
                return isset($context['_global']['cartCount'])
                    && $context['_global']['cartCount'] === 5;
            }), Mockery::type(ExpressionEvaluator::class))
            ->once()
            ->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * render()에서 쿼리 파라미터가 DataSourceResolver에 전달됩니다.
     */
    public function test_render_passes_query_params_to_data_source_resolver(): void
    {
        $request = Request::create('/shop/products', 'GET', ['page' => '2', 'sort' => 'price_asc']);

        $this->setupRouteResolver('/shop/products', [
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'shop/index',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['products'],
            components: [],
        );
        $mergedLayout['data_sources'] = [
            ['id' => 'products', 'endpoint' => '/api/products', 'method' => 'GET'],
        ];

        $this->layoutService
            ->shouldReceive('getLayout')
            ->once()
            ->andReturn($mergedLayout);

        // DataSourceResolver에 쿼리 파라미터가 전달되는지 검증
        $this->dataSourceResolver
            ->shouldReceive('resolve')
            ->with(
                Mockery::type('array'),
                ['products'],
                [],
                Mockery::type('string'),
                Mockery::on(function ($queryParams) {
                    return isset($queryParams['page']) && $queryParams['page'] === '2'
                        && isset($queryParams['sort']) && $queryParams['sort'] === 'price_asc';
                })
            )
            ->once()
            ->andReturn(['products' => ['data' => []]]);

        $this->metaResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: '상품목록'));

        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>상품</html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    // =========================================================================
    // init_actions → _local 초기화 테스트
    // =========================================================================

    /**
     * init_actions의 setState(target: local)이 _local에 반영됩니다.
     * 쿼리 파라미터 기반 표현식({{query.tab ?? 'info'}})이 올바르게 평가됩니다.
     */
    public function test_render_resolves_init_actions_set_state_to_local(): void
    {
        $request = Request::create('/products/123', 'GET', ['tab' => 'reviews']);

        $this->setupRouteResolver('/products/123', [
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '123'],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['product'],
            components: [['component' => 'Div', 'props' => ['text' => '상품']]],
            initActions: [
                [
                    'handler' => 'loadFromLocalStorage',
                    'params' => ['key' => 'recentProductIds', 'defaultValue' => []],
                ],
                [
                    'handler' => 'setState',
                    'params' => [
                        'target' => 'local',
                        'activeTab' => '{{query.tab ?? \'info\'}}',
                        'reviewsPage' => 1,
                        'reviewPhotoOnly' => false,
                    ],
                ],
            ],
        );

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);

        config()->set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product_detail', true);

        $this->dataSourceResolver->shouldReceive('resolve')
            ->once()
            ->andReturn(['product' => ['data' => ['name' => '에어맥스']]]);

        $this->metaResolver->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: '에어맥스'));

        // ExpressionEvaluator는 실제 인스턴스를 사용하여 {{query.tab ?? 'info'}} 평가 검증
        // Mock이므로 evaluate 호출을 캡처
        $this->evaluator->shouldReceive('evaluate')
            ->with("{{query.tab ?? 'info'}}", Mockery::on(function ($context) {
                return isset($context['query']['tab']) && $context['query']['tab'] === 'reviews';
            }))
            ->andReturn('reviews');

        // setSeoOverrides 호출 허용
        $this->evaluator->shouldReceive('setSeoOverrides')->byDefault();

        // _local.activeTab이 'reviews'로 설정된 context가 htmlMapper.render에 전달되는지 검증
        $this->htmlMapper->shouldReceive('render')
            ->with(Mockery::type('array'), Mockery::on(function ($context) {
                return isset($context['_local']['activeTab'])
                    && $context['_local']['activeTab'] === 'reviews'
                    && $context['_local']['reviewsPage'] === 1
                    && $context['_local']['reviewPhotoOnly'] === false;
            }), Mockery::type(ExpressionEvaluator::class))
            ->once()
            ->andReturn('<div>리뷰 탭</div>');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>리뷰</html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * init_actions가 없는 레이아웃에서 _local이 빈 배열로 초기화됩니다.
     */
    public function test_render_returns_empty_local_when_no_init_actions(): void
    {
        $request = Request::create('/shop/products');

        $this->setupRouteResolver('/shop/products', [
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'shop/index',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['products'],
            components: [['component' => 'Div', 'props' => []]],
        );

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);

        $this->dataSourceResolver->shouldReceive('resolve')
            ->once()
            ->andReturn(['products' => ['data' => []]]);

        $this->metaResolver->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: '상품'));

        // _local이 빈 배열인지 검증
        $this->htmlMapper->shouldReceive('render')
            ->with(Mockery::type('array'), Mockery::on(function ($context) {
                return isset($context['_local']) && $context['_local'] === [];
            }), Mockery::type(ExpressionEvaluator::class))
            ->once()
            ->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * init_actions에서 target이 'global'인 setState는 _local에 반영되지 않습니다.
     */
    public function test_render_skips_global_target_set_state_for_local(): void
    {
        $request = Request::create('/products/123');

        $this->setupRouteResolver('/products/123', [
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '123'],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['product'],
            components: [['component' => 'Div', 'props' => []]],
            initActions: [
                [
                    'handler' => 'setState',
                    'params' => [
                        'target' => 'global',
                        'isNavigatingToCart' => false,
                    ],
                ],
                [
                    'handler' => 'setState',
                    'params' => [
                        'target' => 'local',
                        'activeTab' => 'info',
                    ],
                ],
            ],
        );

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);

        config()->set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product_detail', true);

        $this->dataSourceResolver->shouldReceive('resolve')
            ->once()
            ->andReturn(['product' => ['data' => ['name' => '에어맥스']]]);

        $this->metaResolver->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: '에어맥스'));

        // global setState의 키가 _local에 포함되지 않는지 검증
        $this->htmlMapper->shouldReceive('render')
            ->with(Mockery::type('array'), Mockery::on(function ($context) {
                return isset($context['_local']['activeTab'])
                    && $context['_local']['activeTab'] === 'info'
                    && ! array_key_exists('isNavigatingToCart', $context['_local']);
            }), Mockery::type(ExpressionEvaluator::class))
            ->once()
            ->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * init_actions에서 setState가 아닌 핸들러(loadFromLocalStorage, closeModal)는 무시됩니다.
     */
    public function test_render_ignores_non_set_state_handlers_in_init_actions(): void
    {
        $request = Request::create('/products/123');

        $this->setupRouteResolver('/products/123', [
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '123'],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['product'],
            components: [['component' => 'Div', 'props' => []]],
            initActions: [
                [
                    'handler' => 'loadFromLocalStorage',
                    'params' => ['key' => 'recentProductIds', 'defaultValue' => []],
                ],
                [
                    'handler' => 'closeModal',
                ],
                [
                    'handler' => 'setState',
                    'params' => [
                        'target' => 'local',
                        'reviewsPage' => 1,
                    ],
                ],
            ],
        );

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);

        config()->set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product_detail', true);

        $this->dataSourceResolver->shouldReceive('resolve')
            ->once()
            ->andReturn(['product' => ['data' => []]]);

        $this->metaResolver->shouldReceive('resolve')
            ->once()
            ->andReturn($this->buildMetaResult(title: '상품'));

        // loadFromLocalStorage, closeModal의 키가 _local에 없고, setState만 반영
        $this->htmlMapper->shouldReceive('render')
            ->with(Mockery::type('array'), Mockery::on(function ($context) {
                return $context['_local'] === ['reviewsPage' => 1]
                    && ! array_key_exists('key', $context['_local']);
            }), Mockery::type(ExpressionEvaluator::class))
            ->once()
            ->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    // =========================================================================
    // SEO 렌더러 훅 시스템 테스트 (core.seo.filter_context / filter_meta / filter_view_data)
    // =========================================================================

    /**
     * filter_context 훅: 리스너 미등록 시 기존 동작이 불변합니다.
     */
    public function test_filter_context_hook_no_listener_preserves_behavior(): void
    {
        HookManager::clearFilter('core.seo.filter_context');

        $request = Request::create('/products/1');

        $this->setupRouteResolver('/products/1', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['product'],
            components: [],
        );

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->dataSourceResolver->shouldReceive('resolve')->once()
            ->andReturn(['product' => ['data' => ['name' => '테스트상품']]]);
        $this->metaResolver->shouldReceive('resolve')->once()->andReturn($this->buildMetaResult(title: '테스트상품'));
        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html>테스트상품</html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * filter_context 훅: 리스너 등록 시 context가 변형되어 후속 단계에 반영됩니다.
     */
    public function test_filter_context_hook_modifies_context_for_meta_resolution(): void
    {
        HookManager::clearFilter('core.seo.filter_context');

        // 리뷰 플러그인이 reviews_aggregate를 context에 주입하는 시나리오
        HookManager::addFilter('core.seo.filter_context', function (array $context, array $hookMeta) {
            $context['reviews_aggregate'] = ['average' => 4.5, 'count' => 128];

            return $context;
        });

        $request = Request::create('/products/1');

        $this->setupRouteResolver('/products/1', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['product'],
            components: [],
        );

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->dataSourceResolver->shouldReceive('resolve')->once()
            ->andReturn(['product' => ['data' => ['name' => '에어맥스']]]);

        // metaResolver.resolve에 전달되는 context에 reviews_aggregate가 포함되어야 함
        $this->metaResolver->shouldReceive('resolve')
            ->once()
            ->with(
                Mockery::type('array'),
                Mockery::on(function ($context) {
                    return isset($context['reviews_aggregate'])
                        && $context['reviews_aggregate']['average'] === 4.5
                        && $context['reviews_aggregate']['count'] === 128;
                }),
                'sirsoft-ecommerce',
                null,
                Mockery::type('array')
            )
            ->andReturn($this->buildMetaResult(title: '에어맥스'));

        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);

        HookManager::clearFilter('core.seo.filter_context');
    }

    /**
     * filter_meta 훅: 리스너 미등록 시 기존 동작이 불변합니다.
     */
    public function test_filter_meta_hook_no_listener_preserves_behavior(): void
    {
        HookManager::clearFilter('core.seo.filter_meta');

        $request = Request::create('/products/1');

        $this->setupRouteResolver('/products/1', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(seoEnabled: true, components: []);
        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->dataSourceResolver->shouldReceive('resolve')->never();
        $this->metaResolver->shouldReceive('resolve')->once()
            ->andReturn($this->buildMetaResult(title: '원본 타이틀'));
        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')
            ->with('seo', Mockery::on(function ($data) {
                return $data['title'] === '원본 타이틀';
            }))
            ->once()
            ->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * filter_meta 훅: 리스너 등록 시 meta 값이 변형되어 View에 반영됩니다.
     */
    public function test_filter_meta_hook_modifies_meta_for_view(): void
    {
        HookManager::clearFilter('core.seo.filter_meta');

        // SEO 플러그인이 title suffix를 변경하는 시나리오
        HookManager::addFilter('core.seo.filter_meta', function (array $meta, array $hookMeta) {
            $meta['titleSuffix'] = ' | A/B Test Variant';
            $meta['description'] = '수정된 설명';

            return $meta;
        });

        $request = Request::create('/products/1');

        $this->setupRouteResolver('/products/1', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(seoEnabled: true, components: []);
        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->dataSourceResolver->shouldReceive('resolve')->never();
        $this->metaResolver->shouldReceive('resolve')->once()
            ->andReturn($this->buildMetaResult(title: '상품명', description: '원본 설명'));
        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')
            ->with('seo', Mockery::on(function ($data) {
                return $data['title'] === '상품명'
                    && $data['titleSuffix'] === ' | A/B Test Variant'
                    && $data['description'] === '수정된 설명';
            }))
            ->once()
            ->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);

        HookManager::clearFilter('core.seo.filter_meta');
    }

    /**
     * filter_view_data 훅: 리스너 미등록 시 기본 extraHeadTags/extraBodyEnd가 빈 문자열입니다.
     */
    public function test_filter_view_data_hook_no_listener_has_empty_extension_slots(): void
    {
        HookManager::clearFilter('core.seo.filter_view_data');

        $request = Request::create('/products/1');

        $this->setupRouteResolver('/products/1', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(seoEnabled: true, components: []);
        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->dataSourceResolver->shouldReceive('resolve')->never();
        $this->metaResolver->shouldReceive('resolve')->once()
            ->andReturn($this->buildMetaResult(title: '테스트'));
        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')
            ->with('seo', Mockery::on(function ($data) {
                return $data['extraHeadTags'] === ''
                    && $data['extraBodyEnd'] === '';
            }))
            ->once()
            ->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);
    }

    /**
     * filter_view_data 훅: 리스너 등록 시 extraHeadTags/extraBodyEnd에 값이 주입됩니다.
     */
    public function test_filter_view_data_hook_injects_extra_tags(): void
    {
        HookManager::clearFilter('core.seo.filter_view_data');

        // Analytics 플러그인이 추적 스크립트를 주입하는 시나리오
        $trackingScript = '<script src="https://analytics.example.com/track.js"></script>';
        $bodyWidget = '<div id="chat-widget"></div>';

        HookManager::addFilter('core.seo.filter_view_data', function (array $viewData, array $hookMeta) use ($trackingScript, $bodyWidget) {
            $viewData['extraHeadTags'] .= $trackingScript;
            $viewData['extraBodyEnd'] .= $bodyWidget;

            return $viewData;
        });

        $request = Request::create('/products/1');

        $this->setupRouteResolver('/products/1', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(seoEnabled: true, components: []);
        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->dataSourceResolver->shouldReceive('resolve')->never();
        $this->metaResolver->shouldReceive('resolve')->once()
            ->andReturn($this->buildMetaResult(title: '테스트'));
        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')
            ->with('seo', Mockery::on(function ($data) use ($trackingScript, $bodyWidget) {
                return $data['extraHeadTags'] === $trackingScript
                    && $data['extraBodyEnd'] === $bodyWidget;
            }))
            ->once()
            ->andReturn($viewMock);

        $result = $this->renderer->render($request);

        $this->assertNotNull($result);

        HookManager::clearFilter('core.seo.filter_view_data');
    }

    /**
     * filter_context 훅: hookMeta에 layoutName, moduleIdentifier 등 메타 정보가 전달됩니다.
     */
    public function test_filter_context_hook_receives_correct_meta(): void
    {
        HookManager::clearFilter('core.seo.filter_context');

        $receivedMeta = null;
        HookManager::addFilter('core.seo.filter_context', function (array $context, array $hookMeta) use (&$receivedMeta) {
            $receivedMeta = $hookMeta;

            return $context;
        });

        $request = Request::create('/products/1');

        $this->setupRouteResolver('/products/1', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'pluginIdentifier' => 'sirsoft-reviews',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(seoEnabled: true, components: []);
        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->dataSourceResolver->shouldReceive('resolve')->never();
        $this->metaResolver->shouldReceive('resolve')->once()
            ->andReturn($this->buildMetaResult(title: '테스트'));
        $this->htmlMapper->shouldReceive('render')->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $this->renderer->render($request);

        $this->assertNotNull($receivedMeta);
        $this->assertEquals('shop/show', $receivedMeta['layoutName']);
        $this->assertEquals('sirsoft-ecommerce', $receivedMeta['moduleIdentifier']);
        $this->assertEquals('sirsoft-reviews', $receivedMeta['pluginIdentifier']);
        $this->assertEquals(['id' => '1'], $receivedMeta['routeParams']);
        $this->assertArrayHasKey('locale', $receivedMeta);

        HookManager::clearFilter('core.seo.filter_context');
    }

    // =========================================================================
    // computed 속성 해석 테스트
    // =========================================================================

    /**
     * computed 문자열 표현식이 evaluateRaw를 통해 평가되고
     * _computed/$computed로 context에 전달됩니다.
     */
    public function test_computed_string_expression_evaluates_and_passes_to_context(): void
    {
        $request = Request::create('/products/1');

        $this->setupRouteResolver('/products/1', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            components: [['component' => 'Div']],
            computed: [
                'totalPrice' => '{{product.data.price * 2}}',
                'label' => 'static text',
            ],
        );

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->dataSourceResolver->shouldReceive('resolve')->never();
        $this->metaResolver->shouldReceive('resolve')->once()->andReturn($this->buildMetaResult());

        // evaluateRaw는 {{expr}} 표현식에 대해 호출됨
        $this->evaluator->shouldReceive('evaluateRaw')
            ->with('{{product.data.price * 2}}', Mockery::type('array'))
            ->once()
            ->andReturn(258000);

        // htmlMapper->render에 전달되는 context에서 _computed 확인
        $capturedContext = null;
        $this->htmlMapper->shouldReceive('render')
            ->once()
            ->withArgs(function ($components, $context) use (&$capturedContext) {
                $capturedContext = $context;

                return true;
            })
            ->andReturn('<div></div>');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $this->renderer->render($request);

        $this->assertNotNull($capturedContext);
        $this->assertArrayHasKey('_computed', $capturedContext);
        $this->assertArrayHasKey('$computed', $capturedContext);
        $this->assertEquals(258000, $capturedContext['_computed']['totalPrice']);
        $this->assertEquals('static text', $capturedContext['_computed']['label']);
        // $computed는 _computed의 별칭
        $this->assertEquals($capturedContext['_computed'], $capturedContext['$computed']);
    }

    /**
     * computed $switch 형식이 올바르게 해석됩니다.
     */
    public function test_computed_switch_resolves_matching_case(): void
    {
        $request = Request::create('/products/1');

        $this->setupRouteResolver('/products/1', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            components: [['component' => 'Div']],
            computed: [
                'badgeClass' => [
                    '$switch' => '{{product.data.status}}',
                    '$cases' => [
                        'active' => 'bg-green-100 text-green-800',
                        'sold_out' => 'bg-red-100 text-red-800',
                    ],
                    '$default' => 'bg-gray-100 text-gray-600',
                ],
            ],
        );

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->dataSourceResolver->shouldReceive('resolve')->never();
        $this->metaResolver->shouldReceive('resolve')->once()->andReturn($this->buildMetaResult());

        // $switch 표현식 평가 → 'active' 반환
        $this->evaluator->shouldReceive('evaluate')
            ->with('{{product.data.status}}', Mockery::type('array'))
            ->once()
            ->andReturn('active');

        $capturedContext = null;
        $this->htmlMapper->shouldReceive('render')
            ->once()
            ->withArgs(function ($components, $context) use (&$capturedContext) {
                $capturedContext = $context;

                return true;
            })
            ->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $this->renderer->render($request);

        $this->assertEquals('bg-green-100 text-green-800', $capturedContext['_computed']['badgeClass']);
    }

    /**
     * computed $switch에서 매칭 케이스가 없으면 $default를 반환합니다.
     */
    public function test_computed_switch_falls_back_to_default(): void
    {
        $request = Request::create('/products/1');

        $this->setupRouteResolver('/products/1', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            components: [['component' => 'Div']],
            computed: [
                'badgeClass' => [
                    '$switch' => '{{product.data.status}}',
                    '$cases' => [
                        'active' => 'bg-green-100',
                    ],
                    '$default' => 'bg-gray-100',
                ],
            ],
        );

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->dataSourceResolver->shouldReceive('resolve')->never();
        $this->metaResolver->shouldReceive('resolve')->once()->andReturn($this->buildMetaResult());

        // $switch 표현식이 'unknown_status'를 반환 → $cases에 없음
        $this->evaluator->shouldReceive('evaluate')
            ->with('{{product.data.status}}', Mockery::type('array'))
            ->once()
            ->andReturn('unknown_status');

        $capturedContext = null;
        $this->htmlMapper->shouldReceive('render')
            ->once()
            ->withArgs(function ($components, $context) use (&$capturedContext) {
                $capturedContext = $context;

                return true;
            })
            ->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $this->renderer->render($request);

        $this->assertEquals('bg-gray-100', $capturedContext['_computed']['badgeClass']);
    }

    /**
     * computed가 비어있으면 _computed가 context에 추가되지 않습니다.
     */
    public function test_empty_computed_does_not_add_to_context(): void
    {
        $request = Request::create('/products/1');

        $this->setupRouteResolver('/products/1', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        // computed 없는 레이아웃
        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            components: [['component' => 'Div']],
        );

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->dataSourceResolver->shouldReceive('resolve')->never();
        $this->metaResolver->shouldReceive('resolve')->once()->andReturn($this->buildMetaResult());

        $capturedContext = null;
        $this->htmlMapper->shouldReceive('render')
            ->once()
            ->withArgs(function ($components, $context) use (&$capturedContext) {
                $capturedContext = $context;

                return true;
            })
            ->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $this->renderer->render($request);

        $this->assertArrayNotHasKey('_computed', $capturedContext);
        $this->assertArrayNotHasKey('$computed', $capturedContext);
    }

    /**
     * computed에서 후속 항목이 이전 computed 결과를 참조할 수 있습니다.
     * (순차 평가: totalPrice → formattedTotal)
     */
    public function test_computed_sequential_reference(): void
    {
        $request = Request::create('/products/1');

        $this->setupRouteResolver('/products/1', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            components: [['component' => 'Div']],
            computed: [
                'totalPrice' => '{{product.data.price}}',
                'formattedTotal' => '{{_computed.totalPrice.toLocaleString()}}',
            ],
        );

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->dataSourceResolver->shouldReceive('resolve')->never();
        $this->metaResolver->shouldReceive('resolve')->once()->andReturn($this->buildMetaResult());

        // 첫 번째 computed 평가 — context에 아직 _computed 없음
        $this->evaluator->shouldReceive('evaluateRaw')
            ->with('{{product.data.price}}', Mockery::on(function ($ctx) {
                return ! isset($ctx['_computed']['totalPrice']);
            }))
            ->once()
            ->andReturn(129000);

        // 두 번째 computed 평가 — context._computed.totalPrice가 존재해야 함
        $this->evaluator->shouldReceive('evaluateRaw')
            ->with('{{_computed.totalPrice.toLocaleString()}}', Mockery::on(function ($ctx) {
                return isset($ctx['_computed']['totalPrice']) && $ctx['_computed']['totalPrice'] === 129000;
            }))
            ->once()
            ->andReturn('129,000');

        $capturedContext = null;
        $this->htmlMapper->shouldReceive('render')
            ->once()
            ->withArgs(function ($components, $context) use (&$capturedContext) {
                $capturedContext = $context;

                return true;
            })
            ->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $this->renderer->render($request);

        $this->assertEquals(129000, $capturedContext['_computed']['totalPrice']);
        $this->assertEquals('129,000', $capturedContext['_computed']['formattedTotal']);
    }

    /**
     * computed 표현식 평가 실패 시 null이 설정되고 렌더링이 계속됩니다.
     */
    public function test_computed_evaluation_failure_sets_null(): void
    {
        $request = Request::create('/products/1');

        $this->setupRouteResolver('/products/1', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            components: [['component' => 'Div']],
            computed: [
                'broken' => '{{invalid.reduce((a, b) => a + b)}}',
                'valid' => 'still works',
            ],
        );

        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);
        $this->dataSourceResolver->shouldReceive('resolve')->never();
        $this->metaResolver->shouldReceive('resolve')->once()->andReturn($this->buildMetaResult());

        // 첫 번째 computed는 예외 발생
        $this->evaluator->shouldReceive('evaluateRaw')
            ->with('{{invalid.reduce((a, b) => a + b)}}', Mockery::type('array'))
            ->once()
            ->andThrow(new \RuntimeException('Evaluation failed'));

        $capturedContext = null;
        $this->htmlMapper->shouldReceive('render')
            ->once()
            ->withArgs(function ($components, $context) use (&$capturedContext) {
                $capturedContext = $context;

                return true;
            })
            ->andReturn('');

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn('<html></html>');
        View::shouldReceive('make')->with('seo', Mockery::type('array'))->once()->andReturn($viewMock);

        $this->renderer->render($request);

        // 실패한 computed는 null, 성공한 computed는 정상
        $this->assertNull($capturedContext['_computed']['broken']);
        $this->assertEquals('still works', $capturedContext['_computed']['valid']);
    }

    // =========================================================================
    // _seo context 주입 테스트 (extensions 기반)
    // =========================================================================

    /**
     * 상품 상세 페이지에서 extensions + seoVariables()로 _seo.product context가 주입됩니다.
     */
    public function test_seo_context_injected_for_product_page(): void
    {
        $request = Request::create('/shop/products/1');
        $this->setupRouteResolver('/shop/products/1', [
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['product'],
            pageType: 'product',
            extensions: [['type' => 'module', 'id' => 'sirsoft-ecommerce']],
            vars: [
                'product_name' => '{{product.data.name}}',
                'product_description' => '{{product.data.description}}',
            ],
        );
        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);

        // 모듈 인스턴스 mock
        $moduleMock = Mockery::mock(\App\Extension\AbstractModule::class)->makePartial();
        $moduleMock->shouldReceive('seoVariables')->andReturn([
            '_common' => [
                'commerce_name' => ['source' => 'setting', 'key' => 'basic_info.shop_name'],
            ],
            'product' => [
                'product_name' => ['source' => 'data', 'required' => true],
                'product_description' => ['source' => 'data'],
            ],
        ]);
        $this->moduleManager->shouldReceive('getModule')
            ->with('sirsoft-ecommerce')
            ->andReturn($moduleMock);

        // 설정값 mock
        config()->set('g7_settings.modules.sirsoft-ecommerce.basic_info.shop_name', '테스트쇼핑몰');
        config()->set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', '{commerce_name} - {product_name}');
        config()->set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_description', '{product_description}');

        $this->dataSourceResolver->shouldReceive('resolve')->once()->andReturn([
            'product' => ['data' => ['name' => '에어맥스', 'description' => '나이키 에어맥스 상품']],
        ]);

        // vars 해석 시 evaluator 호출
        $this->evaluator->shouldReceive('evaluate')
            ->with('{{product.data.name}}', Mockery::any())
            ->andReturn('에어맥스');
        $this->evaluator->shouldReceive('evaluate')
            ->with('{{product.data.description}}', Mockery::any())
            ->andReturn('나이키 에어맥스 상품');

        // _seo context 캡처
        $capturedContext = null;
        $this->metaResolver->shouldReceive('resolve')
            ->once()
            ->withArgs(function ($seoConfig, $context) use (&$capturedContext) {
                $capturedContext = $context;

                return true;
            })
            ->andReturn($this->buildMetaResult(title: '테스트쇼핑몰 - 에어맥스'));

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->andReturn('<html></html>');
        View::shouldReceive('make')->once()->andReturn($viewMock);

        $this->renderer->render($request);

        $this->assertNotNull($capturedContext);
        $this->assertArrayHasKey('_seo', $capturedContext);
        $this->assertArrayHasKey('product', $capturedContext['_seo']);
        $this->assertEquals('테스트쇼핑몰 - 에어맥스', $capturedContext['_seo']['product']['title']);
        $this->assertEquals('나이키 에어맥스 상품', $capturedContext['_seo']['product']['description']);
    }

    /**
     * 카테고리 목록 페이지에서 _seo.category context가 주입됩니다.
     */
    public function test_seo_context_injected_for_category_page(): void
    {
        $request = Request::create('/shop/category/shoes');
        $this->setupRouteResolver('/shop/category/shoes', [
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'shop/category',
            'routeParams' => ['slug' => 'shoes'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['category'],
            pageType: 'category',
            extensions: [['type' => 'module', 'id' => 'sirsoft-ecommerce']],
            vars: [
                'category_name' => '{{category.data.name}}',
                'category_description' => '{{category.data.description}}',
            ],
        );
        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);

        $moduleMock = Mockery::mock(\App\Extension\AbstractModule::class)->makePartial();
        $moduleMock->shouldReceive('seoVariables')->andReturn([
            '_common' => [
                'commerce_name' => ['source' => 'setting', 'key' => 'basic_info.shop_name'],
            ],
            'category' => [
                'category_name' => ['source' => 'data', 'required' => true],
                'category_description' => ['source' => 'data'],
            ],
        ]);
        $this->moduleManager->shouldReceive('getModule')
            ->with('sirsoft-ecommerce')
            ->andReturn($moduleMock);

        config()->set('g7_settings.modules.sirsoft-ecommerce.basic_info.shop_name', '테스트쇼핑몰');
        config()->set('g7_settings.modules.sirsoft-ecommerce.seo.meta_category_title', '{commerce_name} - {category_name}');
        config()->set('g7_settings.modules.sirsoft-ecommerce.seo.meta_category_description', '{category_description}');

        $this->dataSourceResolver->shouldReceive('resolve')->once()->andReturn([
            'category' => ['data' => ['name' => '신발', 'description' => '신발 카테고리']],
        ]);

        $this->evaluator->shouldReceive('evaluate')
            ->with('{{category.data.name}}', Mockery::any())
            ->andReturn('신발');
        $this->evaluator->shouldReceive('evaluate')
            ->with('{{category.data.description}}', Mockery::any())
            ->andReturn('신발 카테고리');

        $capturedContext = null;
        $this->metaResolver->shouldReceive('resolve')
            ->once()
            ->withArgs(function ($seoConfig, $context) use (&$capturedContext) {
                $capturedContext = $context;

                return true;
            })
            ->andReturn($this->buildMetaResult(title: '테스트쇼핑몰 - 신발'));

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->andReturn('<html></html>');
        View::shouldReceive('make')->once()->andReturn($viewMock);

        $this->renderer->render($request);

        $this->assertNotNull($capturedContext);
        $this->assertEquals('테스트쇼핑몰 - 신발', $capturedContext['_seo']['category']['title']);
        $this->assertEquals('신발 카테고리', $capturedContext['_seo']['category']['description']);
    }

    /**
     * 검색결과 페이지에서 _seo.search context가 주입됩니다 (query source 변수 포함).
     */
    public function test_seo_context_injected_for_search_page(): void
    {
        $request = Request::create('/search', 'GET', ['q' => '운동화']);
        // request() 헬퍼가 이 request를 반환하도록 바인딩
        app()->instance('request', $request);

        $this->setupRouteResolver('/search', [
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'search/index',
            'routeParams' => [],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: [],
            pageType: 'search',
            extensions: [['type' => 'module', 'id' => 'sirsoft-ecommerce']],
        );
        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);

        $moduleMock = Mockery::mock(\App\Extension\AbstractModule::class)->makePartial();
        $moduleMock->shouldReceive('seoVariables')->andReturn([
            '_common' => [
                'commerce_name' => ['source' => 'setting', 'key' => 'basic_info.shop_name'],
            ],
            'search' => [
                'keyword_name' => ['source' => 'query', 'key' => 'q'],
            ],
        ]);
        $this->moduleManager->shouldReceive('getModule')
            ->with('sirsoft-ecommerce')
            ->andReturn($moduleMock);

        config()->set('g7_settings.modules.sirsoft-ecommerce.basic_info.shop_name', '테스트쇼핑몰');
        config()->set('g7_settings.modules.sirsoft-ecommerce.seo.meta_search_title', '{commerce_name} - {keyword_name}');
        config()->set('g7_settings.modules.sirsoft-ecommerce.seo.meta_search_description', '');

        $this->dataSourceResolver->shouldReceive('resolve')->never();

        $capturedContext = null;
        $this->metaResolver->shouldReceive('resolve')
            ->once()
            ->withArgs(function ($seoConfig, $context) use (&$capturedContext) {
                $capturedContext = $context;

                return true;
            })
            ->andReturn($this->buildMetaResult(title: '테스트쇼핑몰 - 운동화'));

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->andReturn('<html></html>');
        View::shouldReceive('make')->once()->andReturn($viewMock);

        $this->renderer->render($request);

        $this->assertNotNull($capturedContext);
        $this->assertEquals('테스트쇼핑몰 - 운동화', $capturedContext['_seo']['search']['title']);
    }

    /**
     * 설정 템플릿 값이 null인 경우에도 TypeError 없이 정상 동작합니다.
     */
    public function test_seo_context_handles_null_settings_template(): void
    {
        $request = Request::create('/shop/products/1');
        $this->setupRouteResolver('/shop/products/1', [
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['product'],
            pageType: 'product',
            extensions: [['type' => 'module', 'id' => 'sirsoft-ecommerce']],
            vars: [
                'product_name' => '{{product.data.name}}',
            ],
        );
        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);

        $moduleMock = Mockery::mock(\App\Extension\AbstractModule::class)->makePartial();
        $moduleMock->shouldReceive('seoVariables')->andReturn([
            'product' => [
                'product_name' => ['source' => 'data', 'required' => true],
            ],
        ]);
        $this->moduleManager->shouldReceive('getModule')
            ->with('sirsoft-ecommerce')
            ->andReturn($moduleMock);

        // 설정값을 null로 설정 (미구성 상태 재현)
        config()->set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_title', null);
        config()->set('g7_settings.modules.sirsoft-ecommerce.seo.meta_product_description', null);

        $this->dataSourceResolver->shouldReceive('resolve')->once()->andReturn([
            'product' => ['data' => ['name' => '에어맥스']],
        ]);

        $this->evaluator->shouldReceive('evaluate')
            ->with('{{product.data.name}}', Mockery::any())
            ->andReturn('에어맥스');

        $capturedContext = null;
        $this->metaResolver->shouldReceive('resolve')
            ->once()
            ->withArgs(function ($seoConfig, $context) use (&$capturedContext) {
                $capturedContext = $context;

                return true;
            })
            ->andReturn($this->buildMetaResult(title: '에어맥스'));

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->andReturn('<html></html>');
        View::shouldReceive('make')->once()->andReturn($viewMock);

        // TypeError 없이 정상 실행되어야 함
        $result = $this->renderer->render($request);
        $this->assertNotNull($result);

        // null 템플릿 → _seo context에 주입되지 않음 (빈 문자열이므로)
        $this->assertNotNull($capturedContext);
        $this->assertArrayNotHasKey('_seo', $capturedContext);
    }

    /**
     * extensions 미선언 시 _seo context가 주입되지 않습니다 (하위호환).
     */
    public function test_seo_context_not_injected_without_extensions(): void
    {
        $request = Request::create('/products/1');
        $this->setupRouteResolver('/products/1', [
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '1'],
            'moduleIdentifier' => 'sirsoft-ecommerce',
            'routeMeta' => [],
        ]);

        $mergedLayout = $this->buildMergedLayout(
            seoEnabled: true,
            seoDataSources: ['product'],
            pageType: 'product',
            toggleSetting: '$module_settings:seo.seo_product_detail',
        );
        $this->layoutService->shouldReceive('getLayout')->once()->andReturn($mergedLayout);

        config()->set('g7_settings.modules.sirsoft-ecommerce.seo.seo_product_detail', true);

        $this->dataSourceResolver->shouldReceive('resolve')->once()->andReturn([
            'product' => ['data' => ['name' => '에어맥스']],
        ]);

        $capturedContext = null;
        $this->metaResolver->shouldReceive('resolve')
            ->once()
            ->withArgs(function ($seoConfig, $context) use (&$capturedContext) {
                $capturedContext = $context;

                return true;
            })
            ->andReturn($this->buildMetaResult(title: '에어맥스'));

        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->andReturn('<html></html>');
        View::shouldReceive('make')->once()->andReturn($viewMock);

        $this->renderer->render($request);

        $this->assertNotNull($capturedContext);
        $this->assertArrayNotHasKey('_seo', $capturedContext);
    }

    /**
     * 회귀: filter_og_data 청취자가 og 배열을 수정하면 SeoRenderer 가
     * renderOgHtml 을 다시 호출하여 최종 ogTags 에 반영됨.
     *
     * 검증 포인트: hook 적용 → 변경 감지 → 재렌더 호출 흐름.
     * (실제 HTML 출력은 SeoMetaResolverTest 가 단위 레벨로 커버)
     */
    public function test_filter_og_data_hook_triggers_rerender_via_metaresolver(): void
    {
        $request = Request::create('/products/99');

        $this->setupRouteResolver('/products/99', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '99'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);
        $this->layoutService->shouldReceive('getLayout')->once()->andReturn(
            $this->buildMergedLayout(seoEnabled: true, pageType: 'product')
        );
        $this->dataSourceResolver->shouldReceive('resolve')->andReturn([]);

        // resolve() 가 og 배열 포함 결과 반환
        $initialOg = [
            'type' => 'product',
            'title' => 'p',
            'image' => 'https://e.co/p.jpg',
            'image_width' => 800,
            'site_name' => 'TestShop',
        ];
        $this->metaResolver->shouldReceive('resolve')->once()->andReturn(
            $this->buildMetaResult(
                title: 'p',
                ogTags: '<initial>',
                og: $initialOg,
            )
        );

        // 청취자: image_width 1200 으로 변경
        HookManager::addFilter('core.seo.filter_og_data', function (array $og) {
            $og['image_width'] = 1200;
            return $og;
        });

        // hook 변경 후 SeoRenderer 가 renderOgHtml 을 다시 호출해야 함
        $this->metaResolver->shouldReceive('renderOgHtml')
            ->once()
            ->with(Mockery::on(fn ($og) => ($og['image_width'] ?? null) === 1200))
            ->andReturn('<post-hook-og>');

        $this->htmlMapper->shouldReceive('render')->andReturn('');
        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->andReturn('<html></html>');
        View::shouldReceive('make')
            ->with('seo', Mockery::on(fn ($d) => str_contains($d['ogTags'], '<post-hook-og>')))
            ->once()
            ->andReturn($viewMock);

        $result = $this->renderer->render($request);
        $this->assertNotNull($result);

        HookManager::resetAll();
    }

    /**
     * 회귀: filter_structured_data 청취자가 JSON-LD 배열을 수정하면 재렌더.
     */
    public function test_filter_structured_data_hook_triggers_rerender_via_metaresolver(): void
    {
        $request = Request::create('/posts/42');

        $this->setupRouteResolver('/posts/42', [
            'templateIdentifier' => 'sirsoft-user_basic',
            'layoutName' => 'board/show',
            'routeParams' => ['id' => '42'],
            'moduleIdentifier' => null,
            'routeMeta' => [],
        ]);
        $this->layoutService->shouldReceive('getLayout')->once()->andReturn(
            $this->buildMergedLayout(seoEnabled: true, pageType: 'post')
        );
        $this->dataSourceResolver->shouldReceive('resolve')->andReturn([]);

        $initialSchema = ['@type' => 'Article', 'headline' => 'h'];
        $this->metaResolver->shouldReceive('resolve')->once()->andReturn(
            $this->buildMetaResult(
                title: 'h',
                jsonLd: '{"@type":"Article"}',
                structuredData: $initialSchema,
            )
        );

        // 청취자: review 배열 주입
        HookManager::addFilter('core.seo.filter_structured_data', function (?array $sd) {
            if ($sd === null) return $sd;
            $sd['review'] = [['@type' => 'Review', 'reviewBody' => 'Great!']];
            return $sd;
        });

        $this->metaResolver->shouldReceive('renderStructuredJson')
            ->once()
            ->with(Mockery::on(fn ($sd) => isset($sd['review']) && count($sd['review']) === 1))
            ->andReturn('{"@type":"Article","review":[...]}');

        $this->htmlMapper->shouldReceive('render')->andReturn('');
        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->andReturn('<html></html>');
        View::shouldReceive('make')->once()->andReturn($viewMock);

        $this->renderer->render($request);

        HookManager::resetAll();
    }
}
