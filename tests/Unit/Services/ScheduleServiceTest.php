<?php

namespace Tests\Unit\Services;

use App\Enums\ScheduleType;
use App\Extension\HookManager;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->scheduleService = app(ScheduleService::class);
        HookManager::resetAll();
    }

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
    // delete() - 관계 레코드 명시적 삭제 검증
    // ========================================================================

    /**
     * 스케줄 삭제 시 실행 이력이 삭제되는지 확인
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
}
