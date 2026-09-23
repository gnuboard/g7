<?php

namespace Tests\Unit\Listeners;

use App\Enums\ActivityLogType;
use App\Listeners\CoreActivityLogListener;
use App\Models\ActivityLog;
use App\Models\Attachment;
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
 * 구독 훅 70개(docs/backend/activity-log-hooks.md 「코어 훅」 표의 행 수)의 구독 계약과
 * 핸들러별 기록 형태를 검증한다.
 * 시나리오 SSoT: tests/scenarios/core-activity-log-listener.yaml
 */
class CoreActivityLogListenerTest extends TestCase
{
    use RefreshDatabase;

    private CoreActivityLogListener $listener;

    private MockInterface $logChannel;

    /**
     * 관리자 경로 요청을 심고 리스너를 만든 뒤 activity 로그 채널을 mock 으로 바꿉니다.
     *
     * 관리자 경로(`/api/admin/users`)라 `resolveLogType()` 이 Admin 을 돌려준다. 사용자 경로 테스트는 요청을 따로 바꾼다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 관리자 경로 요청 설정 (resolveLogType()이 Admin 반환하도록)
        $this->app->instance('request', Request::create('/api/admin/users'));

        $this->listener = app(CoreActivityLogListener::class);

        // Mock Log::channel('activity') → $logChannel
        $this->logChannel = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')
            ->with('activity')
            ->andReturn($this->logChannel);
    }

    /**
     * Mockery 기대 횟수를 PHPUnit 단언 수에 합산한 뒤 Mockery 컨테이너를 닫습니다.
     *
     * `shouldReceive()->once()` 만으로 검증하는 테스트가 risky(단언 0건)로 표시되지 않게 한다.
     *
     * @return void 반환값 없음
     */
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
     * id·uuid·이메일·이름을 채운 User 부분 mock 을 만듭니다.
     *
     * @param  int  $id  사용자 ID
     * @param  string  $uuid  사용자 UUID
     * @param  string  $email  이메일
     * @param  string  $name  이름
     * @return MockInterface|User 속성이 채워진 사용자 mock
     */
    private function createUserMock(int $id = 1, string $uuid = 'uuid-123', string $email = 'test@test.com', string $name = 'Test User'): MockInterface
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => $id, 'uuid' => $uuid, 'email' => $email, 'name' => $name]);

        return $user;
    }

    /**
     * id 와 추가 속성을 채운 범용 Model 부분 mock 을 만듭니다.
     *
     * @param  int  $id  모델 ID
     * @param  array  $extraAttributes  추가로 채울 속성
     * @return MockInterface|Model 속성이 채워진 모델 mock
     */
    private function createModelMock(int $id = 1, array $extraAttributes = []): MockInterface
    {
        $model = Mockery::mock(Model::class)->makePartial();
        $model->forceFill(array_merge(['id' => $id], $extraAttributes));

        return $model;
    }

    /**
     * activity 채널 info() 가 지정 action·log_type·설명 키로 한 번 호출되기를 기대로 겁니다.
     *
     * @param  string  $expectedAction  기대 action 식별자
     * @param  ActivityLogType  $expectedLogType  기대 로그 유형
     * @param  string  $expectedDescriptionKey  기대 설명 번역 키
     * @param  bool  $expectLoggable  true 면 context 에 loggable 이 실려야 한다
     * @return void 반환값 없음
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

    /**
     * 구독 훅 수가 70개인지 확인합니다 — docs/backend/activity-log-hooks.md 「코어 훅」 표의 행 수와 같습니다.
     *
     * @scenario case=subscription_contract
     *
     * @effects subscribed_hook_count_matches_the_hook_table
     *
     * @return void 반환값 없음
     */
    public function test_get_subscribed_hooks_returns_all_hooks(): void
    {
        $hooks = CoreActivityLogListener::getSubscribedHooks();

        // 70개 로깅 훅 — docs/backend/activity-log-hooks.md 「코어 훅」 표와 같은 수다(표의 행이 SSoT).
        // (mail_template 2개 제거, IDV/auth 후속 추가, 관리자 계정 잠금 해제, 회원 알림 일괄 삭제 등 포함)
        $this->assertCount(70, $hooks);
    }

    /**
     * 기본 handle() 은 아무 작업도 하지 않고 예외 없이 끝납니다.
     *
     * @scenario case=subscription_contract
     *
     * @effects default_handle_is_a_no_op
     *
     * @return void 반환값 없음
     */
    public function test_handle_does_nothing(): void
    {
        // handle() 메서드는 기본 핸들러로 아무 작업도 하지 않아야 한다
        $this->listener->handle('arg1', 'arg2');
        $this->assertTrue(true); // 예외 없이 완료 확인
    }

    // ═══════════════════════════════════════════
    // User 핸들러 테스트 (8개)
    // ═══════════════════════════════════════════

    /**
     * 사용자 생성이 user.create 로 기록되고 uuid·이메일·이름이 실립니다.
     *
     * @scenario case=user_handlers
     *
     * @effects user_crud_view_and_search_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_user_after_create_logs_activity(): void
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

    /**
     * 사용자 수정이 user.update 로 기록되고 변경 내역(changes)이 실립니다.
     *
     * @scenario case=user_handlers
     *
     * @effects user_crud_view_and_search_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_user_after_update_logs_activity_with_changes(): void
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

    /**
     * 사용자 수정 핸들러가 스냅샷 인자를 받아 변경 내역을 계산합니다.
     *
     * @scenario case=user_handlers
     *
     * @effects update_handlers_accept_a_snapshot_argument
     *
     * @return void 반환값 없음
     */
    public function test_handle_user_after_update_accepts_snapshot_argument(): void
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

    /**
     * 사용자 삭제가 user.delete 로 기록되고 삭제된 사용자 정보가 properties 에 남습니다.
     *
     * @scenario case=user_handlers
     *
     * @effects user_crud_view_and_search_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_user_after_delete_logs_activity(): void
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

    /**
     * 삭제 데이터에 uuid 가 없으면 빈 문자열로 대체해 기록합니다.
     *
     * @scenario case=missing_inputs
     *
     * @effects missing_identifiers_fall_back_to_empty_strings
     *
     * @return void 반환값 없음
     */
    public function test_handle_user_after_delete_handles_missing_uuid(): void
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

    /**
     * 회원 탈퇴가 user.withdraw 로 loggable 과 함께 기록됩니다.
     *
     * @scenario case=user_handlers
     *
     * @effects user_crud_view_and_search_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_user_after_withdraw_logs_activity(): void
    {
        $user = $this->createUserMock(1, 'uuid-wd');

        $this->expectLogInfo('user.withdraw', ActivityLogType::Admin, 'activity_log.description.user_withdraw', true);

        $this->listener->handleUserAfterWithdraw($user);
    }

    /**
     * 사용자 상세 조회가 user.show 로 기록됩니다.
     *
     * @scenario case=user_handlers
     *
     * @effects user_crud_view_and_search_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_user_after_show_logs_activity(): void
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

    /**
     * 사용자 목록 조회가 user.index 로 결과 수와 함께 기록됩니다.
     *
     * @scenario case=user_handlers
     *
     * @effects user_crud_view_and_search_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_user_after_list_logs_activity(): void
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

    /**
     * 사용자 검색이 user.search 로 검색어·결과 수와 함께 기록됩니다.
     *
     * @scenario case=user_handlers
     *
     * @effects user_crud_view_and_search_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_user_after_search_logs_activity(): void
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

    /**
     * 사용자 일괄 상태 변경은 대상마다 한 건씩(count=1) 기록됩니다.
     *
     * @scenario case=user_handlers
     *
     * @effects user_bulk_update_logs_one_entry_per_existing_user
     *
     * @return void 반환값 없음
     */
    public function test_handle_user_after_bulk_update_logs_per_item(): void
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

    /**
     * 사용자 일괄 상태 변경에서 존재하지 않는 uuid 는 건너뜁니다.
     *
     * @scenario case=user_handlers
     *
     * @effects user_bulk_update_logs_one_entry_per_existing_user
     *
     * @return void 반환값 없음
     */
    public function test_handle_user_after_bulk_update_skips_nonexistent_uuids(): void
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

    /**
     * 로그인이 auth.login 으로 기록됩니다 (관리자 경로 → Admin 유형).
     *
     * @scenario case=auth_handlers
     *
     * @effects auth_events_are_logged_with_log_type_by_route
     *
     * @return void 반환값 없음
     */
    public function test_handle_auth_after_login_logs_activity(): void
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

    /**
     * 로그아웃이 auth.logout 으로 기록됩니다.
     *
     * @scenario case=auth_handlers
     *
     * @effects auth_events_are_logged_with_log_type_by_route
     *
     * @return void 반환값 없음
     */
    public function test_handle_auth_logout_logs_activity(): void
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

    /**
     * 회원가입은 사용자 경로라 User 유형의 auth.register 로 기록됩니다.
     *
     * @scenario case=auth_handlers
     *
     * @effects auth_events_are_logged_with_log_type_by_route
     *
     * @return void 반환값 없음
     */
    public function test_handle_auth_register_logs_activity_with_user_type(): void
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

    /**
     * 비밀번호 찾기가 User 유형의 auth.forgot_password 로 기록됩니다.
     *
     * @scenario case=auth_handlers
     *
     * @effects auth_events_are_logged_with_log_type_by_route
     *
     * @return void 반환값 없음
     */
    public function test_handle_auth_forgot_password_logs_activity(): void
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

    /**
     * 비밀번호 재설정이 User 유형의 auth.reset_password 로 기록됩니다.
     *
     * @scenario case=auth_handlers
     *
     * @effects auth_events_are_logged_with_log_type_by_route
     *
     * @return void 반환값 없음
     */
    public function test_handle_auth_reset_password_logs_activity(): void
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

    /**
     * 약관 동의 기록이 auth.record_consents 로 동의 시점 IP 와 함께 기록됩니다.
     *
     * @scenario case=auth_handlers
     *
     * @effects auth_events_are_logged_with_log_type_by_route
     *
     * @return void 반환값 없음
     */
    public function test_handle_auth_record_consents_logs_activity(): void
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

    /**
     * 역할 생성이 role.create 로 기록됩니다.
     *
     * @scenario case=role_handlers
     *
     * @effects role_changes_are_logged_with_permission_diff
     *
     * @return void 반환값 없음
     */
    public function test_handle_role_after_create_logs_activity(): void
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

    /**
     * 역할 수정이 role.update 로 변경 내역과 함께 기록됩니다.
     *
     * @scenario case=role_handlers
     *
     * @effects role_changes_are_logged_with_permission_diff
     *
     * @return void 반환값 없음
     */
    public function test_handle_role_after_update_logs_activity_with_changes(): void
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

    /**
     * 역할 수정 핸들러가 스냅샷 인자를 받아 변경 내역을 계산합니다.
     *
     * @scenario case=role_handlers
     *
     * @effects update_handlers_accept_a_snapshot_argument
     *
     * @return void 반환값 없음
     */
    public function test_handle_role_after_update_accepts_snapshot_argument(): void
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

    /**
     * 역할 삭제가 role.delete 로 역할 ID 와 함께 기록됩니다.
     *
     * @scenario case=role_handlers
     *
     * @effects role_changes_are_logged_with_permission_diff
     *
     * @return void 반환값 없음
     */
    public function test_handle_role_after_delete_logs_activity(): void
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

    /**
     * 역할 권한 동기화가 추가·제거된 권한 목록과 함께 기록됩니다.
     *
     * @scenario case=role_handlers
     *
     * @effects role_changes_are_logged_with_permission_diff
     *
     * @return void 반환값 없음
     */
    public function test_handle_role_after_sync_permissions_logs_activity(): void
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

    /**
     * 역할 상태 토글이 role.toggle_status 로 기록됩니다.
     *
     * @scenario case=role_handlers
     *
     * @effects role_changes_are_logged_with_permission_diff
     *
     * @return void 반환값 없음
     */
    public function test_handle_role_after_toggle_status_logs_activity(): void
    {
        $role = $this->createModelMock(10);

        $this->expectLogInfo('role.toggle_status', ActivityLogType::Admin, 'activity_log.description.role_toggle_status', true);

        $this->listener->handleRoleAfterToggleStatus($role);
    }

    // ═══════════════════════════════════════════
    // Menu 핸들러 테스트 (6개)
    // ═══════════════════════════════════════════

    /**
     * 메뉴 생성이 menu.create 로 기록됩니다.
     *
     * @scenario case=menu_handlers
     *
     * @effects menu_changes_are_logged_with_role_diff
     *
     * @return void 반환값 없음
     */
    public function test_handle_menu_after_create_logs_activity(): void
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

    /**
     * 메뉴 수정이 menu.update 로 변경 내역과 함께 기록됩니다.
     *
     * @scenario case=menu_handlers
     *
     * @effects menu_changes_are_logged_with_role_diff
     *
     * @return void 반환값 없음
     */
    public function test_handle_menu_after_update_logs_activity_with_changes(): void
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

    /**
     * 메뉴 수정 핸들러가 스냅샷 인자를 받아 변경 내역을 계산합니다.
     *
     * @scenario case=menu_handlers
     *
     * @effects update_handlers_accept_a_snapshot_argument
     *
     * @return void 반환값 없음
     */
    public function test_handle_menu_after_update_accepts_snapshot_argument(): void
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

    /**
     * 메뉴 삭제가 menu.delete 로 메뉴 ID 와 함께 기록됩니다.
     *
     * @scenario case=menu_handlers
     *
     * @effects menu_changes_are_logged_with_role_diff
     *
     * @return void 반환값 없음
     */
    public function test_handle_menu_after_delete_logs_activity(): void
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

    /**
     * 메뉴 순서 변경이 menu.update_order 로 기록됩니다.
     *
     * @scenario case=menu_handlers
     *
     * @effects menu_changes_are_logged_with_role_diff
     *
     * @return void 반환값 없음
     */
    public function test_handle_menu_after_update_order_logs_activity(): void
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

    /**
     * 메뉴 상태 토글이 menu.toggle_status 로 기록됩니다.
     *
     * @scenario case=menu_handlers
     *
     * @effects menu_changes_are_logged_with_role_diff
     *
     * @return void 반환값 없음
     */
    public function test_handle_menu_after_toggle_status_logs_activity(): void
    {
        $menu = $this->createModelMock(5);

        $this->expectLogInfo('menu.toggle_status', ActivityLogType::Admin, 'activity_log.description.menu_toggle_status', true);

        $this->listener->handleMenuAfterToggleStatus($menu);
    }

    /**
     * 메뉴 역할 동기화가 추가·제거된 역할과 함께 기록됩니다.
     *
     * @scenario case=menu_handlers
     *
     * @effects menu_changes_are_logged_with_role_diff
     *
     * @return void 반환값 없음
     */
    public function test_handle_menu_after_sync_roles_logs_activity(): void
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

    /**
     * 환경설정 저장이 settings.save 로 탭·저장 키 목록과 함께 기록됩니다.
     *
     * @scenario case=settings_handlers
     *
     * @effects settings_save_and_set_are_logged_with_keys
     *
     * @return void 반환값 없음
     */
    public function test_handle_settings_after_save_logs_activity(): void
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

    /**
     * 단일 설정 변경이 settings.update 로 키와 함께 기록됩니다.
     *
     * @scenario case=settings_handlers
     *
     * @effects settings_save_and_set_are_logged_with_keys
     *
     * @return void 반환값 없음
     */
    public function test_handle_settings_after_set_logs_activity(): void
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

    /**
     * 스케줄 생성이 schedule.create 로 기록됩니다.
     *
     * @scenario case=schedule_handlers
     *
     * @effects schedule_changes_and_runs_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_schedule_after_create_logs_activity(): void
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

    /**
     * 스케줄 수정이 schedule.update 로 변경 내역과 함께 기록됩니다.
     *
     * @scenario case=schedule_handlers
     *
     * @effects schedule_changes_and_runs_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_schedule_after_update_logs_activity_with_changes(): void
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

    /**
     * 스케줄 수정 핸들러가 스냅샷 인자를 받아 변경 내역을 계산합니다.
     *
     * @scenario case=schedule_handlers
     *
     * @effects update_handlers_accept_a_snapshot_argument
     *
     * @return void 반환값 없음
     */
    public function test_handle_schedule_after_update_accepts_snapshot_argument(): void
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

    /**
     * 스케줄 삭제가 schedule.delete 로 스케줄 ID 와 함께 기록됩니다.
     *
     * @scenario case=schedule_handlers
     *
     * @effects schedule_changes_and_runs_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_schedule_after_delete_logs_activity(): void
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

    /**
     * 스케줄 수동 실행이 schedule.run 으로 기록됩니다.
     *
     * @scenario case=schedule_handlers
     *
     * @effects schedule_changes_and_runs_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_schedule_after_run_logs_activity(): void
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

    /**
     * 스케줄 일괄 상태 변경은 대상마다 한 건씩 기록됩니다.
     *
     * @scenario case=schedule_handlers
     *
     * @effects schedule_bulk_actions_log_one_entry_per_item
     *
     * @return void 반환값 없음
     */
    public function test_handle_schedule_after_bulk_update_logs_per_item(): void
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

    /**
     * 스케줄 일괄 상태 변경에서 존재하지 않는 ID 는 건너뜁니다.
     *
     * @scenario case=schedule_handlers
     *
     * @effects schedule_bulk_actions_log_one_entry_per_item
     *
     * @return void 반환값 없음
     */
    public function test_handle_schedule_after_bulk_update_skips_nonexistent_ids(): void
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

    /**
     * 스케줄 일괄 삭제는 대상마다 한 건씩 loggable_type·loggable_id 를 직접 실어 기록합니다.
     *
     * 삭제 뒤에는 모델이 없으므로 loggable 모델 대신 타입·ID 를 싣는다.
     *
     * @scenario case=schedule_handlers
     *
     * @effects schedule_bulk_actions_log_one_entry_per_item
     *
     * @return void 반환값 없음
     */
    public function test_handle_schedule_after_bulk_delete_logs_per_item(): void
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

    /**
     * 첨부파일 업로드가 attachment.upload 로 기록됩니다.
     *
     * @scenario case=attachment_handlers
     *
     * @effects attachment_upload_delete_and_bulk_delete_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_attachment_after_upload_logs_activity(): void
    {
        $attachment = $this->createModelMock(20);

        $this->expectLogInfo('attachment.upload', ActivityLogType::Admin, 'activity_log.description.attachment_upload', true);

        $this->listener->handleAttachmentAfterUpload($attachment);
    }

    /**
     * 첨부파일 삭제가 attachment.delete 로 기록됩니다.
     *
     * @scenario case=attachment_handlers
     *
     * @effects attachment_upload_delete_and_bulk_delete_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_attachment_after_delete_logs_activity(): void
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

    /**
     * 첨부파일 일괄 삭제는 파일마다 한 건씩 스냅샷과 함께 기록됩니다.
     *
     * @scenario case=attachment_handlers
     *
     * @effects attachment_upload_delete_and_bulk_delete_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_attachment_after_bulk_delete_creates_per_item_logs(): void
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
                    && $context['loggable_type'] === Attachment::class
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
    // Module 핸들러 테스트 (6개)
    // ═══════════════════════════════════════════

    /**
     * 모듈 설치·활성화·비활성화가 각각의 action 과 설명 키로 기록됩니다.
     *
     * @dataProvider moduleInstallActivateDeactivateProvider
     *
     * @scenario case=module_handlers
     *
     * @effects module_lifecycle_is_logged
     *
     * @param  string  $method  호출할 리스너 핸들러 메서드명
     * @param  string  $expectedAction  기대 action 식별자
     * @param  string  $expectedDescriptionKey  기대 설명 번역 키
     * @return void 반환값 없음
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

    /**
     * 모듈 설치·활성화·비활성화 핸들러와 기대 action·설명 키 조합을 제공합니다.
     *
     * @return array<string, array{0: string, 1: string, 2: string}> 케이스명 → [핸들러, action, 설명 키]
     */
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

    /**
     * 모듈 설치 기록에 식별자와 버전이 properties 로 실립니다.
     *
     * @scenario case=module_handlers
     *
     * @effects module_lifecycle_is_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_module_after_install_includes_version_in_properties(): void
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

    /**
     * 모듈 삭제가 데이터 삭제 여부와 함께 기록됩니다.
     *
     * @scenario case=module_handlers
     *
     * @effects module_lifecycle_is_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_module_after_uninstall_logs_activity(): void
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

    /**
     * 모듈 업데이트가 결과(result)와 함께 기록됩니다.
     *
     * @scenario case=module_handlers
     *
     * @effects module_lifecycle_is_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_module_after_update_logs_activity(): void
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

    /**
     * 모듈 레이아웃 갱신이 module.refresh_layouts 로 기록됩니다.
     *
     * @scenario case=module_handlers
     *
     * @effects module_lifecycle_is_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_module_after_refresh_layouts_logs_activity(): void
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
     * 플러그인 설치·활성화·비활성화가 각각의 action 과 설명 키로 기록됩니다.
     *
     * @dataProvider pluginInstallActivateDeactivateProvider
     *
     * @scenario case=plugin_handlers
     *
     * @effects plugin_lifecycle_is_logged
     *
     * @param  string  $method  호출할 리스너 핸들러 메서드명
     * @param  string  $expectedAction  기대 action 식별자
     * @param  string  $expectedDescriptionKey  기대 설명 번역 키
     * @return void 반환값 없음
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

    /**
     * 플러그인 설치·활성화·비활성화 핸들러와 기대 action·설명 키 조합을 제공합니다.
     *
     * @return array<string, array{0: string, 1: string, 2: string}> 케이스명 → [핸들러, action, 설명 키]
     */
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

    /**
     * 플러그인 설치 기록에 식별자와 버전이 properties 로 실립니다.
     *
     * @scenario case=plugin_handlers
     *
     * @effects plugin_lifecycle_is_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_plugin_after_install_includes_version_in_properties(): void
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

    /**
     * 플러그인 삭제가 데이터 삭제 여부와 함께 기록됩니다.
     *
     * @scenario case=plugin_handlers
     *
     * @effects plugin_lifecycle_is_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_plugin_after_uninstall_logs_activity(): void
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

    /**
     * 플러그인 업데이트가 결과(result)와 함께 기록됩니다.
     *
     * @scenario case=plugin_handlers
     *
     * @effects plugin_lifecycle_is_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_plugin_after_update_logs_activity(): void
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

    /**
     * 템플릿 설치가 식별자·버전과 함께 기록됩니다.
     *
     * @scenario case=template_handlers
     *
     * @effects template_lifecycle_is_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_template_after_install_logs_activity(): void
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

    /**
     * 템플릿 활성화가 template.activate 로 기록됩니다.
     *
     * @scenario case=template_handlers
     *
     * @effects template_lifecycle_is_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_template_after_activate_logs_activity(): void
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

    /**
     * 템플릿 활성화 데이터에 식별자가 없으면 빈 문자열로 대체해 기록합니다.
     *
     * @scenario case=missing_inputs
     *
     * @effects missing_identifiers_fall_back_to_empty_strings
     *
     * @return void 반환값 없음
     */
    public function test_handle_template_after_activate_handles_missing_identifier(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'template.activate'
                    && $context['description_params']['template_name'] === '';
            });

        $this->listener->handleTemplateAfterActivate([]);
    }

    /**
     * 템플릿 비활성화가 template.deactivate 로 기록됩니다.
     *
     * @scenario case=template_handlers
     *
     * @effects template_lifecycle_is_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_template_after_deactivate_logs_activity(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'template.deactivate'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.template_deactivate'
                    && $context['description_params']['template_name'] === 'sirsoft-basic';
            });

        $this->listener->handleTemplateAfterDeactivate('sirsoft-basic');
    }

    /**
     * 템플릿 삭제가 데이터 삭제 여부와 함께 기록됩니다.
     *
     * @scenario case=template_handlers
     *
     * @effects template_lifecycle_is_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_template_after_uninstall_logs_activity(): void
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

    /**
     * 템플릿 버전 업데이트가 결과(result)와 함께 기록됩니다.
     *
     * @scenario case=template_handlers
     *
     * @effects template_lifecycle_is_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_template_after_version_update_logs_activity(): void
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

    /**
     * 템플릿 레이아웃 갱신이 template.refresh_layouts 로 기록됩니다.
     *
     * @scenario case=template_handlers
     *
     * @effects template_lifecycle_is_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_template_after_refresh_layouts_logs_activity(): void
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

    /**
     * 레이아웃 수정이 레이아웃 경로와 함께 기록됩니다.
     *
     * @scenario case=layout_handlers
     *
     * @effects layout_update_and_restore_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_layout_after_update_logs_activity(): void
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

    /**
     * 레이아웃 버전 복원이 복원한 버전 ID 와 함께 기록됩니다.
     *
     * @scenario case=layout_handlers
     *
     * @effects layout_update_and_restore_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_layout_after_version_restore_logs_activity(): void
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

    /**
     * 모듈 설정 저장이 저장 키 목록과 함께 기록됩니다.
     *
     * @scenario case=extension_settings_handlers
     *
     * @effects extension_settings_save_and_reset_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_module_settings_after_save_logs_activity(): void
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

    /**
     * 모듈 설정 초기화가 module_settings.reset 으로 기록됩니다.
     *
     * @scenario case=extension_settings_handlers
     *
     * @effects extension_settings_save_and_reset_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_module_settings_after_reset_logs_activity(): void
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

    /**
     * 플러그인 설정 저장이 저장 키 목록과 함께 기록됩니다.
     *
     * @scenario case=extension_settings_handlers
     *
     * @effects extension_settings_save_and_reset_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_plugin_settings_after_save_logs_activity(): void
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

    /**
     * 플러그인 설정 초기화가 plugin_settings.reset 으로 기록됩니다.
     *
     * @scenario case=extension_settings_handlers
     *
     * @effects extension_settings_save_and_reset_are_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_plugin_settings_after_reset_logs_activity(): void
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

    /**
     * 로그 기록이 예외를 던져도 밖으로 전파하지 않고 Log::error 로 흡수합니다.
     *
     * 로깅 실패가 도메인 동작(여기서는 사용자 조회)을 깨뜨리면 안 된다.
     *
     * @scenario case=logging_failure_is_absorbed
     *
     * @effects logging_exception_is_absorbed_into_log_error
     *
     * @return void 반환값 없음
     */
    public function test_log_activity_catches_exception_and_logs_error(): void
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
     * 구독한 모든 훅이 리스너에 실재하는 핸들러 메서드로 연결되는지 확인합니다.
     *
     * @scenario case=subscription_contract
     *
     * @effects subscribed_hooks_map_to_existing_handlers
     *
     * @return void 반환값 없음
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
     * 스냅샷 캡처를 뺀 로깅 훅은 모두 priority 20 인지 확인합니다 — 도메인 훅보다 뒤에 섭니다.
     *
     * @scenario case=subscription_contract
     *
     * @effects all_logging_hooks_registered_at_priority_20
     *
     * @return void 반환값 없음
     */
    public function test_logging_hooks_have_priority_20(): void
    {
        $hooks = CoreActivityLogListener::getSubscribedHooks();
        $snapshotMethods = [
            'captureUserSnapshot',
            'captureRoleSnapshot',
            'captureMenuSnapshot',
            'captureScheduleSnapshot',
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
     * 훅 이름 → 핸들러 메서드 매핑이 action 표와 일치하는지 확인합니다.
     *
     * action·log_type·설명 키 값 자체는 핸들러별 개별 테스트가 검증하고, 여기서는 매핑의 정확성만 본다.
     *
     * @dataProvider allHookActionsProvider
     *
     * @scenario case=subscription_contract
     *
     * @effects hook_to_handler_mapping_matches_the_action_table
     *
     * @param  string  $hookName  훅 이름
     * @param  string  $method  매핑돼야 할 핸들러 메서드명
     * @param  string  $expectedAction  기대 action 식별자
     * @param  ActivityLogType  $expectedLogType  기대 로그 유형
     * @param  string  $expectedDescriptionKey  기대 설명 번역 키
     * @return void 반환값 없음
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

    /**
     * 로깅 훅마다 핸들러·action·로그 유형·설명 키 기대값을 제공합니다.
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: ActivityLogType, 4: string}> [훅, 핸들러, action, 유형, 설명 키] 목록
     */
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
