<?php

namespace Tests\Unit\Seo;

use App\Seo\SeoRenderer;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SeoRenderer 가 봇 화면에 싣는 템플릿 CSS 는 파일이 있을 때만 링크한다.
 *
 * `template.json` 의 `assets.css` 는 선언일 뿐이라 산출물이 없을 수 있다. 그 경로를
 * 그대로 `<link>` 로 실으면 봇 화면에서만 404 가 나고 일반 화면에는 흔적이 없다 —
 * 서버 로그에도 남지 않으므로 운영자가 알 방법이 없다.
 */
class SeoRendererTemplateCssGateTest extends TestCase
{
    private string $templateRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->templateRoot = storage_path('framework/testing/seo-template-css/vendor-sample');
        File::ensureDirectoryExists($this->templateRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->templateRoot));
        parent::tearDown();
    }

    /**
     * 임시 디렉토리를 템플릿 루트로 쓰는 익명 서브클래스를 만든다.
     *
     * @param  list<string>  $cssPaths  template.json 의 assets.css 선언
     */
    private function rendererWithTemplateCss(array $cssPaths): SeoRenderer
    {
        File::put(
            $this->templateRoot.'/template.json',
            json_encode(['name' => 'sample', 'assets' => ['css' => $cssPaths]], JSON_UNESCAPED_UNICODE)
        );

        $root = $this->templateRoot;

        // 부모 생성자(의존 12개)는 호출하지 않는다 — getTemplateCssUrls 는 그 의존을
        // 하나도 쓰지 않으므로 미초기화 프로퍼티에 닿지 않는다.
        return new class($root) extends SeoRenderer
        {
            public function __construct(private readonly string $root) {}

            protected function templateRootPath(string $identifier): string
            {
                return $this->root;
            }
        };
    }

    /**
     * @param  list<string>  $cssPaths
     * @return list<string>
     */
    private function cssUrls(array $cssPaths): array
    {
        $renderer = $this->rendererWithTemplateCss($cssPaths);
        $method = new ReflectionMethod(SeoRenderer::class, 'getTemplateCssUrls');

        return $method->invoke($renderer, 'vendor-sample');
    }

    /**
     * 존재하는 CSS 만 URL 로 나온다 — 없는 경로는 조용히 빠진다.
     */
    public function test_only_existing_css_paths_become_urls(): void
    {
        File::ensureDirectoryExists($this->templateRoot.'/dist/css');
        File::put($this->templateRoot.'/dist/css/components.css', '.a{color:red}');

        $urls = $this->cssUrls(['dist/css/components.css', 'dist/css/absent.css']);

        $this->assertCount(1, $urls);
        $this->assertStringContainsString('css/components.css', $urls[0]);
    }

    /**
     * 선언한 CSS 가 하나도 없으면 빈 배열이다 (404 링크를 만들지 않는다).
     */
    public function test_absent_css_declaration_yields_no_urls(): void
    {
        $this->assertSame([], $this->cssUrls(['dist/css/components.css']));
    }

    /**
     * 0바이트 CSS 는 존재하므로 링크한다 — 존재 여부만 본다.
     */
    public function test_present_zero_byte_css_is_still_linked(): void
    {
        File::ensureDirectoryExists($this->templateRoot.'/dist/css');
        File::put($this->templateRoot.'/dist/css/components.css', '');

        $this->assertCount(1, $this->cssUrls(['dist/css/components.css']));
    }
}
