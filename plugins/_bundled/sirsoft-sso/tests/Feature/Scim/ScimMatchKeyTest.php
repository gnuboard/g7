<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Scim;

use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Services\Provisioning\ScimSchema;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * SCIM 대조 키(W31) — `scim_match=externalId` 면 연결 subject 는 externalId 이고, userName 은 따로 보관해 필터로 찾는다.
 *
 * @since 1.0.0
 */
class ScimMatchKeyTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * externalId 대조 — subject = externalId, 로그인 subject 로 그 연결을 찾는다. userName, externalId 필터 모두 동작.
     *
     * @scenario scim_match=externalId
     *
     * @effects subject_is_external_id, filters_work
     *
     * @return void 반환값 없음
     */
    public function test_external_id_match(): void
    {
        $idp = $this->makeIdp(['deprovision' => ['scim' => true, 'scim_match' => 'externalId']]);
        $token = $this->issueScimToken($idp);

        $created = $this->scim('POST', $idp, '/Users', $token, [
            'schemas' => [ScimSchema::USER],
            'userName' => 'kim@corp798.test',
            'externalId' => '00000000-aaaa-bbbb-cccc-000000000001',
            'name' => ['formatted' => '김객체'],
        ])->assertStatus(201)
            ->assertJsonPath('userName', 'kim@corp798.test')
            ->assertJsonPath('externalId', '00000000-aaaa-bbbb-cccc-000000000001');

        $account = SsoAccount::query()->findOrFail((int) $created->json('id'));
        $this->assertSame('00000000-aaaa-bbbb-cccc-000000000001', $account->subject);

        $this->scim('GET', $idp, '/Users?filter='.rawurlencode('userName eq "kim@corp798.test"'), $token)->assertJsonPath('totalResults', 1);
        $this->scim('GET', $idp, '/Users?filter='.rawurlencode('externalId eq "00000000-aaaa-bbbb-cccc-000000000001"'), $token)->assertJsonPath('totalResults', 1);
    }

    /**
     * 대조 키 값이 없으면 400 invalidValue.
     *
     * @scenario scim_match=externalId, externalId=missing
     *
     * @effects scim_400
     *
     * @return void 반환값 없음
     */
    public function test_missing_match_key_is_400(): void
    {
        $idp = $this->makeIdp(['deprovision' => ['scim' => true, 'scim_match' => 'externalId']]);
        $token = $this->issueScimToken($idp);

        $this->scim('POST', $idp, '/Users', $token, ['schemas' => [ScimSchema::USER], 'userName' => 'no-ext'])
            ->assertStatus(400)
            ->assertJsonPath('scimType', 'invalidValue');
    }

    /**
     * 기본(userName 대조)에서는 subject = userName.
     *
     * @scenario scim_match=default
     *
     * @effects subject_is_user_name
     *
     * @return void 반환값 없음
     */
    public function test_default_user_name_match(): void
    {
        $idp = $this->makeIdp();
        $token = $this->issueScimToken($idp);

        $id = $this->scim('POST', $idp, '/Users', $token, ['schemas' => [ScimSchema::USER], 'userName' => 'scim-un', 'externalId' => 'X-1'])->assertStatus(201)->json('id');

        $account = SsoAccount::query()->findOrFail((int) $id);
        $this->assertSame('scim-un', $account->subject);
        $this->assertSame('X-1', $account->scim_external_id);
    }
}
