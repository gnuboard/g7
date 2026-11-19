<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Policy;

use Illuminate\Support\Facades\Mail;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;
use Tests\Support\Concerns\CapturesSentMail;

/**
 * 비상 계정은 강제가 켜진 동안 2단계 인증 필수(W29, 함정 38) — 사이트 2단계 설정이 꺼져 있어도 2단계 단계를 탄다.
 *
 * @since 1.0.0
 */
class EmergencyTwoFactorRequiredTest extends PluginTestCase
{
    use CapturesSentMail;
    use PolicyFixtures;

    /**
     * 사이트 전체 강제 중 비상 계정의 비밀번호 로그인은 토큰 대신 2단계 인증 단계로 가는지 확인한다.
     *
     * @scenario enforce_site=true, emergency=yes, site_two_factor=off, guard=admin
     *
     * @effects two_factor_required, no_token
     *
     * @return void 반환값 없음
     */
    public function test_emergency_login_requires_two_factor(): void
    {
        Mail::fake();
        $this->prepareIdentityNotifications();
        $this->makeIdp('corp');
        $emergency = $this->makeSuper();
        $this->setPolicy(['enforce_site' => true, 'emergency_user_ids' => [$emergency->id]]);

        $response = $this->postJson('/api/auth/admin/login', ['email' => $emergency->email, 'password' => $this->password]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('data.two_factor_required'));
        $this->assertNull($response->json('data.token'));
    }

    /**
     * 강제가 꺼져 있으면 같은 비상 계정이 사이트 설정대로(2단계 꺼짐) 바로 로그인하는지 확인한다.
     *
     * @scenario enforce=off, emergency=yes, site_two_factor=off
     *
     * @effects token_issued
     *
     * @return void 반환값 없음
     */
    public function test_without_enforcement_site_setting_applies(): void
    {
        $emergency = $this->makeSuper();
        $this->setPolicy(['emergency_user_ids' => [$emergency->id]]);

        $response = $this->postJson('/api/auth/admin/login', ['email' => $emergency->email, 'password' => $this->password]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.token'));
    }
}
