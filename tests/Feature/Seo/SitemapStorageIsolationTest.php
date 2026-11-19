<?php

namespace Tests\Feature\Seo;

use App\Contracts\Repositories\SitemapUrlRepositoryInterface;
use App\Jobs\GenerateSitemapJob;
use App\Seo\SitemapFileStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Support\IsolatedSitemapStorage;
use Tests\TestCase;

/**
 * 사이트맵 저장소 격리 회귀 테스트
 *
 * 테스트 큐는 `sync` 라서 게시판·게시글·상품·페이지를 만드는 테스트가 리스너를 거쳐 사이트맵 재생성 잡을
 * 즉시 실행합니다. 격리가 없으면 그 잡이 테스트 DB 의 색인(테스트 주소)으로 실제 사이트맵 파일
 * (storage/app/private/cache/sitemap)을 덮어써, 같은 폴더에서 띄운 개발 서버가 검색 로봇에 테스트 주소를
 * 내보냅니다. RefreshDatabase 는 DB 만 되돌리므로 이 오염은 남고, 테스트는 계속 green 이라 드러나지
 * 않습니다. 그래서 TestCase 의 격리를 명시적으로 고정합니다.
 *
 * @scenario-manifest tests/scenarios/testing-sitemap-storage-isolation.yaml
 */
class SitemapStorageIsolationTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 실제 사이트맵 파일 경로 목록 (storage/app/private/cache/sitemap 하위)
     *
     * @var array<int, string>
     */
    private array $realPaths = [];

    /**
     * 테스트 초기화
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.locale', 'ko');
        Config::set('app.supported_locales', ['ko']);
        Config::set('g7_settings.core.seo.sitemap_enabled', true);

        $liveDir = storage_path('app/private/'.SitemapFileStore::CATEGORY.'/'.SitemapFileStore::LIVE_DIR);
        $this->realPaths = [
            $liveDir.'/'.SitemapFileStore::MANIFEST_FILE,
            $liveDir.'/'.SitemapFileStore::INDEX_FILE,
            $liveDir.'/'.SitemapFileStore::childFilename(1),
        ];
    }

    /**
     * 실제 사이트맵 파일들의 현재 내용 해시를 반환합니다 (없으면 null).
     *
     * @return array<string, string|null> 경로 => sha1 (없으면 null)
     */
    private function realFileHashes(): array
    {
        $hashes = [];
        foreach ($this->realPaths as $path) {
            $hashes[$path] = is_file($path) ? sha1_file($path) : null;
        }

        return $hashes;
    }

    /**
     * 리스너와 같은 경로(잡 디스패치 → sync 큐 즉시 실행)로 사이트맵을 다시 씁니다.
     *
     * @param  string  $loc  색인에 넣을 주소
     * @return void 반환값 없음
     */
    private function regenerateLikeListener(string $loc): void
    {
        $this->app->make(SitemapUrlRepositoryInterface::class)
            ->upsertForResource('board', '1', [['loc' => $loc, 'contributor' => 'sirsoft-board']]);

        GenerateSitemapJob::dispatch();
    }

    /**
     * 리스너 경로로 사이트맵을 다시 써도 실제 storage 경로의 파일은 생기거나 바뀌지 않는다.
     *
     * @scenario entry=core_job_dispatch
     *
     * @effects sitemap_regeneration_never_writes_real_storage
     *
     * @return void 반환값 없음
     */
    public function test_sitemap_regeneration_does_not_write_real_storage_path(): void
    {
        $before = $this->realFileHashes();

        $this->regenerateLikeListener(url('/board/isolation-check'));

        $this->assertSame($before, $this->realFileHashes(), '사이트맵 재생성이 실제 storage 경로의 사이트맵 파일을 만들거나 바꿨습니다.');

        // 격리가 기능을 죽이면 안 된다 — 생성은 페이크 전용 디스크에서 정상 동작하고, 서빙측도 같은 디스크를 본다.
        $fallback = Storage::disk(IsolatedSitemapStorage::FALLBACK_DISK);
        $fallback->assertExists('cache/sitemap/'.SitemapFileStore::MANIFEST_FILE);
        $this->assertStringContainsString(
            url('/board/isolation-check'),
            (string) $fallback->get('cache/sitemap/'.SitemapFileStore::childFilename(1))
        );
        $this->assertTrue($this->app->make(SitemapFileStore::class)->exists());
    }

    /**
     * 페이크 전용 디스크는 테스트 전용 경로를 가리키고, 실제 local 디스크 경로가 아니다.
     *
     * @scenario entry=fallback_disk_config
     *
     * @effects isolated_sitemap_disk_points_to_testing_path
     *
     * @return void 반환값 없음
     */
    public function test_fallback_disk_points_to_testing_path(): void
    {
        $this->assertTrue(IsolatedSitemapStorage::diskIsTestingPath(IsolatedSitemapStorage::FALLBACK_DISK));
        $this->assertFalse(IsolatedSitemapStorage::diskIsTestingPath('local'), '이 테스트는 local 을 페이크로 바꾸지 않은 상태를 전제합니다.');
        $this->assertSame(IsolatedSitemapStorage::FALLBACK_DISK, (new IsolatedSitemapStorage)->getDisk());
    }

    /**
     * 테스트가 local 을 페이크로 바꾸면 사이트맵은 그 페이크 local 에 쓰인다 — 이미 만들어진
     * 서빙측 싱글톤도 같은 디스크를 본다 (기존 사이트맵 테스트의 단언 경로 유지).
     *
     * @scenario entry=faked_local_disk
     *
     * @effects faked_local_disk_receives_sitemap
     *
     * @return void 반환값 없음
     */
    public function test_faked_local_disk_receives_sitemap_even_after_store_was_resolved(): void
    {
        $store = $this->app->make(SitemapFileStore::class);

        Storage::fake('local');
        $before = $this->realFileHashes();

        $this->regenerateLikeListener(url('/board/faked-local'));

        Storage::disk('local')->assertExists('cache/sitemap/'.SitemapFileStore::MANIFEST_FILE);
        Storage::disk(IsolatedSitemapStorage::FALLBACK_DISK)->assertMissing('cache/sitemap/'.SitemapFileStore::MANIFEST_FILE);
        $this->assertTrue($store->exists(), '먼저 만들어진 SitemapFileStore 가 페이크 local 을 보지 못합니다.');
        $this->assertSame($before, $this->realFileHashes());
    }
}
