<?php

namespace Tests\Unit\Seo;

use App\Seo\SeoRenderer;
use App\Services\ExtensionStaticCacheService;
use App\Support\AssetUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * SEO 봇 HTML 의 템플릿 CSS URL 은 정적 게시(bake) 경로를 우회해야 한다 (#122).
 *
 * 봇 HTML 은 SeoCacheManager(`seo.page.*` — 키에 cache_version 미포함, TTL 수시간)에
 * 캐시되는데, 정적 게시 디렉토리는 GC 가 현재+직전 1개만 보존한다. 캐시된 HTML 이
 * GC 된 `/build/ext/{v}/…` 를 참조하면 봇 화면의 CSS 가 404 이고, SEO HTML 에는
 * asset-url-recovery 파샬이 포함되지 않아 자가 복구 경로도 없다. 따라서 SEO 렌더는
 * 항상 무버전 API URL(정적 게이트 우회 = 종전 동작)을 사용한다.
 */
class SeoRendererStaticAssetBypassTest extends TestCase
{
    private const VERSION = 424242;

    /** 테스트 전용 public 루트 (실 게시 트리 격리) */
    private string $isolatedPublicPath;

    protected function setUp(): void
    {
        parent::setUp();

        // 실 게시 트리(public/build/ext) 오염 방지 (ExtensionStaticCacheServiceTest 와 동일 근거)
        $this->isolatedPublicPath = storage_path('framework/testing/public-seobypass-'.getmypid());
        File::ensureDirectoryExists($this->isolatedPublicPath);
        $this->app->usePublicPath($this->isolatedPublicPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->isolatedPublicPath);
        AssetUrl::resetStaticExtBaseMemo();
        ExtensionStaticCacheService::resetPublishScheduleForTesting();

        parent::tearDown();
    }

    /**
     * 정적 게이트가 열린 상태에서도 SEO CSS URL 은 `/build/ext/` 를 참조하지 않는다.
     *
     * @effects seo_html_never_references_gc_target_static_path
     */
    public function test_seo_css_url_은_정적_게시_경로를_우회한다(): void
    {
        Cache::put('g7:core:ext.cache_version', self::VERSION);
        app()['env'] = 'production';

        $dir = public_path('build/ext/'.self::VERSION);
        File::ensureDirectoryExists($dir.'/templates/sirsoft-basic/assets/css');
        File::put($dir.'/manifest.json', '{}');
        File::put($dir.'/templates/sirsoft-basic/assets/css/components.css', 'x');
        AssetUrl::resetStaticExtBaseMemo();

        // 전제 고정 — 블레이드 경로(기본값)는 정적 게이트를 통과하는 상태다.
        // 이 단언이 깨지면 아래 우회 단언은 게이트 미통과로 인한 공허한 통과가 된다.
        $this->assertSame(
            '/build/ext/'.self::VERSION.'/templates/sirsoft-basic/assets/css/components.css',
            AssetUrl::templateAsset('sirsoft-basic', 'css/components.css')
        );

        $method = new \ReflectionMethod(SeoRenderer::class, 'getTemplateCssUrls');
        $urls = $method->invoke(app(SeoRenderer::class), 'sirsoft-basic');

        $this->assertNotEmpty($urls);
        foreach ($urls as $url) {
            $this->assertStringNotContainsString('/build/ext/', $url);
        }
    }
}
