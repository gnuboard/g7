<?php

namespace Plugins\Sirsoft\Totp\Tests\Feature\Notifications;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Plugins\Sirsoft\Totp\Listeners\TotpNotificationDataListener;
use Plugins\Sirsoft\Totp\Tests\PluginTestCase;

/**
 * 2단계 인증 알림의 보안 설정 주소 (1.1.1, #952 W9)
 *
 * 받는 사람은 대상 회원 자신이다 — 일반 회원에게 관리자 화면 주소를 보내면 열 수 없다.
 */
class SecurityUrlTest extends PluginTestCase
{
    /**
     * 알림 데이터를 뽑습니다.
     *
     * @param  User  $user  받는 회원
     * @return array 추출 결과
     */
    private function extract(User $user): array
    {
        return app(TotpNotificationDataListener::class)->extract(
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            'totp_enrolled',
            [$user, ['ip_address' => '127.0.0.1']],
        );
    }

    /**
     * 일반 회원은 회원 화면의 내 정보(보안 카드) 주소를 받는다.
     *
     * @scenario case=totp_enrolled_member
     *
     * @effects totp_security_url_matches_recipient
     *
     * @return void 반환값 없음
     */
    public function test_member_receives_member_profile_url(): void
    {
        $member = User::factory()->create();

        $url = $this->extract($member)['data']['security_url'];

        $this->assertStringContainsString('/mypage/profile', $url);
        $this->assertStringNotContainsString('/admin/', $url);
    }

    /**
     * 관리자는 플러그인 설정 화면 주소를 받는다.
     *
     * @scenario case=totp_enrolled_admin
     *
     * @effects totp_security_url_matches_recipient
     *
     * @return void 반환값 없음
     */
    public function test_admin_receives_plugin_settings_url(): void
    {
        $admin = User::factory()->create();
        $role = Role::create(['identifier' => 'i952_totp_admin_'.uniqid(), 'name' => ['ko' => '관리자', 'en' => 'Admin'], 'is_active' => true]);
        $permission = Permission::firstOrCreate(
            ['identifier' => 'admin.access'],
            ['name' => ['ko' => '관리자 접근', 'en' => 'Admin Access'], 'type' => PermissionType::Admin],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $admin->roles()->attach($role->id);

        $url = $this->extract($admin->fresh())['data']['security_url'];

        $this->assertStringContainsString('/admin/plugins/sirsoft-totp/settings', $url);
    }
}
