<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Unit\Drivers;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\SocialLogin\Drivers\GoogleDriver;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;

/**
 * 구글 드라이버 시험 — nonce·PKCE, id_token 검증과 sub 대조, 프로필 변종 4(부록 B), invalid_grant, 연결 해제.
 *
 * @since 1.0.0
 */
class GoogleDriverTest extends DriverTestCase
{
    /**
     * 사용자 정보와 JWKS 를 가짜로 둡니다.
     *
     * @param  array<string, mixed>  $userinfo  사용자 정보
     * @return void 반환값 없음
     */
    private function fakeGoogle(array $userinfo): void
    {
        Http::fake([
            'openidconnect.googleapis.com/v1/userinfo' => Http::response($userinfo),
            'www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [self::jwk()]]),
        ]);
    }

    /**
     * 구글이 줄 법한 id_token 을 만듭니다.
     *
     * @param  string  $sub  토큰 주체(sub) 값
     * @param  string  $nonce  왕복 nonce 값
     * @param  string  $iss  발급자(iss) 값
     * @return string 서명된 JWT 문자열
     */
    private function idToken(string $sub, string $nonce = 'nonce-xyz', string $iss = 'https://accounts.google.com'): string
    {
        return self::signJwt(['iss' => $iss, 'aud' => 'client-123', 'sub' => $sub, 'exp' => time() + 300, 'iat' => time(), 'nonce' => $nonce]);
    }

    /**
     * 구글 인가 주소에 openid 범위, nonce, PKCE 가 실리는지 확인한다.
     *
     * @scenario provider=google
     * @effects authorize_url_has_nonce_and_scope
     * @return void 반환값 없음
     */
    public function test_authorize_url_has_openid_scope_nonce_and_pkce(): void
    {
        $q = $this->query($this->driver(GoogleDriver::class)->authorizeUrl($this->request(true, true)));

        $this->assertSame('openid email profile', $q['scope']);
        $this->assertSame('nonce-xyz', $q['nonce']);
        $this->assertSame('S256', $q['code_challenge_method']);
    }

    /**
     * 부록 B 구글 4행.
     *
     * @return array<string, array{0: string, 1: string|null, 2: bool|null}> 사례 이름 => 인자 목록
     */
    public static function profiles(): array
    {
        return [
            '완비' => ['google_full', 'google.user@example.test', true],
            '미검증' => ['google_unverified', 'google.unverified@example.test', false],
            '이메일 거부' => ['google_email_denied', null, null],
            'hd 있음' => ['google_hosted_domain', 'staff@corp.example.test', true],
        ];
    }

    /**
     * 검증된 id_token 과 함께 구글 프로필 변종이 정규화되는지 확인한다.
     *
     * @scenario provider=google, variant=appendix_b
     * @effects profile_variants_normalized
     * @param  string  $fixture  데이터 제공자가 준 값
     * @param  string|null  $email  데이터 제공자가 준 값
     * @param  bool|null  $verified  데이터 제공자가 준 값
     * @return void 반환값 없음
     */
    #[DataProvider('profiles')]
    public function test_profile_variants_with_verified_id_token(string $fixture, ?string $email, ?bool $verified): void
    {
        $body = $this->fixture($fixture);
        $this->fakeGoogle($body);

        $profile = $this->driver(GoogleDriver::class)->fetchProfile($this->tokens($this->idToken($body['sub'])), $this->request(true, true));

        $this->assertSame($body['sub'], $profile->providerUserId);
        $this->assertSame($email, $profile->email);
        $this->assertSame($verified, $profile->emailVerified);
    }

    /**
     * id_token 의 sub 와 사용자 정보의 sub 가 다르면 거절하는지 확인한다.
     *
     * @scenario provider=google, id_token=sub_mismatch
     * @effects id_token_sub_must_match_userinfo
     * @return void 반환값 없음
     */
    public function test_id_token_subject_must_match_userinfo(): void
    {
        $this->fakeGoogle($this->fixture('google_full'));

        try {
            $this->driver(GoogleDriver::class)->fetchProfile($this->tokens($this->idToken('someone-else')), $this->request(true, true));
            $this->fail('예외가 나야 한다');
        } catch (ProviderException $e) {
            $this->assertSame('id_token_sub_mismatch', $e->errorCode);
        }
    }

    /**
     * nonce 가 다른 id_token 을 거절하는지 확인한다.
     *
     * @scenario provider=google, id_token=nonce_mismatch
     * @effects id_token_nonce_checked
     * @return void 반환값 없음
     */
    public function test_id_token_nonce_mismatch_is_rejected(): void
    {
        $body = $this->fixture('google_full');
        $this->fakeGoogle($body);

        try {
            $this->driver(GoogleDriver::class)->fetchProfile($this->tokens($this->idToken($body['sub'], 'other-nonce')), $this->request(true, true));
            $this->fail('예외가 나야 한다');
        } catch (ProviderException $e) {
            $this->assertSame('id_token_nonce_mismatch', $e->errorCode);
        }
    }

    /**
     * 구글 문서가 정한 발급자 두 형태(https 있음·없음)를 모두 받는지 확인한다.
     *
     * @scenario provider=google, id_token=issuer_without_scheme
     * @effects google_issuer_both_forms_accepted
     * @return void 반환값 없음
     */
    public function test_id_token_issuer_without_scheme_is_accepted(): void
    {
        $body = $this->fixture('google_full');
        $this->fakeGoogle($body);

        $profile = $this->driver(GoogleDriver::class)->fetchProfile($this->tokens($this->idToken($body['sub'], 'nonce-xyz', 'accounts.google.com')), $this->request(true, true));

        $this->assertSame($body['sub'], $profile->providerUserId);
    }

    /**
     * 구글 두 형태가 아닌 발급자는 그대로 거절하는지 확인한다.
     *
     * @scenario provider=google, id_token=issuer_other
     * @effects id_token_issuer_checked
     * @return void 반환값 없음
     */
    public function test_id_token_other_issuer_is_rejected(): void
    {
        $body = $this->fixture('google_full');
        $this->fakeGoogle($body);

        try {
            $this->driver(GoogleDriver::class)->fetchProfile($this->tokens($this->idToken($body['sub'], 'nonce-xyz', 'https://accounts.example.test')), $this->request(true, true));
            $this->fail('예외가 나야 한다');
        } catch (ProviderException $e) {
            $this->assertSame('id_token_iss_mismatch', $e->errorCode);
        }
    }

    /**
     * 구글 invalid_grant 오류 코드가 그대로 남는지 확인한다.
     *
     * @scenario provider=google, error=invalid_grant
     * @effects provider_error_code_mapped
     * @return void 반환값 없음
     */
    public function test_invalid_grant(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Bad Request'], 400)]);

        try {
            $this->driver(GoogleDriver::class)->exchangeCode('c', $this->request());
            $this->fail('예외가 나야 한다');
        } catch (ProviderException $e) {
            $this->assertSame('invalid_grant', $e->errorCode);
        }
    }

    /**
     * 구글 토큰 폐기가 갱신 토큰을 먼저 쓰는지 확인한다.
     *
     * @scenario provider=google
     * @effects revoke_posts_token
     * @return void 반환값 없음
     */
    public function test_revoke_prefers_refresh_token(): void
    {
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response([], 200)]);

        $this->assertTrue($this->driver(GoogleDriver::class)->revoke($this->tokens(), new SocialAccount));
        Http::assertSent(fn (Request $r) => $r['token'] === 'refresh-1');
    }
}
