<?php

namespace Tests\Feature\Seo;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Contracts\Repositories\SitemapUrlRepositoryInterface;
use App\Enums\SitemapGenerationMode;
use App\Extension\Storage\CoreStorageDriver;
use App\Models\SitemapUrl;
use App\Seo\AbstractSitemapContributor;
use App\Seo\SitemapFileStore;
use App\Seo\SitemapGenerator;
use App\Seo\SitemapManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * SitemapManager 재생성 모드 분기 테스트 (S4 ㉑)
 *
 * 검증 목적 (DoD: 모드 분기 green + 도메인 재쿼리 0):
 * - full: 저장소가 채워져 있어도 기여자를 스트리밍해 전량 대체
 * - auto: 저장소 비었으면 full(기여자 드레인), 채워졌으면 incremental(드레인 없음)
 * - incremental: 기여자 재쿼리 없이 저장소 스트림만으로 파일 재작성
 */
class SitemapManagerModeTest extends TestCase
{
    use RefreshDatabase;

    private CoreStorageDriver $storage;

    private SitemapUrlRepositoryInterface $repository;

    /**
     * 테스트 초기화
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.locale', 'ko');
        Config::set('app.supported_locales', ['ko']);
        Config::set('g7_settings.core.seo.sitemap_enabled', true);

        Storage::fake('local');
        $this->storage = new CoreStorageDriver('local');
        $this->repository = $this->app->make(SitemapUrlRepositoryInterface::class);
    }

    /**
     * 호출 횟수를 세는 가짜 기여자를 만듭니다.
     *
     * @param  array<int, array{url: string, resource_type: string, resource_id: ?string}>  $entries  emit 항목
     * @return AbstractSitemapContributor 카운터가 달린 기여자
     */
    private function countingContributor(array $entries): AbstractSitemapContributor
    {
        return new class($entries) extends AbstractSitemapContributor
        {
            public int $drainCount = 0;

            /**
             * @param  array<int, array<string, mixed>>  $entries  emit 항목
             */
            public function __construct(private array $entries) {}

            public function getIdentifier(): string
            {
                return 'fake-contributor';
            }

            public function getUrlsLazy(): iterable
            {
                $this->drainCount++;
                yield from $this->entries;
            }
        };
    }

    /**
     * 매니저를 구성합니다 (기여자 등록 포함).
     *
     * @param  AbstractSitemapContributor  $contributor  등록할 기여자
     * @return SitemapManager 매니저
     */
    private function makeManager(AbstractSitemapContributor $contributor): SitemapManager
    {
        $generator = $this->app->make(SitemapGenerator::class);
        $generator->registerContributor($contributor);

        return new SitemapManager(
            $generator,
            Mockery::spy(CacheInterface::class),
            Mockery::spy(ConfigRepositoryInterface::class),
            $this->storage,
            $this->repository,
            $this->app->make(\App\Seo\SitemapProgress::class),
        );
    }

    /**
     * 커밋된 첫 자식 sitemap 의 XML 본문을 반환합니다.
     *
     * @return string 자식 XML
     */
    private function firstChildXml(): string
    {
        return (string) $this->storage->get(SitemapFileStore::CATEGORY, SitemapFileStore::LIVE_DIR.'/'.SitemapFileStore::childFilename(1));
    }

    /**
     * full: 저장소가 이미 채워져 있어도 기여자를 스트리밍해 전량 대체한다.
     */
    public function test_full_mode_rebuilds_store_from_contributors(): void
    {
        // 옛(stale) 저장소 상태 — 전량 대체로 사라져야 한다
        $this->repository->replaceAllForContributor('fake-contributor', [
            ['loc' => url('/stale'), 'resource_type' => 'product', 'resource_id' => '999'],
        ]);

        $contributor = $this->countingContributor([
            ['url' => '/fresh', 'resource_type' => 'product', 'resource_id' => '1'],
        ]);

        $this->makeManager($contributor)->regenerate(SitemapGenerationMode::Full);

        $this->assertSame(1, $contributor->drainCount, 'full 은 기여자를 드레인해야 합니다.');
        $locs = SitemapUrl::where('contributor', 'fake-contributor')->pluck('loc')->all();
        $this->assertSame([url('/fresh')], $locs, 'full 은 저장소를 전량 대체해야 합니다.');
        $this->assertStringContainsString(url('/fresh'), $this->firstChildXml());
    }

    /**
     * auto: 저장소가 비어 있으면 full 로 동작(기여자 드레인 + 저장소 채움).
     */
    public function test_auto_mode_is_full_when_store_empty(): void
    {
        $contributor = $this->countingContributor([
            ['url' => '/a', 'resource_type' => 'product', 'resource_id' => '1'],
        ]);

        $this->makeManager($contributor)->regenerate(SitemapGenerationMode::Auto);

        $this->assertSame(1, $contributor->drainCount, '빈 저장소에서 auto 는 full 이어야 합니다.');
        $this->assertSame(1, $this->repository->countVisible());
    }

    /**
     * auto: 저장소가 채워져 있으면 incremental 로 동작(기여자 드레인 없음).
     */
    public function test_auto_mode_is_incremental_when_store_populated(): void
    {
        // 다른 경로(리스너)로 채워진 저장소 — auto 는 이를 재쿼리 없이 사용
        $this->repository->upsertForResource('page', '1', [['loc' => url('/page/seeded'), 'contributor' => 'sirsoft-page']]);

        $contributor = $this->countingContributor([
            ['url' => '/should-not-appear', 'resource_type' => 'product', 'resource_id' => '1'],
        ]);

        $this->makeManager($contributor)->regenerate(SitemapGenerationMode::Auto);

        $this->assertSame(0, $contributor->drainCount, '채워진 저장소에서 auto 는 기여자를 재쿼리하면 안 됩니다.');

        $xml = $this->firstChildXml();
        $this->assertStringContainsString(url('/page/seeded'), $xml);
        $this->assertStringNotContainsString(url('/should-not-appear'), $xml);
    }

    /**
     * incremental: 기여자 재쿼리 없이 저장소 스트림만으로 파일을 재작성한다.
     *
     * @scale n=1500000 asserts=no_full_table_load
     */
    public function test_incremental_mode_streams_store_without_domain_requery(): void
    {
        $this->repository->upsertForResource('board_post', '1', [['loc' => url('/board/a/1'), 'contributor' => 'sirsoft-board']]);

        $contributor = $this->countingContributor([
            ['url' => '/should-not-appear', 'resource_type' => 'product', 'resource_id' => '1'],
        ]);

        $this->makeManager($contributor)->regenerate(SitemapGenerationMode::Incremental);

        $this->assertSame(0, $contributor->drainCount, 'incremental 은 기여자를 재쿼리하면 안 됩니다.');
        $this->assertStringContainsString(url('/board/a/1'), $this->firstChildXml());
    }
}
