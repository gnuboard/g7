<?php

namespace Tests\Feature\Repositories;

use App\Models\Schedule;
use App\Repositories\ScheduleRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 스케줄 목록 쪽 이동 — 정렬 값이 같은 행의 순서 고정 회귀 테스트
 *
 * 결함(7.1.0 영역 J D-J1): 예약 작업 관리의 쪽 이동을 살린 뒤(7.1.0), 등록 시각이 같은 스케줄이
 * 한 쪽을 넘으면 2쪽이 1쪽의 행을 다시 보여 주고 일부 행은 어느 쪽에도 나오지 않았다. 확장 설치가
 * 스케줄 여러 개를 같은 초에 등록하므로 실제 사이트에서 흔히 생긴다. 정렬이 `created_at` 하나뿐이라
 * MySQL 이 같은 값끼리의 순서를 쪽마다 달리 고를 수 있었다. 정렬 끝에 `id` 를 같은 방향으로 덧붙인다.
 *
 * 로컬 MySQL 은 migrate:fresh 의 파티션·인덱스 DDL 에서 OOM 이 나므로 트랜잭션 격리를 쓴다.
 *
 * 시나리오 SSoT: tests/scenarios/schedule-pagination-tie-break.yaml
 */
#[Group('core')]
class SchedulePaginationTieBreakTest extends TestCase
{
    use DatabaseTransactions;

    private const SAME_MOMENT_UTC = '2001-02-03 04:05:06';

    /**
     * 등록 시각이 같은 스케줄 25개를 20개씩 넘기면 두 쪽이 겹침·빠짐 없이 25개를 모두 보여 준다.
     * (실측 재현 조건 그대로 — 필터 없이 기본 정렬, 트랜잭션 안에서 기존 스케줄을 비운다.)
     *
     * @scenario case=same_moment_pages
     *
     * @effects schedule_pages_cover_every_row_once
     *
     * @return void 반환값 없음
     */
    public function test_pages_do_not_repeat_or_drop_rows_with_identical_created_at(): void
    {
        Schedule::query()->delete();
        $created = $this->makeSameMomentSchedules(25);

        $seen = [];
        foreach ([1, 2] as $page) {
            Paginator::currentPageResolver(fn () => $page);
            $paginator = app(ScheduleRepository::class)->getPaginatedSchedules([
                'per_page' => 20,
            ]);
            $this->assertSame(25, $paginator->total());
            foreach ($paginator->items() as $item) {
                $seen[] = $item->id;
            }
        }

        $this->assertCount(25, $seen);
        $this->assertSame(25, count(array_unique($seen)), '쪽 사이에 같은 행이 다시 나왔다');
        $this->assertEqualsCanonicalizing($created, $seen);
    }

    /**
     * 목록 조회 SQL 의 정렬은 요청한 칸 다음에 id 를 같은 방향으로 덧붙인다.
     *
     * MySQL 이 같은 값끼리 고르는 순서는 실행 계획·버퍼 상태에 따라 달라 위 시험이 늘 붉지는 않으므로,
     * 정렬 절을 직접 확인해 결정적으로 잠근다(오름차순·내림차순·다른 칸 정렬 모두).
     *
     * @scenario case=order_clause_tiebreak
     *
     * @effects schedule_pages_cover_every_row_once
     *
     * @return void 반환값 없음
     */
    public function test_list_query_orders_by_id_after_requested_column(): void
    {
        $this->makeSameMomentSchedules(2);
        $cases = [
            [[], '`created_at` desc, `id` desc'],
            [['sort_by' => 'name', 'sort_order' => 'asc'], '`name` asc, `id` asc'],
            [['sort_by' => 'last_run_at', 'sort_order' => 'desc'], '`last_run_at` desc, `id` desc'],
        ];

        foreach ($cases as [$filters, $expectedOrder]) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(ScheduleRepository::class)->getPaginatedSchedules($filters + ['per_page' => 20]);
            $select = collect(DB::getQueryLog())->pluck('query')
                ->first(fn (string $sql) => str_contains($sql, 'from `'.DB::getTablePrefix().'schedules`') && str_contains($sql, 'order by'));
            DB::disableQueryLog();

            $this->assertNotNull($select, '목록 조회 SQL 을 찾지 못했다');
            $this->assertStringContainsString('order by '.$expectedOrder, $select);
        }
    }

    /**
     * 같은 등록 시각의 스케줄을 여러 개 만듭니다.
     *
     * @param  int  $count  만들 개수
     * @return array<int, int> 만든 스케줄 id 목록
     */
    private function makeSameMomentSchedules(int $count): array
    {
        $prefix = 'tiebreak-'.Str::lower(Str::random(6));
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $schedule = Schedule::create([
                'name' => $prefix.'-'.$i,
                'description' => $prefix,
                'type' => 'artisan',
                'command' => 'inspire',
                'expression' => '0 3 * * *',
                'frequency' => 'daily',
                'is_active' => false,
            ]);
            $schedule->forceFill(['created_at' => self::SAME_MOMENT_UTC])->saveQuietly();
            $ids[] = $schedule->id;
        }

        return $ids;
    }
}
