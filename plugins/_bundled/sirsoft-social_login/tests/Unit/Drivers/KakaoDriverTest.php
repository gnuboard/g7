<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Unit\Drivers;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\SocialLogin\Drivers\KakaoDriver;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Support\Jwt\Base64Url;

/**
 * 카카오 드라이버 시험 — 인가 주소, 토큰 정규화, 프로필 변종 6(부록 B), 오류 매핑, 연결 해제 형태.
 *
 * @since 1.0.0
 */
class KakaoDriverTest extends DriverTestCase
{
    /**
     * 카카오 인가 주소에 state, 콜백 주소, PKCE 가 실리는지 확인한다.
     *
     * @scenario provider=kakao
     * @effects authorize_url_has_pkce_and_state
     * @return void 반환값 없음
     */
    public function test_authorize_url_carries_state_redirect_and_pkce(): void
    {
        $req = $this->request();
        $url = $this->driver(KakaoDriver::class)->authorizeUrl($req);
        $q = $this->query($url);

        $this->assertStringStartsWith('https://kauth.kakao.com/oauth/authorize?', $url);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('client-123', $q['client_id']);
        $this->assertSame(self::CALLBACK, $q['redirect_uri']);
        $this->assertSame('state-abc', $q['state']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame(Base64Url::encode(hash('sha256', $req->codeVerifier, true)), $q['code_challenge']);
        $this->assertArrayNotHasKey('nonce', $q);
    }

    /**
     * 카카오 토큰 교환이 code_verifier 를 보내고 만료 시각을 정규화하는지 확인한다.
     *
     * @scenario provider=kakao
     * @effects token_normalized
     * @return void 반환값 없음
     */
    public function test_token_exchange_sends_verifier_and_normalizes_expiry(): void
    {
        Http::fake(['kauth.kakao.com/oauth/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 21599])]);

        $tokens = $this->driver(KakaoDriver::class)->exchangeCode('code-1', $this->request());

        $this->assertSame('at', $tokens->accessToken);
        $this->assertSame('rt', $tokens->refreshToken);
        $this->assertEqualsWithDelta(time() + 21599, $tokens->expiresAt?->getTimestamp(), 5);
        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'authorization_code'
            && $r['code'] === 'code-1' && $r['code_verifier'] === str_repeat('v', 64) && $r['client_secret'] === 'secret-456');
    }

    /**
     * 카카오 인증 서버 오류 사례 — 인가 코드 재사용·만료(KOE320), 콘솔에 등록하지 않은 Redirect URI(KOE006).
     *
     * @return array<string, array{0: string, 1: string, 2: string}> 사례 이름 => [error_code, error, error_description]
     */
    public static function kakaoTokenErrors(): array
    {
        return [
            'KOE320 인가 코드 재사용' => ['KOE320', 'invalid_grant', 'authorization code not found'],
            'KOE006 Redirect URI 불일치' => ['KOE006', 'invalid_request', 'redirect_uri mismatch'],
        ];
    }

    /**
     * 카카오가 주는 `error_code` 를 그대로 오류 코드로 남겨 진단 화면이 원인을 보이게 한다.
     *
     * @scenario provider=kakao, error=KOE320|KOE006
     * @effects provider_error_code_mapped
     * @param  string  $kakaoCode  카카오가 주는 오류 코드(`error_code`)
     * @param  string  $error  표준 오류 이름(`error`)
     * @param  string  $description  오류 설명(`error_description`)
     * @return void 반환값 없음
     */
    #[DataProvider('kakaoTokenErrors')]
    public function test_token_error_maps_kakao_error_code(string $kakaoCode, string $error, string $description): void
    {
        Http::fake(['kauth.kakao.com/oauth/token' => Http::response(['error' => $error, 'error_description' => $description, 'error_code' => $kakaoCode], 400)]);

        try {
            $this->driver(KakaoDriver::class)->exchangeCode('used', $this->request());
            $this->fail('예외가 나야 한다');
        } catch (ProviderException $e) {
            $this->assertSame($kakaoCode, $e->errorCode);
            $this->assertSame(400, $e->httpStatus);
        }
    }

    /**
     * 부록 B 카카오 6행.
     *
     * @return array<string, array{0: string, 1: string, 2: string|null, 3: bool|null, 4: string|null}> 사례 이름 => 인자 목록
     */
    public static function profiles(): array
    {
        return [
            '완비' => ['kakao_full', '4123456789', 'kakao.user@example.test', true, '카카오닉'],
            '이메일 미동의' => ['kakao_email_not_agreed', '4123456790', null, null, '카카오닉'],
            '마스킹' => ['kakao_masked_email', '4123456791', null, null, '카카오닉'],
            '미검증' => ['kakao_unverified', '4123456792', 'kakao.unverified@example.test', false, '카카오닉'],
            '닉네임만' => ['kakao_nickname_only', '4123456793', null, null, '닉네임만'],
            '정수 id' => ['kakao_integer_id', '9223372036854775807', null, null, null],
        ];
    }

    /**
     * 부록 B 프로필 변종이 정규화되는지 확인한다.
     *
     * @scenario provider=kakao, variant=appendix_b
     * @effects profile_variants_normalized
     * @param  string  $fixture  데이터 제공자가 준 값
     * @param  string|null  $id  데이터 제공자가 준 값
     * @param  string|null  $email  데이터 제공자가 준 값
     * @param  bool|null  $verified  데이터 제공자가 준 값
     * @param  string|null  $nickname  데이터 제공자가 준 값
     * @return void 반환값 없음
     */
    #[DataProvider('profiles')]
    public function test_profile_variants(string $fixture, string $id, ?string $email, ?bool $verified, ?string $nickname): void
    {
        Http::fake(['kapi.kakao.com/v2/user/me' => Http::response($this->fixture($fixture))]);

        $profile = $this->driver(KakaoDriver::class)->fetchProfile($this->tokens(), $this->request());

        $this->assertSame($id, $profile->providerUserId);
        $this->assertSame($email, $profile->email);
        $this->assertSame($verified, $profile->emailVerified);
        $this->assertNull($profile->name);
        $this->assertSame($nickname, $profile->nickname);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer access-1'));
    }

    /**
     * 카카오 API 오류(code -401)가 kakao_-401 로 바뀌는지 확인한다.
     *
     * @scenario provider=kakao, error=-401
     * @effects provider_error_code_mapped
     * @return void 반환값 없음
     */
    public function test_profile_api_error_maps_kakao_code(): void
    {
        Http::fake(['kapi.kakao.com/v2/user/me' => Http::response(['msg' => 'this access token does not exist', 'code' => -401], 401)]);

        try {
            $this->driver(KakaoDriver::class)->fetchProfile($this->tokens(), $this->request());
            $this->fail('예외가 나야 한다');
        } catch (ProviderException $e) {
            $this->assertSame('kakao_-401', $e->errorCode);
            $this->assertSame(401, $e->httpStatus);
        }
    }

    /**
     * 카카오 연결 해제가 어드민 키와 회원 ID 대상으로 가는지 확인한다.
     *
     * @scenario provider=kakao
     * @effects revoke_uses_admin_key
     * @return void 반환값 없음
     */
    public function test_revoke_uses_admin_key_and_user_id_target(): void
    {
        Http::fake(['kapi.kakao.com/v1/user/unlink' => Http::response(['id' => 4123456789])]);
        $account = new SocialAccount(['provider_user_id' => '4123456789']);

        $this->assertTrue($this->driver(KakaoDriver::class, ['admin_key' => 'admin-key-1'])->revoke($this->tokens(), $account));
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'KakaoAK admin-key-1')
            && $r['target_id_type'] === 'user_id' && $r['target_id'] === '4123456789');

        $this->assertFalse($this->driver(KakaoDriver::class)->revoke($this->tokens(), $account), '어드민 키가 없으면 보내지 않는다');
    }
}
