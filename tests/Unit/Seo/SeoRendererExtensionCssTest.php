<?php

namespace Tests\Unit\Seo;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Seo\SeoRenderer;
use Illuminate\Support\Facades\File;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 검색엔진용 화면(SEO 렌더)에 확장 CSS 가 사용자 화면과 같은 순서로 실린다.
 *
 * 확장 화면 스타일은 확장이 자기 CSS 로 공급한다(#816). 봇 화면에도 그 CSS 가 없으면 확장 화면이 맨 HTML 로 보인다.
 * 순서는 템플릿 CSS → `@layer g7-extension;` 선언 → 모듈 번들 CSS → 플러그인 번들 CSS → 호환 덮기 파일이고,
 * 봇 화면은 라이트로 그리므로 `<html data-g7-color-scheme="light">` 를 단다.
 *
 * @scenario surface=seo_render
 *
 * @effects seo_render_loads_extension_css_in_user_order
 */
class SeoRendererExtensionCssTest extends TestCase
{
    private string $templateRoot;

    /**
     * 덮기 파일 두 개(hello 호환·board 비호환)를 둔 임시 템플릿 루트를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->templateRoot = storage_path('framework/testing/seo-extension-css/vendor-sample');
        File::ensureDirectoryExists($this->templateRoot.'/dist/css/overrides');
        File::put($this->templateRoot.'/dist/css/overrides/hello.css', '.hellomodule-card__title{color:red}');
        File::put($this->templateRoot.'/dist/css/overrides/board.css', '.board-title{color:red}');
        File::put($this->templateRoot.'/template.json', json_encode([
            'identifier' => 'vendor-sample',
            'extension_css_overrides' => [
                ['target' => 'gnuboard7-hello_module', 'targetVersion' => '^0.3', 'path' => 'dist/css/overrides/hello.css'],
                ['target' => 'sirsoft-board', 'targetVersion' => '^9.0', 'path' => 'dist/css/overrides/board.css'],
            ],
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 임시 템플릿 루트를 지우고 목을 닫는다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->templateRoot));
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 식별자·버전·CSS 선언만 가진 가짜 확장을 만든다.
     *
     * @param  string  $identifier  확장 식별자
     * @param  string  $version  확장 버전
     * @param  bool  $hasCss  manifest assets.css 선언 여부
     * @return object 가짜 확장 목 객체
     */
    private function extension(string $identifier, string $version, bool $hasCss): object
    {
        $ext = Mockery::mock();
        $ext->shouldReceive('getIdentifier')->andReturn($identifier);
        $ext->shouldReceive('getVersion')->andReturn($version);
        $ext->shouldReceive('getAssets')->andReturn($hasCss ? ['css' => ['namespace' => $identifier]] : []);

        return $ext;
    }

    /**
     * 활성 모듈·플러그인을 주입하고 템플릿 루트를 임시 디렉터리로 바꾼 렌더러로 확장 CSS URL 목록을 얻는다.
     *
     * @param  array<int, object>  $modules  활성 모듈
     * @param  array<int, object>  $plugins  활성 플러그인
     * @return array<int, string> 확장 CSS URL 목록
     */
    private function extensionCssUrls(array $modules, array $plugins): array
    {
        $moduleManager = Mockery::mock(ModuleManagerInterface::class);
        $moduleManager->shouldReceive('getActiveModules')->andReturn($modules);
        $pluginManager = Mockery::mock(PluginManagerInterface::class);
        $pluginManager->shouldReceive('getActivePlugins')->andReturn($plugins);
        $this->app->instance(ModuleManagerInterface::class, $moduleManager);
        $this->app->instance(PluginManagerInterface::class, $pluginManager);

        $root = $this->templateRoot;
        $renderer = $this->app->make(SeoRenderer::class);

        // 컨테이너가 만든 렌더러의 의존(목 관리자 포함)을 그대로 옮겨 받고 템플릿 루트만 임시 디렉터리로 바꾼다
        $sub = new class($renderer, $root) extends SeoRenderer
        {
            /**
             * 컨테이너가 만든 렌더러의 초기화된 속성을 옮겨 받는다.
             *
             * @param  SeoRenderer  $inner  의존이 주입된 원본 렌더러
             * @param  string  $root  임시 템플릿 루트
             */
            public function __construct(private readonly SeoRenderer $inner, private readonly string $root)
            {
                foreach ((new \ReflectionClass(SeoRenderer::class))->getProperties() as $property) {
                    if (! $property->isStatic() && $property->isInitialized($inner)) {
                        $property->setValue($this, $property->getValue($inner));
                    }
                }
            }

            /**
             * 템플릿 루트를 임시 디렉터리로 바꾼다.
             *
             * @param  string  $identifier  템플릿 식별자(쓰지 않음)
             * @return string 임시 템플릿 루트
             */
            protected function templateRootPath(string $identifier): string
            {
                return $this->root;
            }
        };

        return (new ReflectionMethod(SeoRenderer::class, 'getExtensionCssUrls'))->invoke($sub, 'vendor-sample');
    }

    /**
     * 모듈 번들 → 플러그인 번들 → 호환 덮기 파일 순서이고, 버전이 맞지 않는 덮기 파일은 빠진다.
     *
     * @scenario case=seo_css_c01
     *
     * @effects seo_render_loads_extension_css_in_user_order
     *
     * @return void 반환값 없음
     */
    public function test_bundles_then_compatible_overrides_in_user_order(): void
    {
        $urls = $this->extensionCssUrls(
            [$this->extension('gnuboard7-hello_module', '0.3.4', true), $this->extension('sirsoft-board', '1.2.0', true)],
            [$this->extension('sirsoft-daum_postcode', '1.1.2', true)],
        );

        $this->assertCount(3, $urls);
        $this->assertStringContainsString('modules', $urls[0]);
        $this->assertStringContainsString('css', $urls[0]);
        $this->assertStringContainsString('plugins', $urls[1]);
        $this->assertStringContainsString('css/overrides/hello.css', $urls[2]);
        $this->assertStringNotContainsString('board.css', implode(' ', $urls));
    }

    /**
     * CSS 를 선언한 확장이 없는 종류는 번들 링크를 싣지 않는다(빈 번들 링크 금지).
     *
     * @scenario case=seo_css_c02
     *
     * @effects seo_render_loads_extension_css_in_user_order
     *
     * @return void 반환값 없음
     */
    public function test_type_without_css_declaring_extension_has_no_bundle_link(): void
    {
        $urls = $this->extensionCssUrls(
            [$this->extension('gnuboard7-hello_module', '0.3.4', false)],
            [$this->extension('sirsoft-daum_postcode', '1.1.2', true)],
        );

        $this->assertCount(2, $urls);
        $this->assertStringContainsString('plugins', $urls[0]);
        $this->assertStringContainsString('css/overrides/hello.css', $urls[1]);
    }

    /**
     * 화면 뼈대 — 라이트 중립 표식, 템플릿 CSS 뒤 층 선언, 그 뒤 확장 CSS 링크 순서.
     *
     * @scenario case=seo_css_c03
     *
     * @effects seo_render_loads_extension_css_in_user_order
     *
     * @return void 반환값 없음
     */
    public function test_view_declares_layer_after_template_css_and_marks_light_scheme(): void
    {
        $html = view('seo', [
            'locale' => 'ko', 'title' => 't', 'titleSuffix' => '', 'description' => '', 'keywords' => '',
            'canonicalUrl' => 'https://example.test/', 'hreflangTags' => '', 'ogTags' => '', 'twitterTags' => '',
            'jsonLd' => '', 'bodyHtml' => '', 'googleAnalyticsId' => '', 'googleVerification' => '', 'naverVerification' => '',
            'stylesheets' => ['/template.css'], 'extensionStylesheets' => ['/modules.css', '/plugins.css', '/override.css'],
            'colorScheme' => 'light', 'generatorTag' => '',
        ])->render();

        $this->assertStringContainsString('<html lang="ko" data-g7-color-scheme="light">', $html);
        $template = strpos($html, '/template.css');
        $layer = strpos($html, '<style>@layer g7-extension;</style>');
        $modules = strpos($html, '/modules.css');
        $plugins = strpos($html, '/plugins.css');
        $override = strpos($html, '/override.css');
        $this->assertNotFalse($layer);
        $this->assertTrue($template < $layer && $layer < $modules && $modules < $plugins && $plugins < $override);
    }
}
