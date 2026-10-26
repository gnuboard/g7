<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Support\SensitiveSettingMask;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 관리자 IdP API — CRUD, 비밀값 가림과 유지, 활성화 조건(W9), 연결 테스트, SP 키, SCIM 토큰, 권한 4단계.
 *
 * @since 1.0.0
 */
class AdminIdpApiTest extends PluginTestCase
{
    use PolicyFixtures;

    /** API 접두 */
    private const BASE = '/api/plugins/sirsoft-sso/admin/idps';

    /**
     * IdP 를 추가, 조회, 수정, 삭제하고 비밀값을 가리는지 확인한다.
     *
     * @scenario action=store|show|update|destroy, secrets=new|masked|null
     *
     * @effects secrets_masked, masked_secret_kept, activity_log_idp
     *
     * @return void 반환값 없음
     */
    public function test_crud_and_secret_masking(): void
    {
        Sanctum::actingAs($this->makeSuper());

        $created = $this->postJson(self::BASE, [
            'key' => 'hq',
            'protocol' => 'oidc',
            'label' => ['ko' => '본사', 'en' => 'HQ'],
            'owned_domains' => ['Corp798.TEST'],
            'config' => ['discovery_url' => 'https://idp.corp798.test/.well-known/openid-configuration', 'client_id' => 'g7'],
            'secrets' => ['client_secret' => 'top-secret'],
        ])->assertStatus(201)->json('data');

        $this->assertSame(['corp798.test'], $created['owned_domains']);
        $this->assertFalse($created['enabled']);
        $this->assertSame(SensitiveSettingMask::MASK, $created['secrets']['client_secret']);
        $this->assertStringNotContainsString('top-secret', json_encode($created));
        $this->assertNotEmpty($created['schema']);
        $this->assertNotEmpty($created['schema'][0]['label']);

        $id = $created['id'];
        $this->putJson(self::BASE.'/'.$id, ['secrets' => ['client_secret' => SensitiveSettingMask::MASK], 'enabled' => true])->assertOk();
        $this->assertSame('top-secret', IdentityProvider::query()->find($id)->secret('client_secret'));
        $this->assertTrue(IdentityProvider::query()->find($id)->enabled);

        $this->putJson(self::BASE.'/'.$id, ['secrets' => ['client_secret' => 'rotated']])->assertOk();
        $this->assertSame('rotated', IdentityProvider::query()->find($id)->secret('client_secret'));

        $list = $this->getJson(self::BASE)->assertOk()->json('data');
        $this->assertSame('hq', $list['idps'][0]['key']);
        $this->assertContains('oidc', array_column($list['protocols'], 'key'));

        $this->deleteJson(self::BASE.'/'.$id)->assertOk();
        $this->assertNull(IdentityProvider::query()->find($id));

        foreach (['sso.idp_created', 'sso.idp_updated', 'sso.idp_deleted'] as $action) {
            $this->assertGreaterThan(0, ActivityLog::query()->where('action', $action)->count(), $action);
        }
    }

    /**
     * 설정이 비어 있으면 활성화가 422 로 거절되고 칸별 오류를 주는지 확인한다.
     *
     * @scenario enabled=true, config=invalid
     *
     * @effects config_invalid_422_with_fields
     *
     * @return void 반환값 없음
     */
    public function test_enable_requires_valid_config(): void
    {
        Sanctum::actingAs($this->makeSuper());

        $response = $this->postJson(self::BASE, ['key' => 'bad', 'protocol' => 'oidc', 'label' => ['ko' => '불량', 'en' => 'Bad'], 'enabled' => true]);

        $response->assertStatus(422);
        $this->assertSame('config_invalid', $response->json('errors.code'));
        $this->assertArrayHasKey('discovery_url', $response->json('errors.fields'));
        $this->assertNull(IdentityProvider::query()->where('key', 'bad')->first());
    }

    /**
     * 키 예약, 중복, 연결 회원이 있는 IdP 삭제를 거절하는지 확인한다.
     *
     * @scenario key=mock_prefix|duplicate, destroy=with_accounts
     *
     * @effects key_reserved_422, key_taken_422, has_accounts_409
     *
     * @return void 반환값 없음
     */
    public function test_key_rules_and_delete_guard(): void
    {
        Sanctum::actingAs($this->makeSuper());
        $idp = $this->makeIdp('corp');
        $this->linkAccount($this->makeMember(), $idp);

        $this->assertSame('idp_key_reserved', $this->postJson(self::BASE, ['key' => 'mock_x', 'protocol' => 'oidc', 'label' => ['ko' => 'x', 'en' => 'x']])->assertStatus(422)->json('errors.code'));
        $this->assertSame('idp_key_taken', $this->postJson(self::BASE, ['key' => 'corp', 'protocol' => 'oidc', 'label' => ['ko' => 'x', 'en' => 'x']])->assertStatus(422)->json('errors.code'));
        $this->assertSame('idp_has_accounts', $this->deleteJson(self::BASE.'/'.$idp->id)->assertStatus(409)->json('errors.code'));
    }

