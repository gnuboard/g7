<?php

namespace Tests\Unit\Seo;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Seo\ComponentHtmlMapper;
use App\Seo\DataSourceResolver;
use App\Seo\ExpressionEvaluator;
use App\Seo\PipeRegistry;
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

/**
 * SeoRenderer::renderFromResolved 추출 회귀 가드.
 *
 * render(Request) 는 URL 매핑·data_sources fetch·_global/_local/computed 해석까지 마친 뒤
 * 공통 파이프라인(renderFromResolved)에 위임하는 얇은 래퍼다. 본 테스트는 그 추출 계약을
 * 잠근다 — ① render() 가 해석한 컨텍스트로 renderFromResolved 를 호출, ② renderFromResolved
 * 가 이미 해석된 컨텍스트로 동일 HTML 을 생성, ③ enabled 게이트 일관(편집기 봇 미리보기가
 * 직접 호출해도 enabled=false → null), ④ defaultLocale 파라미터 폴백. render() 운영 경로의
 * byte 동등성은 SeoRendererTest(70+) + SEO 전체 스위트가 별도로 잠근다.
 */
class SeoRendererRenderFromResolvedTest extends TestCase
{
    private TemplateRouteResolver|MockInterface $routeResolver;

    private LayoutService|MockInterface $layoutService;

    private DataSourceResolver|MockInterface $dataSourceResolver;

    private SeoMetaResolver|MockInterface $metaResolver;

    private ComponentHtmlMapper|MockInterface $htmlMapper;

    private ExpressionEvaluator|MockInterface $evaluator;

    private SeoConfigMerger|MockInterface $seoConfigMerger;

    private SeoRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->routeResolver = Mockery::mock(TemplateRouteResolver::class);
        $this->layoutService = Mockery::mock(LayoutService::class);
        $this->dataSourceResolver = Mockery::mock(DataSourceResolver::class);
        $this->metaResolver = Mockery::mock(SeoMetaResolver::class);
        $this->htmlMapper = Mockery::mock(ComponentHtmlMapper::class);
        $this->evaluator = Mockery::mock(ExpressionEvaluator::class);
        $this->seoConfigMerger = Mockery::mock(SeoConfigMerger::class);

        $settingsService = Mockery::mock(SettingsService::class);
        $settingsService->shouldReceive('getFrontendSettings')->andReturn([])->byDefault();
        $pluginSettingsService = Mockery::mock(PluginSettingsService::class);
        $pluginSettingsService->shouldReceive('getAllActiveSettings')->andReturn([])->byDefault();
        $templateService = Mockery::mock(TemplateService::class);
        $templateService->shouldReceive('getLanguageDataWithModules')
            ->andReturn(['success' => true, 'data' => [], 'error' => null])->byDefault();
        $moduleManager = Mockery::mock(ModuleManagerInterface::class);
        $pluginManager = Mockery::mock(PluginManagerInterface::class);

        // 공통 파이프라인이 건드리는 의존성 mock
        $this->seoConfigMerger->shouldReceive('getMergedConfig')->andReturn([])->byDefault();
        $pipeRegistry = Mockery::mock(PipeRegistry::class);
        $pipeRegistry->shouldReceive('setLocale')->byDefault();
        $this->evaluator->shouldReceive('setTranslations')->byDefault();
        $this->evaluator->shouldReceive('getPipeRegistry')->andReturn($pipeRegistry)->byDefault();
        $this->evaluator->shouldReceive('setSeoOverrides')->byDefault();
        foreach (['setComponentMap', 'setRenderModes', 'setSelfClosing', 'setTextProps', 'setAttrMap', 'setAllowedAttrs', 'setGlobalResolver', 'setSeoVars'] as $m) {
            $this->htmlMapper->shouldReceive($m)->byDefault();
        }
        $this->metaResolver->shouldReceive('resolveLocalizedValue')
            ->andReturnUsing(fn ($v) => is_string($v) ? $v : (string) ($v ?? ''))->byDefault();

