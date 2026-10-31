<?php

namespace Tests\Feature\ActivityLog;

use App\Extension\HookManager;
use App\Listeners\CoreActivityLogListener;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 가입·비밀번호 활동 로그 — 실제 발화 이름으로 발화했을 때 로그 행이 남는다 (#138 후속 전수 점검 3번)
 *
 * 활동 로그 리스너가 `core.auth.register`·`forgot_password`·`reset_password` 를 구독했지만 AuthService 는
 * `after_register`·`after_reset_password_request`·`after_password_changed` 로 발화해, 세 사건의 로그가
 * 한 번도 남지 않았다. 핸들러를 직접 부르는 단위 테스트는 구독 이름이 틀려도 통과하므로, 여기서는
 * 발행 측 이름으로 doAction 해서 행이 남는지 본다.
 */
class AuthActivityLogWiringTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 가입 완료 훅이 회원가입 로그를 남긴다.
     *
     * @scenario case=register_self
     *
     * @effects register_logged_via_published_hook
     *
     * @return void 반환값 없음
     */
    public function test_after_register_writes_register_log(): void
    {
        $user = User::factory()->create();

        HookManager::doAction('core.auth.after_register', $user, [
            'registration_time' => now(),
            'registration_data' => ['email' => $user->email],
        ]);

        $row = DB::table('activity_logs')->where('action', 'auth.register')->where('loggable_id', $user->id)->first();

        $this->assertNotNull($row, 'core.auth.after_register 발화가 회원가입 로그를 남기지 않았다');
        $this->assertSame($user->id, (int) $row->user_id);
    }

    /**
     * 비밀번호 재설정 요청 훅이 비밀번호 찾기 요청 로그를 남긴다.
     *
     * @scenario case=forgot_password_self
     *
     * @effects forgot_password_logged_via_published_hook
     *
     * @return void 반환값 없음
     */
    public function test_after_reset_password_request_writes_forgot_password_log(): void
    {
        $user = User::factory()->create();

        HookManager::doAction('core.auth.after_reset_password_request', $user, ['reset_url' => 'https://example.test/reset']);

        $row = DB::table('activity_logs')->where('action', 'auth.forgot_password')->where('loggable_id', $user->id)->first();

        $this->assertNotNull($row, 'core.auth.after_reset_password_request 발화가 비밀번호 찾기 로그를 남기지 않았다');
        $this->assertSame($user->id, (int) $row->user_id);
    }

    /**
     * 메일 재설정으로 바꾼 비밀번호 기록 훅이 비밀번호 재설정 로그를 남긴다 — 재설정 링크(비로그인)는 본인이 행위자다.
     *
     * 비밀번호 변경 로그는 #736 부터 경로·행위자를 아는 `core.auth.password_change_recorded` 에서 남긴다
     * (종전 `after_password_changed` 는 인자가 회원뿐이라 모든 변경을 "재설정" 으로 적었다).
     *
     * @scenario case=password_changed_self
     *
     * @effects password_changed_logged_via_published_hook
     *
     * @return void 반환값 없음
     */
    public function test_password_change_recorded_reset_writes_reset_password_log_with_self_actor(): void
    {
        $user = User::factory()->create();

        HookManager::doAction('core.auth.password_change_recorded', $user, [
            'source' => 'reset',
            'actor_id' => $user->id,
            'sessions_revoked' => 0,
        ]);

        $row = DB::table('activity_logs')->where('action', 'auth.reset_password')->where('loggable_id', $user->id)->first();

        $this->assertNotNull($row, 'core.auth.password_change_recorded(reset) 발화가 비밀번호 재설정 로그를 남기지 않았다');
        $this->assertSame($user->id, (int) $row->user_id);
    }

    /**
     * 관리자가 회원 정보 수정에서 비밀번호를 정하면 행위자는 그 관리자다 — 회원 본인으로 적지 않는다.
     *
     * @scenario case=password_changed_admin
     *
     * @effects password_changed_by_admin_logs_admin_actor
     *
     * @return void 반환값 없음
     */
    public function test_password_change_recorded_by_admin_logs_admin_as_actor(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();

        HookManager::doAction('core.auth.password_change_recorded', $member, [
            'source' => 'admin',
            'actor_id' => $admin->id,
            'sessions_revoked' => 1,
        ]);

        $row = DB::table('activity_logs')->where('action', 'auth.password_change')->where('loggable_id', $member->id)->first();

        $this->assertNotNull($row);
        $this->assertSame($admin->id, (int) $row->user_id, '관리자가 정한 비밀번호를 회원 본인이 한 것으로 기록했다');
        $this->assertSame($member->id, (int) $row->loggable_id);
        $this->assertSame('activity_log.description.auth_password_change_by_admin', $row->description_key);
    }

    /**
     * 구독 표에 옛(발화되지 않는) 이름이 남아 있지 않다.
     *
     * @scenario case=subscription_contract
     *
     * @effects auth_subscriptions_use_published_names
     *
     * @return void 반환값 없음
     */
    public function test_listener_does_not_subscribe_unpublished_auth_names(): void
    {
        $hooks = CoreActivityLogListener::getSubscribedHooks();

        foreach (['core.auth.register', 'core.auth.forgot_password', 'core.auth.reset_password'] as $dead) {
            $this->assertArrayNotHasKey($dead, $hooks, $dead.' 은 발화되지 않는 이름이다');
        }
    }
}
