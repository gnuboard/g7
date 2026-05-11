<?php

namespace Tests\Unit\Services;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ActivityLogService 테스트
 *
 * ActivityLogService 는 조회/삭제 기능만 제공합니다.
 * 기록은 Log::channel('activity') → ActivityLogHandler 경로로 이행되었으므로
 * 기록 경로 테스트는 별도 파일(ActivityLogHandlerTest, CoreActivityLogListenerTest)에서 커버합니다.
 */
class ActivityLogServiceTest extends TestCase
{
    use RefreshDatabase;

    private ActivityLogService $activityLogService;

    private string $testPrefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testPrefix = 'test_' . uniqid() . '_';
        $this->activityLogService = app(ActivityLogService::class);
    }

    /**
     * 특정 모델의 로그만 반환하는지 확인
     */
    public function test_get_logs_for_model_returns_only_model_logs(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $actionPrefix = $this->testPrefix . 'model.';

        $this->createLog($actionPrefix . 'view1', $user1);
        $this->createLog($actionPrefix . 'update1', $user1);
        $this->createLog($actionPrefix . 'view2', $user2);

        $user1Logs = $this->activityLogService->getLogsForModel($user1);

        $this->assertEquals(2, $user1Logs->total());
        foreach ($user1Logs->items() as $log) {
            $this->assertEquals(User::class, $log->loggable_type);
            $this->assertEquals($user1->id, $log->loggable_id);
        }
    }

    /**
     * 전체 로그 목록을 페이지네이션으로 반환하는지 확인
     */
    public function test_get_list_returns_paginated_logs(): void
    {
        $actionPrefix = $this->testPrefix . 'paginate.';

        for ($i = 1; $i <= 5; $i++) {
            $this->createLog($actionPrefix . $i);
        }

        $logs = $this->activityLogService->getList(['per_page' => 10]);

        $this->assertEquals(5, $logs->total());
    }

    /**
     * log_type 필터가 동작하는지 확인
     */
    public function test_get_list_filters_by_log_type(): void
    {
        $actionPrefix = $this->testPrefix . 'type.';

        $this->createLog($actionPrefix . 'admin', null, ActivityLogType::Admin);
        $this->createLog($actionPrefix . 'user', null, ActivityLogType::User);
        $this->createLog($actionPrefix . 'system', null, ActivityLogType::System);

        $adminLogs = $this->activityLogService->getList([
            'log_type' => ActivityLogType::Admin,
            'action' => $actionPrefix . 'admin',
        ]);

        $this->assertEquals(1, $adminLogs->total());
        foreach ($adminLogs->items() as $log) {
            $this->assertEquals(ActivityLogType::Admin->value, $log->log_type->value);
        }
    }

    /**
     * action 필터가 동작하는지 확인
     */
    public function test_get_list_filters_by_action(): void
    {
        $action = $this->testPrefix . 'filter.update';

        $this->createLog($this->testPrefix . 'filter.create');
        $this->createLog($action);
        $this->createLog($this->testPrefix . 'filter.delete');

        $logs = $this->activityLogService->getList(['action' => $action]);

        $this->assertEquals(1, $logs->total());
        $this->assertEquals($action, $logs->items()[0]->action);
    }

    /**
     * user_id 필터가 동작하는지 확인
     */
    public function test_get_list_filters_by_user_id(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $action1 = $this->testPrefix . 'user1.action';
        $action2 = $this->testPrefix . 'user2.action';

        $this->createLog($action1, null, ActivityLogType::Admin, $user1);
        $this->createLog($action2, null, ActivityLogType::Admin, $user2);

        $user1Logs = $this->activityLogService->getList([
            'user_id' => $user1->id,
            'action' => $action1,
        ]);

        $this->assertEquals(1, $user1Logs->total());
        $this->assertEquals($user1->id, $user1Logs->items()[0]->user_id);
    }

    /**
     * 단일 로그 삭제가 동작하는지 확인
     */
    public function test_delete_removes_log(): void
    {
        $action = $this->testPrefix . 'delete.single';
        $log = $this->createLog($action);

        $result = $this->activityLogService->delete($log->id);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('activity_logs', ['id' => $log->id]);
    }

    /**
     * 여러 로그 일괄 삭제가 동작하는지 확인
     */
    public function test_delete_many_removes_multiple_logs(): void
    {
        $actionPrefix = $this->testPrefix . 'delete.bulk.';

        $log1 = $this->createLog($actionPrefix . '1');
        $log2 = $this->createLog($actionPrefix . '2');
        $log3 = $this->createLog($actionPrefix . '3');

        $count = $this->activityLogService->deleteMany([$log1->id, $log2->id, $log3->id]);

        $this->assertEquals(3, $count);
        $this->assertDatabaseMissing('activity_logs', ['id' => $log1->id]);
        $this->assertDatabaseMissing('activity_logs', ['id' => $log2->id]);
        $this->assertDatabaseMissing('activity_logs', ['id' => $log3->id]);
    }

    /**
     * ActivityLog 를 직접 생성합니다 (Monolog 경로 우회 — getList/delete 테스트 전용)
     */
    private function createLog(
        string $action,
        ?User $loggable = null,
        ActivityLogType $type = ActivityLogType::Admin,
        ?User $user = null,
    ): ActivityLog {
        return ActivityLog::create([
            'log_type' => $type->value,
            'action' => $action,
            'description' => "Test log for {$action}",
            'user_id' => $user?->id,
            'loggable_type' => $loggable ? $loggable->getMorphClass() : null,
            'loggable_id' => $loggable?->id,
        ]);
    }
}