    /**
     * 한 도메인은 IdP 하나만 소유한다(§7 G-3) — 다른 IdP 가 소유한 도메인은 추가·수정 모두 422 로 거절하고, 자기 도메인을 다시 저장하거나
     * 대소문자만 다른 겹침도 같은 도메인으로 본다.
     *
     * @scenario action=create|update, domain=owned_by_other|own|case_variant
     *
     * @effects domain_owned_by_other_idp_rejected, own_domain_resaved
     *
     * @return void 반환값 없음
     */
    public function test_domain_owned_by_one_idp_only(): void
    {
        Sanctum::actingAs($this->makeSuper());
        $corp = $this->makeIdp('corp', ['owned_domains' => ['corp798.test']]);
        $other = $this->makeIdp('other', ['owned_domains' => ['other798.test']]);

        $created = $this->postJson(self::BASE, ['key' => 'dup', 'protocol' => 'oidc', 'label' => ['ko' => 'x', 'en' => 'x'], 'owned_domains' => ['new798.test', 'Corp798.TEST']])->assertStatus(422);
        $this->assertSame('domain_owned_by_other_idp', $created->json('errors.code'));
        $this->assertSame(['corp798.test'], $created->json('errors.domains'));

        $updated = $this->putJson(self::BASE.'/'.$other->id, ['owned_domains' => ['other798.test', 'corp798.test']])->assertStatus(422);
        $this->assertSame('domain_owned_by_other_idp', $updated->json('errors.code'));
        $this->assertSame(['other798.test'], $other->fresh()->owned_domains);

        $this->putJson(self::BASE.'/'.$corp->id, ['owned_domains' => ['corp798.test', 'corp798-2.test']])->assertOk();
        $this->assertSame(['corp798.test', 'corp798-2.test'], $corp->fresh()->owned_domains);
    }

    /**
     * SCIM 토큰을 한 번만 보이고 해시로 저장하며, 다시 발급하면 해시가 바뀌는지 확인한다.
     *
     * @scenario action=scim_token, times=2
     *
     * @effects token_shown_once, hash_stored, previous_invalidated
     *
     * @return void 반환값 없음
     */
    public function test_scim_token_issue(): void
    {
        Sanctum::actingAs($this->makeSuper());
        $idp = $this->makeIdp('corp');

        $first = $this->postJson(self::BASE.'/'.$idp->id.'/scim-token')->assertOk()->json('data');
        $this->assertSame(64, strlen($first['token']));
        $this->assertSame(hash('sha256', $first['token']), $idp->fresh()->scim_token_hash);

        $second = $this->postJson(self::BASE.'/'.$idp->id.'/scim-token')->assertOk()->json('data');
        $this->assertNotSame($first['token'], $second['token']);
        $this->assertSame(hash('sha256', $second['token']), $idp->fresh()->scim_token_hash);

        $show = $this->getJson(self::BASE.'/'.$idp->id)->assertOk()->json('data');
        $this->assertTrue($show['scim']['has_token']);
        $this->assertStringNotContainsString($second['token'], json_encode($show));
    }

    /**
     * 브라우저 로그인 테스트가 1회용 시작 주소를 주고, SP 키는 SAML 만 받는지 확인한다.
     *
     * @scenario action=test(browser=true)|sp_keys(protocol=oidc)
     *
     * @effects start_url_with_ticket, sp_keys_saml_only_422
     *
     * @return void 반환값 없음
     */
    public function test_browser_test_and_sp_keys_guard(): void
    {
        Sanctum::actingAs($this->makeSuper());
        $idp = $this->makeIdp('corp');

        $start = $this->postJson(self::BASE.'/'.$idp->id.'/test', ['browser' => true])->assertOk()->json('data.start_url');
        $this->assertStringStartsWith('/plugins/sirsoft-sso/login/corp?ticket=', $start);

        $this->assertSame('sp_keys_saml_only', $this->postJson(self::BASE.'/'.$idp->id.'/sp-keys')->assertStatus(422)->json('errors.code'));
    }

    /**
     * 권한 4단계 — 권한 없음 403, 조회만 가능, 변경 가능, 비관리자 차단.
     *
     * @scenario actor=member|no_permission|view|update
     *
     * @effects permission_matrix
     *
     * @return void 반환값 없음
     */
    public function test_permission_levels(): void
    {
        $idp = $this->makeIdp('corp');

        Sanctum::actingAs($this->makeMember());
        $this->getJson(self::BASE)->assertStatus(403);

        Sanctum::actingAs($this->makeAdmin([]));
        $this->getJson(self::BASE)->assertStatus(403);

        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.settings.view']));
        $this->getJson(self::BASE)->assertOk()->assertJsonPath('data.abilities.can_update', false);
        $this->putJson(self::BASE.'/'.$idp->id, ['sort_order' => 3])->assertStatus(403);
        $this->postJson(self::BASE.'/'.$idp->id.'/scim-token')->assertStatus(403);

        Sanctum::actingAs($this->makeAdmin(['sirsoft-sso.settings.view', 'sirsoft-sso.settings.update']));
        $this->putJson(self::BASE.'/'.$idp->id, ['sort_order' => 3])->assertOk();
        $this->assertSame(3, $idp->fresh()->sort_order);
    }
}
