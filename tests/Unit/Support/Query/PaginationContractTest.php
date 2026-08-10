<?php

namespace Tests\Unit\Support\Query;

use App\Extension\HookManager;
use App\Http\Resources\BaseApiCollection;
use App\Models\User;
use App\Support\Query\BoundedPaginator;
use App\Support\Query\KeysetPaginator;
use App\Support\Query\PaginationLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 페이지네이션 공통 계약 테스트
 *
 * 응답 메타(BaseApiCollection::paginationMeta)·커서 표준(KeysetPaginator)·한계값 해석
 * (PaginationLimits)을 검색과 무관한 임의 모델로 검증한다.
 */
class PaginationContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 표준 paginate 를 쓰는 기존 컬렉션의 응답이 이전과 동일한지 확인
     *
     * 필드가 하나라도 늘거나 줄면 기존 화면의 바인딩이 조용히 어긋난다.
     */
    public function test_standard_paginator_meta_is_unchanged(): void
    {
        User::factory()->count(5)->create();

        $meta = $this->metaFor(User::query()->paginate(2, ['*'], 'page', 1));

        $this->assertSame(
            ['current_page', 'per_page', 'from', 'to', 'has_more_pages', 'last_page', 'total'],
            array_keys($meta),
            '표준 paginate 응답에는 상한형 전용 필드가 붙지 않는다'
        );
        $this->assertSame(1, $meta['current_page']);
        $this->assertSame(2, $meta['per_page']);
        $this->assertSame(3, $meta['last_page']);
        $this->assertSame(5, $meta['total']);
        $this->assertSame(1, $meta['from']);
        $this->assertSame(2, $meta['to']);
        $this->assertTrue($meta['has_more_pages']);
    }

    /**
     * 상한형 페이지가 정확도 메타를 함께 내보내는지 확인 (상한 이하)
     */
    public function test_bounded_meta_reports_exact_total(): void
    {
        User::factory()->count(5)->create();

        $meta = $this->metaFor(BoundedPaginator::paginate(User::query(), 2, 1, 100));

        $this->assertSame('exact', $meta['total_relation']);
        $this->assertTrue($meta['total_is_exact']);
        $this->assertSame(100, $meta['result_cap']);
        $this->assertSame(5, $meta['total']);
        $this->assertSame(3, $meta['last_page']);
    }

    /**
     * 상한 초과 시 정확도 메타가 하한이고 마지막 페이지가 비는지 확인
     */
    public function test_bounded_meta_reports_at_least_and_null_last_page(): void
    {
        User::factory()->count(9)->create();

        $meta = $this->metaFor(BoundedPaginator::paginate(User::query(), 2, 1, 4));

        $this->assertSame('at_least', $meta['total_relation']);
        $this->assertFalse($meta['total_is_exact']);
        $this->assertSame(4, $meta['result_cap']);
        $this->assertSame(4, $meta['total']);
        $this->assertNull($meta['last_page'], '마지막 페이지 점프는 계산 불가');
        $this->assertTrue($meta['has_more_pages'], '"다음" 이동은 계속 열려 있다');
    }

    /**
     * 단순형(simplePaginate)은 총 건수를 세지 않으므로 total/last_page 를 내보내지 않는지 확인
     */
    public function test_simple_paginator_meta_omits_total(): void
    {
        User::factory()->count(5)->create();

        $meta = $this->metaFor(User::query()->simplePaginate(2, ['*'], 'page', 1));

        $this->assertArrayNotHasKey('total', $meta, '세지 않은 값을 0 으로 채우면 화면이 그것을 사실로 읽는다');
        $this->assertArrayNotHasKey('last_page', $meta);
        $this->assertTrue($meta['has_more_pages']);
    }

    /**
     * 커서형 페이지가 앞뒤 커서를 메타로 내보내는지 확인
     */
    public function test_cursor_paginator_meta_exposes_cursors(): void
    {
        User::factory()->count(5)->create();

        $first = User::query()->orderBy('id')->cursorPaginate(2);
        $meta = $this->metaFor($first);

        $this->assertArrayHasKey('next_cursor', $meta);
        $this->assertArrayHasKey('prev_cursor', $meta);
        $this->assertNotNull($meta['next_cursor']);
        $this->assertNull($meta['prev_cursor'], '첫 페이지에는 이전 커서가 없다');
        $this->assertTrue($meta['has_more_pages']);
    }

    /**
     * 커서로 끝까지 이동해도 행이 새거나 겹치지 않는지 확인
     */
    public function test_keyset_cursor_round_trip_covers_every_row_once(): void
    {
        User::factory()->count(7)->create();

        $seen = [];
        $cursor = null;

        do {
            $page = KeysetPaginator::paginate(
                User::query(),
                perPage: 2,
                sortKeys: [['created_at', 'desc']],
                uniqueKey: 'id',
                cursor: $cursor
            );

            foreach ($page->items() as $user) {
                $seen[] = $user->id;
            }

            $cursor = KeysetPaginator::nextCursor($page);
        } while ($cursor !== null);

        $expected = User::query()->pluck('id')->all();

        sort($expected);
        $sortedSeen = $seen;
        sort($sortedSeen);

        $this->assertSame($expected, $sortedSeen, '모든 행이 정확히 한 번씩 나와야 한다');
        $this->assertSame(count($seen), count(array_unique($seen)), '중복 노출 금지');
    }

    /**
     * 깨진 커서 문자열이 예외 대신 첫 페이지로 처리되는지 확인
     */
    public function test_malformed_cursor_falls_back_to_first_page(): void
    {
        User::factory()->count(3)->create();

        $this->assertNull(KeysetPaginator::decode('not-a-valid-cursor'));

        $page = KeysetPaginator::paginate(
            User::query(),
            perPage: 2,
            sortKeys: [['id', 'desc']],
            uniqueKey: 'id',
            cursor: 'not-a-valid-cursor'
        );

        $this->assertCount(2, $page->items());
    }

    /**
     * 계산값 정렬은 커서 대상이 아님을 판정하는지 확인
     */
    public function test_supports_rejects_non_column_sort_keys(): void
    {
        $whitelist = ['created_at', 'id'];

        $this->assertTrue(KeysetPaginator::supports([['created_at', 'desc']], $whitelist));
        $this->assertFalse(
            KeysetPaginator::supports([['_ft_score', 'desc']], $whitelist),
            '계산값은 WHERE 절 경계로 쓸 수 없다'
        );
        $this->assertFalse(KeysetPaginator::supports([], $whitelist));
    }

    /**
     * 한계값이 config 기본값 → 환경설정 → 필터 훅 순으로 해석되는지 확인
     */
    public function test_pagination_limits_resolution_order(): void
    {
        config(['core.pagination.result_cap' => 5000, 'g7_settings.core.pagination' => []]);
        $this->assertSame(5000, PaginationLimits::resultCap(), 'config 기본값');

        config(['g7_settings.core.pagination.result_cap' => 777]);
        $this->assertSame(777, PaginationLimits::resultCap(), '관리자 환경설정이 config 를 덮는다');

        HookManager::addFilter('core.pagination.filter_result_cap', fn ($value) => 42);
        $this->assertSame(42, PaginationLimits::resultCap(), '확장 필터 훅이 최종 결정');
    }

    /**
     * 한계값 0 이 무제한(null)으로 해석되는지 확인
     */
    public function test_zero_limit_means_unlimited(): void
    {
        config(['g7_settings.core.pagination.result_cap' => 0, 'g7_settings.core.pagination.max_page' => 0]);

        $this->assertNull(PaginationLimits::resultCap());
        $this->assertNull(PaginationLimits::maxPage());
    }

    /**
     * 페이지네이터의 pagination 메타를 뽑아냅니다.
     *
     * @param  mixed  $paginator  페이지 결과
     * @return array<string, mixed> pagination 메타
     */
    private function metaFor(mixed $paginator): array
    {
        $collection = new PaginationMetaProbeCollection($paginator);

        return $collection->toArray(Request::create('/'))['pagination'] ?? [];
    }
}

/**
 * paginationMeta() 결과만 확인하기 위한 테스트 전용 컬렉션
 */
class PaginationMetaProbeCollection extends BaseApiCollection
{
    /**
     * pagination 메타만 담은 배열을 반환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> pagination 메타
     */
    public function toArray(Request $request): array
    {
        return $this->paginationMeta();
    }
}
