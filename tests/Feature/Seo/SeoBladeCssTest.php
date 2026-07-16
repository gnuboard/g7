<?php

namespace Tests\Feature\Seo;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * 운영 seo.blade.php 의 CSS 링크 렌더링 테스트.
 *
 * 봇 렌더링 HTML 에는 코어 Laravel 기본 에셋 빌드 산출물(app.css)에 대한
 * 고정 경로 `/build/assets/app.css` 링크가 포함되지 않아야 한다. 해당 파일은
 * 실제 사용자 화면(app.blade.php·admin.blade.php)에서 참조되지 않는 Laravel
 * 기본 빌드 파일이며, Vite manifest 가 해시 파일로만 생성하므로 고정 경로는
 * 항상 404 였다. 봇 스타일은 템플릿 CSS($stylesheets)가 담당한다.
 * (공개#64 — 봇 SEO HTML CSS 404 제거)
 */
class SeoBladeCssTest extends TestCase
{
    /** 운영 seo.blade.php 가 받는 $viewData 형태(대표 값, cssPath 미포함) */
    private function viewData(array $overrides = []): array
    {
        return array_merge([
            'locale' => 'ko',
            'title' => '베이직 오버핏 코튼 티셔츠',
            'titleSuffix' => ' | 코지홈',
            'description' => '사계절 입기 좋은 오버핏 기본 티셔츠',
            'keywords' => '티셔츠,오버핏',
            'canonicalUrl' => 'https://example.com/shop/products/1',
            'hreflangTags' => '',
            'ogTags' => '<meta property="og:type" content="product">',
            'twitterTags' => '<meta name="twitter:card" content="summary_large_image">',
            'jsonLd' => '{"@type":"Product","name":"베이직 오버핏 코튼 티셔츠"}',
            'bodyHtml' => '<div class="app-chrome"><nav><a href="/login">로그인</a></nav></div>',
            'googleAnalyticsId' => '',
            'googleVerification' => '',
            'naverVerification' => '',
            'stylesheets' => [
                '/api/templates/assets/sirsoft-basic/css/components.css',
            ],
            'extraHeadTags' => '',
            'extraBodyEnd' => '',
            'generatorTag' => '<meta name="generator" content="GnuBoard7">',
        ], $overrides);
    }

    private function renderSeo(array $overrides = []): string
    {
        return View::make('seo', $this->viewData($overrides))->render();
    }

    public function test_봇_HTML에_코어_app_css_고정경로_링크가_없다(): void
    {
        $out = $this->renderSeo();

        $this->assertStringNotContainsString('/build/assets/app.css', $out);
        $this->assertStringNotContainsString('/build/', $out);
    }

    public function test_템플릿_stylesheets는_정상_링크된다(): void
    {
        $out = $this->renderSeo();

        $this->assertStringContainsString(
            '<link rel="stylesheet" href="/api/templates/assets/sirsoft-basic/css/components.css">',
            $out,
        );
    }

    public function test_cssPath_변수_없이도_예외없이_렌더된다(): void
    {
        // cssPath 를 뷰 데이터에서 완전히 제거해도 (undefined variable 등) 예외가 없어야 한다.
        $out = $this->renderSeo();

        $this->assertStringContainsString('<title>베이직 오버핏 코튼 티셔츠 | 코지홈</title>', $out);
    }
}
