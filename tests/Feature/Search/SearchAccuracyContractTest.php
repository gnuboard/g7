<?php

namespace Tests\Feature\Search;

use App\Enums\TotalRelation;
use App\Models\User;
use App\Support\Query\BoundedPaginator;
use App\Support\Query\PaginationLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 검색·목록 총 건수 정확도 계약 테스트
 *
 * 화면이 "1,234건" 과 "10,000건 이상" 을 구분해 말할 수 있으려면, 서버가 그 구분을
 * 응답에 실어 보내야 한다. 세지 않은 값을 정확한 것처럼 말하지 않는다.
 *
 * @scenario pagination-accuracy-contract
 *
 * @effects exact_total_reports_exact,
 *          bounded_total_reports_at_least,
 *          bounded_total_hides_last_page,
 *          bounded_total_keeps_next,
 *          page_limit_rejects_abusive_page,
 *          search_message_switches_on_accuracy
 */
class SearchAccuracyContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 상한 이하면 총 건수가 정확하고 마지막 페이지가 계산되는지 확인
     *
     * @effects exact_total_reports_exact
     */
    public function test_exact_total_reports_exact_and_last_page(): void
    {
        User::factory()->count(7)->create();

        $page = BoundedPaginator::paginate(User::query(), perPage: 3, page: 1, resultCap: 100);

        $this->assertSame(TotalRelation::Exact, $page->totalRelation());
        $this->assertTrue($page->totalRelation()->isExact());
        $this->assertSame(User::query()->count(), $page->total());
        $this->assertNotNull($page->lastPage(), '정확한 총 건수에서는 마지막 페이지가 계산된다');
    }

    /**
     * 상한 초과 시 하한으로 보고하고 마지막 페이지를 비우는지 확인
     *
     * @effects bounded_total_reports_at_least, bounded_total_hides_last_page
     */
    public function test_bounded_total_reports_at_least_and_hides_last_page(): void
    {
        User::factory()->count(12)->create();

        $page = BoundedPaginator::paginate(User::query(), perPage: 3, page: 1, resultCap: 5);

        $this->assertSame(TotalRelation::AtLeast, $page->totalRelation());
        $this->assertFalse($page->totalRelation()->isExact());
        $this->assertSame(5, $page->total(), '상한을 넘으면 상한값을 하한으로 보고한다');
        $this->assertNull($page->lastPage(), '마지막 페이지는 계산할 수 없다');
    }

    /**
     * 상한을 넘겨도 "다음" 이동이 끝까지 열려 있는지 확인
     *
     * @effects bounded_total_keeps_next
     */
    public function test_bounded_total_keeps_next_navigation_open(): void
    {
        User::factory()->count(12)->create();

        $reached = [];

        for ($pageNumber = 1; $pageNumber <= 5; $pageNumber++) {
            $page = BoundedPaginator::paginate(User::query(), perPage: 3, page: $pageNumber, resultCap: 5);

            foreach ($page->items() as $user) {
                $reached[] = $user->id;
            }

            if (! $page->hasMorePages()) {
                break;
            }
        }

        $this->assertSame(
            User::query()->orderBy('id')->pluck('id')->all(),
            collect($reached)->sort()->values()->all(),
            '상한과 무관하게 마지막 행까지 도달할 수 있어야 한다'
        );
    }

    /**
     * 통합검색 응답이 정확도 메타를 함께 내보내는지 확인
     *
     * @effects search_message_switches_on_accuracy
     */
    public function test_search_response_carries_accuracy_meta(): void
    {
        $response = $this->getJson('/api/search?'.http_build_query(['q' => '검색어테스트']));

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertArrayHasKey('total_relation', $data, '정확도를 응답에 실어야 화면이 문구를 고를 수 있다');
        $this->assertArrayHasKey('total_is_exact', $data);
        $this->assertArrayHasKey('result_cap', $data);
        $this->assertSame(TotalRelation::Exact->value, $data['total_relation']);
        $this->assertTrue($data['total_is_exact']);
    }

    /**
     * 남용 방지용 페이지 상한이 적용되는지 확인
     *
     * 정상 탐색은 has_more_pages 로 열려 있고, 상한은 임의의 큰 페이지 번호를 직접
     * 던져 초대형 OFFSET 을 만드는 것만 막는다.
     *
     * @effects page_limit_rejects_abusive_page
     */
    public function test_page_number_upper_bound_is_enforced(): void
    {
        config(['g7_settings.core.pagination.max_page' => 50]);

        $maxPage = PaginationLimits::maxPage('search');
        $this->assertSame(50, $maxPage);

        $response = $this->getJson('/api/search?'.http_build_query(['q' => '검색어테스트', 'page' => 999999]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['page']);
    }

    /**
     * 상한이 0 이면 무제한으로 해석되는지 확인
     */
    public function test_zero_cap_means_unlimited(): void
    {
        config(['g7_settings.core.pagination.result_cap' => 0]);

        $this->assertNull(PaginationLimits::resultCap('search'));

        User::factory()->count(4)->create();

        [$total, $relation] = BoundedPaginator::countWithCap(User::query(), PaginationLimits::resultCap('search'));

        $this->assertSame(User::query()->count(), $total);
        $this->assertSame(TotalRelation::Exact, $relation);
    }
}
