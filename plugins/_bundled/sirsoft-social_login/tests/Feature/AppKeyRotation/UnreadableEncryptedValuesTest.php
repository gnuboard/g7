<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\AppKeyRotation;

use App\Models\User;
use App\Support\SensitiveSettingMask;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\SocialLogin\Database\Seeders\ProviderSeeder;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Services\ProviderRegistry;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 앱 키가 바뀌어 저장된 암호문을 풀 수 없는 상태에서의 저장 — 제공자 비밀값 다시 입력, 다른 칸만 저장, 연결된 회원의 재로그인.
 *
 * 앱 키가 바뀐 상태는 다른 키로 암호화한 값을 DB 에 직접 넣어 만든다.
 *
 * @since 1.0.0
 */
class UnreadableEncryptedValuesTest extends PluginTestCase
{
    /** API 접두 */
    private const BASE = '/api/plugins/sirsoft-social_login/admin/providers';

    /** 열람·변경 권한 */
    private const BOTH = ['sirsoft-social_login.settings.view', 'sirsoft-social_login.settings.update'];

    /**
     * 내장 제공자를 시딩하고 설정 변경 권한이 있는 관리자로 로그인합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        (new ProviderSeeder)->run();
        app(ProviderRegistry::class)->flush();
    }

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
     * 제공자 행의 칸에 풀 수 없는 암호문을 직접 넣습니다.
     *
     * @param  string  $key  제공자 키
     * @param  array<int, string>  $fields  비밀값 칸
     * @return void 반환값 없음
     */
    private function plantUnreadable(string $key, array $fields): void
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $this->foreignCiphertext('old-'.$field);
        }

        SocialLoginProvider::query()->where('key', $key)->toBase()->update($values);
        app(ProviderRegistry::class)->flush();
    }

    /**
     * 화면 안내대로 비밀값을 다시 입력해 저장하면 풀 수 없는 옛 값을 덮어 저장되고 「다시 입력」 안내가 사라진다.
     * 카카오는 비밀값 두 칸 중 하나만 다시 넣으면 남은 칸 때문에 안내가 남고, 둘 다 넣으면 사라진다.
     *
     * @scenario case=reentered_secret
     *
     * @effects reentered_secret_overwrites_unreadable, unreadable_notice_cleared
     *
     * @return void 반환값 없음
     */
    public function test_reentered_secret_overwrites_unreadable_value(): void
    {
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('rot')], self::BOTH));
        $this->plantUnreadable('naver', ['client_secret']);
        $listed = collect($this->getJson(self::BASE)->assertOk()->json('data.providers'))->firstWhere('key', 'naver');
        $this->assertTrue($listed['credentials_unreadable']);

        $this->putJson(self::BASE.'/naver', ['client_secret' => 'naver-new-secret'])
            ->assertOk()
            ->assertJsonPath('data.credentials_unreadable', false)
            ->assertJsonPath('data.client_secret', SensitiveSettingMask::MASK);
        $this->assertSame('naver-new-secret', SocialLoginProvider::query()->where('key', 'naver')->first()->client_secret);

        $this->plantUnreadable('kakao', ['client_secret', 'admin_key']);
        $this->putJson(self::BASE.'/kakao', ['client_secret' => 'kakao-new-secret'])
            ->assertOk()
            ->assertJsonPath('data.credentials_unreadable', true);

        $this->putJson(self::BASE.'/kakao', ['admin_key' => 'kakao-new-admin'])
            ->assertOk()
            ->assertJsonPath('data.credentials_unreadable', false);
        $kakao = SocialLoginProvider::query()->where('key', 'kakao')->first();
        $this->assertSame('kakao-new-secret', $kakao->client_secret);
        $this->assertSame('kakao-new-admin', $kakao->admin_key);
    }

    /**
     * 풀 수 없는 상태에서 다른 칸만 바꾸거나 마스크를 그대로 돌려보내면 저장되고, 옛 암호문은 그대로 두며 안내도 남는다.
     * 비밀값을 비우면 종전처럼 지워진다(안내도 사라진다). 켜진 제공자도 같다 — 암호문이 있으니 「자격증명 없음」으로 막지 않는다.
     *
     * @scenario case=other_field_mask_or_empty
     *
     * @effects other_field_saved_keeps_unreadable, unreadable_secret_cleared_by_empty
     *
     * @return void 반환값 없음
     */
    public function test_other_fields_save_while_secret_is_unreadable(): void
    {
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('rot')], self::BOTH));
        SocialLoginProvider::query()->where('key', 'naver')->first()->forceFill(['enabled' => true, 'client_id' => 'naver-id', 'client_secret' => 'readable'])->save();
        $this->plantUnreadable('naver', ['client_secret']);
        $planted = SocialLoginProvider::query()->where('key', 'naver')->toBase()->value('client_secret');

        $this->putJson(self::BASE.'/naver', ['client_id' => 'naver-id-2'])
            ->assertOk()
            ->assertJsonPath('data.client_id', 'naver-id-2')
            ->assertJsonPath('data.credentials_unreadable', true);

        $this->putJson(self::BASE.'/naver', ['client_secret' => SensitiveSettingMask::MASK, 'client_id' => 'naver-id-3'])
            ->assertOk()
            ->assertJsonPath('data.credentials_unreadable', true);
        $this->assertSame($planted, SocialLoginProvider::query()->where('key', 'naver')->toBase()->value('client_secret'));

        $this->putJson(self::BASE.'/naver', ['client_secret' => '', 'enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.client_secret', null)
            ->assertJsonPath('data.credentials_unreadable', false);
    }

    /**
     * 앱 키가 바뀐 뒤에도 연결된 회원이 다시 로그인하면 풀 수 없는 옛 토큰·원본 프로필을 새 값으로 덮어 저장하고 인계 주소로 간다.
     *
     * @scenario case=returning_member_login
     *
     * @effects returning_member_login_overwrites_unreadable_tokens
     *
     * @return void 반환값 없음
     */
    public function test_returning_member_login_overwrites_unreadable_tokens(): void
    {
        $this->enableKakao();
        $user = User::factory()->create(['email' => $this->uniqueEmail('rot')]);
        $account = SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider_key' => 'kakao',
            'provider_user_id' => 'k-rot-1',
            'linked_at' => now(),
        ]);
        SocialAccount::query()->whereKey($account->id)->toBase()->update([
            'access_token' => $this->foreignCiphertext('old-access'),
            'refresh_token' => $this->foreignCiphertext('old-refresh'),
            'profile_raw' => $this->foreignCiphertext('{"id":"k-rot-1"}'),
        ]);

        $response = $this->kakaoRoundTrip($this->kakaoProfile('k-rot-1', 'rot@example.test'));

        $this->assertSame('/login', $this->redirectPath($response));
        $this->assertArrayHasKey('login_handoff', $this->redirectQuery($response), (string) $response->headers->get('Location'));

        $fresh = $account->fresh();
        $this->assertStringStartsWith('at-', (string) $fresh->access_token);
        $this->assertSame('rt', $fresh->refresh_token);
        $this->assertSame('k-rot-1', (string) ($fresh->profile_raw['id'] ?? ''));
    }
}
