<?php

namespace Tests\Unit\Services;

use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleType;
use App\Exceptions\ScheduleAlreadyRunningException;
use App\Extension\HookManager;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ScheduleService 삭제 테스트
 *
 * 스케줄 삭제 시 관계 레코드 명시적 삭제 및 훅 실행을 검증합니다.
 */
class ScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    private ScheduleService $scheduleService;

    /**
     * 서비스 시험 준비를 합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->scheduleService = app(ScheduleService::class);
        HookManager::resetAll();
    }

    /**
     * 시험 뒤 정리를 합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    // ========================================================================
    // getPaginatedSchedules() / getHistory() - 반환 타입 해석 검증
    // ========================================================================

    /**
     * 스케줄이 존재할 때 목록 조회가 TypeError 없이 페이지네이터를 반환하는지 확인
     *
     * 회귀 배경: LengthAwarePaginator 를 import 하지 않아 반환 타입이
     * App\Services\LengthAwarePaginator 로 해석되어 목록 API 가 500 을 반환했다.
     *
     * @scenario case=service_query
     *
     * @effects schedule_service_returns_paginators
     *
     * @return void 반환값 없음
     */
    public function test_get_paginated_schedules_returns_paginator(): void
    {
        Schedule::create([
            'name' => '목록 조회 테스트 스케줄',
            'type' => ScheduleType::Artisan,
            'command' => 'inspire',
            'expression' => '* * * * *',
            'is_active' => true,
        ]);

        $result = $this->scheduleService->getPaginatedSchedules([]);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame(1, $result->total());
    }

    /**
     * 실행 이력이 존재할 때 이력 조회가 TypeError 없이 페이지네이터를 반환하는지 확인
     *
     * @scenario case=service_query
     *
     * @effects schedule_service_returns_paginators
     *
     * @return void 반환값 없음
     */
    public function test_get_history_returns_paginator(): void
    {
        $schedule = Schedule::create([
            'name' => '이력 조회 테스트 스케줄',
            'type' => ScheduleType::Artisan,
            'command' => 'inspire',
            'expression' => '* * * * *',
            'is_active' => true,
        ]);

        ScheduleHistory::create([
            'schedule_id' => $schedule->id,
            'started_at' => now(),
            'finished_at' => now(),
            'result_status' => 'success',
        ]);

        $result = $this->scheduleService->getHistory($schedule->id, []);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame(1, $result->total());
    }

    // ========================================================================
    // runSchedule() - 실행 직전 방어선 (KVE-2026-1653)
    // ========================================================================

    /**
     * 게이트를 켜고 `bash` 를 등록한 상태라도, DB 에 직접 심어진 `bash -c id` 는
     * 실행 직전 방어선에서 차단된다 (저장 검증을 우회해 들어온 값 방어).
     *
     * @scenario command_class=inline_code, enforcement_point=run
     *
     * @effects run_time_last_line_of_defense_blocks_inline_code
     *
     * @return void 반환값 없음
     */
    public function test_run_schedule_blocks_interpreter_inline_code(): void
    {
        config([
            'schedule_security.shell.enabled' => true,
            'schedule_security.shell.allowed_binaries' => ['bash'],
        ]);
        Process::fake();

        $schedule = Schedule::create([
            'name' => 'DB 직접 주입 — 인라인 코드',
            'type' => ScheduleType::Shell,
            'command' => 'bash -c id',
            'expression' => '* * * * *',
            'is_active' => true,
        ]);

        try {
            $this->scheduleService->runSchedule($schedule);
            $this->fail('인라인 코드 스케줄이 실행됨');
        } catch (ValidationException) {
            // 실행 직전 방어선이 차단 — 아래에서 미실행·실패 기록을 확인한다
        }

        Process::assertNothingRan();
        $this->assertSame(ScheduleResultStatus::Failed, $schedule->fresh()->last_result);
    }

    // ========================================================================
    // delete() - 관계 레코드 명시적 삭제 검증
    // ========================================================================

    /**
     * 스케줄 삭제 시 실행 이력이 삭제되는지 확인
     *
     * @scenario case=service_delete
     *
     * @effects schedule_delete_removes_histories_and_fires_hooks
     *
     * @return void 반환값 없음
     */
    public function test_delete_schedule_deletes_histories(): void
    {
        $schedule = Schedule::create([
            'name' => '테스트 스케줄',
            'type' => ScheduleType::Artisan,
            'command' => 'inspire',
            'expression' => '* * * * *',
            'is_active' => true,
        ]);

        ScheduleHistory::create([
            'schedule_id' => $schedule->id,
            'started_at' => now(),
            'finished_at' => now(),
            'result_status' => 'success',
        ]);

        $this->assertDatabaseHas('schedule_histories', ['schedule_id' => $schedule->id]);

        $this->scheduleService->delete($schedule);

        $this->assertDatabaseMissing('schedule_histories', ['schedule_id' => $schedule->id]);
    }

    // ========================================================================
    // delete() - 훅 실행 검증
    // ========================================================================

    /**
     * 스케줄 삭제 시 before_delete/after_delete 훅이 호출되는지 확인
     *
     * @scenario case=service_delete
     *
     * @effects schedule_delete_removes_histories_and_fires_hooks
     *
     * @return void 반환값 없음
     */
    public function test_delete_schedule_fires_hooks(): void
    {
        $schedule = Schedule::create([
            'name' => '훅 테스트 스케줄',
            'type' => ScheduleType::Artisan,
            'command' => 'inspire',
            'expression' => '* * * * *',
            'is_active' => true,
        ]);

        $beforeCalled = false;
        $afterCalled = false;

        HookManager::addAction('core.schedule.before_delete', function ($s) use (&$beforeCalled, $schedule) {
            $beforeCalled = true;
            $this->assertEquals($schedule->id, $s->id);
        });

        HookManager::addAction('core.schedule.after_delete', function ($scheduleId) use (&$afterCalled, $schedule) {
            $afterCalled = true;
            $this->assertEquals($schedule->id, $scheduleId);
        });

        $this->scheduleService->delete($schedule);

        $this->assertTrue($beforeCalled);
        $this->assertTrue($afterCalled);
    }

    // ========================================================================
    // runSchedule() - 「중복 실행 방지」 실행 잠금 (#161 F4)
    // ========================================================================

    /**
     * 외부 프로세스를 돌리지 않는 Shell 스케줄을 만듭니다 — 실행은 Process 가짜가 받는다.
     *
     * @param  bool  $withoutOverlapping  중복 실행 방지 여부
     * @return Schedule 생성된 스케줄
     */
    private function createFakeShellSchedule(bool $withoutOverlapping): Schedule
    {
        config([
            'schedule_security.shell.enabled' => true,
            'schedule_security.shell.allowed_binaries' => ['i161-task'],
        ]);

        return Schedule::create([
            'name' => '실행 잠금 시험 스케줄',
            'type' => ScheduleType::Shell,
            'command' => 'i161-task',
            'expression' => '* * * * *',
            'is_active' => true,
            'without_overlapping' => $withoutOverlapping,
            'timeout' => 60,
        ]);
    }

    /**
     * 「중복 실행 방지」를 켠 스케줄은 실행 중에 같은 스케줄을 다시 실행하면 409 로 거절한다 — 7.1.4 까지는 값을 저장만 해
     * 「지금 실행」을 두 번 누르면 같은 작업이 두 번 돌았다. 거절된 실행은 이력·last_result 를 남기지 않는다.
     *
     * @scenario case=run_rejected_while_running
     *
     * @effects schedule_run_rejected_while_same_schedule_runs
     *
     * @return void 반환값 없음
     */
    public function test_run_rejects_second_run_when_without_overlapping(): void
    {
        Process::fake();
        $schedule = $this->createFakeShellSchedule(true);
        $nested = null;

        // 첫 실행이 도는 도중(직전 훅)에 같은 스케줄을 다시 실행한다
        HookManager::addAction('core.schedule.before_run', function () use ($schedule, &$nested) {
            if ($nested !== null) {
                return;
            }
            try {
                $this->scheduleService->runSchedule(Schedule::find($schedule->id));
                $nested = 'ran';
            } catch (ScheduleAlreadyRunningException $e) {
                $nested = $e;
            }
        });

        $history = $this->scheduleService->runSchedule($schedule);

        $this->assertInstanceOf(ScheduleAlreadyRunningException::class, $nested, '실행 중인 스케줄의 두 번째 실행은 거절된다');
        $this->assertSame(409, $nested->getStatusCode());
        $this->assertSame('schedule.already_running', $nested->getMessageKey());
        $this->assertSame(1, ScheduleHistory::where('schedule_id', $schedule->id)->count(), '거절된 실행은 이력을 남기지 않는다');
        $this->assertSame(ScheduleResultStatus::Success, $history->status);
        Process::assertRanTimes(fn () => true, 1);

        // 다른 프로세스가 실행 잠금을 쥐고 있는 동안 — 이력·last_result 를 건드리지 않는다
        $other = Cache::lock('schedule-run:'.$schedule->id, 3600, 'other-runner');
        $this->assertTrue($other->get());
        $before = $schedule->fresh()->last_result;

        try {
            $this->scheduleService->runSchedule($schedule->fresh());
            $this->fail('실행 잠금이 잡혀 있는데 실행됨');
        } catch (ScheduleAlreadyRunningException) {
            // 거절 — 아래에서 부수효과가 없음을 본다
        }

        $this->assertSame(1, ScheduleHistory::where('schedule_id', $schedule->id)->count());
        $this->assertSame($before, $schedule->fresh()->last_result, 'last_result 를 Running 으로 바꾸지 않는다');
        $this->assertTrue(Cache::restoreLock('schedule-run:'.$schedule->id, 'other-runner')->isOwnedByCurrentProcess(), '남의 실행 잠금은 풀지 않는다');
    }

    /**
     * 「중복 실행 방지」를 끈 스케줄은 실행 잠금을 잡지 않아 겹쳐 실행할 수 있다 — 종전 동작 그대로다.
     *
     * @scenario case=run_overlap_allowed
     *
     * @effects schedule_run_overlap_allowed_when_guard_off
     *
     * @return void 반환값 없음
     */
    public function test_run_allows_concurrent_when_overlapping_allowed(): void
    {
        Process::fake();
        $schedule = $this->createFakeShellSchedule(false);
        $nested = null;

        HookManager::addAction('core.schedule.before_run', function () use ($schedule, &$nested) {
            if ($nested !== null) {
                return;
            }
            $nested = 'started';
            $nested = $this->scheduleService->runSchedule(Schedule::find($schedule->id));
        });

        $this->scheduleService->runSchedule($schedule);

        $this->assertInstanceOf(ScheduleHistory::class, $nested, '겹친 실행도 끝까지 돈다');
        $this->assertSame(2, ScheduleHistory::where('schedule_id', $schedule->id)->count());
        Process::assertRanTimes(fn () => true, 2);
        $this->assertTrue(Cache::lock('schedule-run:'.$schedule->id, 10)->get(), '방지를 끈 스케줄은 실행 잠금을 잡지 않는다');
    }

    /**
     * 실행이 실패해도 실행 잠금은 풀린다 — 풀리지 않으면 실패한 스케줄을 유지 시간(최소 1시간) 동안 다시 실행할 수 없다.
     *
     * @scenario case=run_failed_releases_lock
     *
     * @effects schedule_run_lock_released_after_failure
     *
     * @return void 반환값 없음
     */
    public function test_lock_released_after_failed_run(): void
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: 'i161 task failed', exitCode: 1)]);
        $schedule = $this->createFakeShellSchedule(true);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $this->scheduleService->runSchedule($schedule->fresh());
                $this->fail('실패해야 하는 실행이 성공함');
            } catch (ValidationException) {
                // 실행 실패 — 두 번째 시도도 409 가 아니라 실행 실패로 끝나야 한다
            }
        }

        $this->assertSame(2, ScheduleHistory::where('schedule_id', $schedule->id)->count(), '두 번 모두 실제로 실행됐다');
        $this->assertSame(ScheduleResultStatus::Failed, $schedule->fresh()->last_result);
        $this->assertTrue(Cache::lock('schedule-run:'.$schedule->id, 10)->get(), '실패 뒤 실행 잠금이 풀려 있다');
    }
}
