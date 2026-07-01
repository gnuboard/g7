<?php

namespace Tests\Feature\Seo;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * seo-preview.blade.php 테스트.
 *
 * 봇 미리보기는 검색엔진 탭·레이아웃·모듈/코어 SEO 설정이 산출한 데이터만 보여준다.
 * 본 블레이드는 그 산출물(title/description/keywords/canonical/hreflang/og/twitter/JSON-LD/
 * 소유권 확인 메타)만 렌더하고, SEO 설정 산출물이 아닌 것(body 컴포넌트 마크업·CSS·charset/
 * viewport/generator 시스템 메타·Google Analytics 추적 스크립트)은 포함하지 않는다.
 */
class SeoPreviewBladeTest extends TestCase
{
    /** 운영 seo.blade.php / seo-preview.blade.php 가 공유하는 $viewData 형태(대표 값) */
    private function viewData(array $overrides = []): array
    {
        return array_merge([
            'locale' => 'ko',
            'title' => '베이직 오버핏 코튼 티셔츠',
            'titleSuffix' => ' | 코지홈',
            'description' => '사계절 입기 좋은 오버핏 기본 티셔츠',
            'keywords' => '티셔츠,오버핏',
            'canonicalUrl' => 'https://example.com/shop/products/1',
            'hreflangTags' => '<link rel="alternate" hreflang="en" href="https://example.com/shop/products/1?locale=en">',
            'ogTags' => '<meta property="og:type" content="product"><meta property="og:title" content="베이직 오버핏 코튼 티셔츠">',
            'twitterTags' => '<meta name="twitter:card" content="summary_large_image">',
            'jsonLd' => '{"@type":"Product","name":"베이직 오버핏 코튼 티셔츠"}',
            'bodyHtml' => '<div class="app-chrome"><button>장바구니</button><nav><a href="/login">로그인</a></nav></div>',
            'googleAnalyticsId' => 'G-XXXX',
            'googleVerification' => 'gverify123',
            'naverVerification' => 'nverify456',
            'cssPath' => '/build/assets/app.css',
            'stylesheets' => ['/api/templates/assets/sirsoft-basic/css/components.css', 'https://cdnjs.cloudflare.com/font-awesome.css'],
            'extraHeadTags' => '',
            'extraBodyEnd' => '',
            'generatorTag' => '<meta name="generator" content="GnuBoard7">',
        ], $overrides);
    }

    private function renderPreview(array $overrides = []): string
    {
        return View::make('seo-preview', $this->viewData($overrides))->render();
    }

    public function test_seo_설정_산출물은_포함된다(): void
    {
        $out = $this->renderPreview();

        $this->assertStringContainsString('<title>베이직 오버핏 코튼 티셔츠 | 코지홈</title>', $out);
        $this->assertStringContainsString('name="description"', $out);
        $this->assertStringContainsString('name="keywords"', $out);
        $this->assertStringContainsString('rel="canonical"', $out);
        $this->assertStringContainsString('hreflang="en"', $out);
        $this->assertStringContainsString('property="og:title"', $out);
        $this->assertStringContainsString('name="twitter:card"', $out);
        $this->assertStringContainsString('application/ld+json', $out);
        $this->assertStringContainsString('"@type":"Product"', $out);
        // 사이트 소유권 확인 메타 = 검색엔진 SEO 설정 산출물.
        $this->assertStringContainsString('google-site-verification', $out);
        $this->assertStringContainsString('naver-site-verification', $out);
    }

    public function test_seo_설정_산출물이_아닌_것은_제외된다(): void
    {
        $out = $this->renderPreview();

        // body 컴포넌트 마크업 — 페이지 본문(검색엔진 탭과 무관).
        $this->assertStringNotContainsString('app-chrome', $out);
        $this->assertStringNotContainsString('장바구니', $out);
        $this->assertStringNotContainsString('<button', $out);
        // CSS 에셋.
        $this->assertStringNotContainsString('app.css', $out);
        $this->assertStringNotContainsString('components.css', $out);
        $this->assertStringNotContainsString('font-awesome', $out);
        // 시스템 기본 메타(charset/viewport/generator).
        $this->assertStringNotContainsString('charset', $out);
        $this->assertStringNotContainsString('viewport', $out);
        $this->assertStringNotContainsString('generator', $out);
        // Google Analytics 추적 스크립트(검색 노출 산출물 아님).
        $this->assertStringNotContainsString('googletagmanager', $out);
        // body 영역 자체 미포함.
        $this->assertStringNotContainsString('<body', $out);
    }

    public function test_keywords_없으면_keywords_메타_미출력(): void
    {
        $out = $this->renderPreview(['keywords' => '']);
        $this->assertStringNotContainsString('name="keywords"', $out);
    }

    public function test_json_ld_없으면_구조화_스크립트_미출력(): void
    {
        $out = $this->renderPreview(['jsonLd' => '']);
        $this->assertStringNotContainsString('application/ld+json', $out);
    }
}
