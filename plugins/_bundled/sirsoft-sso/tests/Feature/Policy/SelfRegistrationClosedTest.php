<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Policy;

use App\Services\AuthService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 사이트 전체 강제는 셀프 가입도 닫는다(W37) — 회사가 만드는 계정(서비스 직접 가입)은 계속 된다.
 *
 * @since 1.0.0
 */
class SelfRegistrationClosedTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 사이트 전체 강제에 따라 셀프 가입 열림 판정이 바뀌는지 확인한다.
     *
     * @scenario enforce_site=false|true
     *
     * @effects open_then_closed, public_register_403
     *
     * @return void 반환값 없음
     */
    public function test_site_enforcement_closes_self_registration(): void
    {
        $auth = app(AuthService::class);
        $this->assertTrue($auth->isSelfRegistrationOpen());

        $this->makeIdp('corp');
        $this->setPolicy(['enforce_site' => true]);

        $this->assertFalse(app(AuthService::class)->isSelfRegistrationOpen());
        $this->postJson('/api/auth/register', [
            'name' => '가입시도',
            'email' => 'self798@example.test',
            'password' => 'Passw0rd!798',
            'password_confirmation' => 'Passw0rd!798',
            'agree_terms' => true,
            'agree_privacy' => true,
        ])->assertStatus(403);
    }
}
