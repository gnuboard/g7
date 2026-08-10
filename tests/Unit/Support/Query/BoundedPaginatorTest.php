<?php

namespace Tests\Unit\Support\Query;

use App\Enums\TotalRelation;
use App\Models\User;
use App\Support\Query\BoundedPage;
use App\Support\Query\BoundedPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BoundedPaginator 계약 테스트
 *
 * 검색과 무관한 임의 모델(User)로 검증한다. 이 계약은 특정 도메인 지식을 갖지 않으므로,
 * 검색 화면을 거치지 않고도 성립해야 한다.
 */
class BoundedPaginatorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 상한 이하면 총 건수가 정확하고 표준 paginate 와 값이 같은지 확인
     */
    public function test_total_is_exact_when_under_cap(): void
    {
        User::factory()->count(7)->create();

        $page = BoundedPaginator::paginate(User::query(), perPage: 3, page: 1, resultCap: 100);

        $this->assertSame(User::query()->count(), $page->total());
        $this->assertSame(TotalRelation::Exact, $page->totalRelation());
        $this->assertFalse($page->isTruncated());
        $this->assertSame(100, $page->resultCap());
    }

    /**
     * 상한을 넘으면 총 건수가 하한(AtLeast)으로 보고되는지 확인
     */
    public function test_total_is_at_least_when_over_cap(): void
    {
        User::factory()->count(9)->create();

        $page = BoundedPaginator::paginate(User::query(), perPage: 2, page: 1, resultCap: 4);

        $this->assertSame(4, $page->total());
        $this->assertSame(TotalRelation::AtLeast, $page->totalRelation());
        $this->assertTrue($page->isTruncated());
    }

    /**
     * 총 건수가 잘려도 마지막 페이지 번호만 사라지고 "다음" 이동은 유지되는지 확인
     */
    public function test_truncated_hides_last_page_but_keeps_next(): void
    {
        User::factory()->count(9)->create();

        $page = BoundedPaginator::paginate(User::query(), perPage: 2, page: 1, resultCap: 4);

        $this->assertNull($page->lastPage(), '총 건수가 부정확하면 마지막 페이지를 계산할 수 없다');
        $this->assertTrue($page->hasMorePages(), '상한과 무관하게 다음 페이지 이동은 열려 있어야 한다');
    }

    /**
     * 상한을 넘긴 상태에서도 상한 뒤쪽 페이지까지 실제로 이동되는지 확인
     */
    public function test_can_navigate_past_the_cap(): void
    {
        User::factory()->count(9)->create();

        // 상한 4 < 실제 9. 5페이지(9~10번째 행)는 상한 바깥이지만 조회되어야 한다.
        $page = BoundedPaginator::paginate(User::query(), perPage: 2, page: 5, resultCap: 4);

        $this->assertCount(1, $page->items(), '상한 바깥 페이지도 실제 행을 돌려준다');
        $this->assertFalse($page->hasMorePages());
    }

    /**
     * 다음 페이지 존재 여부가 per_page + 1 실측으로 정확한지 확인
     */
    public function test_has_more_pages_is_probed_exactly(): void
    {
        User::factory()->count(6)->create();

        $notLast = BoundedPaginator::paginate(User::query(), perPage: 3, page: 1, resultCap: 100);
        $last = BoundedPaginator::paginate(User::query(), perPage: 3, page: 2, resultCap: 100);

        $this->assertTrue($notLast->hasMorePages());
        $this->assertFalse($last->hasMorePages());
        $this->assertCount(3, $notLast->items(), 'per_page + 1 로 읽어도 per_page 만 돌려준다');
        $this->assertCount(3, $last->items());
    }

    /**
     * 마지막 페이지가 정확히 채워졌을 때 다음 페이지가 없다고 판정하는지 확인
     */
    public function test_exactly_full_last_page_reports_no_more(): void
    {
        User::factory()->count(4)->create();

        $page = BoundedPaginator::paginate(User::query(), perPage: 2, page: 2, resultCap: 100);

        $this->assertFalse($page->hasMorePages());
        $this->assertSame(2, $page->lastPage());
    }

    /**
     * GROUP BY 쿼리의 총 건수가 그룹 수인지 확인 (행 수가 아님)
     */
    public function test_group_by_counts_groups_not_rows(): void
    {
        User::factory()->count(3)->create(['status' => 'active']);
        User::factory()->count(2)->create(['status' => 'inactive']);

        $query = User::query()
            ->select('status', DB::raw('count(*) as c'))
            ->groupBy('status');

        [$total, $relation] = BoundedPaginator::countWithCap($query, 100);

        $this->assertSame(2, $total, 'status 그룹은 2개');
        $this->assertSame(TotalRelation::Exact, $relation);
    }

    /**
     * 상한이 null 이면 정확한 전량 COUNT 로 동작하는지 확인
     */
    public function test_null_cap_counts_everything_exactly(): void
    {
        User::factory()->count(5)->create();

        [$total, $relation] = BoundedPaginator::countWithCap(User::query(), null);

        $this->assertSame(User::query()->count(), $total);
        $this->assertSame(TotalRelation::Exact, $relation);
    }

    /**
     * 호출자의 빌더가 페이지 조회로 오염되지 않는지 확인
     */
    public function test_caller_builder_is_not_mutated(): void
    {
        User::factory()->count(5)->create();

        $query = User::query();
        BoundedPaginator::paginate($query, perPage: 2, page: 1, resultCap: 100);

        $this->assertNull($query->toBase()->limit, '페이지 조회가 원본 빌더에 LIMIT 을 남기면 안 된다');
        $this->assertSame(5, $query->count());
    }

    /**
     * 쿼리 빌더(`DB::table`)를 그대로 넘겨도 동작하는지 확인
     *
     * 이 클래스는 "임의의 빌더" 를 받는다고 선언하고 supports() 도 쿼리 빌더를 허용한다.
     * 그런데 집계 경로가 Eloquent 전용 메서드를 무조건 부르면, 모델을 거치지 않는 호출이
     * 예외로 죽는다. 계약이 연 입구는 실제로 통해야 한다.
     */
    public function test_plain_query_builder_is_supported(): void
    {
        User::factory()->count(5)->create();

        $this->assertTrue(BoundedPaginator::supports(DB::table('users')));

        [$total, $relation] = BoundedPaginator::countWithCap(DB::table('users'), 100);

        $this->assertSame(5, $total);
        $this->assertTrue($relation->isExact());

        $page = BoundedPaginator::paginate(DB::table('users')->orderBy('id'), perPage: 2, page: 1, resultCap: 100);

        $this->assertCount(2, $page->items());
        $this->assertSame(5, $page->total());
        $this->assertTrue($page->hasMorePages());
    }

    /**
     * 관계(`$model->relation()`)를 그대로 넘겨도 동작하는지 확인
     *
     * Laravel 에서 `$user->notifications()->paginate()` 는 관계가 빌더로 호출을 넘겨 주기
     * 때문에 성립한다. 정적 메서드는 그 전달을 받지 못하므로 관계를 직접 이해해야 한다.
     * 이 입구가 막히면 "회원 1명에 종속된 목록" 이라는 가장 흔한 형태가 통째로 500 이 된다.
     */
    public function test_eloquent_relation_is_supported(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 3) as $i) {
            $user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'test',
                'data' => ['message' => 'n'.$i],
            ]);
        }

        $this->assertTrue(BoundedPaginator::supports($user->notifications()));

        $page = BoundedPaginator::paginate($user->notifications(), perPage: 2, page: 1, resultCap: 100);

        $this->assertCount(2, $page->items());
        $this->assertSame(3, $page->total(), '관계의 소속 조건이 유지되어야 한다');
        $this->assertTrue($page->hasMorePages());

        $count = BoundedPaginator::count($user->notifications(), 100);
        $this->assertSame(3, $count->total());
    }

    /**
     * 관계 입력에서도 상한이 실제로 걸리는지 확인 (회귀 수정이 성능을 되돌리지 않았는가)
     *
     * 관계를 받아들이게 넓히면서 "그냥 표준 paginate 로 흘려보내기" 로 고치면 타입 오류는
     * 사라지지만 상한이 함께 사라진다. 오류가 없어졌다는 것만으로는 이 계약이 지켜졌다고
     * 말할 수 없으므로, 관계 경로에서도 잘림이 보고되는지를 따로 못박는다.
     */
    public function test_relation_still_honours_the_cap(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 6) as $i) {
            $user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'test',
                'data' => ['message' => 'n'.$i],
            ]);
        }

        $page = BoundedPaginator::paginate($user->notifications(), perPage: 2, page: 1, resultCap: 3);

        $this->assertSame(3, $page->total(), '상한이 걸리지 않았다 — 관계 경로가 표준 집계로 새고 있다');
        $this->assertSame(TotalRelation::AtLeast, $page->totalRelation());
        $this->assertNull($page->lastPage(), '잘렸으면 마지막 페이지를 계산할 수 없다');
        $this->assertTrue($page->hasMorePages(), '상한과 무관하게 다음 이동은 열려 있어야 한다');
    }

    /**
     * 쿼리 빌더에서도 상한 초과가 "이상" 으로 보고되는지 확인
     */
    public function test_plain_query_builder_reports_at_least_over_cap(): void
    {
        User::factory()->count(5)->create();

        [$total, $relation] = BoundedPaginator::countWithCap(DB::table('users'), 2);

        $this->assertSame(2, $total);
        $this->assertSame(TotalRelation::AtLeast, $relation);
    }

    /**
     * 이 계약의 입력 범위가 표준 `paginate()` 보다 좁지 않은지 확인
     *
     * 이 계약은 기존 `->paginate()` 호출을 대체하려고 만들었다. 그런데 받는 타입이 표준보다
     * 좁으면, 바꾸는 것만으로 멀쩡하던 목록이 죽는다 — 실제로 그렇게 두 번 터졌다
     * (쿼리 빌더에서 BadMethodCallException, 관계에서 TypeError).
     *
     * 그래서 "표준에서 되는 형태는 여기서도 된다" 를 형태별로 고정한다. 새 형태를 지원하게
     * 넓힐 때 이 목록에 추가하고, 좁히는 변경은 여기서 걸린다.
     */
    public function test_accepts_every_shape_standard_paginate_accepts(): void
    {
        $user = User::factory()->create();

        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'data' => ['message' => 'n'],
        ]);

        $shapes = [
            'Eloquent 빌더' => User::query(),
            '쿼리 빌더' => DB::table('users'),
            '관계' => $user->notifications(),
        ];

        foreach ($shapes as $label => $query) {
            $this->assertTrue(
                BoundedPaginator::supports($query),
                "supports() 가 {$label} 를 거부한다 — 표준 paginate 는 받는 형태다"
            );

            // 실제로 통과하는지까지 본다. supports() 만 true 이고 본체가 죽는 경우가 있었다.
            $page = BoundedPaginator::paginate($query, perPage: 1, page: 1, resultCap: 100);

            $this->assertInstanceOf(
                BoundedPage::class,
                $page,
                "{$label} 입력이 페이지 결과를 만들지 못했다"
            );
            $this->assertSame(
                TotalRelation::Exact,
                $page->totalRelation(),
                "{$label} 입력에서 정확도 계약이 깨졌다"
            );
        }
    }

    /**
     * 정렬이 걸린 빌더도 상한 집계가 성립하는지 확인
     */
    public function test_ordered_query_is_countable(): void
    {
        User::factory()->count(5)->create();

        $page = BoundedPaginator::paginate(
            User::query()->orderBy('id', 'desc'),
            perPage: 2,
            page: 1,
            resultCap: 3
        );

        $this->assertSame(3, $page->total());
        $this->assertSame(TotalRelation::AtLeast, $page->totalRelation());
    }

    /**
     * BoundedPage 가 표준 페이지네이터 인터페이스를 그대로 만족하는지 확인
     */
    public function test_bounded_page_is_a_standard_paginator(): void
    {
        User::factory()->count(5)->create();

        $page = BoundedPaginator::paginate(User::query(), perPage: 2, page: 2, resultCap: 100);

        $this->assertInstanceOf(BoundedPage::class, $page);
        $this->assertInstanceOf(LengthAwarePaginator::class, $page);
        $this->assertSame(2, $page->currentPage());
        $this->assertSame(2, $page->perPage());
        $this->assertSame(3, $page->firstItem());
        $this->assertSame(4, $page->lastItem());
    }
}
