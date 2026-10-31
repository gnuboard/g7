<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Flow;

use App\Models\User;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 관리자, 최고관리자 계정은 소유 도메인 자동 연결과 연결 확인으로 붙이지 않는다(W26 — 권한 상승 방지).
 *
 * @since 1.0.0
 */
class AdminTargetLinkRestrictionTest extends PluginTestCase
{
    /**
     * 최고관리자 계정은 소유 도메인 자동 연결 대상이 아닌지 확인한다.
     *
     * @scenario target=super_admin, domain_auto=on
     *
     * @effects admin_target_manual_only
     *
     * @return void 반환값 없음
     */
    public function test_super_admin_is_not_auto_linked(): void
    {
        $idp = $this->mockOidcIdp(['link_methods' => ['domain_auto' => true, 'verify' => true, 'admin' => true]]);
        $super = User::factory()->create(['email' => $this->uniqueEmail('corp798.test'), 'is_super' => true]);

        $response = $this->roundTrip($idp, 'full', ['sub' => 'adm-1', 'email' => $super->email]);

        $this->assertSame('admin_target_manual_only', $this->redirectQuery($response)['sso_error']);
        $this->assertDatabaseMissing('sso_accounts', ['subject' => 'adm-1']);
    }

    /**
     * 최고관리자 계정에는 연결 확인 화면을 내주지 않는지 확인한다.
     *
     * @scenario target=super_admin, domain_auto=off, verify=on
     *
     * @effects admin_target_manual_only
     *
     * @return void 반환값 없음
     */
    public function test_super_admin_is_not_offered_link_verification(): void
    {
        $idp = $this->mockOidcIdp();
        $super = User::factory()->create(['email' => $this->uniqueEmail('corp798.test'), 'is_super' => true]);

        $response = $this->roundTrip($idp, 'full', ['sub' => 'adm-2', 'email' => $super->email]);

        $query = $this->redirectQuery($response);
        $this->assertArrayNotHasKey('sso_link', $query);
        $this->assertSame('admin_target_manual_only', $query['sso_error']);
    }
}
