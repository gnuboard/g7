<?php

namespace Plugins\Sirsoft\Totp\Tests\Feature;

use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Totp\Listeners\UserTotpEnrollmentInfoListener;
use Plugins\Sirsoft\Totp\Models\TotpCredential;
use Plugins\Sirsoft\Totp\Plugin;
use Plugins\Sirsoft\Totp\Tests\PluginTestCase;

/**
 * 회원 상세 「인증 앱」 카드의 등록 여부 — 관리자 회원 상세 응답의 `totp_enrollment`.
 *
 * 카드가 등록 여부를 보이지 않아 미등록 회원에게도 [강제 해제] 가 보였다(7.1.0 관측 R-26·B O-B5).
 * 결정(2026-10-07): 등록 여부(등록됨/미등록) 표시 + 미등록이면 [강제 해제] 숨김. 등록 여부는 해제 권한
 * (`sirsoft-totp.credentials.delete`)을 가진 관리자의 회원 상세 응답에만 싣는다 — 회원 정보를 볼 수 있다는 것과
 * 남의 2단계 인증 상태를 아는 것은 다른 신뢰 수준이다(해제 권한과 같은 경계).
 *
 * @since 1.0.0
 */
class TotpAdminEnrollmentStatusTest extends PluginTestCase
{
    /**
     * 실제 훅 체인으로 리스너를 붙입니다(플러그인 테스트 부팅에는 자동 등록 경로가 없다).
     *
     * @return void 반환값 없음
     */
    private function subscribeListener(): void
    {
        $listener = app(UserTotpEnrollmentInfoListener::class);

        foreach (UserTotpEnrollmentInfoListener::getSubscribedHooks() as $hook => $meta) {
            HookManager::addFilter(
                $hook,
                fn (...$args) => $listener->{$meta['method']}(...$args),
                $meta['priority'] ?? 10,
            );
        }
    }

    /**
     * 회원 상세를 볼 수 있는 관리자를 만든다(해제 권한 포함 여부 선택).
     *
     * @param  bool  $withResetPermission  인증 앱 해제 권한 포함 여부
     * @return User 관리자
     */
    private function adminViewer(bool $withResetPermission): User
    {
        $user = $withResetPermission ? $this->createCredentialOperatorUser() : $this->createAdminUser();
        $role = Role::firstOrCreate(
            ['identifier' => 'qa_totp_user_viewer'],
            ['name' => ['ko' => '회원 열람', 'en' => 'User viewer']]
        );
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.users.read'],
            ['name' => ['ko' => '회원 조회', 'en' => 'Read users'], 'type' => PermissionType::Admin]
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * 확인까지 마친 등록이 있으면 「등록됨」과 등록 시각을 싣는다.
     *
     * @scenario viewer=reset_permitted_admin, enrollment=confirmed
     *
     * @effects member_detail_reports_enrollment_to_reset_permitted_admin
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_confirmed_enrollment_is_reported_as_enrolled(): void
    {
        $this->subscribeListener();
        $target = User::factory()->create();
        TotpCredential::create(['user_id' => $target->id, 'secret' => 'JBSWY3DPEHPK3PXP', 'confirmed_at' => now()]);

        $this->actingAs($this->adminViewer(true))
            ->getJson('/api/admin/users/'.$target->uuid)
            ->assertOk()
            ->assertJsonPath('data.totp_enrollment.enrolled', true)
            ->assertJsonPath('data.totp_enrollment.enrolled_at', fn ($v) => is_string($v) && $v !== '');
    }

    /**
     * 등록이 없거나 발급만 하고 확인하지 않았으면 「미등록」이다(로그인에 쓰이지 않는 발급분).
     *
     * @scenario viewer=reset_permitted_admin, enrollment=none
     *
     * @effects member_detail_reports_enrollment_to_reset_permitted_admin
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function no_or_unconfirmed_enrollment_is_reported_as_not_enrolled(): void
    {
        $this->subscribeListener();
        $viewer = $this->adminViewer(true);

        $none = User::factory()->create();
        $this->actingAs($viewer)
            ->getJson('/api/admin/users/'.$none->uuid)
            ->assertOk()
            ->assertJsonPath('data.totp_enrollment.enrolled', false)
            ->assertJsonPath('data.totp_enrollment.enrolled_at', null);

        $pending = User::factory()->create();
        TotpCredential::create(['user_id' => $pending->id, 'secret' => 'JBSWY3DPEHPK3PXP', 'confirmed_at' => null]);
        $this->actingAs($viewer)
            ->getJson('/api/admin/users/'.$pending->uuid)
            ->assertOk()
            ->assertJsonPath('data.totp_enrollment.enrolled', false);
    }

    /**
     * 해제 권한이 없는 관리자에게는 등록 여부를 싣지 않는다.
     *
     * @scenario viewer=admin_without_reset_permission, enrollment=confirmed
     *
     * @effects enrollment_hidden_without_reset_permission
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function an_admin_without_the_reset_permission_does_not_see_the_status(): void
    {
        $this->subscribeListener();
        $target = User::factory()->create();
        TotpCredential::create(['user_id' => $target->id, 'secret' => 'JBSWY3DPEHPK3PXP', 'confirmed_at' => now()]);

        $this->actingAs($this->adminViewer(false))
            ->getJson('/api/admin/users/'.$target->uuid)
            ->assertOk()
            ->assertJsonMissingPath('data.totp_enrollment');
    }

    /**
     * 본인 프로필(`/api/me`)에는 싣지 않는다 — 관리자 응답 전용.
     *
     * @scenario viewer=member_self, enrollment=confirmed
     *
     * @effects enrollment_absent_from_profile_response
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_profile_response_does_not_carry_the_status(): void
    {
        $this->subscribeListener();
        $member = User::factory()->create();
        TotpCredential::create(['user_id' => $member->id, 'secret' => 'JBSWY3DPEHPK3PXP', 'confirmed_at' => now()]);

        $this->actingAs($member)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonMissingPath('data.totp_enrollment');
    }

    /**
     * 플러그인이 리스너를 등록한다(빠지면 운영에서 카드가 늘 「확인 불가」로 남는다).
     *
     * @effects enrollment_status_listener_is_registered_by_the_plugin
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_plugin_registers_the_status_listener(): void
    {
        $this->assertContains(UserTotpEnrollmentInfoListener::class, (new Plugin)->getHookListeners());
    }
}
