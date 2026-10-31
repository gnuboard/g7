<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Unit\Drivers;

use Plugins\Sirsoft\SocialLogin\Contracts\ProviderDriverInterface;
use Plugins\Sirsoft\SocialLogin\DTO\AuthorizationRequest;
use Plugins\Sirsoft\SocialLogin\DTO\TokenSet;
use Plugins\Sirsoft\SocialLogin\Support\Jwt\Base64Url;
use Plugins\Sirsoft\SocialLogin\Support\RsaKeyFactory;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 드라이버 시험 공통 도구 — 드라이버·시작 정보·시험용 서명 키.
 *
 * 외부 HTTP 는 전부 `Http::fake()` 로 막는다. 서명 키는 실행마다 새로 만든다(저장소에 키를 두지 않는다).
 *
 * @since 1.0.0
 */
abstract class DriverTestCase extends PluginTestCase
{
    /** 시험 콜백 주소 */
    protected const CALLBACK = 'https://site.example.test/plugins/sirsoft-social_login/oauth/test/callback';

    /** @var string|null 시험용 RSA 개인키 PEM */
    private static ?string $privateKey = null;

    /**
     * 드라이버를 설정과 함께 만듭니다.
     *
     * @param  class-string<ProviderDriverInterface>  $class  드라이버 클래스
     * @param  array<string, mixed>  $config  추가 설정
     * @return ProviderDriverInterface 드라이버
     */
    protected function driver(string $class, array $config = []): ProviderDriverInterface
    {
        return app($class)->withConfig($config + [
            'key' => 'test',
            'client_id' => 'client-123',
            'client_secret' => 'secret-456',
            'label' => ['ko' => '시험', 'en' => 'Test'],
        ]);
    }

    /**
     * 시작 정보를 만듭니다.
     *
     * @param  bool  $pkce  PKCE 사용
     * @param  bool  $nonce  nonce 사용
     * @param  bool  $reconsent  제공자 동의 화면 재요청
     * @return AuthorizationRequest 시작 정보
     */
    protected function request(bool $pkce = true, bool $nonce = false, bool $reconsent = false): AuthorizationRequest
    {
        return new AuthorizationRequest(
            providerKey: 'test',
            intent: 'login',
            redirect: '/',
            state: 'state-abc',
            callbackUrl: self::CALLBACK,
            nonce: $nonce ? 'nonce-xyz' : null,
            codeVerifier: $pkce ? str_repeat('v', 64) : null,
            createdAt: time(),
            reconsent: $reconsent,
        );
    }

    /**
     * 토큰 묶음을 만듭니다.
     *
     * @param  string|null  $idToken  OIDC id_token (없으면 null)
     * @return TokenSet 토큰
     */
    protected function tokens(?string $idToken = null): TokenSet
    {
        return new TokenSet(accessToken: 'access-1', refreshToken: 'refresh-1', idToken: $idToken);
    }

    /**
     * 인가 주소의 쿼리를 읽습니다.
     *
     * @param  string  $url  인가 주소
     * @return array<string, string> 쿼리
     */
    protected function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * 시험용 개인키를 반환합니다 (프로세스당 한 번 생성).
     *
     * @return string 개인키 PEM 문자열
     */
    protected static function privateKey(): string
    {
        self::$privateKey ??= RsaKeyFactory::generatePem();

        return self::$privateKey;
    }

    /**
     * 시험용 공개키 JWK 를 반환합니다.
     *
     * @param  string  $kid  키 ID
     * @return array<string, string> 공개키 JWK
     */
    protected static function jwk(string $kid = 'k1'): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private(self::privateKey()));

        return [
            'kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256',
            'n' => Base64Url::encode($details['rsa']['n']),
            'e' => Base64Url::encode($details['rsa']['e']),
        ];
    }

    /**
     * 클레임에 서명해 JWT 를 만듭니다.
     *
     * @param  array<string, mixed>  $claims  클레임
     * @param  array<string, mixed>  $header  헤더 덮어쓰기
     * @return string 서명된 JWT 문자열
     */
    protected static function signJwt(array $claims, array $header = []): string
    {
        $h = Base64Url::encode(json_encode($header + ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'k1']));
        $p = Base64Url::encode(json_encode($claims));
        openssl_sign($h.'.'.$p, $signature, self::privateKey(), OPENSSL_ALGO_SHA256);

        return $h.'.'.$p.'.'.Base64Url::encode($signature);
    }
}
