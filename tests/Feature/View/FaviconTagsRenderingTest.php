<?php

namespace Tests\Feature\View;

use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Helpers\CreatesTemplateSettingsFixtures;
use Tests\TestCase;

/**
 * 파비콘 `<head>` 주입 렌더링 테스트 (#434)
 *
 * SPA 셸(사용자·관리자)은 3계층 해석, 오류·점검·DB 오류 화면은 번들 계층만(DB·API 를 보지 않는다).
 */
class FaviconTagsRenderingTest extends TestCase
{
    use CreatesTemplateSettingsFixtures;
    use RefreshDatabase;

    /**
     * 코어 파비콘 설정을 비우고 시작합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('g7_settings.core.general.site_favicon', []);
    }

    /**
     * 테스트가 만든 픽스처를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanUpTemplateSettingsFixtures();

        parent::tearDown();
    }

    /**
     * 사용자·관리자 셸 HTML 에 파비콘 링크가 있고, 일반 탭 파비콘을 정하면 그 경로로 바뀐다(템플릿 없음 화면 포함).
     *
     * @scenario surface=spa
     *
     * @effects highest_valid_tier_wins
     *
     * @return void 반환값 없음
     */
    public function test_spa_shells_render_resolved_favicon(): void
    {
        $user = $this->get('/')->getContent();
        $this->assertStringContainsString('/assets/core/favicon/favicon.ico?v=', $user);
        $this->assertStringContainsString('rel="apple-touch-icon"', $user);

        $core = $this->makeFaviconAttachment('site_favicon', 'image/png');
        Config::set('g7_settings.core.general.site_favicon', [$core->id]);
        $this->resetFaviconResolution();

        $admin = $this->get('/admin')->getContent();
        $this->assertStringContainsString('href="/api/favicon/'.$core->hash.'"', $admin);
        $this->assertStringContainsString('type="image/png"', $admin);
    }

    /**
     * 오류(404)·점검·DB 접속 오류 화면은 번들 계층만 링크한다.
     *
     * @scenario surface=static_error
     *
     * @effects static_pages_bundled_only
     *
     * @return void 반환값 없음
     */
    public function test_static_pages_render_bundled_favicon(): void
    {
        $core = $this->makeFaviconAttachment('site_favicon', 'image/png');
        Config::set('g7_settings.core.general.site_favicon', [$core->id]);

        foreach ([
            view('errors.404')->render(),
            view('maintenance')->render(),
            view('database-credential-error', ['reason' => 'x'])->render(),
        ] as $html) {
            $this->assertStringContainsString('/assets/core/favicon/favicon.svg?v=', $html);
            $this->assertStringNotContainsString('/api/favicon/', $html, '정적 화면은 DB 첨부를 보지 않는다');
        }
    }

    /**
     * SEO 봇 화면 뷰는 렌더러가 넘긴 파비콘 태그를 head 에 싣는다.
     *
     * @scenario surface=seo
     *
     * @effects highest_valid_tier_wins
     *
     * @return void 반환값 없음
     */
    public function test_seo_view_renders_favicon_tags(): void
    {
        $html = g7_favicon_tags('user');

        $this->assertStringContainsString('<link rel="icon"', $html);
        $this->assertStringContainsString('<link rel="apple-touch-icon"', $html);
        $this->assertStringContainsString('<link rel="icon"', g7_favicon_tags_static());
    }

