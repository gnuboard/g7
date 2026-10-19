<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Unit\Drivers;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\SocialLogin\Drivers\OidcDriver;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;

/**
 * 일반 OIDC 드라이버 시험 — discovery 캐시, 필수 키 누락, 문자열 boolean, 프로필 변종 4(부록 B, nonce 불일치 포함).
 *
 * @since 1.0.0
 */
class OidcDriverTest extends DriverTestCase
{
    /** 시험 discovery 주소 */
    private const DISCOVERY = 'https://idp.example.test/.well-known/openid-configuration';

    /**
     * discovery 문서를 만듭니다.
     *
     * @return array<string, string> discovery 문서
     */
    private function discoveryDoc(): array
    {
        return [
            'issuer' => 'https://idp.example.test',
            'authorization_endpoint' => 'https://idp.example.test/authorize',
            'token_endpoint' => 'https://idp.example.test/token',
            'userinfo_endpoint' => 'https://idp.example.test/userinfo',
            'jwks_uri' => 'https://idp.example.test/jwks',
        ];
    }

    /**
     * OIDC discovery 문서를 캐시해 한 번만 받는지 확인한다.
     *
     * @scenario provider=oidc
     * @effects discovery_cached
     * @return void 반환값 없음
     */
    public function test_discovery_is_cached(): void
    {
        Http::fake([self::DISCOVERY => Http::response($this->discoveryDoc())]);

        $url = $this->driver(OidcDriver::class, ['discovery_url' => self::DISCOVERY])->authorizeUrl($this->request(true, true));
        $this->driver(OidcDriver::class, ['discovery_url' => self::DISCOVERY])->authorizeUrl($this->request(true, true));

        $this->assertStringStartsWith('https://idp.example.test/authorize?', $url);
        Http::assertSentCount(1);
    }

    /**
     * discovery 문서에 필수 키가 없으면 실패하는지 확인한다.
     *
     * @scenario provider=oidc, discovery=incomplete
     * @effects discovery_required_keys_checked
     * @return void 반환값 없음
     */
    public function test_discovery_missing_required_key_fails(): void
    {
        $doc = $this->discoveryDoc();
        unset($doc['jwks_uri']);
        Http::fake([self::DISCOVERY.'*' => Http::response($doc)]);

        try {
            $this->driver(OidcDriver::class, ['discovery_url' => self::DISCOVERY.'?incomplete'])->authorizeUrl($this->request());
            $this->fail('예외가 나야 한다');
        } catch (ProviderException $e) {
            $this->assertSame('discovery_incomplete', $e->errorCode);
        }
    }

    /**
     * 부록 B 일반 OIDC 4행.
     *
     * @return array<string, array{0: string, 1: bool|null, 2: string|null, 3: string|null}> 사례 이름 => 인자 목록
     */
    public static function profiles(): array
    {
        return [
            '완비' => ['oidc_full', true, 'OIDC User', null],
            '문자열 true' => ['oidc_string_true', true, 'stringbool', null],
            '검증 부재' => ['oidc_verified_absent', null, 'absent', null],
            'nonce 불일치' => ['oidc_nonce_mismatch', null, null, 'id_token_nonce_mismatch'],
        ];
    }

    /**
     * 부록 B 프로필 변종이 정규화되는지 확인한다.
     *
     * @scenario provider=oidc, variant=appendix_b
     * @effects profile_variants_normalized
     * @param  string  $fixture  데이터 제공자가 준 값
     * @param  bool|null  $verified  데이터 제공자가 준 값
     * @param  string|null  $name  데이터 제공자가 준 값
     * @param  string|null  $errorCode  데이터 제공자가 준 값
     * @return void 반환값 없음
     */
    #[DataProvider('profiles')]
    public function test_profile_variants(string $fixture, ?bool $verified, ?string $name, ?string $errorCode): void
    {
        $body = $this->fixture($fixture);
        Http::fake([
            self::DISCOVERY => Http::response($this->discoveryDoc()),
            'idp.example.test/userinfo' => Http::response($body),
            'idp.example.test/jwks' => Http::response(['keys' => [self::jwk()]]),
        ]);

        $nonce = $errorCode === 'id_token_nonce_mismatch' ? 'tampered' : 'nonce-xyz';
        $idToken = self::signJwt(['iss' => 'https://idp.example.test', 'aud' => 'client-123', 'sub' => $body['sub'], 'exp' => time() + 300, 'nonce' => $nonce]);

        try {
            $profile = $this->driver(OidcDriver::class, ['discovery_url' => self::DISCOVERY])
                ->fetchProfile($this->tokens($idToken), $this->request(true, true));
        } catch (ProviderException $e) {
            $this->assertSame($errorCode, $e->errorCode);

            return;
        }

        $this->assertNull($errorCode, '오류 변종인데 예외가 나지 않았다');
        $this->assertSame($body['sub'], $profile->providerUserId);
        $this->assertSame($verified, $profile->emailVerified);
        $this->assertSame($name, $profile->name);
    }
}
