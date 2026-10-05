<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * [case:backend-30] 2단계 인증 적용 범위(`security.two_factor_scope`) 테스트
 *
 * 범위 설정은 기존 저장값과 **뜻이 같아야** 합니다. 7.0.11 까지 `two_factor_auth=true` 는
 * "전체 회원에게 메일 2FA" 를 뜻했고, 그 사이트들이 업그레이드해도 동작이 바뀌면 안 됩니다.
 * 그래서 기본값은 `all` 이고 마이그레이션이 없습니다 — 이 테스트가 그 계약을 잠급니다.
 *
 * `admin` 범위에서는 관리자만 한 단계를 더 거치고 일반 회원은 종전처럼 바로 로그인합니다.
 */
class TwoFactorScopeTest extends TestCase
{
    use CapturesSentMail;
    use RefreshDatabase;

    private User $member;

    private User $admin;

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // 인증번호 메일 구성에는 본인인증 메시지 정의가 필요하다 — 없으면 게이트 동작이
        // 아니라 시드 부재("발송 실패")를 측정하게 된다.
        $this->prepareIdentityNotifications();
        $this->seed(RolePermissionSeeder::class);

        $this->member = User::factory()->create([
            'email' => 'scope-member@test.com',
            'password' => Hash::make('Passw0rd!2fa'),
            'status' => UserStatus::Active->value,
        ]);

        $this->admin = User::factory()->create([
            'email' => 'scope-admin@test.com',
            'password' => Hash::make('Passw0rd!2fa'),
            'status' => UserStatus::Active->value,
        ]);

        $adminRole = Role::where('identifier', 'admin')->firstOrFail();
        $this->admin->roles()->attach($adminRole->id);
    }

    /**
     * 2단계 인증 설정을 구성합니다.
     *
     * @param  bool  $enabled  마스터 토글
     * @param  string|null  $scope  적용 범위 (null 이면 미저장 — 레거시 상태 재현)
     * @return void 반환값 없음
     */
    private function setTwoFactor(bool $enabled, ?string $scope = null): void
    {
        config(['g7_settings.core.security.two_factor_auth' => $enabled]);

        if ($scope === null) {
            // 레거시 저장값에는 scope 키 자체가 없다 — 그 상태를 그대로 재현한다.
            config(['g7_settings.core.security.two_factor_scope' => null]);

            return;
        }

        config(['g7_settings.core.security.two_factor_scope' => $scope]);
    }

    /**
     * 사용자 로그인 엔드포인트로 로그인을 시도합니다.
     *
     * @param  User  $user  대상 사용자
     * @return TestResponse 로그인 응답
     */
    private function login(User $user)
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Passw0rd!2fa',
        ]);
    }

    /**
     * scope 키가 없던 사이트의 저장값이 업그레이드 뒤에도 같은 뜻(전체 회원)으로 읽히는지 확인합니다.
     *
     * @scenario two_factor=on, scope=absent(legacy), subject=member
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_legacy_setting_without_a_scope_still_covers_every_member(): void
    {
        $this->setTwoFactor(true);

        $response = $this->login($this->member);

        $response->assertStatus(200);
        $this->assertTrue(
            (bool) $response->json('data.two_factor_required'),
            'scope 키가 없던 사이트는 종전처럼 전체 회원에게 2단계 인증을 요구해야 합니다.'
        );
        $this->assertNull($response->json('data.token'));
    }

    /**
     * 범위가 전체일 때 일반 회원에게도 2단계 인증을 요구하는지 확인합니다.
     *
     * @scenario two_factor=on, scope=all, subject=member
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function scope_all_covers_ordinary_members(): void
    {
        $this->setTwoFactor(true, 'all');

        $response = $this->login($this->member);

        $response->assertStatus(200);
        $this->assertTrue((bool) $response->json('data.two_factor_required'));
        $this->assertNull($response->json('data.token'));
    }

    /**
     * 범위가 관리자만일 때 일반 회원은 종전대로 바로 로그인되는지 확인합니다.
     *
     * @scenario two_factor=on, scope=admin, subject=member
     *
     * @effects member_login_bypasses_two_factor
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function scope_admin_lets_ordinary_members_sign_in_directly(): void
    {
        $this->setTwoFactor(true, 'admin');

        $response = $this->login($this->member);

        $response->assertStatus(200);
        $this->assertNotTrue(
            $response->json('data.two_factor_required'),
            '관리자만 범위인데 일반 회원에게도 2단계 인증을 요구했습니다.'
        );
        $this->assertNotEmpty(
            $response->json('data.token'),
            '범위 밖 회원은 종전대로 바로 로그인되어야 합니다.'
        );
    }

    /**
     * 범위가 관리자만일 때 관리자에게는 여전히 2단계 인증을 요구하는지 확인합니다.
     *
     * @scenario two_factor=on, scope=admin, subject=admin
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function scope_admin_still_covers_administrators(): void
    {
        $this->setTwoFactor(true, 'admin');

        $response = $this->login($this->admin);

        $response->assertStatus(200);
        $this->assertTrue(
            (bool) $response->json('data.two_factor_required'),
            '관리자만 범위인데 관리자가 통과했습니다 — 이 설정의 목적이 무산됩니다.'
        );
        $this->assertNull($response->json('data.token'));
    }

    /**
     * 마스터 토글이 꺼져 있으면 범위 설정과 무관하게 2단계 인증을 요구하지 않는지 확인합니다.
     *
     * @scenario two_factor=off, scope=admin, subject=admin
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_master_toggle_still_wins_over_the_scope(): void
    {
        $this->setTwoFactor(false, 'admin');

        $response = $this->login($this->admin);

        $response->assertStatus(200);
        $this->assertNotEmpty(
            $response->json('data.token'),
            '마스터 토글이 꺼져 있으면 범위와 무관하게 2단계 인증을 요구하지 않아야 합니다.'
        );
    }
}