        $this->renderer = new SeoRenderer(
            $this->routeResolver,
            $this->layoutService,
            $templateService,
            $this->dataSourceResolver,
            $this->metaResolver,
            $this->htmlMapper,
            $this->evaluator,
            $this->seoConfigMerger,
            $settingsService,
            $pluginSettingsService,
            $moduleManager,
            $pluginManager,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** 최소 meta 결과 — SeoMetaResolver::resolve 흉내 */
    private function metaResult(string $title): array
    {
        return [
            'title' => $title,
            'titleSuffix' => '',
            'description' => '',
            'keywords' => '',
            'og' => [],
            'twitter' => [],
            'structured_data' => null,
            'ogTags' => '',
            'twitterTags' => '',
            'jsonLd' => '',
            'googleAnalyticsId' => '',
            'googleVerification' => '',
            'naverVerification' => '',
        ];
    }

    private function enabledLayout(): array
    {
        return [
            'meta' => ['seo' => ['enabled' => true]],
            'components' => [['component' => 'Div', 'props' => ['text' => '본문']]],
        ];
    }

    /**
     * renderFromResolved 는 이미 해석된 컨텍스트로 완성 HTML 을 생성한다(편집기 봇 미리보기 경로).
     */
    public function test_render_from_resolved_produces_html_from_seed_context(): void
    {
        $this->metaResolver->shouldReceive('resolve')->once()->andReturn($this->metaResult('봇 제목'));
        $this->htmlMapper->shouldReceive('render')->once()->andReturn('<div>본문</div>');

        $expected = '<!DOCTYPE html><html lang="ko"><head><title>봇 제목</title></head><body><div>본문</div></body></html>';
        $viewMock = Mockery::mock(\Illuminate\View\View::class);
        $viewMock->shouldReceive('render')->once()->andReturn($expected);
        View::shouldReceive('make')
            ->with('seo', Mockery::on(fn ($d) => $d['title'] === '봇 제목' && $d['bodyHtml'] === '<div>본문</div>'))
            ->once()
            ->andReturn($viewMock);

        $result = $this->renderer->renderFromResolved(
            $this->enabledLayout(),
            ['id' => '1'],
            '/preview/test',
            'ko',
            'sirsoft-basic',
            'preview/test',
            null,
            null,
            ['route' => ['path' => '/preview/test'], 'query' => [], '_global' => [], '_local' => []],
            null,
        );

        $this->assertSame($expected, $result);
    }

    /**
     * enabled=false 면 renderFromResolved 도 render() 단계3 게이트와 동일하게 null 을 반환한다.
     * (편집기 봇 미리보기가 render() 를 거치지 않고 직접 호출해도 "검색엔진 미노출"과 일치.)
     */
    public function test_render_from_resolved_returns_null_when_disabled(): void
    {
        // resolve/render/View 는 호출되면 안 됨 — enabled 게이트에서 조기 반환.
        $this->metaResolver->shouldReceive('resolve')->never();
        $this->htmlMapper->shouldReceive('render')->never();

        $result = $this->renderer->renderFromResolved(
            ['meta' => ['seo' => ['enabled' => false]], 'components' => []],
            [],
            '/preview/test',
            'ko',
            'sirsoft-basic',
            'preview/test',
            null,
            null,
            ['route' => [], 'query' => [], '_global' => [], '_local' => []],
            null,
        );

        $this->assertNull($result);
    }

    /**
     * meta.seo 자체가 없으면 null (render() 단계3 `! $seoConfig` 와 동일).
     */
    public function test_render_from_resolved_returns_null_when_no_seo_config(): void
    {
        $this->metaResolver->shouldReceive('resolve')->never();

        $result = $this->renderer->renderFromResolved(
            ['meta' => [], 'components' => []],
            [],
            '/x',
            'ko',
            'sirsoft-basic',
            'x',
            null,
            null,
            [],
            null,
        );

        $this->assertNull($result);
    }

    /**
     * defaultLocale=null 이면 config('app.locale') 폴백으로 canonical 을 구성한다(render() 가
     * seo_default_locale request attribute 에서 추출해 넘기는 값의 폴백).
     */
    public function test_render_from_resolved_default_locale_falls_back_to_config(): void
    {
        config()->set('app.locale', 'ko');

        $this->metaResolver->shouldReceive('resolve')->once()->andReturn($this->metaResult('제목'));
        $this->htmlMapper->shouldReceive('render')->once()->andReturn('');

        // locale=ko == defaultLocale(config ko) → canonical 에 ?locale= 없음.
        View::shouldReceive('make')
            ->with('seo', Mockery::on(fn ($d) => ! str_contains($d['canonicalUrl'], '?locale=')))
            ->once()
            ->andReturn(tap(Mockery::mock(\Illuminate\View\View::class), fn ($m) => $m->shouldReceive('render')->andReturn('ok')));

        $result = $this->renderer->renderFromResolved(
            $this->enabledLayout(),
            [],
            '/page',
            'ko',
            'sirsoft-basic',
            'page',
            null,
            null,
            ['route' => [], 'query' => [], '_global' => [], '_local' => []],
            null,
        );

        $this->assertSame('ok', $result);
    }

    /**
     * render(Request) 는 컨텍스트 해석 후 같은 입력에 대해 renderFromResolved 와 동일한 최종
     * HTML 을 만든다 — 추출 위임 동등성(부분 목으로 renderFromResolved 호출을 검증).
     */
    public function test_render_delegates_to_render_from_resolved_with_resolved_context(): void
    {
        $request = Request::create('/products/9');
        $request->attributes->set('seo_default_locale', 'ko');

        $this->routeResolver->shouldReceive('resolve')->with('/products/9')->once()->andReturn([
            'templateIdentifier' => 'sirsoft-basic',
            'layoutName' => 'shop/show',
            'routeParams' => ['id' => '9'],
            'moduleIdentifier' => null,
            'pluginIdentifier' => null,
        ]);
        $this->layoutService->shouldReceive('getLayout')->with('sirsoft-basic', 'shop/show')->once()
            ->andReturn($this->enabledLayout());

        // render() 본문이 renderFromResolved 에 위임 → 부분 목으로 인자 검증.
        $partial = Mockery::mock(SeoRenderer::class, [
            $this->routeResolver, $this->layoutService, Mockery::mock(TemplateService::class),
            $this->dataSourceResolver, $this->metaResolver, $this->htmlMapper, $this->evaluator,
            $this->seoConfigMerger, Mockery::mock(SettingsService::class), Mockery::mock(PluginSettingsService::class),
            Mockery::mock(ModuleManagerInterface::class), Mockery::mock(PluginManagerInterface::class),
        ])->makePartial();

        $partial->shouldReceive('renderFromResolved')
            ->once()
            ->withArgs(function ($layout, $routeParams, $url, $locale, $tpl, $layoutName, $mod, $plug, $ctx, $default) {
                return $url === '/products/9'
                    && $routeParams === ['id' => '9']
                    && $tpl === 'sirsoft-basic'
                    && $layoutName === 'shop/show'
                    && $default === 'ko'
                    && ($ctx['route']['path'] ?? null) === '/products/9';
            })
            ->andReturn('<delegated/>');

        $result = $partial->render($request);

        $this->assertSame('<delegated/>', $result);
    }
}
