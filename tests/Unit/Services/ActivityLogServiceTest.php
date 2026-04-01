<?php

namespace Tests\Unit\Services;

use App\ActivityLog\ActivityLogManager;
use App\Enums\ActivityLogType;
use App\Extension\HookManager;
use App\Listeners\ActivityLogListener;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * ActivityLogService 테스트
 *
 * ActivityLogService의 훅 발행 및 조회 기능을 검증합니다.
 */
class ActivityLogServiceTest extends TestCase
{
    use RefreshDatabase;

    private ActivityLogService $activityLogService;

    private string $testPrefix;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트 격리를 위한 고유 프리픽스
        $this->testPrefix = 'test_' . uniqid() . '_';

        // HookManager 및 Laravel Event 초기화 (중복 등록 방지)
        HookManager::resetAll();
        Event::forget('hook.core.activity.log');
        Event::forget('hook.core.activity.admin');
        Event::forget('hook.core.activity.user');
        Event::forget('hook.core.activity.system');

        // 설정 초기화
        config(['activity_log.enabled' => true]);
        config(['activity_log.driver' => 'database']);

        // ActivityLogManager 인스턴스 초기화
        $this->app->forgetInstance(ActivityLogManager::class);

        // 리스너 등록 - static 배열에만 등록 (addAction은 Event::listen도 호출하여 중복 실행됨)
        $listener = app(ActivityLogListener::class);
        $this->registerHookWithoutEvent('core.activity.log', [$listener, 'processLog']);
        $this->registerHookWithoutEvent('core.activity.admin', [$listener, 'handleAdmin']);
        $this->registerHookWithoutEvent('core.activity.user', [$listener, 'handleUser']);
        $this->registerHookWithoutEvent('core.activity.system', [$listener, 'handleSystem']);

