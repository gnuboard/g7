<?php

namespace Tests\Unit\Listeners;

use App\Enums\ActivityLogType;
use App\Http\Resources\ActivityLogResource;
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
use PHPUnit\Framework\Attributes\DataProvider;
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
     * 관리자 경로 요청을 심고, 리스너 인스턴스와 activity 로그 채널 목을 준비합니다.
     *
     * 관리자 경로(`/api/admin/users`)라 `resolveLogType()` 이 Admin 을 돌려준다. 사용자 경로 테스트는 요청을 따로 바꾼다.
     *
     * @return void 없음
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
     * Mockery 기대 횟수를 단언 수에 반영한 뒤 목 컨테이너를 닫습니다.
     *
     * `shouldReceive()->once()` 만으로 검증하는 테스트가 risky(단언 0건)로 표시되지 않게 한다.
     *
     * @return void 없음
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
     * 식별 속성을 채운 User 부분 목을 만듭니다.
     *
     * @param  int  $id  회원 id
     * @param  string  $uuid  회원 uuid
     * @param  string  $email  회원 이메일
     * @param  string  $name  회원 이름
     * @return MockInterface|User 속성이 채워진 회원 목
     */
    private function createUserMock(int $id = 1, string $uuid = 'uuid-123', string $email = 'test@test.com', string $name = 'Test User'): MockInterface
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => $id, 'uuid' => $uuid, 'email' => $email, 'name' => $name]);

        return $user;
    }

    /**
     * id 와 추가 속성을 채운 범용 Model 부분 목을 만듭니다.
     *
     * @param  int  $id  모델 id
     * @param  array<string, mixed>  $extraAttributes  함께 채울 추가 속성
     * @return MockInterface|Model 속성이 채워진 모델 목
     */
    private function createModelMock(int $id = 1, array $extraAttributes = []): MockInterface
    {
        $model = Mockery::mock(Model::class)->makePartial();
        $model->forceFill(array_merge(['id' => $id], $extraAttributes));

        return $model;
    }

    /**
     * activity 채널 info() 가 주어진 액션·유형·설명 키로 한 번 호출되기를 기대하도록 설정합니다.
     *
     * @param  string  $expectedAction  기대하는 액션 이름
     * @param  ActivityLogType  $expectedLogType  기대하는 로그 유형
     * @param  string  $expectedDescriptionKey  기대하는 설명 다국어 키
     * @param  bool  $expectLoggable  true 면 모델 참조(loggable)까지 요구
     * @return void 없음
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
     * 구독 훅 목록이 현재 등록된 로깅·스냅샷 훅 수(82)와 일치하는지 확인합니다.
     *
     * @effects subscribed_hook_count_matches_registry
     *
     * @return void 없음
     */
    // @scenario case=subscription_contract
    // @effects subscribed_hook_count_matches_the_hook_table
    public function test_get_subscribed_hooks_returns_all_hooks(): void
    {
        $hooks = CoreActivityLogListener::getSubscribedHooks();

        // 81개 로깅 훅 (mail_template 2개 제거됨, IDV/auth 후속 추가 분 포함, develop 머지 후 +2, 관리자 계정
        // 잠금 해제(core.auth.account_unlocked) +1, 회원 알림 일괄 삭제 등). docs/backend/activity-log-hooks.md
        // 「코어 훅」 표가 SSoT 이나 develop-7.1.0 16차 병합 시점 표는 70행이다 — 아래 11훅이 아직 표에 없다.
        // 65 에서 멈춰 있던 동안 방문자 통계(core.analytics.* 9)·개인정보 보유기간(core.privacy.* 2)·
        // 회원 알림함·커스텀 자산·설정 등이 더해졌다 — 2026-09-26 실측 81(접두어별: auth 9 · analytics 9 ·
        // user 7 · menu 6 · schedule 6 · modules 6 · templates 6 · role 5 · plugins 5 · layout 5 · 그 외 17).
        // #708 회원 역할 일괄 변경(core.user.after_bulk_update_roles) +1 → 82.
        // #736 비밀번호 정책 — after_password_changed 구독을 password_change_recorded 로 옮기고(±0)
        // password_change_postponed·password_expired 를 더함 +2 → 84.
        // #736 D23 회원 등록·수정의 역할 변경(core.user.after_update_roles) +1 → 85.
        $this->assertCount(85, $hooks);
        $this->assertSame(
            ['method' => 'handleUserAfterUpdateRoles', 'priority' => 20],
            $hooks['core.user.after_update_roles'] ?? null,
        );
    }

    /**
     * 기본 handle() 은 아무 기록도 남기지 않고 예외 없이 끝나는지 확인합니다.
     *
     * @effects default_handle_is_noop
     *
     * @return void 없음
     */
    // @scenario case=subscription_contract
    // @effects default_handle_is_a_no_op
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
     * 회원 생성 훅이 대상 uuid·이메일·이름과 모델 참조를 담아 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects user_create_logs_identity_properties
     *
     * @return void 없음
     */
    // @scenario case=user_handlers
    // @effects user_crud_view_and_search_are_logged
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
     * 회원 수정 훅이 변경 내역(changes)과 모델 참조를 담아 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects update_hook_records_change_set
     *
     * @return void 없음
     */
    // @scenario case=user_handlers
    // @effects user_crud_view_and_search_are_logged
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
     * 회원 수정 훅이 수정 전 스냅샷 인자를 받아도 변경 내역을 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects update_hook_accepts_prior_snapshot
     *
     * @return void 없음
     */
    // @scenario case=user_handlers
    // @effects update_handlers_accept_a_snapshot_argument
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
     * 회원 삭제 훅이 삭제된 회원 정보를 스냅샷으로 남기는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects user_delete_logs_deleted_user_snapshot
     *
     * @return void 없음
     */
    // @scenario case=user_handlers
    // @effects user_crud_view_and_search_are_logged
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
     * 삭제된 회원 데이터에 uuid 가 없으면 빈 문자열로 대신 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects user_delete_tolerates_missing_uuid
     *
     * @return void 없음
     */
    // @scenario case=missing_inputs
    // @effects missing_identifiers_fall_back_to_empty_strings
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
     * 회원 탈퇴 훅이 모델 참조를 담아 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects user_view_and_withdraw_logged_against_model
     *
     * @return void 없음
     */
    // @scenario case=user_handlers
    // @effects user_crud_view_and_search_are_logged
    public function test_handle_user_after_withdraw_logs_activity(): void
    {
        $user = $this->createUserMock(1, 'uuid-wd');

        $this->expectLogInfo('user.withdraw', ActivityLogType::Admin, 'activity_log.description.user_withdraw', true);

        $this->listener->handleUserAfterWithdraw($user);
    }

    /**
     * 회원 상세 조회 훅이 대상 uuid 와 모델 참조를 담아 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects user_view_and_withdraw_logged_against_model
     *
     * @return void 없음
     */
    // @scenario case=user_handlers
    // @effects user_crud_view_and_search_are_logged
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
     * 회원 목록 조회 훅이 결과 건수를 담아 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects user_list_and_search_log_query_and_count
     *
     * @return void 없음
     */
    // @scenario case=user_handlers
    // @effects user_crud_view_and_search_are_logged
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
     * 회원 검색 훅이 검색어와 결과 건수를 담아 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects user_list_and_search_log_query_and_count
     *
     * @return void 없음
     */
    // @scenario case=user_handlers
    // @effects user_crud_view_and_search_are_logged
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
     * 회원 상태 일괄 변경이 대상마다 한 건씩(count=1) 기록되는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects bulk_update_logs_one_entry_per_item
     *
     * @return void 없음
     */
    // @scenario case=user_handlers
    // @effects user_bulk_update_logs_one_entry_per_existing_user
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
     * 회원 일괄 변경에서 존재하지 않는 uuid 는 기록하지 않는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects bulk_update_skips_missing_targets
     *
     * @return void 없음
     */
    // @scenario case=user_handlers
    // @effects user_bulk_update_logs_one_entry_per_existing_user
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
     * 관리자 로그인 훅이 관리자 유형으로 사용자 id 와 함께 기록되는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects admin_login_and_logout_logged_as_admin
     *
     * @return void 없음
     */
    // @scenario case=auth_handlers
    // @effects auth_events_are_logged_with_log_type_by_route
    public function test_handle_auth_after_login_logs_activity(): void
    {
        $user = $this->createUserMock(1);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'auth.login'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.auth_admin_login'
                    && $context['user_id'] === 1
                    && isset($context['loggable']);
            });

        $this->listener->handleAuthAfterLogin($user, ['ip' => '127.0.0.1']);
    }

    /**
     * 관리자 로그아웃 훅이 관리자 유형으로 사용자 id 와 함께 기록되는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects admin_login_and_logout_logged_as_admin
     *
     * @return void 없음
     */
    // @scenario case=auth_handlers
    // @effects auth_events_are_logged_with_log_type_by_route
    public function test_handle_auth_logout_logs_activity(): void
    {
        $user = $this->createUserMock(2);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'auth.logout'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'activity_log.description.auth_admin_logout'
                    && $context['user_id'] === 2
                    && isset($context['loggable']);
            });

        $this->listener->handleAuthLogout($user);
    }

    /**
     * 같은 로그인·로그아웃 훅이라도 설명은 들어온 경로를 따른다 — 사용자 로그인에 「관리자 로그인」이 찍히지 않는다
     * (7.1.0 실측 O-B3). 관리자 로그인은 `api/auth/admin/*`, 관리자 로그아웃은 `api/admin/*` 이다.
     *
     * @scenario request_area=user
     *
     * @effects login_logout_description_follows_auth_route
     *
     * @return void 없음
     */
    // @scenario case=auth_handlers
    // @effects auth_events_are_logged_with_log_type_by_route
    public function test_login_and_logout_descriptions_follow_the_auth_route(): void
    {
        $user = $this->createUserMock(5);
        $seen = [];

        $this->logChannel->shouldReceive('info')
            ->times(5)
            ->withArgs(function (string $action, array $context) use (&$seen) {
                $seen[] = [$action, $context['description_key']];

                return true;
            });

        foreach (['/api/auth/login', '/api/auth/admin/login', '/api/auth/admin/login/password-change'] as $path) {
            $this->app->instance('request', Request::create($path, 'POST'));
            $this->listener->handleAuthAfterLogin($user, []);
        }
        foreach (['/api/auth/logout', '/api/admin/auth/logout'] as $path) {
            $this->app->instance('request', Request::create($path, 'POST'));
            $this->listener->handleAuthLogout($user);
        }

        $this->assertSame([
            ['auth.login', 'activity_log.description.auth_login'],
            ['auth.login', 'activity_log.description.auth_admin_login'],
            ['auth.login', 'activity_log.description.auth_admin_login'],
            ['auth.logout', 'activity_log.description.auth_logout'],
            ['auth.logout', 'activity_log.description.auth_admin_logout'],
        ], $seen);

        $this->assertSame('로그인', __('activity_log.description.auth_login', [], 'ko'));
        $this->assertSame('관리자 로그인', __('activity_log.description.auth_admin_login', [], 'ko'));
        $this->assertSame('Login', __('activity_log.description.auth_login', [], 'en'));
        $this->assertSame('Admin logout', __('activity_log.description.auth_admin_logout', [], 'en'));
    }

    /**
     * 회원가입 요청 경로에서는 로그 유형이 사용자(User)로 정해지는지 확인합니다.
     *
     * @scenario request_area=user
     *
     * @effects public_auth_actions_logged_as_user_type
     *
     * @return void 없음
     */
    // @scenario case=auth_handlers
    // @effects auth_events_are_logged_with_log_type_by_route
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
     * 비밀번호 찾기 요청 경로에서 사용자 유형으로 기록되는지 확인합니다.
     *
     * @scenario request_area=user
     *
     * @effects public_auth_actions_logged_as_user_type
     *
     * @return void 없음
     */
    // @scenario case=auth_handlers
    // @effects auth_events_are_logged_with_log_type_by_route
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
     * 비밀번호 재설정 요청 경로에서 사용자 유형으로 기록되는지 확인합니다.
     *
     * @scenario request_area=user
     *
     * @effects public_auth_actions_logged_as_user_type
     *
     * @return void 없음
     */
    // @scenario case=auth_handlers
    // @effects auth_events_are_logged_with_log_type_by_route
    public function test_handle_auth_reset_password_logs_activity(): void
    {
        // #736 — 비밀번호 변경 로그는 경로를 아는 기록 훅에서 남긴다. 메일 재설정(source=reset)은 종전 문구 그대로다.
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

        $this->listener->handlePasswordChangeRecorded($user, ['source' => 'reset', 'actor_id' => 5, 'sessions_revoked' => 0]);
    }

    /**
     * 약관 동의 기록 훅이 사용자 유형과 동의 시점의 IP 를 담아 기록하는지 확인합니다.
     *
     * @scenario request_area=user
     *
     * @effects public_auth_actions_logged_as_user_type, consent_log_records_client_ip
     *
     * @return void 없음
     */
    // @scenario case=auth_handlers
    // @effects auth_events_are_logged_with_log_type_by_route
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
     * 역할 생성 훅이 역할 id·이름과 모델 참조를 담아 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects create_hook_logs_target_id_and_label
     *
     * @return void 없음
     */
    // @scenario case=role_handlers
    // @effects role_changes_are_logged_with_permission_diff
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
     * 역할 수정 훅이 변경 내역과 모델 참조를 담아 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects update_hook_records_change_set
     *
     * @return void 없음
     */
    // @scenario case=role_handlers
    // @effects role_changes_are_logged_with_permission_diff
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
     * 역할 수정 훅이 수정 전 스냅샷 인자를 받아도 변경 내역을 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects update_hook_accepts_prior_snapshot
     *
     * @return void 없음
     */
    // @scenario case=role_handlers
    // @effects update_handlers_accept_a_snapshot_argument
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
     * 역할 삭제 훅이 삭제된 역할 id 를 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects delete_hook_logs_removed_id
     *
     * @return void 없음
     */
    // @scenario case=role_handlers
    // @effects role_changes_are_logged_with_permission_diff
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
     * 역할 권한 동기화 훅이 추가·제거된 권한만 추려 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects sync_hook_logs_added_and_removed_members
     *
     * @return void 없음
     */
    // @scenario case=role_handlers
    // @effects role_changes_are_logged_with_permission_diff
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
     * 역할 상태 전환 훅이 모델 참조를 담아 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects toggle_status_logged_against_model
     *
     * @return void 없음
     */
    // @scenario case=role_handlers
    // @effects role_changes_are_logged_with_permission_diff
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
     * 메뉴 생성 훅이 메뉴 id·제목과 모델 참조를 담아 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects create_hook_logs_target_id_and_label
     *
     * @return void 없음
     */
    // @scenario case=menu_handlers
    // @effects menu_changes_are_logged_with_role_diff
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
     * 메뉴 수정 훅이 변경 내역과 모델 참조를 담아 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects update_hook_records_change_set
     *
     * @return void 없음
     */
    // @scenario case=menu_handlers
    // @effects menu_changes_are_logged_with_role_diff
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
     * 메뉴 수정 훅이 수정 전 스냅샷 인자를 받아도 변경 내역을 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects update_hook_accepts_prior_snapshot
     *
     * @return void 없음
     */
    // @scenario case=menu_handlers
    // @effects update_handlers_accept_a_snapshot_argument
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
     * 메뉴 삭제 훅이 삭제된 메뉴 id 를 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects delete_hook_logs_removed_id
     *
     * @return void 없음
     */
    // @scenario case=menu_handlers
    // @effects menu_changes_are_logged_with_role_diff
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
     * 메뉴 순서 변경 훅이 기록되는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects menu_reorder_logged
     *
     * @return void 없음
     */
    // @scenario case=menu_handlers
    // @effects menu_changes_are_logged_with_role_diff
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
     * 메뉴 상태 전환 훅이 모델 참조를 담아 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects toggle_status_logged_against_model
     *
     * @return void 없음
     */
    // @scenario case=menu_handlers
    // @effects menu_changes_are_logged_with_role_diff
    public function test_handle_menu_after_toggle_status_logs_activity(): void
    {
        $menu = $this->createModelMock(5);

        $this->expectLogInfo('menu.toggle_status', ActivityLogType::Admin, 'activity_log.description.menu_toggle_status', true);

        $this->listener->handleMenuAfterToggleStatus($menu);
    }

    /**
     * 메뉴 역할 동기화 훅이 추가·제거된 역할만 추려 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects sync_hook_logs_added_and_removed_members
     *
     * @return void 없음
     */
    // @scenario case=menu_handlers
    // @effects menu_changes_are_logged_with_role_diff
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
     * 환경설정 저장 훅이 탭 이름과 저장된 키 목록을 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects settings_save_logs_tab_and_changed_keys
     *
     * @return void 없음
     */
    // @scenario case=settings_handlers
    // @effects settings_save_and_set_are_logged_with_keys
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
     * 단일 설정 변경 훅이 바뀐 키를 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects single_setting_update_logs_key
     *
     * @return void 없음
     */
    // @scenario case=settings_handlers
    // @effects settings_save_and_set_are_logged_with_keys
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
     * 스케줄 생성 훅이 스케줄 id 와 모델 참조를 담아 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects create_hook_logs_target_id_and_label
     *
     * @return void 없음
     */
    // @scenario case=schedule_handlers
    // @effects schedule_changes_and_runs_are_logged
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
     * 스케줄 수정 훅이 변경 내역과 모델 참조를 담아 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects update_hook_records_change_set
     *
     * @return void 없음
     */
    // @scenario case=schedule_handlers
    // @effects schedule_changes_and_runs_are_logged
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
     * 스케줄 수정 훅이 수정 전 스냅샷 인자를 받아도 변경 내역을 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects update_hook_accepts_prior_snapshot
     *
     * @return void 없음
     */
    // @scenario case=schedule_handlers
    // @effects update_handlers_accept_a_snapshot_argument
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
     * 스케줄 삭제 훅이 삭제된 스케줄 id 를 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects delete_hook_logs_removed_id
     *
     * @return void 없음
     */
    // @scenario case=schedule_handlers
    // @effects schedule_changes_and_runs_are_logged
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
     * 스케줄 수동 실행 훅이 스케줄 id 와 모델 참조를 담아 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects schedule_run_logged_against_schedule
     *
     * @return void 없음
     */
    // @scenario case=schedule_handlers
    // @effects schedule_changes_and_runs_are_logged
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
     * 스케줄 일괄 변경이 대상마다 한 건씩(count=1) 기록되는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects bulk_update_logs_one_entry_per_item
     *
     * @return void 없음
     */
    // @scenario case=schedule_handlers
    // @effects schedule_bulk_actions_log_one_entry_per_item
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
     * 스케줄 일괄 변경에서 존재하지 않는 id 는 기록하지 않는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects bulk_update_skips_missing_targets
     *
     * @return void 없음
     */
    // @scenario case=schedule_handlers
    // @effects schedule_bulk_actions_log_one_entry_per_item
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
     * 스케줄 일괄 삭제가 id 마다 한 건씩 대상 유형·id 를 담아 기록되는지 확인합니다.
     *
     * 삭제 뒤에는 모델이 없으므로 loggable 모델 대신 타입·ID 를 싣는다.
     *
     * @scenario request_area=admin
     *
     * @effects bulk_delete_logs_one_entry_per_id
     *
     * @return void 없음
     */
    // @scenario case=schedule_handlers
    // @effects schedule_bulk_actions_log_one_entry_per_item
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
     * 첨부 업로드 훅이 모델 참조를 담아 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects attachment_upload_and_delete_logged
     *
     * @return void 없음
     */
    // @scenario case=attachment_handlers
    // @effects attachment_upload_delete_and_bulk_delete_are_logged
    public function test_handle_attachment_after_upload_logs_activity(): void
    {
        $attachment = $this->createModelMock(20);

        $this->expectLogInfo('attachment.upload', ActivityLogType::Admin, 'activity_log.description.attachment_upload', true);

        $this->listener->handleAttachmentAfterUpload($attachment);
    }

    /**
     * 첨부 삭제 훅이 기록되는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects attachment_upload_and_delete_logged
     *
     * @return void 없음
     */
    // @scenario case=attachment_handlers
    // @effects attachment_upload_delete_and_bulk_delete_are_logged
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
     * 첨부 일괄 삭제가 id 마다 한 건씩 스냅샷과 식별자를 담아 기록되는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects bulk_delete_logs_one_entry_per_id
     *
     * @return void 없음
     */
    // @scenario case=attachment_handlers
    // @effects attachment_upload_delete_and_bulk_delete_are_logged
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
     * 모듈 설치·활성화·비활성화 훅이 관리자 유형으로 모듈 식별자와 함께 기록되는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_lifecycle_hooks_log_identifier
     *
     * @param  string  $method  호출할 리스너 핸들러 이름
     * @param  string  $expectedAction  기대하는 액션 이름
     * @param  string  $expectedDescriptionKey  기대하는 설명 다국어 키
     * @return void 없음
     */
    // @scenario case=module_handlers
    // @effects module_lifecycle_is_logged
    #[DataProvider('moduleInstallActivateDeactivateProvider')]
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
     * 모듈 설치·활성화·비활성화 훅의 핸들러·액션·설명 키 조합을 제공합니다.
     *
     * @return array<int|string, array<int, mixed>> 케이스 목록
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
     * 모듈 설치 기록에 식별자와 설치 버전이 담기는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_install_records_version
     *
     * @return void 없음
     */
    // @scenario case=module_handlers
    // @effects module_lifecycle_is_logged
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
     * 모듈 제거 기록에 데이터 삭제 여부가 담기는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_uninstall_records_delete_data_flag
     *
     * @return void 없음
     */
    // @scenario case=module_handlers
    // @effects module_lifecycle_is_logged
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
     * 모듈 업데이트 기록에 성공 여부(result)가 담기는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_update_records_result
     *
     * @return void 없음
     */
    // @scenario case=module_handlers
    // @effects module_lifecycle_is_logged
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
     * 모듈 레이아웃 갱신 훅이 모듈 식별자와 함께 기록되는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_lifecycle_hooks_log_identifier
     *
     * @return void 없음
     */
    // @scenario case=module_handlers
    // @effects module_lifecycle_is_logged
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
     * 플러그인 설치·활성화·비활성화 훅이 관리자 유형으로 플러그인 식별자와 함께 기록되는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_lifecycle_hooks_log_identifier
     *
     * @param  string  $method  호출할 리스너 핸들러 이름
     * @param  string  $expectedAction  기대하는 액션 이름
     * @param  string  $expectedDescriptionKey  기대하는 설명 다국어 키
     * @return void 없음
     */
    // @scenario case=plugin_handlers
    // @effects plugin_lifecycle_is_logged
    #[DataProvider('pluginInstallActivateDeactivateProvider')]
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
     * 플러그인 설치·활성화·비활성화 훅의 핸들러·액션·설명 키 조합을 제공합니다.
     *
     * @return array<int|string, array<int, mixed>> 케이스 목록
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
     * 플러그인 설치 기록에 식별자와 설치 버전이 담기는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_install_records_version
     *
     * @return void 없음
     */
    // @scenario case=plugin_handlers
    // @effects plugin_lifecycle_is_logged
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
     * 플러그인 제거 기록에 데이터 삭제 여부가 담기는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_uninstall_records_delete_data_flag
     *
     * @return void 없음
     */
    // @scenario case=plugin_handlers
    // @effects plugin_lifecycle_is_logged
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
     * 플러그인 업데이트 기록에 성공 여부(result)가 담기는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_update_records_result
     *
     * @return void 없음
     */
    // @scenario case=plugin_handlers
    // @effects plugin_lifecycle_is_logged
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
     * 템플릿 설치 기록에 식별자와 설치 버전이 담기는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_install_records_version
     *
     * @return void 없음
     */
    // @scenario case=template_handlers
    // @effects template_lifecycle_is_logged
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
     * 템플릿 활성화 훅이 템플릿 식별자와 함께 기록되는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_lifecycle_hooks_log_identifier
     *
     * @return void 없음
     */
    // @scenario case=template_handlers
    // @effects template_lifecycle_is_logged
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
     * 템플릿 활성화 데이터에 식별자가 없으면 빈 문자열로 대신 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects template_activate_tolerates_missing_identifier
     *
     * @return void 없음
     */
    // @scenario case=missing_inputs
    // @effects missing_identifiers_fall_back_to_empty_strings
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
     * 템플릿 비활성화 훅이 템플릿 식별자와 함께 기록되는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_lifecycle_hooks_log_identifier
     *
     * @return void 없음
     */
    // @scenario case=template_handlers
    // @effects template_lifecycle_is_logged
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
     * 템플릿 제거 기록에 데이터 삭제 여부가 담기는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_uninstall_records_delete_data_flag
     *
     * @return void 없음
     */
    // @scenario case=template_handlers
    // @effects template_lifecycle_is_logged
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
     * 템플릿 버전 업데이트 기록에 성공 여부(result)가 담기는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_update_records_result
     *
     * @return void 없음
     */
    // @scenario case=template_handlers
    // @effects template_lifecycle_is_logged
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
     * 템플릿 레이아웃 갱신 훅이 템플릿 식별자와 함께 기록되는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_lifecycle_hooks_log_identifier
     *
     * @return void 없음
     */
    // @scenario case=template_handlers
    // @effects template_lifecycle_is_logged
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
     * 레이아웃 수정 훅이 레이아웃 경로와 모델 참조를 담아 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects layout_update_and_restore_logged_with_path
     *
     * @return void 없음
     */
    // @scenario case=layout_handlers
    // @effects layout_update_and_restore_are_logged
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
     * 레이아웃 버전 복원 훅이 레이아웃 경로와 복원한 버전 id 를 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects layout_update_and_restore_logged_with_path
     *
     * @return void 없음
     */
    // @scenario case=layout_handlers
    // @effects layout_update_and_restore_are_logged
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
     * 모듈 설정 저장 훅이 모듈 식별자와 저장된 키 목록을 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_settings_save_logs_changed_keys
     *
     * @return void 없음
     */
    // @scenario case=extension_settings_handlers
    // @effects extension_settings_save_and_reset_are_logged
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
     * 모듈 설정 초기화 훅이 모듈 식별자와 함께 기록되는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_settings_reset_logged
     *
     * @return void 없음
     */
    // @scenario case=extension_settings_handlers
    // @effects extension_settings_save_and_reset_are_logged
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
     * 플러그인 설정 저장 훅이 플러그인 식별자와 저장된 키 목록을 기록하는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_settings_save_logs_changed_keys
     *
     * @return void 없음
     */
    // @scenario case=extension_settings_handlers
    // @effects extension_settings_save_and_reset_are_logged
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
     * 플러그인 설정 초기화 훅이 플러그인 식별자와 함께 기록되는지 확인합니다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_settings_reset_logged
     *
     * @return void 없음
     */
    // @scenario case=extension_settings_handlers
    // @effects extension_settings_save_and_reset_are_logged
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
     * 활동 로그 기록이 실패해도 예외를 밖으로 던지지 않고 오류 로그로 남기는지 확인합니다.
     *
     * 로깅 실패가 도메인 동작(여기서는 사용자 조회)을 깨뜨리면 안 된다.
     *
     * @scenario request_area=admin
     *
     * @effects activity_log_write_failure_is_swallowed_and_reported
     *
     * @return void 없음
     */
    // @scenario case=logging_failure_is_absorbed
    // @effects logging_exception_is_absorbed_into_log_error
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
     * 모든 훅 매핑이 실제 메서드와 연결되는지 확인
     *
     * @effects every_subscribed_hook_maps_to_existing_method
     *
     * @return void 없음
     */
    // @scenario case=subscription_contract
    // @effects subscribed_hooks_map_to_existing_handlers
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
     *
     * 스냅샷 캡처 훅(`capture*Snapshot`)은 빼고 센다 — 로깅 훅은 도메인 훅보다 뒤에 선다.
     *
     * @effects logging_hooks_run_at_priority_20
     *
     * @return void 없음
     */
    // @scenario case=subscription_contract
    // @effects all_logging_hooks_registered_at_priority_20
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
     * 로깅 훅마다 구독 목록에 등록돼 있고 기대한 핸들러 메서드로 연결되는지 확인합니다.
     *
     * action·log_type·설명 키 값 자체는 핸들러별 개별 테스트가 검증하고, 여기서는 매핑의 정확성만 본다.
     *
     * @effects hook_to_handler_mapping_matches_catalog
     *
     * @param  string  $hookName  훅 이름
     * @param  string  $method  기대하는 핸들러 메서드 이름
     * @param  string  $expectedAction  기대하는 액션 이름
     * @param  ActivityLogType  $expectedLogType  기대하는 로그 유형
     * @param  string  $expectedDescriptionKey  기대하는 설명 다국어 키
     * @return void 없음
     */
    // @scenario case=subscription_contract
    // @effects hook_to_handler_mapping_matches_the_action_table
    #[DataProvider('allHookActionsProvider')]
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
     * 로깅 훅별 핸들러·액션·로그 유형·설명 키 기대값을 제공합니다.
     *
     * @return array<int|string, array<int, mixed>> 케이스 목록
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
            ['core.auth.after_register', 'handleAuthRegister', 'auth.register', ActivityLogType::User, 'activity_log.description.auth_register'],
            ['core.auth.after_reset_password_request', 'handleAuthForgotPassword', 'auth.forgot_password', ActivityLogType::User, 'activity_log.description.auth_forgot_password'],
            ['core.auth.password_change_recorded', 'handlePasswordChangeRecorded', 'auth.reset_password', ActivityLogType::User, 'activity_log.description.auth_reset_password'],
            ['core.auth.password_change_postponed', 'handlePasswordChangePostponed', 'auth.password_change_postpone', ActivityLogType::User, 'activity_log.description.auth_password_change_postpone'],
            ['core.auth.password_expired', 'handlePasswordExpired', 'auth.password_expiry_logout', ActivityLogType::System, 'activity_log.description.auth_password_expiry_logout'],
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

    /**
     * 모듈 업데이트 기록에 레이아웃 처리 요약(카운트)과 운영자 관련 행마다 변경 내역 한 줄이 담기는지 확인합니다 (#727 D2).
     *
     * @scenario request_area=admin
     *
     * @effects extension_update_records_layout_sync
     *
     * @return void 없음
     */
    // @scenario case=module_handlers
    // @effects module_lifecycle_is_logged
    public function test_handle_module_after_update_logs_layout_sync(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'module.update'
                    && $context['properties']['layout_strategy'] === 'keep'
                    && $context['properties']['layout_sync']['applied_count'] === 2
                    && $context['properties']['layout_sync']['modified_count'] === 1
                    && ! isset($context['properties']['layout_sync']['entries'])
                    && $context['properties']['layout_sync_conflicts'][0]['reason'] === 'both_changed'
                    && count($context['changes']) === 1
                    && $context['changes'][0]['field'] === 'sirsoft-admin_basic · sirsoft-ecommerce.admin_list'
                    && $context['changes'][0]['type'] === 'enum'
                    && $context['changes'][0]['new'] === 'merged'
                    && $context['changes'][0]['new_label_key'] === 'extensions.layout_sync.verdict.merged'
                    && $context['changes'][0]['old_label_key'] === 'activity_log.layout_sync.operator_modified';
            });

        $this->listener->handleModuleAfterUpdate('sirsoft-ecommerce', [
            'success' => true,
            'layout_strategy' => 'keep',
            'layout_sync' => [
                'strategy' => 'keep', 'applied_count' => 2, 'conflict_count' => 1, 'kept_no_base_count' => 0,
                'kept_removed_count' => 0, 'kept_deleted_by_operator_count' => 0, 'kept_name_collision_count' => 0,
                'kept_invalid_count' => 0, 'modified_count' => 1,
                'entries' => [[
                    'kind' => 'layout', 'template' => 'sirsoft-admin_basic', 'name' => 'sirsoft-ecommerce.admin_list',
                    'verdict' => 'merged', 'applied' => [['unit' => 'a', 'kind' => 'partial'], ['unit' => 'b', 'kind' => 'key']],
                    'conflicts' => [['unit' => 'c', 'reason' => 'both_changed']],
                ]],
            ],
        ], ['version' => '1.1.0']);
    }

    /**
     * 레이아웃 보고가 없는 업데이트(종전 결과 모양)는 변경 내역을 싣지 않는다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_update_records_result
     *
     * @return void 없음
     */
    // @scenario case=module_handlers
    // @effects module_lifecycle_is_logged
    public function test_handle_plugin_after_update_without_layout_sync_has_no_changes(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(fn (string $action, array $context) => $action === 'plugin.update' && ! array_key_exists('changes', $context) && ! array_key_exists('layout_sync', $context['properties']));

        $this->listener->handlePluginAfterUpdate('sirsoft-gdpr', ['success' => true], ['version' => '1.0.1']);
    }

    /**
     * 레이아웃 처리 보고 한 건 (레이아웃 병합 + 확장 조각 기준 없음).
     *
     * @param  string  $template  템플릿 식별자
     * @param  string  $layout  레이아웃 이름
     * @return array<string, mixed> 업데이트 결과
     */
    private function layoutSyncResult(string $template, string $layout): array
    {
        return [
            'success' => true,
            'layout_strategy' => 'keep',
            'layout_sync' => [
                'strategy' => 'keep', 'applied_count' => 1, 'conflict_count' => 1, 'kept_no_base_count' => 1,
                'kept_removed_count' => 0, 'kept_deleted_by_operator_count' => 0, 'kept_name_collision_count' => 0,
                'kept_invalid_count' => 0, 'modified_count' => 2,
                'entries' => [
                    [
                        'kind' => 'layout', 'template' => $template, 'name' => $layout, 'verdict' => 'merged',
                        'applied' => [['unit' => 'a', 'kind' => 'partial']], 'conflicts' => [['unit' => 'b', 'reason' => 'both_changed']],
                    ],
                    [
                        'kind' => 'extension', 'template' => $template, 'name' => 'admin_user_detail:probe_a', 'verdict' => 'kept_no_base',
                        'applied' => [], 'conflicts' => [],
                    ],
                ],
            ],
        ];
    }

    /**
     * 템플릿 버전 업데이트 기록에도 레이아웃 처리 요약(카운트)·겹친 조각·운영자 관련 행마다 변경 내역 한 줄이 담긴다 (#727 D2).
     *
     * @scenario request_area=admin
     *
     * @effects extension_update_records_layout_sync
     *
     * @return void 없음
     */
    // @scenario case=template_handlers
    // @effects template_lifecycle_is_logged
    public function test_handle_template_after_version_update_logs_layout_sync(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'template.version_update'
                    && $context['properties']['identifier'] === 'sirsoft-admin_basic'
                    && $context['properties']['layout_strategy'] === 'keep'
                    && $context['properties']['layout_sync']['kept_no_base_count'] === 1
                    && $context['properties']['layout_sync']['modified_count'] === 2
                    && ! isset($context['properties']['layout_sync']['entries'])
                    && $context['properties']['layout_sync_conflicts'] === [['layout' => 'sirsoft-admin_basic · admin_dashboard', 'unit' => 'b', 'reason' => 'both_changed']]
                    && count($context['changes']) === 2
                    && $context['changes'][0]['field'] === 'sirsoft-admin_basic · admin_dashboard'
                    && $context['changes'][1]['new'] === 'kept_no_base'
                    && $context['changes'][1]['new_label_key'] === 'extensions.layout_sync.verdict.kept_no_base';
            });

        $this->listener->handleTemplateAfterVersionUpdate('sirsoft-admin_basic', $this->layoutSyncResult('sirsoft-admin_basic', 'admin_dashboard'), ['version' => '1.2.0']);
    }

    /**
     * 플러그인 업데이트 기록도 레이아웃 처리 보고가 있으면 카운트·변경 내역을 싣는다 (#727 D2 — 모듈과 같은 모양).
     *
     * @scenario request_area=admin
     *
     * @effects extension_update_records_layout_sync
     *
     * @return void 없음
     */
    // @scenario case=plugin_handlers
    // @effects plugin_lifecycle_is_logged
    public function test_handle_plugin_after_update_logs_layout_sync(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'plugin.update'
                    && $context['properties']['identifier'] === 'sirsoft-tosspayments'
                    && $context['properties']['layout_sync']['applied_count'] === 1
                    && $context['properties']['layout_sync']['conflict_count'] === 1
                    && count($context['changes']) === 2
                    && $context['changes'][0]['field'] === 'sirsoft-admin_basic · sirsoft-tosspayments.admin_settings'
                    && $context['changes'][0]['old'] === 'operator_modified'
                    && $context['changes'][0]['new_label_key'] === 'extensions.layout_sync.verdict.merged';
            });

        $this->listener->handlePluginAfterUpdate('sirsoft-tosspayments', $this->layoutSyncResult('sirsoft-admin_basic', 'sirsoft-tosspayments.admin_settings'), ['version' => '1.0.1']);
    }

    /**
     * 업데이트 기록의 변경 내역 행은 활동 로그 화면(리소스)에서 번역 라벨로 보인다 — 이전 = 「운영자 수정본」, 이후 = 판정별 문구.
     * 모든 판정 키가 ko·en 에 있어야 목록을 펼쳤을 때 키 문자열이 그대로 새지 않는다.
     *
     * @scenario request_area=admin
     *
     * @effects extension_update_records_layout_sync
     *
     * @return void 없음
     */
    // @scenario case=module_handlers
    // @effects module_lifecycle_is_logged
    public function test_layout_sync_change_rows_render_translated_labels(): void
    {
        $captured = null;
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) use (&$captured) {
                $captured = $context['changes'] ?? null;

                return $action === 'module.update';
            });

        $verdicts = ['merged', 'conflict', 'kept', 'kept_no_base', 'kept_removed', 'kept_invalid', 'kept_deleted_by_operator', 'kept_name_collision'];
        $result = $this->layoutSyncResult('sirsoft-admin_basic', 'sirsoft-board.admin_board_index');
        $result['layout_sync']['entries'] = array_map(fn (string $verdict) => [
            'kind' => 'layout', 'template' => 'sirsoft-admin_basic', 'name' => 'sirsoft-board.'.$verdict, 'verdict' => $verdict, 'applied' => [], 'conflicts' => [],
        ], $verdicts);

        $this->listener->handleModuleAfterUpdate('sirsoft-board', $result, ['version' => '1.1.0']);
        $this->assertIsArray($captured);

        $translate = new \ReflectionMethod(ActivityLogResource::class, 'translateChangeGroup');
        $translate->setAccessible(true);
        $resource = new ActivityLogResource(new ActivityLog);

        foreach (['ko', 'en'] as $locale) {
            app()->setLocale($locale);
            $rows = $translate->invoke($resource, $captured);

            foreach ($rows as $i => $row) {
                $verdict = $verdicts[$i];
                $this->assertSame('sirsoft-admin_basic · sirsoft-board.'.$verdict, $row['label'], '항목 칸은 `{템플릿} · {화면}`');
                $this->assertSame(__('activity_log.layout_sync.operator_modified'), $row['old_label']);
                $this->assertNotSame('activity_log.layout_sync.operator_modified', $row['old_label'], "{$locale}: 이전 라벨 번역 누락");
                $this->assertSame(__('extensions.layout_sync.verdict.'.$verdict), $row['new_label']);
                $this->assertNotSame('extensions.layout_sync.verdict.'.$verdict, $row['new_label'], "{$locale}: {$verdict} 판정 라벨 번역 누락");
            }
        }

        app()->setLocale('ko');
        $this->assertSame('운영자 수정본', __('activity_log.layout_sync.operator_modified'));
    }
}
