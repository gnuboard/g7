<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\AppKeyRotation;

use App\Support\SensitiveSettingMask;
use Illuminate\Encryption\Encrypter;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Repositories\Contracts\ProvisioningAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 앱 키가 바뀌어 저장된 암호문을 풀 수 없는 상태에서의 IdP 편집과 저장, 연결의 갱신 토큰 저장.
 *
 * 앱 키가 바뀐 상태는 다른 키로 암호화한 값을 DB 에 직접 넣어 만든다.
 *
 * @since 1.0.0
 */
class UnreadableEncryptedValuesTest extends PluginTestCase
{
    use PolicyFixtures;

    /** API 접두 */
    private const BASE = '/api/plugins/sirsoft-sso/admin/idps';

    /**
     * 다른 앱 키로 암호화한 값을 만듭니다 (encrypted 캐스트와 같은 직렬화 없는 형식).
     *
     * @param  string  $plain  평문
     * @return string 지금 앱 키로는 풀 수 없는 암호문
     */
    private function foreignCiphertext(string $plain): string
    {
        $cipher = (string) config('app.cipher');

        return (new Encrypter(Encrypter::generateKey($cipher), $cipher))->encryptString($plain);
    }

    /**
     * IdP 비밀값에 풀 수 없는 암호문을 직접 넣고 그 값을 돌려줍니다.
     *
     * @param  IdentityProvider  $idp  비밀값을 바꿀 IdP
     * @return string 넣은 암호문
     */
    private function plantUnreadable(IdentityProvider $idp): string
    {
        $ciphertext = $this->foreignCiphertext((string) json_encode(['client_secret' => 'old-secret']));
        IdentityProvider::query()->whereKey($idp->id)->toBase()->update(['secrets' => $ciphertext]);

        return $ciphertext;
    }

    /**
     * 풀 수 없는 상태에서도 IdP 편집 화면이 열리고(비밀값은 미설정으로 보인다), 비밀값을 다시 입력해 저장하면 옛 값을 덮어 쓴다.
     *
     * @scenario case=reentered_secret
     *
     * @effects idp_detail_opens_with_unreadable_secrets, reentered_idp_secret_overwrites_unreadable
     *
     * @return void 반환값 없음
     */
    public function test_detail_opens_and_reentered_secret_overwrites_unreadable(): void
    {
        Sanctum::actingAs($this->makeSuper());
        $idp = $this->makeIdp('rot');
        $this->plantUnreadable($idp);

        $detail = $this->getJson(self::BASE.'/'.$idp->id)->assertOk();
        $this->assertSame([], (array) $detail->json('data.secrets'));

        $this->putJson(self::BASE.'/'.$idp->id, ['secrets' => ['client_secret' => 'rotated-secret']])
            ->assertOk()
            ->assertJsonPath('data.secrets.client_secret', SensitiveSettingMask::MASK);
        $this->assertSame('rotated-secret', IdentityProvider::query()->find($idp->id)->secret('client_secret'));
    }

    /**
     * 풀 수 없는 상태에서 다른 칸만 저장하면 꺼진 IdP 는 저장되고 옛 암호문을 그대로 둔다(마스크·빈 값만 보내도 같다).
     * 켜진 IdP 는 활성화 조건을 볼 비밀값이 없으므로 500 이 아니라 비밀값 칸을 짚는 422 로 다시 입력을 요구한다.
     *
     * @scenario case=other_field_mask_or_empty
     *
     * @effects disabled_idp_other_field_saved_keeps_unreadable, enabled_idp_requires_secret_reentry_422
     *
     * @return void 반환값 없음
     */
    public function test_other_fields_while_secrets_are_unreadable(): void
    {
        Sanctum::actingAs($this->makeSuper());
        $off = $this->makeIdp('rot_off', ['enabled' => false, 'owned_domains' => ['off798.test']]);
        $planted = $this->plantUnreadable($off);

        $this->putJson(self::BASE.'/'.$off->id, ['sort_order' => 7])->assertOk()->assertJsonPath('data.sort_order', 7);
        $this->putJson(self::BASE.'/'.$off->id, ['secrets' => ['client_secret' => SensitiveSettingMask::MASK], 'sort_order' => 8])->assertOk();
        $this->putJson(self::BASE.'/'.$off->id, ['secrets' => ['client_secret' => ''], 'sort_order' => 9])->assertOk();
        $this->assertSame($planted, IdentityProvider::query()->whereKey($off->id)->toBase()->value('secrets'));

        $on = $this->makeIdp('rot_on');
        $this->plantUnreadable($on);

        $refused = $this->putJson(self::BASE.'/'.$on->id, ['sort_order' => 3])->assertStatus(422);
        $this->assertSame('config_invalid', $refused->json('errors.code'));
        $this->assertArrayHasKey('client_secret', (array) $refused->json('errors.fields'));
    }

    /**
     * 앱 키가 바뀐 뒤 연결의 갱신 토큰을 새 값으로 저장하면 풀 수 없는 옛 값을 덮어 쓴다(로그인 때 속성 동기화가 쓰는 경로).
     *
     * @scenario case=account_refresh_token
     *
     * @effects account_refresh_token_overwrites_unreadable
     *
     * @return void 반환값 없음
     */
    public function test_account_refresh_token_overwrites_unreadable_value(): void
    {
        $idp = $this->makeIdp('rot_acc');
        $account = $this->linkAccount($this->makeMember(), $idp);
        SsoAccount::query()->whereKey($account->id)->toBase()->update(['refresh_token' => $this->foreignCiphertext('old-refresh')]);

        $loaded = SsoAccount::query()->findOrFail($account->id);
        app(ProvisioningAccountRepositoryInterface::class)->update($loaded, ['refresh_token' => 'new-refresh']);

        $this->assertSame('new-refresh', SsoAccount::query()->findOrFail($account->id)->refresh_token);
    }
}