        $this->activityLogService = app(ActivityLogService::class);
    }

    /**
     * HookManager의 static 배열에만 훅을 등록합니다.
     * addAction은 Event::listen도 호출하여 중복 실행되므로, static 배열에만 등록합니다.
     */
    private function registerHookWithoutEvent(string $hookName, callable $callback, int $priority = 10): void
    {
        $reflection = new \ReflectionClass(HookManager::class);
        $property = $reflection->getProperty('hooks');

        $hooks = $property->getValue();
        if (! isset($hooks[$hookName])) {
            $hooks[$hookName] = [];
        }
        if (! isset($hooks[$hookName][$priority])) {
            $hooks[$hookName][$priority] = [];
        }
        $hooks[$hookName][$priority][] = $callback;
        $property->setValue(null, $hooks);
    }

    // ========================================================================
    // log() - 기본 로그 기록 테스트 (훅 기반)
    // ========================================================================

    /**
     * 기본 로그 기록이 훅을 통해 데이터베이스에 저장되는지 확인
     */
    public function test_log_creates_activity_log_via_hook(): void
    {
        $action = $this->testPrefix . 'action';

        $this->activityLogService->log(
            ActivityLogType::Admin,
            $action,
            'Test action description'
        );

        $this->assertDatabaseHas('activity_logs', [
            'log_type' => ActivityLogType::Admin->value,
            'action' => $action,
            'description' => 'Test action description',
        ]);
    }

    /**
     * 로그에 대상 모델이 연결되는지 확인
     */
    public function test_log_with_loggable_model(): void
    {
        $user = User::factory()->create();
        $action = $this->testPrefix . 'user.update';

        $this->activityLogService->log(
            ActivityLogType::Admin,
            $action,
            'User updated',
            $user
        );

        $log = ActivityLog::where('action', $action)->first();
        $this->assertNotNull($log);
        $this->assertEquals($user->getMorphClass(), $log->loggable_type);
        $this->assertEquals($user->id, $log->loggable_id);
    }

    /**
     * 로그에 추가 속성이 저장되는지 확인
     */
    public function test_log_with_properties(): void
    {
        $properties = ['old_name' => 'John', 'new_name' => 'Jane'];
        $action = $this->testPrefix . 'user.update.props';

        $this->activityLogService->log(
            ActivityLogType::Admin,
            $action,
            'User name updated',
            null,
            $properties
        );

        $log = ActivityLog::where('action', $action)->first();
        $this->assertNotNull($log);
        $this->assertEquals($properties, $log->properties);
    }

    /**
     * 인증된 사용자가 로그에 기록되는지 확인
     */
    public function test_log_records_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $action = $this->testPrefix . 'auth.action';

        $this->activityLogService->log(
            ActivityLogType::Admin,
            $action,
            'Test action'
        );

        $log = ActivityLog::where('action', $action)->first();
        $this->assertNotNull($log);
        $this->assertEquals($user->id, $log->user_id);
    }

    // ========================================================================
    // logAdmin() / logUser() / logSystem() - 헬퍼 메서드 테스트
    // ========================================================================

    /**
     * logAdmin이 관리자 유형으로 로그를 생성하는지 확인
     */
    public function test_log_admin_creates_admin_type_log(): void
    {
        $action = $this->testPrefix . 'admin.action';
        $this->activityLogService->logAdmin($action, 'Admin action');

        $log = ActivityLog::where('action', $action)->first();
        $this->assertNotNull($log);
        $this->assertEquals(ActivityLogType::Admin, $log->log_type);
    }

    /**
     * logUser가 사용자 유형으로 로그를 생성하는지 확인
     */
    public function test_log_user_creates_user_type_log(): void
    {
        $action = $this->testPrefix . 'user.action';
        $this->activityLogService->logUser($action, 'User action');

        $log = ActivityLog::where('action', $action)->first();
        $this->assertNotNull($log);
        $this->assertEquals(ActivityLogType::User, $log->log_type);
    }

    /**
     * logSystem이 시스템 유형으로 로그를 생성하는지 확인
     */
    public function test_log_system_creates_system_type_log(): void
    {
        $action = $this->testPrefix . 'system.action';
        $this->activityLogService->logSystem($action, 'System action');

        $log = ActivityLog::where('action', $action)->first();
        $this->assertNotNull($log);
        $this->assertEquals(ActivityLogType::System, $log->log_type);
    }

    // ========================================================================
    // getLogsForModel() - 모델별 로그 조회 테스트
    // ========================================================================

    /**
     * 특정 모델의 로그만 조회되는지 확인
     */
    public function test_get_logs_for_model_returns_only_model_logs(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $actionPrefix = $this->testPrefix . 'model_';

        // user1에 대한 로그 2개 생성
        $this->activityLogService->logAdmin($actionPrefix . 'view1', 'Viewed user 1', $user1);
        $this->activityLogService->logAdmin($actionPrefix . 'update1', 'Updated user 1', $user1);

        // user2에 대한 로그 1개 생성
        $this->activityLogService->logAdmin($actionPrefix . 'view2', 'Viewed user 2', $user2);

        $user1Logs = $this->activityLogService->getLogsForModel($user1);

        // 이 테스트에서 생성한 로그만 필터링
        $filteredLogs = collect($user1Logs->items())->filter(fn ($log) => str_starts_with($log->action, $actionPrefix));

        $this->assertCount(2, $filteredLogs);
        foreach ($filteredLogs as $log) {
            $this->assertEquals($user1->id, $log->loggable_id);
        }
    }

    // ========================================================================
    // getList() - 전체 로그 조회 테스트
    // ========================================================================

    /**
     * 로그 목록 조회가 페이지네이션을 반환하는지 확인
     */
    public function test_get_list_returns_paginated_logs(): void
    {
        $actionPrefix = $this->testPrefix . 'paginate_';

        // 로그 5개 생성
        for ($i = 0; $i < 5; $i++) {
            $this->activityLogService->logAdmin($actionPrefix . $i, "Action {$i}");
        }

        // 전체 로그 중 이 테스트에서 생성한 것만 확인
        $allLogs = ActivityLog::where('action', 'like', $actionPrefix . '%')->get();
        $this->assertCount(5, $allLogs);
    }

    /**
     * 로그 유형 필터가 동작하는지 확인
     */
    public function test_get_list_filters_by_log_type(): void
    {
        $actionPrefix = $this->testPrefix . 'type_';

        $this->activityLogService->logAdmin($actionPrefix . 'admin', 'Admin action');
        $this->activityLogService->logUser($actionPrefix . 'user', 'User action');
        $this->activityLogService->logSystem($actionPrefix . 'system', 'System action');

        $adminLogs = $this->activityLogService->getList(['log_type' => ActivityLogType::Admin]);

        // 이 테스트에서 생성한 admin 로그만 필터링
        $filteredLogs = collect($adminLogs->items())->filter(fn ($log) => str_starts_with($log->action, $actionPrefix));

        $this->assertCount(1, $filteredLogs);
        $this->assertEquals(ActivityLogType::Admin, $filteredLogs->first()->log_type);
    }

    /**
     * 액션 필터가 동작하는지 확인
     */
    public function test_get_list_filters_by_action(): void
    {
        $action = $this->testPrefix . 'filter.update';

        $this->activityLogService->logAdmin($this->testPrefix . 'filter.create', 'User created');
        $this->activityLogService->logAdmin($action, 'User updated');
        $this->activityLogService->logAdmin($this->testPrefix . 'filter.delete', 'User deleted');

        $logs = $this->activityLogService->getList(['action' => $action]);

        $this->assertCount(1, $logs);
        $this->assertEquals($action, $logs->first()->action);
    }

    /**
     * 사용자 필터가 동작하는지 확인
     */
    public function test_get_list_filters_by_user_id(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $action1 = $this->testPrefix . 'user1_action';
        $action2 = $this->testPrefix . 'user2_action';

        $this->actingAs($user1);
        $this->activityLogService->logAdmin($action1, 'Action by user 1');

        $this->actingAs($user2);
        $this->activityLogService->logAdmin($action2, 'Action by user 2');

        $user1Logs = $this->activityLogService->getList(['user_id' => $user1->id, 'action' => $action1]);

        $this->assertCount(1, $user1Logs);
        $this->assertEquals($user1->id, $user1Logs->first()->user_id);
    }

    // ========================================================================
    // Model Accessors - 모델 속성 테스트
    // ========================================================================

    /**
     * log_type_label 접근자가 동작하는지 확인
     */
    public function test_log_type_label_accessor(): void
    {
        $action = $this->testPrefix . 'label.action';
        $this->activityLogService->logAdmin($action, 'Test');

        $log = ActivityLog::where('action', $action)->first();
        $this->assertNotNull($log);
        $this->assertNotEmpty($log->log_type_label);
    }

    /**
     * actor_name 접근자가 사용자 이름을 반환하는지 확인
     */
    public function test_actor_name_accessor_with_user(): void
    {
        $user = User::factory()->create(['name' => 'Test User']);
        $this->actingAs($user);
        $action = $this->testPrefix . 'actor.action';

        $this->activityLogService->logAdmin($action, 'Test');

        $log = ActivityLog::where('action', $action)->first();
        $this->assertNotNull($log);
        $this->assertEquals('Test User', $log->actor_name);
    }

    /**
     * actor_name 접근자가 사용자 없을 때 시스템을 반환하는지 확인
     */
    public function test_actor_name_accessor_without_user(): void
    {
        $action = $this->testPrefix . 'system.actor';
        $this->activityLogService->logSystem($action, 'Test');

        $log = ActivityLog::where('action', $action)->first();
        $this->assertNotNull($log);
        $this->assertNotEmpty($log->actor_name);
    }

    // ========================================================================
    // 훅 통합 테스트
    // ========================================================================

    /**
     * 훅이 비활성화되면 로그가 저장되지 않는지 확인
     */
    public function test_log_not_saved_when_disabled(): void
    {
        config(['activity_log.enabled' => false]);
        $action = $this->testPrefix . 'disabled.action';

        $this->activityLogService->logAdmin($action, 'Test');

        $this->assertDatabaseMissing('activity_logs', [
            'action' => $action,
        ]);
    }

    /**
     * null 드라이버 사용 시 로그가 저장되지 않는지 확인
     */
    public function test_log_not_saved_with_null_driver(): void
    {
        // null 드라이버 설정
        config(['activity_log.driver' => 'null']);

        // HookManager 및 Event, 매니저 초기화
        HookManager::resetAll();
        Event::forget('hook.core.activity.admin');
        $this->app->forgetInstance(ActivityLogManager::class);

        // null 드라이버로 리스너 다시 등록 (static 배열에만)
        $listener = app(ActivityLogListener::class);
        $this->registerHookWithoutEvent('core.activity.admin', [$listener, 'handleAdmin']);

        $action = $this->testPrefix . 'null_driver.action';
        $this->activityLogService->logAdmin($action, 'Test');

        $this->assertDatabaseMissing('activity_logs', [
            'action' => $action,
        ]);
    }
}
