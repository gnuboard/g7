<?php

namespace Tests\Feature\Seo;

use App\Contracts\Repositories\SitemapUrlRepositoryInterface;
use App\Extension\HookManager;
use App\Models\SitemapUrl;
use App\Seo\SitemapIndexer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 사이트맵 색인 일괄 제거 테스트 (KVE-2026-2133 배치 deindex 인프라)
 *
 * 부모 리소스(게시판)가 삭제·비공개되어 자식(게시글)이 한꺼번에 빠질 때 리소스마다
 * 삭제 쿼리를 내지 않고 ID 목록으로 정리한다.
 */
class SitemapBatchDeindexTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 테스트 전용 리소스 유형 (기존 데이터와 공존)
     */
    private const TYPE = 'kve_batch_probe';

    private SitemapUrlRepositoryInterface $repository;

    /**
     * 저장소를 컨테이너에서 해석합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(SitemapUrlRepositoryInterface::class);
    }

    /**
     * 지정한 ID 의 행만 지우고 같은 유형의 다른 ID·다른 유형의 같은 ID 는 남긴다.
     *
     * @scenario lifecycle=delete
     *
     * @effects batch_deindex_removes_only_given_ids
     *
     * @return void 반환값 없음
     */
    public function test_remove_many_deletes_only_given_ids_of_that_type(): void
    {
        foreach (['1', '2', '3'] as $id) {
            $this->seedRow(self::TYPE, $id);
        }
        $this->seedRow(self::TYPE.'_other', '1');

        $deleted = $this->repository->removeManyForResource(self::TYPE, [1, '2']);

        $this->assertSame(2, $deleted);
        $this->assertSame(['3'], SitemapUrl::where('resource_type', self::TYPE)->pluck('resource_id')->all());
        $this->assertSame(1, SitemapUrl::where('resource_type', self::TYPE.'_other')->count());
    }

    /**
     * 청크 크기(1,000)를 넘는 목록도 한 번의 호출로 모두 지운다.
     *
     * @scenario lifecycle=delete
     *
     * @effects batch_deindex_removes_only_given_ids
     *
     * @return void 반환값 없음
     */
    public function test_remove_many_handles_more_than_one_chunk(): void
    {
        $ids = range(1, 1205);
        $now = now();
        foreach (array_chunk($ids, 500) as $chunk) {
            SitemapUrl::insert(array_map(fn ($id) => $this->row(self::TYPE, (string) $id, $now), $chunk));
        }

        $deleted = app(SitemapIndexer::class)->deindexResources(self::TYPE, $ids);

        $this->assertSame(1205, $deleted);
        $this->assertSame(0, SitemapUrl::where('resource_type', self::TYPE)->count());
    }

    /**
     * 빈 목록은 아무것도 지우지 않는다.
     *
     * @scenario lifecycle=delete
     *
     * @effects batch_deindex_removes_only_given_ids
     *
     * @return void 반환값 없음
     */
    public function test_empty_list_is_noop(): void
    {
        $this->seedRow(self::TYPE, '9');

        $this->assertSame(0, app(SitemapIndexer::class)->deindexResources(self::TYPE, []));
        $this->assertSame(1, SitemapUrl::where('resource_type', self::TYPE)->count());
    }

    /**
     * 여러 리소스를 한 번에 색인하면 기존 행을 교체하고, 필터 훅은 리소스마다 적용된다 —
     * 필터가 항목을 모두 뺀 리소스는 기존 색인이 지워진다(indexResource 와 같은 규칙).
     *
     * @scenario lifecycle=reactivate
     *
     * @effects batch_index_replaces_rows_and_keeps_per_resource_filter
     *
     * @return void 반환값 없음
     */
    public function test_index_many_replaces_rows_and_applies_filter_per_resource(): void
    {
        $this->seedRow(self::TYPE, '2');
        $this->seedRow(self::TYPE, '3');
        $this->seedRow(self::TYPE, '9');

        $filtered = [];
        HookManager::addFilter(SitemapIndexer::COLLECT_FILTER, function (array $entries, string $type, $id) use (&$filtered) {
            if ($type === self::TYPE) {
                $filtered[] = (string) $id;
            }

            return $type === self::TYPE && (string) $id === '3' ? [] : $entries;
        });

        try {
            $inserted = app(SitemapIndexer::class)->indexResources(self::TYPE, 'kve-test', [
                1 => [['loc' => 'https://example.test/new/1']],
                2 => [['loc' => 'https://example.test/new/2']],
                3 => [['loc' => 'https://example.test/new/3']],
            ]);
        } finally {
            HookManager::clearFilter(SitemapIndexer::COLLECT_FILTER);
        }

        $this->assertSame(2, $inserted);
        $this->assertSame(['1', '2', '3'], $filtered, '필터 훅은 리소스마다 한 번씩 호출돼야 합니다.');
        $this->assertSame(
            ['1' => 'https://example.test/new/1', '2' => 'https://example.test/new/2', '9' => 'https://example.test/'.self::TYPE.'/9'],
            SitemapUrl::where('resource_type', self::TYPE)->orderBy('resource_id')->pluck('loc', 'resource_id')->all()
        );
    }

    /**
     * 청크 크기(1,000)를 넘는 리소스도 한 번의 호출로 모두 색인하고, 청크마다 삽입은 한 번이다.
     *
     * @scenario lifecycle=reactivate
     *
     * @effects batch_index_replaces_rows_and_keeps_per_resource_filter
     *
     * @return void 반환값 없음
     */
    public function test_index_many_handles_more_than_one_chunk_with_one_insert_per_chunk(): void
    {
        $entriesById = [];
        foreach (range(1, 1205) as $id) {
            $entriesById[$id] = [['loc' => "https://example.test/many/{$id}"]];
        }

        $inserts = 0;
        DB::listen(function ($query) use (&$inserts) {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'sitemap_urls')) {
                $inserts++;
            }
        });

        $inserted = app(SitemapIndexer::class)->indexResources(self::TYPE, 'kve-test', $entriesById);

        $this->assertSame(1205, $inserted);
        $this->assertSame(1205, SitemapUrl::where('resource_type', self::TYPE)->count());
        $this->assertSame(2, $inserts, '1,205건은 청크 2개 — 삽입 쿼리 2회여야 합니다.');
    }

    /**
     * 테스트 행 1개를 넣습니다.
     *
     * @param  string  $type  리소스 유형
     * @param  string  $id  리소스 ID
     * @return void 반환값 없음
     */
    private function seedRow(string $type, string $id): void
    {
        SitemapUrl::insert([$this->row($type, $id, now())]);
    }

    /**
     * 삽입용 행 배열을 만듭니다.
     *
     * @param  string  $type  리소스 유형
     * @param  string  $id  리소스 ID
     * @param  mixed  $now  타임스탬프
     * @return array<string, mixed> 행
     */
    private function row(string $type, string $id, mixed $now): array
    {
        $loc = "https://example.test/{$type}/{$id}";

        return [
            'resource_type' => $type,
            'resource_id' => $id,
            'loc' => $loc,
            'loc_hash' => hash('sha256', $loc),
            'contributor' => 'kve-test',
            'is_visible' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
