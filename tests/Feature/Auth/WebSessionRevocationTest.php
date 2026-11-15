<?php

namespace Tests\Feature\Auth;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\PasswordChangeSource;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\AuthService;
use App\Services\PasswordChangeService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 토큰을 폐기하는 경로가 그 회원의 web 세션도 함께 지우는지 검증한다 (KVE-2026-3187 동형 S1).
 *
 * 세션 드라이버가 database 일 때만 세션 행을 지울 수 있다 — 다른 드라이버는 아무것도 하지 않고 0 을 돌려준다.
 */
class WebSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트 공통 준비를 합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('session.driver', 'database');
    }

    /**
     * 회원의 세션 행을 하나 심습니다.
     *
     * @param  User  $user  세션 주인
     * @param  string  $id  세션 ID
     * @return void 반환값 없음
     */
    private function seedSession(User $user, string $id): void
    {
        DB::table(config('session.table', 'sessions'))->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => base64_encode(serialize([])),
            'last_activity' => time(),
        ]);
    }

    /**
     * 회원의 남은 세션 수를 셉니다.
     *
     * @param  User  $user  대상 회원
     * @return int 세션 수
     */
    private function sessionCount(User $user): int
    {
        return DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->count();
    }

    /**
     * 모든 기기 로그아웃은 그 회원의 세션을 전부 지우고 다른 회원 세션은 남긴다.
     *
     * @effects sessions_deleted_on_token_revocation
     *
     * @return void 반환값 없음
     */
    public function test_logout_from_all_devices_deletes_every_session_of_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->seedSession($user, 's-user-1');
        $this->seedSession($user, 's-user-2');
        $this->seedSession($other, 's-other-1');

        app(AuthService::class)->logoutFromAllDevices($user);

        $this->assertSame(0, $this->sessionCount($user));
        $this->assertSame(1, $this->sessionCount($other));
    }

    /**
     * 비밀번호 재설정(현재 기기 유지 없음)은 모든 세션을 지운다.
     *
     * @effects sessions_deleted_on_token_revocation
     *
     * @return void 반환값 없음
     */
    public function test_password_reset_deletes_all_sessions(): void
    {
        $user = User::factory()->create();
        $this->seedSession($user, 's-reset-1');
        $this->seedSession($user, 's-reset-2');

        app(PasswordChangeService::class)->recordChange($user, null, PasswordChangeSource::Reset);

        $this->assertSame(0, $this->sessionCount($user));
    }

    /**
     * 본인 비밀번호 변경(현재 토큰 유지)은 지금 쓰는 세션만 남기고 나머지를 지운다.
     *
     * @effects current_session_kept_on_self_change
     *
     * @return void 반환값 없음
     */
    public function test_self_password_change_keeps_only_current_session(): void
    {
        $user = User::factory()->create();
        // 세션 저장소는 40자 영숫자가 아닌 ID 를 버리고 새로 만든다 — 실제 형식의 ID 를 쓴다.
        $currentId = str_repeat('a', 40);
        $this->seedSession($user, $currentId);
        $this->seedSession($user, str_repeat('b', 40));

        $token = $user->createToken('current')->accessToken;

        $request = request();
        $session = $this->app['session']->driver();
        $session->setId($currentId);
        $request->setLaravelSession($session);

        app(PasswordChangeService::class)->recordChange(
            $user,
            null,
            PasswordChangeSource::Self_,
            keepTokenId: $token->id,
        );

        $remaining = DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->pluck('id')->all();
        $this->assertSame([$currentId], $remaining);
    }

    /**
     * 관리자가 회원을 차단하면 그 회원의 세션이 지워진다.
     *
     * @effects sessions_deleted_on_token_revocation
     *
     * @return void 반환값 없음
     */
    public function test_blocking_user_deletes_sessions(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Active->value]);
        $this->seedSession($user, 's-block-1');

        app(UserService::class)->updateUser($user, ['status' => UserStatus::Blocked->value]);

        $this->assertSame(0, $this->sessionCount($user));
    }

    /**
     * 일괄 상태 변경(Active 외)도 대상 회원들의 세션을 지운다.
     *
     * @effects sessions_deleted_on_token_revocation
     *
     * @return void 반환값 없음
     */
    public function test_bulk_status_change_deletes_sessions(): void
    {
        $a = User::factory()->create(['status' => UserStatus::Active->value]);
        $b = User::factory()->create(['status' => UserStatus::Active->value]);
        $this->seedSession($a, 's-bulk-a');
        $this->seedSession($b, 's-bulk-b');

        app(UserService::class)->bulkUpdateStatus([$a->uuid, $b->uuid], UserStatus::Blocked->value);

        $this->assertSame(0, $this->sessionCount($a));
        $this->assertSame(0, $this->sessionCount($b));
    }

    /**
     * 세션 드라이버가 database 가 아니면 아무것도 지우지 않고 0 을 돌려준다.
     *
     * @effects non_database_session_driver_noop
     *
     * @return void 반환값 없음
     */
    public function test_non_database_driver_is_noop(): void
    {
        Config::set('session.driver', 'file');
        $user = User::factory()->create();
        $this->seedSession($user, 's-file-1');

        $deleted = app(UserRepositoryInterface::class)->deleteSessionsByUserIds([$user->id]);

        $this->assertSame(0, $deleted);
        $this->assertSame(1, $this->sessionCount($user));
    }
}