    /**
     * 켜진 템플릿이 하나도 없어도 사용자·관리자 셸은 코어 계층(정했으면)·번들 계층 파비콘 링크를 낸다.
     *
     * @scenario surface=spa
     *
     * @effects highest_valid_tier_wins
     *
     * @return void 반환값 없음
     */
    public function test_spa_shells_without_active_template_render_core_or_bundled(): void
    {
        Template::query()->update(['status' => 'inactive']);
        $this->assertSame(0, Template::where('status', 'active')->count(), '전제 — 켜진 템플릿 없음');

        $core = $this->makeFaviconAttachment('site_favicon', 'image/png');
        Config::set('g7_settings.core.general.site_favicon', [$core->id]);
        $this->resetFaviconResolution();

        foreach (['/', '/admin'] as $uri) {
            $html = $this->get($uri)->getContent();
            $this->assertStringContainsString('href="/api/favicon/'.$core->hash.'"', $html, $uri.' — 템플릿 없이도 코어 계층');
        }

        Config::set('g7_settings.core.general.site_favicon', []);
        $this->resetFaviconResolution();

        foreach (['/', '/admin'] as $uri) {
            $html = $this->get($uri)->getContent();
            $this->assertStringContainsString('/assets/core/favicon/favicon.ico?v=', $html, $uri.' — 템플릿·코어 없으면 번들 계층');
            $this->assertStringNotContainsString('/api/favicon/', $html);
        }
    }

    /**
     * 500·503 오류 화면도 번들 계층 정적 링크만 낸다.
     *
     * @scenario surface=static_error
     *
     * @effects static_pages_bundled_only
     *
     * @return void 반환값 없음
     */
    public function test_500_and_503_pages_render_bundled_favicon(): void
    {
        $core = $this->makeFaviconAttachment('site_favicon', 'image/png');
        Config::set('g7_settings.core.general.site_favicon', [$core->id]);

        foreach (['errors.500', 'errors.503'] as $view) {
            $html = view($view)->render();
            $this->assertStringContainsString('/assets/core/favicon/favicon.svg?v=', $html, $view);
            $this->assertStringContainsString('/assets/core/favicon/favicon.ico?v=', $html, $view);
            $this->assertStringNotContainsString('/api/favicon/', $html, $view.' — 정적 화면은 DB 첨부를 보지 않는다');
        }
    }

    /**
     * 실제 `seo` 블레이드 뷰를 렌더하면 넘긴 파비콘 태그(해석 결과)가 head 에 들어간다.
     *
     * @scenario surface=seo
     *
     * @effects highest_valid_tier_wins
     *
     * @return void 반환값 없음
     */
    public function test_seo_blade_view_outputs_favicon_tags(): void
    {
        $core = $this->makeFaviconAttachment('site_favicon', 'image/png');
        Config::set('g7_settings.core.general.site_favicon', [$core->id]);
        $this->resetFaviconResolution();

        $html = view('seo', self::seoViewData(['faviconTags' => g7_favicon_tags('user')]))->render();

        $head = substr($html, 0, (int) strpos($html, '</head>'));
        $this->assertStringContainsString('<link rel="icon" type="image/png" href="/api/favicon/'.$core->hash.'">', $head);
        $this->assertStringContainsString('<link rel="apple-touch-icon" href="/api/favicon/'.$core->hash.'">', $head);
    }

    /**
     * `seo` 뷰 렌더에 필요한 최소 변수.
     *
     * @param  array<string, mixed>  $overrides  덮을 값
     * @return array<string, mixed> 뷰 변수
     */
    public static function seoViewData(array $overrides = []): array
    {
        return array_merge([
            'locale' => 'ko',
            'colorScheme' => 'light',
            'title' => 'qa434',
            'titleSuffix' => '',
            'faviconTags' => '',
            'generatorTag' => '',
            'description' => 'qa434',
            'keywords' => '',
            'canonicalUrl' => 'https://example.com/',
            'hreflangTags' => '',
            'googleVerification' => '',
            'naverVerification' => '',
            'ogTags' => '',
            'twitterTags' => '',
            'stylesheets' => [],
            'extensionStylesheets' => [],
            'jsonLd' => '',
            'googleAnalyticsId' => '',
            'extraHeadTags' => '',
            'bodyHtml' => '<p>qa434</p>',
            'extraBodyEnd' => '',
        ], $overrides);
    }
}
