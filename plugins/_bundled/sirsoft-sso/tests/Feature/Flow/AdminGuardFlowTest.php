<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Flow;

use App\Models\User;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 관리자 경로(guard=admin) — 인계는 관리자 로그인 화면으로 가고, 관리자 경로 코드로만 교환된다(코어 K1).
 *
 * @since 1.0.0
 */
class AdminGuardFlowTest extends PluginTestCase
{
    /**
     * 관리자 경로 왕복은 관리자 로그인 화면으로 인계하고 관리자 경로 코드로 교환되는지 확인한다.
     *
     * @scenario guard=admin
     *
     * @effects admin_login_screen, admin_guard_code
     *
     * @return void 반환값 없음
     */
    public function test_admin_guard_hands_off_to_admin_login(): void
    {
        $idp = $this->mockOidcIdp();
        $admin = User::factory()->create(['is_super' => true]);
        $this->link($admin, $idp, 'ag-1');

        $response = $this->roundTrip($idp, 'full', ['sub' => 'ag-1'], 'guard=admin&redirect=%2Fadmin%2Fusers');

        $this->assertSame('/admin/login', $this->redirectPath($response));
        $this->assertSame('/admin/users', $this->redirectQuery($response)['redirect']);
        $this->assertSame($admin->id, $this->redeemHandoff($response, 'admin')['user']->id);
    }

    /**
     * 관리자 경로로 발급한 인계 코드는 사용자 경로에서 교환되지 않는지 확인한다.
     *
     * @scenario guard=admin, code_used_on=user
     *
     * @effects redeem_refused
     *
     * @return void 반환값 없음
     */
    public function test_admin_code_does_not_work_on_user_path(): void
    {
        $idp = $this->mockOidcIdp();
        $admin = User::factory()->create(['is_super' => true]);
        $this->link($admin, $idp, 'ag-2');

        $response = $this->roundTrip($idp, 'full', ['sub' => 'ag-2'], 'guard=admin');

        $this->assertNull($this->redeemHandoff($response, 'user'));
    }

    /**
     * 관리자 경로의 동의 대기가 관리자 로그인 화면으로 돌아가는지 확인한다.
     *
     * @scenario guard=admin, pending=consent
     *
     * @effects pending_on_admin_login_screen
     *
     * @return void 반환값 없음
     */
    public function test_pending_consent_returns_to_admin_login(): void
    {
        $idp = $this->mockOidcIdp();

        $response = $this->roundTrip($idp, 'full', ['sub' => 'ag-3', 'email' => $this->uniqueEmail('corp798.test')], 'guard=admin');

        $this->assertSame('/admin/login', $this->redirectPath($response));
        $token = $this->redirectQuery($response)['sso_pending'];
        $this->withBrowserBind()->getJson('/api/plugins/sirsoft-sso/pending/'.$token)->assertOk()->assertJsonPath('data.guard', 'admin');
    }
}
