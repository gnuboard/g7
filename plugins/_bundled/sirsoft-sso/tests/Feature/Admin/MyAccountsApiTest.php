<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Admin;

use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 마이페이지 「연결된 회사 계정」 API — 목록, 연결 시작(시작 티켓), 해제(마지막 수단, 강제 대상 차단), 남의 연결 404.
 *
 * @since 1.0.0
 */
class MyAccountsApiTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 카드 데이터와 연결 시작 주소를 확인한다.
     *
     * @scenario linked=one, linkable=one
     *
     * @effects accounts_listed, linkable_excludes_linked, start_url_with_ticket
     *
     * @return void 반환값 없음
     */
    public function test_overview_and_start(): void
    {
        $corp = $this->makeIdp('corp');
        $this->makeIdp('partner', ['owned_domains' => ['partner798.test']]);
        $member = $this->makeMember();
        $this->linkAccount($member, $corp);
        Sanctum::actingAs($member);

        $card = $this->getJson('/api/plugins/sirsoft-sso/me/accounts')->assertOk()->json('data');
        $this->assertSame('corp', $card['accounts'][0]['idp_key']);
        $this->assertNull($card['accounts'][0]['unlink_block']);
        $this->assertSame(['partner'], array_column($card['linkable'], 'key'));

        $start = $this->postJson('/api/plugins/sirsoft-sso/me/links/start', ['idp' => 'partner'])->assertOk()->json('data.start_url');
        $this->assertStringStartsWith('/plugins/sirsoft-sso/login/partner?ticket=', $start);
        $this->assertSame('already_linked', $this->postJson('/api/plugins/sirsoft-sso/me/links/start', ['idp' => 'corp'])->assertStatus(409)->json('errors.code'));
    }

    /**
     * 해제 차단(마지막 수단, 강제 대상)과 남의 연결 404 를 확인한다.
     *
     * @scenario unlink=last_method|enforced|other_member|allowed
     *
     * @effects last_method_422, unlink_enforced_422, not_found_404, removed
     *
     * @return void 반환값 없음
     */
    public function test_unlink_rules(): void
    {
        $idp = $this->makeIdp('corp');
        $passwordless = $this->makeMember(['password' => null]);
        $only = $this->linkAccount($passwordless, $idp);
        Sanctum::actingAs($passwordless);
        $this->assertSame('last_method', $this->deleteJson('/api/plugins/sirsoft-sso/me/accounts/'.$only->id)->assertStatus(422)->json('errors.code'));

        $member = $this->makeMember();
        $mine = $this->linkAccount($member, $idp);
        Sanctum::actingAs($member);
        $this->deleteJson('/api/plugins/sirsoft-sso/me/accounts/'.$only->id)->assertStatus(404);

        $this->setPolicy(['enforce_linked' => true]);
        $this->assertSame('unlink_enforced', $this->deleteJson('/api/plugins/sirsoft-sso/me/accounts/'.$mine->id)->assertStatus(422)->json('errors.code'));

        $this->setPolicy(['enforce_linked' => false]);
        $this->deleteJson('/api/plugins/sirsoft-sso/me/accounts/'.$mine->id)->assertOk();
        $this->assertNull(SsoAccount::query()->find($mine->id));
    }
}
