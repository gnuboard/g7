<?php

namespace Tests\Feature\Seo;

use App\Contracts\Repositories\SitemapUrlRepositoryInterface;
use App\Models\SitemapUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SitemapUrlRepository 통합 테스트 (S4 증분 저장소)
 *
 * 검증 목적:
 * - upsertForResource 멱등 (같은 입력 반복 호출 → 테이블 내용 동일)
 * - loc 변경(슬러그 변경) 흡수 (옛 loc 행 잔존 금지)
 * - removeForResource 로 리소스 단위 제거
 * - streamVisible id 순서 + contributor 스코핑 + is_visible 필터
 * - replaceAllForContributor 전량 대체 (타 기여자 미영향)
 * - 긴 loc(2000자) 도 loc_hash unique 인덱스로 안전 저장
 */
class SitemapUrlRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private SitemapUrlRepositoryInterface $repository;

    /**
     * 테스트 초기화 - 저장소를 컨테이너에서 해석합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(SitemapUrlRepositoryInterface::class);
    }

    /**
     * upsertForResource: 항목을 삽입하고 반복 호출해도 테이블 내용이 동일하다(멱등).
     */
    public function test_upsert_for_resource_is_idempotent(): void
    {
        $entries = [[
            'loc' => 'https://example.com/shop/products/1',
            'contributor' => 'sirsoft-ecommerce',
            'lastmod' => '2026-07-18T00:00:00+00:00',
            'changefreq' => 'weekly',
            'priority' => 0.8,
        ]];

        $this->repository->upsertForResource('product', '1', $entries);
        $this->repository->upsertForResource('product', '1', $entries);

        $rows = SitemapUrl::where('resource_type', 'product')->where('resource_id', '1')->get();

        $this->assertCount(1, $rows, '같은 입력 반복 호출 시 행이 중복되면 안 됩니다.');
        $this->assertSame('https://example.com/shop/products/1', $rows[0]->loc);
        $this->assertSame('sirsoft-ecommerce', $rows[0]->contributor);
        $this->assertSame(0.8, $rows[0]->priority);
        $this->assertTrue($rows[0]->is_visible);
    }

    /**
     * upsertForResource: loc(슬러그) 변경 시 옛 loc 행이 남지 않는다.
     */
    public function test_upsert_for_resource_absorbs_loc_change(): void
    {
        $this->repository->upsertForResource('category', '5', [[
            'loc' => 'https://example.com/shop/category/old-slug',
            'contributor' => 'sirsoft-ecommerce',
        ]]);

        $this->repository->upsertForResource('category', '5', [[
            'loc' => 'https://example.com/shop/category/new-slug',
            'contributor' => 'sirsoft-ecommerce',
        ]]);

        $locs = SitemapUrl::where('resource_type', 'category')->where('resource_id', '5')->pluck('loc');

        $this->assertSame(['https://example.com/shop/category/new-slug'], $locs->all());
    }

    /**
     * removeForResource: 해당 리소스 행만 제거한다.
     */
    public function test_remove_for_resource_deletes_only_that_resource(): void
    {
        $this->repository->upsertForResource('product', '1', [['loc' => 'https://example.com/p/1', 'contributor' => 'sirsoft-ecommerce']]);
        $this->repository->upsertForResource('product', '2', [['loc' => 'https://example.com/p/2', 'contributor' => 'sirsoft-ecommerce']]);

        $this->repository->removeForResource('product', '1');

        $this->assertSame(0, SitemapUrl::where('resource_type', 'product')->where('resource_id', '1')->count());
        $this->assertSame(1, SitemapUrl::where('resource_type', 'product')->where('resource_id', '2')->count());
    }

    /**
     * streamVisible: is_visible=true 행을 id 순으로, contributor 스코핑하여 반환한다.
     */
    public function test_stream_visible_orders_by_id_and_scopes_by_contributor(): void
    {
        $this->repository->upsertForResource('board_post', '1', [['loc' => 'https://example.com/board/a/1', 'contributor' => 'sirsoft-board']]);
        $this->repository->upsertForResource('product', '1', [['loc' => 'https://example.com/shop/products/1', 'contributor' => 'sirsoft-ecommerce']]);
        $this->repository->upsertForResource('board_post', '2', [['loc' => 'https://example.com/board/a/2', 'contributor' => 'sirsoft-board']]);

        $all = collect($this->repository->streamVisible())->map(fn ($r) => $r->loc)->all();
        $boardOnly = collect($this->repository->streamVisible('sirsoft-board'))->map(fn ($r) => $r->loc)->all();

        $this->assertSame([
            'https://example.com/board/a/1',
            'https://example.com/shop/products/1',
            'https://example.com/board/a/2',
        ], $all, 'id(삽입) 순서를 유지해야 합니다.');

        $this->assertSame([
            'https://example.com/board/a/1',
            'https://example.com/board/a/2',
        ], $boardOnly, 'contributor 스코핑이 적용돼야 합니다.');
    }

    /**
     * countVisible: 공개 행 개수를 센다.
     */
    public function test_count_visible_counts_rows(): void
    {
        $this->assertSame(0, $this->repository->countVisible());

        $this->repository->upsertForResource('page', '1', [['loc' => 'https://example.com/page/a', 'contributor' => 'sirsoft-page']]);
        $this->repository->upsertForResource('page', '2', [['loc' => 'https://example.com/page/b', 'contributor' => 'sirsoft-page']]);

        $this->assertSame(2, $this->repository->countVisible());
    }

    /**
     * replaceAllForContributor: 기여자 스코프를 전량 대체하고 타 기여자는 건드리지 않는다.
     */
    public function test_replace_all_for_contributor_replaces_scope_only(): void
    {
        // 다른 기여자(page) 는 보존돼야 한다
        $this->repository->upsertForResource('page', '1', [['loc' => 'https://example.com/page/a', 'contributor' => 'sirsoft-page']]);

        // board 기여자 1차 적재
        $this->repository->replaceAllForContributor('sirsoft-board', [
            ['loc' => 'https://example.com/board/a', 'resource_type' => 'board', 'resource_id' => '1'],
            ['loc' => 'https://example.com/board/a/1', 'resource_type' => 'board_post', 'resource_id' => '1'],
        ]);

        // board 기여자 2차 전량 대체 (1건으로 축소)
        $this->repository->replaceAllForContributor('sirsoft-board', [
            ['loc' => 'https://example.com/board/b', 'resource_type' => 'board', 'resource_id' => '2'],
        ]);

        $board = SitemapUrl::where('contributor', 'sirsoft-board')->pluck('loc')->all();
        $page = SitemapUrl::where('contributor', 'sirsoft-page')->pluck('loc')->all();

        $this->assertSame(['https://example.com/board/b'], $board, 'board 스코프는 전량 대체돼야 합니다.');
        $this->assertSame(['https://example.com/page/a'], $page, '다른 기여자 행은 보존돼야 합니다.');
    }

    /**
     * replaceAllForContributor 는 삽입된 행 수를 반환한다 (진행상황 누적 URL 표기용).
     *
     * 청크 경계를 넘겨도 정확히 집계돼야 한다 (INSERT_CHUNK=1000 이므로 2건은 단일 청크지만
     * 반환 계약 자체를 고정한다).
     */
    public function test_replace_all_for_contributor_returns_inserted_count(): void
    {
        $count = $this->repository->replaceAllForContributor('sirsoft-board', [
            ['loc' => 'https://example.com/board/a', 'resource_type' => 'board', 'resource_id' => '1'],
            ['loc' => 'https://example.com/board/a/1', 'resource_type' => 'board_post', 'resource_id' => '1'],
            ['loc' => 'https://example.com/board/a/2', 'resource_type' => 'board_post', 'resource_id' => '2'],
        ]);

        $this->assertSame(3, $count);

        // 재대체 시에도 새로 삽입된 건수를 반환 (기존 삭제분은 제외)
        $recount = $this->repository->replaceAllForContributor('sirsoft-board', [
            ['loc' => 'https://example.com/board/b', 'resource_type' => 'board', 'resource_id' => '2'],
        ]);

        $this->assertSame(1, $recount);
    }

    /**
     * 긴 loc(2000자) 도 loc_hash 로 안전 저장 및 식별된다 (MySQL 키 길이 회피).
     */
    public function test_long_loc_is_stored_via_hash_identity(): void
    {
        $longLoc = 'https://example.com/'.str_repeat('a', 1990);

        $this->repository->upsertForResource('product', '9', [['loc' => $longLoc, 'contributor' => 'sirsoft-ecommerce']]);
        // 동일 loc 재호출 → 멱등 (해시 identity 로 대체)
        $this->repository->upsertForResource('product', '9', [['loc' => $longLoc, 'contributor' => 'sirsoft-ecommerce']]);

        $rows = SitemapUrl::where('resource_type', 'product')->where('resource_id', '9')->get();

        $this->assertCount(1, $rows);
        $this->assertSame($longLoc, $rows[0]->loc);
        $this->assertSame(hash('sha256', $longLoc), $rows[0]->loc_hash);
    }
}
