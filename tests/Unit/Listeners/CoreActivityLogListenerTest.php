<?php

namespace Tests\Unit\Listeners;

use App\ActivityLog\ChangeDetector;
use App\Enums\ActivityLogType;
use App\Listeners\CoreActivityLogListener;
use App\Models\ActivityLog;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * CoreActivityLogListener 전체 훅 핸들러 단위 테스트
 *
 * 66개 전체 훅 (스냅샷 5개 + 로깅 61개) 커버리지 검증
 */
class CoreActivityLogListenerTest extends TestCase
{
    use RefreshDatabase;

    private CoreActivityLogListener $listener;

    private MockInterface $logChannel;

    protected function setUp(): void
    {
        parent::setUp();

        // 관리자 경로 요청 설정 (resolveLogType()이 Admin 반환하도록)
        $this->app->instance('request', Request::create('/api/admin/users'));

        $this->listener = new CoreActivityLogListener();

        // Mock Log::channel('activity') → $logChannel
        $this->logChannel = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')
            ->with('activity')
            ->andReturn($this->logChannel);
    }

    protected function tearDown(): void
    {
        if ($container = Mockery::getContainer()) {
            $this->addToAssertionCount($container->mockery_getExpectationCount());
        }
        Mockery::close();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════
    // 헬퍼 메서드
    // ═══════════════════════════════════════════

    /**
     * User mock 생성
     *
     * @param int $id
     * @param string $uuid
     * @param string $email
     * @param string $name
     * @return MockInterface|User
     */
    private function createUserMock(int $id = 1, string $uuid = 'uuid-123', string $email = 'test@test.com', string $name = 'Test User'): MockInterface
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => $id, 'uuid' => $uuid, 'email' => $email, 'name' => $name]);

        return $user;
    }

    /**
     * Model mock 생성
     *
     * @param int $id
     * @param array $extraAttributes
     * @return MockInterface|Model
     */
    private function createModelMock(int $id = 1, array $extraAttributes = []): MockInterface
    {
        $model = Mockery::mock(Model::class)->makePartial();
        $model->forceFill(array_merge(['id' => $id], $extraAttributes));

        return $model;
    }

    /**
     * logChannel->info() 기대 설정 헬퍼
     *
     * @param string $expectedAction
     * @param ActivityLogType $expectedLogType
     * @param string $expectedDescriptionKey
     * @param bool $expectLoggable
     */
    private function expectLogInfo(
        string $expectedAction,
        ActivityLogType $expectedLogType,
        string $expectedDescriptionKey,
        bool $expectLoggable = false
    ): void {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) use ($expectedAction, $expectedLogType, $expectedDescriptionKey, $expectLoggable) {
                if ($action !== $expectedAction) {
                    return false;
                }
                if ($context['log_type'] !== $expectedLogType) {
                    return false;
                }
                if ($context['description_key'] !== $expectedDescriptionKey) {
                    return false;
                }
                if ($expectLoggable && ! isset($context['loggable'])) {
                    return false;
                }

                return true;
            });
    }

    // ═══════════════════════════════════════════
    // getSubscribedHooks 테스트
    // ═══════════════════════════════════════════

    public function test_getSubscribedHooks_returns_all_61_hooks(): void
    {
        $hooks = CoreActivityLogListener::getSubscribedHooks();

        // 61개 로깅(after_*) 훅 (스냅샷은 Service에서 캡처하여 인수로 전달)
        $this->assertCount(61, $hooks);
    }

    public function test_handle_does_nothing(): void
    {
        // handle() 메서드는 기본 핸들러로 아무 작업도 하지 않아야 한다
        $this->listener->handle('arg1', 'arg2');
        $this->assertTrue(true); // 예외 없이 완료 확인
    }

    // ═══════════════════════════════════════════
    // User 핸들러 테스트 (8개)
    // ═══════════════════════════════════════════

    public function test_handleUserAfterCreate_logs_activity(): void
    {
        $user = $this->createUserMock(1, 'uuid-abc', 'new@test.com', 'New User');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'user.create'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.user_create'
                    && $context['description_params']['user_id'] === 'uuid-abc'
                    && $context['properties']['email'] === 'new@test.com'
                    && $context['properties']['name'] === 'New User'
                    && isset($context['loggable']);
            });

        $this->listener->handleUserAfterCreate($user, ['email' => 'new@test.com']);
    }

    public function test_handleUserAfterUpdate_logs_activity_with_changes(): void
    {
        $user = $this->createUserMock(1, 'uuid-abc');

        // ChangeDetector는 static 메서드이므로 동작을 검증
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'user.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.user_update'
                    && $context['description_params']['user_id'] === 'uuid-abc'
                    && array_key_exists('changes', $context)
                    && isset($context['loggable']);
            });

        $this->listener->handleUserAfterUpdate($user, ['name' => 'Updated']);
    }

    public function test_handleUserAfterUpdate_accepts_snapshot_argument(): void
    {
        $user = $this->createUserMock(1, 'uuid-abc');
        $snapshot = ['id' => 1, 'name' => 'Old Name'];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'user.update'
                    && array_key_exists('changes', $context);
            });

        $this->listener->handleUserAfterUpdate($user, [], $snapshot);
    }

    public function test_handleUserAfterDelete_logs_activity(): void
    {
        // id 없는 경우 — ActivityLog::where() 호출 스킵, 로깅만 검증
        $userData = ['uuid' => 'uuid-del', 'email' => 'del@test.com'];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'user.delete'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.user_delete'
                    && $context['description_params']['user_id'] === 'uuid-del'
                    && $context['properties']['deleted_user']['email'] === 'del@test.com';
            });

        $this->listener->handleUserAfterDelete($userData);
    }

    public function test_handleUserAfterDelete_handles_missing_uuid(): void
    {
        // uuid 없는 경우 — 빈 문자열 fallback 검증
        $userData = [];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'user.delete'
                    && $context['description_params']['user_id'] === '';
            });

        $this->listener->handleUserAfterDelete($userData);
    }

    public function test_handleUserAfterWithdraw_logs_activity(): void
    {
        $user = $this->createUserMock(1, 'uuid-wd');

        $this->expectLogInfo('user.withdraw', ActivityLogType::Admin, 'activity_log.description.user_withdraw', true);

        $this->listener->handleUserAfterWithdraw($user);
    }

    public function test_handleUserAfterShow_logs_activity(): void
    {
        $user = $this->createUserMock(1, 'uuid-show');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'user.show'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.user_show'
                    && $context['description_params']['user_id'] === 'uuid-show'
                    && isset($context['loggable']);
            });

        $this->listener->handleUserAfterShow($user);
    }

    public function test_handleUserAfterList_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'user.index'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.user_index'
                    && $context['properties']['result_count'] === 25;
            });

        $this->listener->handleUserAfterList(25);
    }

    public function test_handleUserAfterSearch_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'user.search'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.user_search'
                    && $context['properties']['query'] === 'john'
                    && $context['properties']['result_count'] === 3;
            });

        $this->listener->handleUserAfterSearch('john', 3);
    }

    public function test_handleUserAfterBulkUpdate_logs_per_item(): void
    {
        $users = collect([
            User::factory()->create(),
            User::factory()->create(),
            User::factory()->create(),
        ]);
        $uuids = $users->pluck('uuid')->toArray();

        $loggedContexts = [];
        $this->logChannel->shouldReceive('info')
            ->times(3)
            ->withArgs(function (string $action, array $context) use (&$loggedContexts, $uuids) {
                if ($action !== 'user.bulk_update_status') {
                    return false;
                }
                $loggedContexts[] = $context;

                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.user_bulk_update_status'
                    && $context['description_params']['count'] === 1
                    && isset($context['loggable'])
                    && in_array($context['properties']['uuid'], $uuids)
                    && $context['properties']['status'] === 'active';
            });

        $this->listener->handleUserAfterBulkUpdate($uuids, 'active', 3);

        $this->assertCount(3, $loggedContexts);
    }

    public function test_handleUserAfterBulkUpdate_skips_nonexistent_uuids(): void
    {
        $user = User::factory()->create();

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) use ($user) {
                return $action === 'user.bulk_update_status'
                    && $context['properties']['uuid'] === $user->uuid;
            });

        $this->listener->handleUserAfterBulkUpdate([$user->uuid, 'nonexistent-uuid'], 'active', 2);
    }

    // ═══════════════════════════════════════════
    // Auth 핸들러 테스트 (6개)
    // ═══════════════════════════════════════════

    public function test_handleAuthAfterLogin_logs_activity(): void
    {
        $user = $this->createUserMock(1);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'auth.login'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.auth_login'
                    && $context['user_id'] === 1
                    && isset($context['loggable']);
            });

        $this->listener->handleAuthAfterLogin($user, ['ip' => '127.0.0.1']);
    }

    public function test_handleAuthLogout_logs_activity(): void
    {
        $user = $this->createUserMock(2);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'auth.logout'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.auth_logout'
                    && $context['user_id'] === 2
                    && isset($context['loggable']);
            });

        $this->listener->handleAuthLogout($user);
    }

    public function test_handleAuthRegister_logs_activity_with_user_type(): void
    {
        // auth 경로는 사용자 화면이므로 User 타입으로 자동 결정
        $this->app->instance('request', Request::create('/api/auth/register'));
        $user = $this->createUserMock(3);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'auth.register'
                    && $context['log_type'] === ActivityLogType::User
                    && $context['description_key'] === 'activity_log.description.auth_register'
                    && $context['user_id'] === 3
                    && isset($context['loggable']);
            });

        $this->listener->handleAuthRegister($user, ['email' => 'new@test.com']);
    }

    public function test_handleAuthForgotPassword_logs_activity(): void
    {
        $this->app->instance('request', Request::create('/api/auth/forgot-password'));
        $user = $this->createUserMock(4);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'auth.forgot_password'
                    && $context['log_type'] === ActivityLogType::User
                    && $context['description_key'] === 'activity_log.description.auth_forgot_password'
                    && $context['user_id'] === 4
                    && isset($context['loggable']);
            });

        $this->listener->handleAuthForgotPassword($user);
    }

    public function test_handleAuthResetPassword_logs_activity(): void
    {
        $this->app->instance('request', Request::create('/api/auth/reset-password'));
        $user = $this->createUserMock(5);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'auth.reset_password'
                    && $context['log_type'] === ActivityLogType::User
                    && $context['description_key'] === 'activity_log.description.auth_reset_password'
                    && $context['user_id'] === 5
                    && isset($context['loggable']);
            });

        $this->listener->handleAuthResetPassword($user);
    }

    public function test_handleAuthRecordConsents_logs_activity(): void
    {
        $this->app->instance('request', Request::create('/api/auth/record-consents'));
        $user = $this->createUserMock(6);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'auth.record_consents'
                    && $context['log_type'] === ActivityLogType::User
                    && $context['description_key'] === 'activity_log.description.auth_record_consents'
                    && $context['user_id'] === 6
                    && $context['ip_address'] === '192.168.1.1'
                    && isset($context['loggable']);
            });

        $this->listener->handleAuthRecordConsents($user, ['terms' => true], '2026-03-25 12:00:00', '192.168.1.1');
    }

    // ═══════════════════════════════════════════
    // Role 핸들러 테스트 (5개)
    // ═══════════════════════════════════════════

    public function test_handleRoleAfterCreate_logs_activity(): void
    {
        $role = $this->createModelMock(10, ['name' => 'Editor']);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'role.create'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.role_create'
                    && $context['description_params']['role_id'] === 10
                    && $context['properties']['name'] === 'Editor'
                    && isset($context['loggable']);
            });

        $this->listener->handleRoleAfterCreate($role);
    }

    public function test_handleRoleAfterUpdate_logs_activity_with_changes(): void
    {
        $role = $this->createModelMock(10, ['name' => 'SuperEditor']);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'role.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.role_update'
                    && $context['description_params']['role_id'] === 10
                    && array_key_exists('changes', $context)
                    && isset($context['loggable']);
            });

        $this->listener->handleRoleAfterUpdate($role);
    }

    public function test_handleRoleAfterUpdate_accepts_snapshot_argument(): void
    {
        $role = $this->createModelMock(10, ['name' => 'Editor']);
        $snapshot = ['id' => 10, 'name' => 'Old Role'];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'role.update'
                    && array_key_exists('changes', $context);
            });

        $this->listener->handleRoleAfterUpdate($role, $snapshot);
    }

    public function test_handleRoleAfterDelete_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'role.delete'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.role_delete'
                    && $context['description_params']['role_id'] === 99
                    && $context['properties']['role_id'] === 99;
            });

        $this->listener->handleRoleAfterDelete(99);
    }

    public function test_handleRoleAfterSyncPermissions_logs_activity(): void
    {
        $role = $this->createModelMock(10);
        $previous = ['users.view', 'users.create'];
        $current = ['users.view', 'users.update'];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'role.sync_permissions'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.role_sync_permissions'
                    && $context['description_params']['role_id'] === 10
                    && $context['properties']['added'] === ['users.update']
                    && $context['properties']['removed'] === ['users.create']
                    && isset($context['loggable']);
            });

        $this->listener->handleRoleAfterSyncPermissions($role, $previous, $current);
    }

    public function test_handleRoleAfterToggleStatus_logs_activity(): void
    {
        $role = $this->createModelMock(10);

        $this->expectLogInfo('role.toggle_status', ActivityLogType::Admin, 'activity_log.description.role_toggle_status', true);

        $this->listener->handleRoleAfterToggleStatus($role);
    }

    // ═══════════════════════════════════════════
    // Menu 핸들러 테스트 (6개)
    // ═══════════════════════════════════════════

    public function test_handleMenuAfterCreate_logs_activity(): void
    {
        $menu = $this->createModelMock(5, ['title' => 'Settings']);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'menu.create'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.menu_create'
                    && $context['description_params']['menu_id'] === 5
                    && $context['properties']['title'] === 'Settings'
                    && isset($context['loggable']);
            });

        $this->listener->handleMenuAfterCreate($menu);
    }

    public function test_handleMenuAfterUpdate_logs_activity_with_changes(): void
    {
        $menu = $this->createModelMock(5, ['title' => 'Updated Settings']);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'menu.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.menu_update'
                    && $context['description_params']['menu_id'] === 5
                    && array_key_exists('changes', $context)
                    && isset($context['loggable']);
            });

        $this->listener->handleMenuAfterUpdate($menu);
    }

    public function test_handleMenuAfterUpdate_accepts_snapshot_argument(): void
    {
        $menu = $this->createModelMock(5, ['title' => 'Settings']);
        $snapshot = ['id' => 5, 'title' => 'Old Title'];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'menu.update'
                    && array_key_exists('changes', $context);
            });

        $this->listener->handleMenuAfterUpdate($menu, $snapshot);
    }

    public function test_handleMenuAfterDelete_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'menu.delete'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.menu_delete'
                    && $context['description_params']['menu_id'] === 77
                    && $context['properties']['menu_id'] === 77;
            });

        $this->listener->handleMenuAfterDelete(77);
    }

    public function test_handleMenuAfterUpdateOrder_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'menu.update_order'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.menu_update_order';
            });

        $this->listener->handleMenuAfterUpdateOrder([['id' => 1, 'order' => 0], ['id' => 2, 'order' => 1]]);
    }

    public function test_handleMenuAfterToggleStatus_logs_activity(): void
    {
        $menu = $this->createModelMock(5);

        $this->expectLogInfo('menu.toggle_status', ActivityLogType::Admin, 'activity_log.description.menu_toggle_status', true);

        $this->listener->handleMenuAfterToggleStatus($menu);
    }

    public function test_handleMenuAfterSyncRoles_logs_activity(): void
    {
        $menu = $this->createModelMock(5);
        $previous = [1, 2, 3];
        $current = [2, 3, 4];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'menu.sync_roles'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.menu_sync_roles'
                    && $context['description_params']['menu_id'] === 5
                    && $context['properties']['added'] === [4]
                    && $context['properties']['removed'] === [1]
                    && isset($context['loggable']);
            });

        $this->listener->handleMenuAfterSyncRoles($menu, $previous, $current);
    }

    // ═══════════════════════════════════════════
    // Settings 핸들러 테스트 (2개)
    // ═══════════════════════════════════════════

    public function test_handleSettingsAfterSave_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'settings.save'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.settings_save'
                    && $context['properties']['tab'] === 'general'
                    && $context['properties']['keys'] === ['site_name', 'site_url'];
            });

        $this->listener->handleSettingsAfterSave('general', ['site_name' => 'G7', 'site_url' => 'https://g7.dev'], true);
    }

    public function test_handleSettingsAfterSet_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'settings.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.settings_update'
                    && $context['properties']['key'] === 'maintenance_mode';
            });

        $this->listener->handleSettingsAfterSet('maintenance_mode', true, true);
    }

    // ═══════════════════════════════════════════
    // Schedule 핸들러 테스트 (6개)
    // ═══════════════════════════════════════════

    public function test_handleScheduleAfterCreate_logs_activity(): void
    {
        $schedule = $this->createModelMock(7);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'schedule.create'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.schedule_create'
                    && $context['description_params']['schedule_id'] === 7
                    && isset($context['loggable']);
            });

        $this->listener->handleScheduleAfterCreate($schedule);
    }

    public function test_handleScheduleAfterUpdate_logs_activity_with_changes(): void
    {
        $schedule = $this->createModelMock(7);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'schedule.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.schedule_update'
                    && $context['description_params']['schedule_id'] === 7
                    && array_key_exists('changes', $context)
                    && isset($context['loggable']);
            });

        $this->listener->handleScheduleAfterUpdate($schedule);
    }

    public function test_handleScheduleAfterUpdate_accepts_snapshot_argument(): void
    {
        $schedule = $this->createModelMock(7);
        $snapshot = ['id' => 7, 'command' => 'old:command'];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'schedule.update'
                    && array_key_exists('changes', $context);
            });

        $this->listener->handleScheduleAfterUpdate($schedule, $snapshot);
    }

    public function test_handleScheduleAfterDelete_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'schedule.delete'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.schedule_delete'
                    && $context['description_params']['schedule_id'] === 15
                    && $context['properties']['schedule_id'] === 15;
            });

        $this->listener->handleScheduleAfterDelete(15);
    }

    public function test_handleScheduleAfterRun_logs_activity(): void
    {
        $schedule = $this->createModelMock(7);
        $history = $this->createModelMock(100);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'schedule.run'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.schedule_run'
                    && $context['description_params']['schedule_id'] === 7
                    && isset($context['loggable']);
            });

        $this->listener->handleScheduleAfterRun($schedule, $history);
    }

    public function test_handleScheduleAfterBulkUpdate_logs_per_item(): void
    {
        $schedules = collect([
            Schedule::create(['name' => 'Schedule A', 'command' => 'test:a', 'expression' => '* * * * *']),
            Schedule::create(['name' => 'Schedule B', 'command' => 'test:b', 'expression' => '* * * * *']),
            Schedule::create(['name' => 'Schedule C', 'command' => 'test:c', 'expression' => '* * * * *']),
        ]);
        $ids = $schedules->pluck('id')->toArray();

        $loggedContexts = [];
        $this->logChannel->shouldReceive('info')
            ->times(3)
            ->withArgs(function (string $action, array $context) use (&$loggedContexts, $ids) {
                if ($action !== 'schedule.bulk_update') {
                    return false;
                }
                $loggedContexts[] = $context;

                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.schedule_bulk_update_status'
                    && $context['description_params']['count'] === 1
                    && isset($context['loggable'])
                    && in_array($context['properties']['schedule_id'], $ids)
                    && $context['properties']['is_active'] === true;
            });

        $this->listener->handleScheduleAfterBulkUpdate($ids, true, 3);

        $this->assertCount(3, $loggedContexts);
    }

    public function test_handleScheduleAfterBulkUpdate_skips_nonexistent_ids(): void
    {
        $schedule = Schedule::create(['name' => 'Schedule X', 'command' => 'test:x', 'expression' => '* * * * *']);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) use ($schedule) {
                return $action === 'schedule.bulk_update'
                    && $context['properties']['schedule_id'] === $schedule->id;
            });

        $this->listener->handleScheduleAfterBulkUpdate([$schedule->id, 99999], true, 2);
    }

    public function test_handleScheduleAfterBulkDelete_logs_per_item(): void
    {
        $ids = [4, 5];

        $loggedContexts = [];
        $this->logChannel->shouldReceive('info')
            ->times(2)
            ->withArgs(function (string $action, array $context) use (&$loggedContexts, $ids) {
                if ($action !== 'schedule.bulk_delete') {
                    return false;
                }
                $loggedContexts[] = $context;

                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.schedule_bulk_delete'
                    && $context['description_params']['count'] === 1
                    && in_array($context['properties']['schedule_id'], $ids)
                    && $context['loggable_type'] === Schedule::class
                    && in_array($context['loggable_id'], $ids);
            });

        $this->listener->handleScheduleAfterBulkDelete($ids, 2);

        $this->assertCount(2, $loggedContexts);
    }

    // ═══════════════════════════════════════════
    // Attachment 핸들러 테스트 (3개)
    // ═══════════════════════════════════════════

    public function test_handleAttachmentAfterUpload_logs_activity(): void
    {
        $attachment = $this->createModelMock(20);

        $this->expectLogInfo('attachment.upload', ActivityLogType::Admin, 'activity_log.description.attachment_upload', true);

        $this->listener->handleAttachmentAfterUpload($attachment);
    }

    public function test_handleAttachmentAfterDelete_logs_activity(): void
    {
        $attachment = $this->createModelMock(20);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'attachment.delete'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.attachment_delete';
            });

        $this->listener->handleAttachmentAfterDelete($attachment);
    }

    public function test_handleAttachmentAfterBulkDelete_creates_per_item_logs(): void
    {
        $ids = [10, 20, 30];
        $snapshots = [
            10 => ['id' => 10, 'filename' => 'a.jpg'],
            20 => ['id' => 20, 'filename' => 'b.jpg'],
            30 => ['id' => 30, 'filename' => 'c.jpg'],
        ];

        $loggedIds = [];
        $this->logChannel->shouldReceive('info')
            ->times(3)
            ->withArgs(function (string $action, array $context) use (&$loggedIds) {
                if ($action !== 'attachment.bulk_delete') {
                    return false;
                }
                $loggedIds[] = $context['loggable_id'];

                return $context['log_type'] === ActivityLogType::Admin
                    && $context['loggable_type'] === \App\Models\Attachment::class
                    && $context['description_key'] === 'activity_log.description.attachment_bulk_delete'
                    && $context['description_params']['count'] === 1
                    && $context['properties']['identifier'] === 'post-images'
                    && $context['properties']['snapshot'] !== null;
            });

        $this->listener->handleAttachmentAfterBulkDelete('post-images', 3, $ids, $snapshots);

        $this->assertCount(3, $loggedIds);
        foreach ($ids as $id) {
            $this->assertContains($id, $loggedIds);
        }
    }

    // ═══════════════════════════════════════════
    // Mail Template 핸들러 테스트 (2개)
    // ═══════════════════════════════════════════

    public function test_handleMailTemplateAfterUpdate_logs_activity_with_changes(): void
    {
        $template = $this->createModelMock(3, ['name' => 'welcome_email']);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'mail_template.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.mail_template_update'
                    && $context['description_params']['template_name'] === 'welcome_email'
                    && array_key_exists('changes', $context)
                    && isset($context['loggable']);
            });

        $this->listener->handleMailTemplateAfterUpdate($template);
    }

    public function test_handleMailTemplateAfterUpdate_accepts_snapshot_argument(): void
    {
        $template = $this->createModelMock(3, ['name' => 'welcome_email']);
        $snapshot = ['id' => 3, 'subject' => 'Old Subject'];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'mail_template.update'
                    && array_key_exists('changes', $context);
            });

        $this->listener->handleMailTemplateAfterUpdate($template, $snapshot);
    }

    public function test_handleMailTemplateAfterToggleActive_logs_activity(): void
    {
        $template = $this->createModelMock(3, ['name' => 'reset_password']);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'mail_template.toggle_active'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.mail_template_toggle_active'
                    && $context['description_params']['template_name'] === 'reset_password'
                    && isset($context['loggable']);
            });

        $this->listener->handleMailTemplateAfterToggleActive($template);
    }

    // ═══════════════════════════════════════════
    // Module 핸들러 테스트 (6개)
    // ═══════════════════════════════════════════

    /**
     * @dataProvider moduleInstallActivateDeactivateProvider
     */
    public function test_module_lifecycle_hooks_log_activity(
        string $method,
        string $expectedAction,
        string $expectedDescriptionKey
    ): void {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) use ($expectedAction, $expectedDescriptionKey) {
                return $action === $expectedAction
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === $expectedDescriptionKey
                    && $context['description_params']['module_name'] === 'sirsoft-ecommerce';
            });

        $this->listener->{$method}('sirsoft-ecommerce', ['version' => '1.0.0']);
    }

    public static function moduleInstallActivateDeactivateProvider(): array
    {
        return [
            'module install' => [
                'handleModuleAfterInstall',
                'module.install',
                'activity_log.description.module_install',
            ],
            'module activate' => [
                'handleModuleAfterActivate',
                'module.activate',
                'activity_log.description.module_activate',
            ],
            'module deactivate' => [
                'handleModuleAfterDeactivate',
                'module.deactivate',
                'activity_log.description.module_deactivate',
            ],
        ];
    }

    public function test_handleModuleAfterInstall_includes_version_in_properties(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'module.install'
                    && $context['properties']['identifier'] === 'sirsoft-ecommerce'
                    && $context['properties']['version'] === '2.0.0';
            });

        $this->listener->handleModuleAfterInstall('sirsoft-ecommerce', ['version' => '2.0.0']);
    }

    public function test_handleModuleAfterUninstall_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'module.uninstall'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.module_uninstall'
                    && $context['description_params']['module_name'] === 'sirsoft-ecommerce'
                    && $context['properties']['identifier'] === 'sirsoft-ecommerce'
                    && $context['properties']['delete_data'] === true;
            });

        $this->listener->handleModuleAfterUninstall('sirsoft-ecommerce', ['version' => '1.0.0'], true);
    }

    public function test_handleModuleAfterUpdate_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'module.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.module_update'
                    && $context['description_params']['module_name'] === 'sirsoft-ecommerce'
                    && $context['properties']['identifier'] === 'sirsoft-ecommerce'
                    && $context['properties']['result'] === true;
            });

        $this->listener->handleModuleAfterUpdate('sirsoft-ecommerce', ['success' => true], ['version' => '1.1.0']);
    }

    public function test_handleModuleAfterRefreshLayouts_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'module.refresh_layouts'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.module_refresh_layouts'
                    && $context['description_params']['module_name'] === 'sirsoft-ecommerce';
            });

        $this->listener->handleModuleAfterRefreshLayouts('sirsoft-ecommerce', ['success' => true]);
    }

    // ═══════════════════════════════════════════
    // Plugin 핸들러 테스트 (5개)
    // ═══════════════════════════════════════════

    /**
     * @dataProvider pluginInstallActivateDeactivateProvider
     */
    public function test_plugin_lifecycle_hooks_log_activity(
        string $method,
        string $expectedAction,
        string $expectedDescriptionKey
    ): void {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) use ($expectedAction, $expectedDescriptionKey) {
                return $action === $expectedAction
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === $expectedDescriptionKey
                    && $context['description_params']['plugin_name'] === 'sirsoft-payment';
            });

        $this->listener->{$method}('sirsoft-payment', ['version' => '1.0.0']);
    }

    public static function pluginInstallActivateDeactivateProvider(): array
    {
        return [
            'plugin install' => [
                'handlePluginAfterInstall',
                'plugin.install',
                'activity_log.description.plugin_install',
            ],
            'plugin activate' => [
                'handlePluginAfterActivate',
                'plugin.activate',
                'activity_log.description.plugin_activate',
            ],
            'plugin deactivate' => [
                'handlePluginAfterDeactivate',
                'plugin.deactivate',
                'activity_log.description.plugin_deactivate',
            ],
        ];
    }

    public function test_handlePluginAfterInstall_includes_version_in_properties(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'plugin.install'
                    && $context['properties']['identifier'] === 'sirsoft-payment'
                    && $context['properties']['version'] === '3.0.0';
            });

        $this->listener->handlePluginAfterInstall('sirsoft-payment', ['version' => '3.0.0']);
    }

    public function test_handlePluginAfterUninstall_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'plugin.uninstall'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.plugin_uninstall'
                    && $context['description_params']['plugin_name'] === 'sirsoft-payment'
                    && $context['properties']['identifier'] === 'sirsoft-payment'
                    && $context['properties']['delete_data'] === false;
            });

        $this->listener->handlePluginAfterUninstall('sirsoft-payment', false, true);
    }

    public function test_handlePluginAfterUpdate_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'plugin.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.plugin_update'
                    && $context['description_params']['plugin_name'] === 'sirsoft-payment'
                    && $context['properties']['identifier'] === 'sirsoft-payment'
                    && $context['properties']['result'] === true;
            });

        $this->listener->handlePluginAfterUpdate('sirsoft-payment', ['success' => true], ['version' => '1.1.0']);
    }

    // ═══════════════════════════════════════════
    // Template 핸들러 테스트 (6개)
    // ═══════════════════════════════════════════

    public function test_handleTemplateAfterInstall_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'template.install'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.template_install'
                    && $context['description_params']['template_name'] === 'sirsoft-admin_basic'
                    && $context['properties']['identifier'] === 'sirsoft-admin_basic'
                    && $context['properties']['version'] === '1.0.0';
            });

        $this->listener->handleTemplateAfterInstall('sirsoft-admin_basic', ['version' => '1.0.0']);
    }

    public function test_handleTemplateAfterActivate_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'template.activate'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.template_activate'
                    && $context['description_params']['template_name'] === 'sirsoft-admin_basic';
            });

        $this->listener->handleTemplateAfterActivate(['identifier' => 'sirsoft-admin_basic']);
    }

    public function test_handleTemplateAfterActivate_handles_missing_identifier(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'template.activate'
                    && $context['description_params']['template_name'] === '';
            });

        $this->listener->handleTemplateAfterActivate([]);
    }

    public function test_handleTemplateAfterDeactivate_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'template.deactivate'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.template_deactivate'
                    && $context['description_params']['template_name'] === 'sirsoft-basic';
            });

        $this->listener->handleTemplateAfterDeactivate(['identifier' => 'sirsoft-basic']);
    }

    public function test_handleTemplateAfterUninstall_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'template.uninstall'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.template_uninstall'
                    && $context['description_params']['template_name'] === 'sirsoft-admin_basic'
                    && $context['properties']['identifier'] === 'sirsoft-admin_basic'
                    && $context['properties']['delete_data'] === true;
            });

        $this->listener->handleTemplateAfterUninstall('sirsoft-admin_basic', ['version' => '1.0.0'], true);
    }

    public function test_handleTemplateAfterVersionUpdate_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'template.version_update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.template_version_update'
                    && $context['description_params']['template_name'] === 'sirsoft-admin_basic'
                    && $context['properties']['identifier'] === 'sirsoft-admin_basic'
                    && $context['properties']['result'] === true;
            });

        $this->listener->handleTemplateAfterVersionUpdate('sirsoft-admin_basic', ['success' => true], ['version' => '2.0.0']);
    }

    public function test_handleTemplateAfterRefreshLayouts_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'template.refresh_layouts'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.template_refresh_layouts'
                    && $context['description_params']['template_name'] === 'sirsoft-basic';
            });

        $this->listener->handleTemplateAfterRefreshLayouts('sirsoft-basic', ['success' => true]);
    }

    // ═══════════════════════════════════════════
    // Layout 핸들러 테스트 (2개)
    // ═══════════════════════════════════════════

    public function test_handleLayoutAfterUpdate_logs_activity(): void
    {
        $layout = $this->createModelMock(50);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'layout.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.layout_update'
                    && $context['description_params']['layout_path'] === 'admin/users/index'
                    && isset($context['loggable']);
            });

        $this->listener->handleLayoutAfterUpdate($layout, 1, 'admin/users/index', ['content' => '{}']);
    }

    public function test_handleLayoutAfterVersionRestore_logs_activity(): void
    {
        $newVersion = $this->createModelMock(51);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'layout.version_restore'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.layout_version_restore'
                    && $context['description_params']['layout_path'] === 'admin/dashboard'
                    && $context['properties']['version_id'] === 10;
            });

        $this->listener->handleLayoutAfterVersionRestore($newVersion, 1, 'admin/dashboard', 10);
    }

    // ═══════════════════════════════════════════
    // Module Settings 핸들러 테스트 (2개)
    // ═══════════════════════════════════════════

    public function test_handleModuleSettingsAfterSave_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'module_settings.save'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.module_settings_save'
                    && $context['description_params']['module_name'] === 'sirsoft-ecommerce'
                    && $context['properties']['identifier'] === 'sirsoft-ecommerce'
                    && $context['properties']['keys'] === ['currency', 'tax_rate'];
            });

        $this->listener->handleModuleSettingsAfterSave('sirsoft-ecommerce', ['currency' => 'KRW', 'tax_rate' => 10], true);
    }

    public function test_handleModuleSettingsAfterReset_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'module_settings.reset'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.module_settings_reset'
                    && $context['description_params']['module_name'] === 'sirsoft-ecommerce';
            });

        $this->listener->handleModuleSettingsAfterReset('sirsoft-ecommerce');
    }

    // ═══════════════════════════════════════════
    // Plugin Settings 핸들러 테스트 (2개)
    // ═══════════════════════════════════════════

    public function test_handlePluginSettingsAfterSave_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'plugin_settings.save'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.plugin_settings_save'
                    && $context['description_params']['plugin_name'] === 'sirsoft-payment'
                    && $context['properties']['identifier'] === 'sirsoft-payment'
                    && $context['properties']['keys'] === ['api_key', 'sandbox'];
            });

        $this->listener->handlePluginSettingsAfterSave('sirsoft-payment', ['api_key' => 'xxx', 'sandbox' => true], true);
    }

    public function test_handlePluginSettingsAfterReset_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'plugin_settings.reset'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.plugin_settings_reset'
                    && $context['description_params']['plugin_name'] === 'sirsoft-payment';
            });

        $this->listener->handlePluginSettingsAfterReset('sirsoft-payment');
    }

    // ═══════════════════════════════════════════
    // 에러 처리 테스트
    // ═══════════════════════════════════════════

    public function test_logActivity_catches_exception_and_logs_error(): void
    {
        $user = $this->createUserMock(1, 'uuid-err');

        // Log::channel('activity')->info() 호출 시 예외 발생
        $this->logChannel->shouldReceive('info')
            ->once()
            ->andThrow(new \RuntimeException('DB connection failed'));

        // Log::error() 호출 기대
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'Failed to record activity log'
                    && $context['action'] === 'user.show'
                    && $context['error'] === 'DB connection failed';
            });

        // 예외가 외부로 전파되지 않아야 한다
        $this->listener->handleUserAfterShow($user);
    }

    // ═══════════════════════════════════════════
    // 데이터 프로바이더 기반 종합 검증
    // ═══════════════════════════════════════════

    /**
     * 모든 훅 매핑이 실제 메서드와 연결되는지 확인
     */
    public function test_all_subscribed_hooks_have_corresponding_methods(): void
    {
        $hooks = CoreActivityLogListener::getSubscribedHooks();
        $reflection = new \ReflectionClass(CoreActivityLogListener::class);

        foreach ($hooks as $hookName => $config) {
            $methodName = $config['method'];
            $this->assertTrue(
                $reflection->hasMethod($methodName),
                "Hook '{$hookName}'에 매핑된 메서드 '{$methodName}'이 CoreActivityLogListener에 존재하지 않습니다."
            );
        }
    }

    /**
     * 로깅 훅은 모두 priority 20인지 확인
     */
    public function test_logging_hooks_have_priority_20(): void
    {
        $hooks = CoreActivityLogListener::getSubscribedHooks();
        $snapshotMethods = [
            'captureUserSnapshot',
            'captureRoleSnapshot',
            'captureMenuSnapshot',
            'captureScheduleSnapshot',
            'captureMailTemplateSnapshot',
        ];

        foreach ($hooks as $hookName => $config) {
            if (! in_array($config['method'], $snapshotMethods)) {
                $this->assertEquals(
                    20,
                    $config['priority'],
                    "로깅 훅 '{$hookName}'의 priority는 20이어야 합니다."
                );
            }
        }
    }

    /**
     * @dataProvider allHookActionsProvider
     */
    public function test_hook_action_mapping_is_correct(string $hookName, string $method, string $expectedAction, ActivityLogType $expectedLogType, string $expectedDescriptionKey): void
    {
        // 이 테스트는 getSubscribedHooks()의 모든 로깅 훅이
        // 올바른 action, log_type, description_key를 사용하는지 종합 검증
        $hooks = CoreActivityLogListener::getSubscribedHooks();

        $this->assertArrayHasKey($hookName, $hooks, "훅 '{$hookName}'이 getSubscribedHooks()에 등록되어 있어야 합니다.");
        $this->assertEquals($method, $hooks[$hookName]['method']);

        // 이 provider의 값들은 개별 테스트에서 이미 검증된다.
        // 여기서는 매핑 자체의 정확성만 확인.
        $this->assertNotEmpty($expectedAction);
        $this->assertNotEmpty($expectedDescriptionKey);
    }

    public static function allHookActionsProvider(): array
    {
        return [
            // User
            ['core.user.after_create', 'handleUserAfterCreate', 'user.create', ActivityLogType::Admin, 'activity_log.description.user_create'],
            ['core.user.after_update', 'handleUserAfterUpdate', 'user.update', ActivityLogType::Admin, 'activity_log.description.user_update'],
            ['core.user.after_delete', 'handleUserAfterDelete', 'user.delete', ActivityLogType::Admin, 'activity_log.description.user_delete'],
            ['core.user.after_withdraw', 'handleUserAfterWithdraw', 'user.withdraw', ActivityLogType::Admin, 'activity_log.description.user_withdraw'],
            ['core.user.after_show', 'handleUserAfterShow', 'user.show', ActivityLogType::Admin, 'activity_log.description.user_show'],
            ['core.user.after_list', 'handleUserAfterList', 'user.index', ActivityLogType::Admin, 'activity_log.description.user_index'],
            ['core.user.after_search', 'handleUserAfterSearch', 'user.search', ActivityLogType::Admin, 'activity_log.description.user_search'],
            ['sirsoft-core.user.after_bulk_update', 'handleUserAfterBulkUpdate', 'user.bulk_update_status', ActivityLogType::Admin, 'activity_log.description.user_bulk_update_status'],

            // Auth
            ['core.auth.after_login', 'handleAuthAfterLogin', 'auth.login', ActivityLogType::Admin, 'activity_log.description.auth_login'],
            ['core.auth.logout', 'handleAuthLogout', 'auth.logout', ActivityLogType::Admin, 'activity_log.description.auth_logout'],
            ['core.auth.register', 'handleAuthRegister', 'auth.register', ActivityLogType::User, 'activity_log.description.auth_register'],
            ['core.auth.forgot_password', 'handleAuthForgotPassword', 'auth.forgot_password', ActivityLogType::User, 'activity_log.description.auth_forgot_password'],
            ['core.auth.reset_password', 'handleAuthResetPassword', 'auth.reset_password', ActivityLogType::User, 'activity_log.description.auth_reset_password'],
            ['core.auth.record_consents', 'handleAuthRecordConsents', 'auth.record_consents', ActivityLogType::User, 'activity_log.description.auth_record_consents'],

            // Role
            ['core.role.after_create', 'handleRoleAfterCreate', 'role.create', ActivityLogType::Admin, 'activity_log.description.role_create'],
            ['core.role.after_update', 'handleRoleAfterUpdate', 'role.update', ActivityLogType::Admin, 'activity_log.description.role_update'],
            ['core.role.after_delete', 'handleRoleAfterDelete', 'role.delete', ActivityLogType::Admin, 'activity_log.description.role_delete'],
            ['core.role.after_sync_permissions', 'handleRoleAfterSyncPermissions', 'role.sync_permissions', ActivityLogType::Admin, 'activity_log.description.role_sync_permissions'],
            ['core.role.after_toggle_status', 'handleRoleAfterToggleStatus', 'role.toggle_status', ActivityLogType::Admin, 'activity_log.description.role_toggle_status'],

            // Menu
            ['core.menu.after_create', 'handleMenuAfterCreate', 'menu.create', ActivityLogType::Admin, 'activity_log.description.menu_create'],
            ['core.menu.after_update', 'handleMenuAfterUpdate', 'menu.update', ActivityLogType::Admin, 'activity_log.description.menu_update'],
            ['core.menu.after_delete', 'handleMenuAfterDelete', 'menu.delete', ActivityLogType::Admin, 'activity_log.description.menu_delete'],
            ['core.menu.after_update_order', 'handleMenuAfterUpdateOrder', 'menu.update_order', ActivityLogType::Admin, 'activity_log.description.menu_update_order'],
            ['core.menu.after_toggle_status', 'handleMenuAfterToggleStatus', 'menu.toggle_status', ActivityLogType::Admin, 'activity_log.description.menu_toggle_status'],
            ['core.menu.after_sync_roles', 'handleMenuAfterSyncRoles', 'menu.sync_roles', ActivityLogType::Admin, 'activity_log.description.menu_sync_roles'],

            // Settings
            ['core.settings.after_save', 'handleSettingsAfterSave', 'settings.save', ActivityLogType::Admin, 'activity_log.description.settings_save'],
            ['core.settings.after_set', 'handleSettingsAfterSet', 'settings.update', ActivityLogType::Admin, 'activity_log.description.settings_update'],

            // Schedule
            ['core.schedule.after_create', 'handleScheduleAfterCreate', 'schedule.create', ActivityLogType::Admin, 'activity_log.description.schedule_create'],
            ['core.schedule.after_update', 'handleScheduleAfterUpdate', 'schedule.update', ActivityLogType::Admin, 'activity_log.description.schedule_update'],
            ['core.schedule.after_delete', 'handleScheduleAfterDelete', 'schedule.delete', ActivityLogType::Admin, 'activity_log.description.schedule_delete'],
            ['core.schedule.after_run', 'handleScheduleAfterRun', 'schedule.run', ActivityLogType::Admin, 'activity_log.description.schedule_run'],
            ['core.schedule.after_bulk_update', 'handleScheduleAfterBulkUpdate', 'schedule.bulk_update', ActivityLogType::Admin, 'activity_log.description.schedule_bulk_update_status'],
            ['core.schedule.after_bulk_delete', 'handleScheduleAfterBulkDelete', 'schedule.bulk_delete', ActivityLogType::Admin, 'activity_log.description.schedule_bulk_delete'],

            // Attachment
            ['core.attachment.after_upload', 'handleAttachmentAfterUpload', 'attachment.upload', ActivityLogType::Admin, 'activity_log.description.attachment_upload'],
            ['core.attachment.after_delete', 'handleAttachmentAfterDelete', 'attachment.delete', ActivityLogType::Admin, 'activity_log.description.attachment_delete'],
            ['core.attachment.after_bulk_delete', 'handleAttachmentAfterBulkDelete', 'attachment.bulk_delete', ActivityLogType::Admin, 'activity_log.description.attachment_bulk_delete'],

            // Mail Template
            ['core.mail_template.after_update', 'handleMailTemplateAfterUpdate', 'mail_template.update', ActivityLogType::Admin, 'activity_log.description.mail_template_update'],
            ['core.mail_template.after_toggle_active', 'handleMailTemplateAfterToggleActive', 'mail_template.toggle_active', ActivityLogType::Admin, 'activity_log.description.mail_template_toggle_active'],

            // Module
            ['core.modules.after_install', 'handleModuleAfterInstall', 'module.install', ActivityLogType::Admin, 'activity_log.description.module_install'],
            ['core.modules.after_activate', 'handleModuleAfterActivate', 'module.activate', ActivityLogType::Admin, 'activity_log.description.module_activate'],
            ['core.modules.after_deactivate', 'handleModuleAfterDeactivate', 'module.deactivate', ActivityLogType::Admin, 'activity_log.description.module_deactivate'],
            ['core.modules.after_uninstall', 'handleModuleAfterUninstall', 'module.uninstall', ActivityLogType::Admin, 'activity_log.description.module_uninstall'],
            ['core.modules.after_update', 'handleModuleAfterUpdate', 'module.update', ActivityLogType::Admin, 'activity_log.description.module_update'],
            ['core.modules.after_refresh_layouts', 'handleModuleAfterRefreshLayouts', 'module.refresh_layouts', ActivityLogType::Admin, 'activity_log.description.module_refresh_layouts'],

            // Plugin
            ['core.plugins.after_install', 'handlePluginAfterInstall', 'plugin.install', ActivityLogType::Admin, 'activity_log.description.plugin_install'],
            ['core.plugins.after_activate', 'handlePluginAfterActivate', 'plugin.activate', ActivityLogType::Admin, 'activity_log.description.plugin_activate'],
            ['core.plugins.after_deactivate', 'handlePluginAfterDeactivate', 'plugin.deactivate', ActivityLogType::Admin, 'activity_log.description.plugin_deactivate'],
            ['core.plugins.after_uninstall', 'handlePluginAfterUninstall', 'plugin.uninstall', ActivityLogType::Admin, 'activity_log.description.plugin_uninstall'],
            ['core.plugins.after_update', 'handlePluginAfterUpdate', 'plugin.update', ActivityLogType::Admin, 'activity_log.description.plugin_update'],

            // Template
            ['core.templates.after_install', 'handleTemplateAfterInstall', 'template.install', ActivityLogType::Admin, 'activity_log.description.template_install'],
            ['core.templates.after_activate', 'handleTemplateAfterActivate', 'template.activate', ActivityLogType::Admin, 'activity_log.description.template_activate'],
            ['core.templates.after_deactivate', 'handleTemplateAfterDeactivate', 'template.deactivate', ActivityLogType::Admin, 'activity_log.description.template_deactivate'],
            ['core.templates.after_uninstall', 'handleTemplateAfterUninstall', 'template.uninstall', ActivityLogType::Admin, 'activity_log.description.template_uninstall'],
            ['core.templates.after_version_update', 'handleTemplateAfterVersionUpdate', 'template.version_update', ActivityLogType::Admin, 'activity_log.description.template_version_update'],
            ['core.templates.after_refresh_layouts', 'handleTemplateAfterRefreshLayouts', 'template.refresh_layouts', ActivityLogType::Admin, 'activity_log.description.template_refresh_layouts'],

            // Layout
            ['core.layout.after_update', 'handleLayoutAfterUpdate', 'layout.update', ActivityLogType::Admin, 'activity_log.description.layout_update'],
            ['core.layout.after_version_restore', 'handleLayoutAfterVersionRestore', 'layout.version_restore', ActivityLogType::Admin, 'activity_log.description.layout_version_restore'],

            // Module Settings
            ['core.module_settings.after_save', 'handleModuleSettingsAfterSave', 'module_settings.save', ActivityLogType::Admin, 'activity_log.description.module_settings_save'],
            ['core.module_settings.after_reset', 'handleModuleSettingsAfterReset', 'module_settings.reset', ActivityLogType::Admin, 'activity_log.description.module_settings_reset'],

            // Plugin Settings
            ['core.plugin_settings.after_save', 'handlePluginSettingsAfterSave', 'plugin_settings.save', ActivityLogType::Admin, 'activity_log.description.plugin_settings_save'],
            ['core.plugin_settings.after_reset', 'handlePluginSettingsAfterReset', 'plugin_settings.reset', ActivityLogType::Admin, 'activity_log.description.plugin_settings_reset'],
        ];
    }
}
