<?php

namespace Tests\Feature\Performance;

use App\Contracts\Repositories\ActivityLogRepositoryInterface;
use App\Enums\TotalRelation;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Query\BoundedPage;
use App\Support\Query\KeysetPaginator;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Tests\TestCase;

/**
 * 지연 조인 목록의 상한 계약 + 커서 전환 테스트
 *
 * 검색 밖에서도 같은 계약이 성립하는지 확인한다. 관리자 로그 목록은 계속 쌓이기만
 * 하는 대표적인 대용량 목록이며, 계약이 검색 전용이면 재사용됐다고 말할 수 없다.
 */
class DeferredJoinBoundedTotalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 현재 페이지 번호를 고정합니다.
     *
     * 저장소는 요청에서 페이지를 해석하므로(`Paginator::resolveCurrentPage`), 테스트에서
     * 페이지를 바꾸려면 해석기를 갈아 끼워야 한다. 필터 배열의 `page` 는 읽지 않는다.
     *
     * @param  int  $page  고정할 페이지 번호
     */
    private function onPage(int $page): void
    {
        Paginator::currentPageResolver(static fn () => $page);
    }

    /**
     * 테스트 종료 시 페이지 해석기를 되돌립니다.
     */
    protected function tearDown(): void
    {
        Paginator::currentPageResolver(static fn () => 1);

        parent::tearDown();
    }

    /**
     * 활동 로그를 원하는 건수만큼 만듭니다.
     *
     * @param  int  $count  생성할 건수
     */
    private function seedLogs(int $count): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < $count; $i++) {
            ActivityLog::create([
                'log_type' => 'admin',
                'action' => 'test.action',
                'user_id' => $user->id,
                'description_key' => 'test',
                'ip_address' => '127.0.0.1',
            ]);
        }
    }

    /**
     * 상한을 넘긴 목록이 BoundedPage 로 응답하며 마지막 페이지를 감추는지 확인
     */
    public function test_deferred_join_returns_bounded_page_when_over_cap(): void
    {
        $this->seedLogs(9);
        config(['g7_settings.core.pagination.result_cap' => 4]);

        $page = app(ActivityLogRepositoryInterface::class)->getPaginated(['per_page' => 2]);

        $this->assertInstanceOf(BoundedPage::class, $page);
        $this->assertSame(4, $page->total());
        $this->assertSame(TotalRelation::AtLeast, $page->totalRelation());
        // 총 건수를 모르면 마지막 페이지 번호는 계산할 수 없다
        $this->assertNull($page->lastPage());
        // 그래도 "다음" 이동은 열려 있어야 한다
        $this->assertTrue($page->hasMorePages());
        $this->assertCount(2, $page->items());
    }

    /**
     * 상한 이하면 정확한 총 건수와 마지막 페이지가 그대로 나오는지 확인
     */
    public function test_deferred_join_stays_exact_under_cap(): void
    {
        $this->seedLogs(5);
        config(['g7_settings.core.pagination.result_cap' => 100]);

        $page = app(ActivityLogRepositoryInterface::class)->getPaginated(['per_page' => 2]);

        $this->assertSame(5, $page->total());
        $this->assertSame(3, $page->lastPage());
        $this->assertTrue($page->hasMorePages());
    }

    /**
     * 마지막 페이지에서는 "다음" 이 닫히는지 확인
     *
     * per_page + 1 실측이 경계에서 어긋나면 마지막 페이지에서도 다음 버튼이 남는다.
     */
    public function test_last_page_closes_next_navigation(): void
    {
        $this->seedLogs(5);
        config(['g7_settings.core.pagination.result_cap' => 3]);

        $this->onPage(3);
        $page = app(ActivityLogRepositoryInterface::class)->getPaginated(['per_page' => 2]);

        $this->assertCount(1, $page->items());
        $this->assertFalse($page->hasMorePages());
    }

    /**
     * 깊은 페이지에서도 offset 이 밀리지 않는지 확인
     *
     * offset 을 per_page + 1 배수로 계산하면 페이지가 깊어질수록 경계가 밀려
     * 뒤쪽 페이지에서 행이 통째로 사라진다.
     */
    public function test_deep_page_offset_does_not_drift(): void
    {
        $this->seedLogs(10);
        config(['g7_settings.core.pagination.result_cap' => 4]);

        $repository = app(ActivityLogRepositoryInterface::class);

        $seen = [];
        for ($p = 1; $p <= 5; $p++) {
            $this->onPage($p);
            foreach ($repository->getPaginated(['per_page' => 2])->items() as $row) {
                $seen[] = $row->id;
            }
        }

        $this->assertCount(10, $seen);
        $this->assertSame(count($seen), count(array_unique($seen)));
    }

    /**
     * 커서를 주면 키셋 방식으로 응답하는지 확인
     */
    public function test_cursor_request_switches_to_keyset(): void
    {
        $this->seedLogs(6);

        $repository = app(ActivityLogRepositoryInterface::class);

        // 첫 페이지는 페이지 번호 방식 — 여기서 다음 커서를 얻을 수 없으므로
        // 커서 모드 첫 진입은 빈 문자열이 아닌 "임의의 유효하지 않은 값" 으로 확인한다.
        $page = $repository->getPaginated(['per_page' => 2, 'cursor' => 'invalid-cursor']);

        $this->assertInstanceOf(CursorPaginator::class, $page);
        // 형식이 깨진 커서는 첫 페이지로 되돌린다 (URL 을 손으로 고쳤다고 오류를 띄우지 않는다)
        $this->assertCount(2, $page->items());
    }

    /**
     * 커서로 끝까지 훑으면 모든 행이 정확히 한 번씩 나오는지 확인
     */
    public function test_cursor_round_trip_covers_every_row_once(): void
    {
        $this->seedLogs(7);

        $repository = app(ActivityLogRepositoryInterface::class);

        $seen = [];
        $cursor = 'invalid-cursor'; // 첫 진입 (첫 페이지로 해석된다)

        for ($i = 0; $i < 10; $i++) {
            /** @var CursorPaginator $page */
            $page = $repository->getPaginated(['per_page' => 2, 'cursor' => $cursor]);

            foreach ($page->items() as $row) {
                $seen[] = $row->id;
            }

            $next = KeysetPaginator::nextCursor($page);
            if ($next === null) {
                break;
            }
            $cursor = $next;
        }

        $this->assertCount(7, $seen);
        $this->assertSame(count($seen), count(array_unique($seen)));
    }
}
