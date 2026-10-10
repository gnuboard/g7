<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 비활성 5상태 회원의 본인 정보 조회 — 역할 권한으로 판단 (결정 2026-10-07, 실측 N O-N3·P15).
 *
 * 마이페이지의 계정 상태 배지·안내는 `GET /api/me` 응답의 `status`·`status_variant` 로 그리는데,
 * `check.user_status` 가 비활성·차단·탈퇴·가입대기·휴면 회원의 모든 요청을 403 으로 막아 그 화면이
 * 실제로 보일 수 없었다. 이제 **본인 정보 읽기**(`GET /api/me`·`GET /api/auth/user`)만 그 회원 역할의
 * `core.profile.read` 권한으로 허용하고, 쓰기와 다른 기능은 종전처럼 상태로 막는다.
 * 로그인 자체(`POST /api/auth/login`)의 상태 거부는 바꾸지 않는다 — 이미 받은 토큰의 조회만 해당한다.
 */
class InactiveUserSelfReadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 코어 역할·권한 정의를 시딩한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * 비활성 5상태.
     *
     * @return array<string, array{0: string}> 상태 값
     */
    public static function inactiveStatuses(): array
    {
        return [
            'inactive' => [UserStatus::Inactive->value],
            'blocked' => [UserStatus::Blocked->value],
            'withdrawn' => [UserStatus::Withdrawn->value],
            'pending_verification' => [UserStatus::PendingVerification->value],
            'dormant' => [UserStatus::Dormant->value],
        ];
    }

    // 이 DataProvider 가 함께 검증하는 5조합 (docblock 은 @scenario 를 1건만 인식하므로 라인 주석으로 병기).
    // @scenario status=inactive, read_permission=granted, request=self_read
    // @scenario status=blocked, read_permission=granted, request=self_read
    // @scenario status=withdrawn, read_permission=granted, request=self_read
    // @scenario status=pending_verification, read_permission=granted, request=self_read
    // @scenario status=dormant, read_permission=granted, request=self_read

    /**
     * 읽기 권한이 있는 역할이면 상태 배지·안내에 필요한 본인 정보가 내려온다.
     *
     * @effects inactive_self_read_allowed_by_role_permission, self_read_response_carries_status_badge_fields
     *
     * @param  string  $status  회원 상태 (DataProvider)
     * @return void 반환값 없음
     */
    #[DataProvider('inactiveStatuses')]
    public function test_inactive_member_with_read_permission_can_read_own_profile(string $status): void
    {
        $member = $this->member($status, 'user');
        $token = $member->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.uuid', $member->uuid)
            ->assertJsonPath('data.status', $status)
            ->assertJsonStructure(['data' => ['status_label', 'status_variant']]);

        $this->withToken($token)->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonPath('data.uuid', $member->uuid);
    }

    // @scenario status=inactive, read_permission=missing, request=self_read
    // @scenario status=blocked, read_permission=missing, request=self_read
    // @scenario status=withdrawn, read_permission=missing, request=self_read
    // @scenario status=pending_verification, read_permission=missing, request=self_read
    // @scenario status=dormant, read_permission=missing, request=self_read

    /**
     * 읽기 권한이 없는 역할이면 종전처럼 상태 사유와 함께 403 이다.
     *
     * @effects inactive_self_read_denied_without_permission
     *
     * @param  string  $status  회원 상태 (DataProvider)
     * @return void 반환값 없음
     */
    #[DataProvider('inactiveStatuses')]
    public function test_inactive_member_without_read_permission_is_still_blocked(string $status): void
    {
        $member = $this->member($status, null);
        $token = $member->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/me')->assertForbidden();
        $this->withToken($token)->getJson('/api/auth/user')->assertForbidden();
    }

    // @scenario status=inactive, read_permission=granted, request=write_or_other
    // @scenario status=blocked, read_permission=granted, request=write_or_other
    // @scenario status=withdrawn, read_permission=granted, request=write_or_other
    // @scenario status=pending_verification, read_permission=granted, request=write_or_other
    // @scenario status=dormant, read_permission=granted, request=write_or_other

    /**
     * 읽기 권한이 있어도 쓰기와 다른 기능은 상태로 막힌다 — 값도 바뀌지 않는다.
     *
     * @effects inactive_writes_and_other_features_still_blocked
     *
     * @param  string  $status  회원 상태 (DataProvider)
     * @return void 반환값 없음
     */
    #[DataProvider('inactiveStatuses')]
    public function test_inactive_member_writes_and_other_features_remain_blocked(string $status): void
    {
        $member = $this->member($status, 'user');
        $token = $member->createToken('test')->plainTextToken;
        $originalName = $member->name;

        $this->withToken($token)->putJson('/api/me', ['name' => '바뀌면 안 됨', 'email' => $member->email])->assertForbidden();
        $this->withToken($token)->putJson('/api/me/password', ['current_password' => 'x', 'password' => 'NewPass!2345', 'password_confirmation' => 'NewPass!2345'])->assertForbidden();
        $this->withToken($token)->postJson('/api/me/verify-password', ['password' => 'x'])->assertForbidden();
        $this->withToken($token)->deleteJson('/api/me')->assertForbidden();
        $this->withToken($token)->deleteJson('/api/me/avatar')->assertForbidden();
        // 같은 읽기라도 `/api/user/*` 묶음(프로필·알림)은 상태 경계 그대로다 — 허용은 마이페이지 부팅 경로 두 개뿐.
        $this->withToken($token)->getJson('/api/user/profile')->assertForbidden();
        $this->withToken($token)->getJson('/api/user/notifications')->assertForbidden();

        $fresh = $member->fresh();
        $this->assertSame($originalName, $fresh->name);
        $this->assertSame($status, $fresh->status);
    }

    /**
     * 로그인 거부(상태 보안 경계)는 바뀌지 않는다 — 차단 회원은 새 토큰을 받지 못한다.
     *
     * @effects login_rejection_by_status_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_blocked_member_still_cannot_log_in(): void
    {
        $member = $this->member(UserStatus::Blocked->value, 'user', 'blocked-login@example.com');

        $this->postJson('/api/auth/login', ['email' => 'blocked-login@example.com', 'password' => 'password123'])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('data.token');

        $this->assertSame(0, $member->tokens()->count());
    }

    /**
     * 활성 회원의 본인 정보 조회는 권한과 무관하게 종전대로 열린다(회귀 없음).
     *
     * @effects active_self_read_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_active_member_self_read_does_not_need_permission(): void
    {
        $member = $this->member(UserStatus::Active->value, null);
        $token = $member->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/me')->assertOk();
        $this->withToken($token)->getJson('/api/auth/user')->assertOk();
    }

    /**
     * 상태·역할을 지정한 회원을 만든다.
     *
     * @param  string  $status  회원 상태
     * @param  string|null  $roleIdentifier  역할 식별자 (null 이면 권한 없는 역할)
     * @param  string|null  $email  이메일 (로그인 시험용)
     * @return User 회원
     */
    private function member(string $status, ?string $roleIdentifier, ?string $email = null): User
    {
        $member = User::factory()->create(array_filter([
            'status' => $status,
            'email' => $email,
            'password' => bcrypt('password123'),
        ]));

        $role = $roleIdentifier !== null
            ? Role::where('identifier', $roleIdentifier)->firstOrFail()
            : Role::create(['identifier' => 'qa_bare_'.uniqid(), 'name' => ['ko' => '빈 역할', 'en' => 'Bare'], 'is_active' => true]);

        $member->roles()->attach($role->id, ['assigned_at' => now()]);

        return $member->fresh();
    }
}
