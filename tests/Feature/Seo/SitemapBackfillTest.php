<?php

namespace Tests\Feature\Seo;

use App\Extension\Storage\CoreStorageDriver;
use App\Models\SitemapUrl;
use App\Seo\AbstractSitemapContributor;
use App\Seo\SitemapFileStore;
use App\Seo\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 사이트맵 백필 통합 테스트 (S4 — 커맨드 → 잡 → 매니저 → 저장소)
 *
 * 검증 목적:
 * - seo:generate-sitemap --rebuild --sync 가 기여자로부터 sitemap_urls 를 백필하고 파일을 커밋
 * - 빈 저장소에서 --mode=auto 가 full 로 동작해 자동 백필
 */
class SitemapBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.locale', 'ko');
        Config::set('app.supported_locales', ['ko']);
        Config::set('g7_settings.core.seo.sitemap_enabled', true);
        Config::set('queue.default', 'sync');

        Storage::fake('local');

        // 컨테이너 싱글톤 generator 에 가짜 기여자를 등록 → 잡이 해석하는 매니저가 이를 사용
        $this->app->make(SitemapGenerator::class)->registerContributor($this->fakeContributor());
    }

    /**
     * resource identity 를 emit 하는 가짜 기여자를 만듭니다.
     *
     * @return AbstractSitemapContributor 가짜 기여자
     */
    private function fakeContributor(): AbstractSitemapContributor
    {
        return new class extends AbstractSitemapContributor
        {
            public function getIdentifier(): string
            {
                return 'fake-contributor';
            }

            public function getUrlsLazy(): iterable
            {
                yield ['url' => '/x/1', 'resource_type' => 'thing', 'resource_id' => '1', 'changefreq' => 'daily', 'priority' => 0.7];
                yield ['url' => '/x/2', 'resource_type' => 'thing', 'resource_id' => '2', 'changefreq' => 'daily', 'priority' => 0.7];
            }
        };
    }

    /**
     * --rebuild --sync: 기여자로부터 저장소를 백필하고 파일을 커밋한다.
     */
    public function test_rebuild_backfills_store_and_commits_files(): void
    {
        $this->artisan('seo:generate-sitemap', ['--rebuild' => true, '--sync' => true])
            ->assertExitCode(0);

        $rows = SitemapUrl::where('contributor', 'fake-contributor')->pluck('resource_id')->sort()->values()->all();
        $this->assertSame(['1', '2'], $rows, '기여자 URL 이 저장소에 백필돼야 합니다.');

        $store = new SitemapFileStore(new CoreStorageDriver('local'));
        $this->assertTrue($store->exists(), '백필 후 sitemap 파일 세트가 커밋돼야 합니다.');
    }

    /**
     * --mode=auto --sync: 빈 저장소에서 full 로 자동 백필한다.
     */
    public function test_auto_mode_backfills_when_store_empty(): void
    {
        $this->assertSame(0, SitemapUrl::count());

        $this->artisan('seo:generate-sitemap', ['--mode' => 'auto', '--sync' => true])
            ->assertExitCode(0);

        $this->assertSame(2, SitemapUrl::where('contributor', 'fake-contributor')->count(), '빈 저장소에서 auto 는 full 백필이어야 합니다.');
    }
}
