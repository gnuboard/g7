<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Seo;

use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoCacheRegenerator;
use App\Seo\SitemapFileStore;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Modules\Sirsoft\Board\Listeners\SeoBoardCacheListener;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use Tests\Support\IsolatedSitemapStorage;

/**
 * 게시판 훅 → 사이트맵 재생성 경로의 저장소 격리 회귀 테스트
 *
 * 게시판 수정 훅의 SEO 리스너는 사이트맵 재생성 잡을 디스패치하고, 테스트 큐는 `sync` 라 잡이 즉시
 * 실행됩니다. 격리가 없으면 게시판을 만들거나 고치는 테스트마다 실제 사이트맵 파일
 * (storage/app/private/cache/sitemap)이 테스트 게시판 주소로 덮어써집니다. 잡을 가짜로 바꾸지 않고
 * 리스너 → 잡 경로를 그대로 태워, 파일이 테스트 전용 디스크에만 쓰이는지 고정합니다.
 *
 * @scenario-manifest tests/scenarios/testing-sitemap-storage-isolation.yaml
 */
class BoardSitemapStorageIsolationTest extends ModuleTestCase
{
    /**
     * SEO 캐시 무효화를 spy 로 대체하고 사이트맵 생성을 켭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 상세 화면 캐시 무효화·재생성은 이 테스트 범위 밖 — spy 로 대체
        $this->app->instance(SeoCacheManagerInterface::class, Mockery::spy(SeoCacheManagerInterface::class));
        $this->app->instance(SeoCacheRegenerator::class, Mockery::spy(SeoCacheRegenerator::class));

        Config::set('g7_settings.core.seo.sitemap_enabled', true);
    }

    /**
     * Mockery 기대값을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 게시판 수정 훅이 사이트맵을 다시 써도 실제 storage 경로의 사이트맵 파일은 생기거나 바뀌지 않는다.
     *
     * @scenario entry=board_update_hook
     *
     * @effects sitemap_regeneration_never_writes_real_storage
     *
     * @return void 반환값 없음
     */
    public function test_board_update_hook_regenerates_sitemap_only_on_testing_disk(): void
    {
        $liveDir = storage_path('app/private/'.SitemapFileStore::CATEGORY.'/'.SitemapFileStore::LIVE_DIR);
        $realManifest = $liveDir.'/'.SitemapFileStore::MANIFEST_FILE;
        $realChild = $liveDir.'/'.SitemapFileStore::childFilename(1);
        $before = [is_file($realManifest) ? sha1_file($realManifest) : null, is_file($realChild) ? sha1_file($realChild) : null];

        // 게시판 수정 훅의 SEO 리스너를 그대로 부른다 — 잡은 가짜로 바꾸지 않는다(sync 큐로 즉시 실행)
        (new SeoBoardCacheListener)->onBoardUpdate((object) [
            'id' => 7,
            'slug' => 'isolation-board',
            'is_active' => true,
            'updated_at' => now(),
        ]);

        $after = [is_file($realManifest) ? sha1_file($realManifest) : null, is_file($realChild) ? sha1_file($realChild) : null];
        $this->assertSame($before, $after, '게시판 훅이 실제 storage 경로의 사이트맵 파일을 만들거나 바꿨습니다.');

        // 재생성 자체는 테스트 전용 디스크에서 일어나야 한다 (격리가 리스너 경로를 끊으면 안 됨)
        $child = (string) Storage::disk(IsolatedSitemapStorage::FALLBACK_DISK)
            ->get('cache/sitemap/'.SitemapFileStore::childFilename(1));
        $this->assertStringContainsString('/board/isolation-board', $child);
    }
}
