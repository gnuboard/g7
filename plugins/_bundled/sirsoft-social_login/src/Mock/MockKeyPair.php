<?php

namespace Plugins\Sirsoft\SocialLogin\Mock;

use App\Contracts\Extension\CacheInterface;
use Plugins\Sirsoft\SocialLogin\Support\Jwt\Base64Url;
use Plugins\Sirsoft\SocialLogin\Support\RsaKeyFactory;
use RuntimeException;

/**
 * 모의 제공자의 id_token 서명 키.
 *
 * 켜질 때 RSA 키를 새로 만들어 모의 활성 시간 동안만 캐시에 둔다. 저장소에 고정 키를 두지 않는다 — 고정 키는 누구나
 * 알 수 있어, 모의 발급자를 실수로 실제 제공자 설정에 넣었을 때 위조 토큰을 만들 수 있다.
 *
 * @since 1.0.0
 */
class MockKeyPair
{
    /** 캐시 키 */
    public const CACHE_KEY = 'mock_rsa_private';

    /** 키 ID */
    public const KID = 'mock-1';

    /**
     * @param  CacheInterface  $cache  플러그인 캐시
     */
    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * 개인키 PEM 을 반환합니다 (없으면 만듭니다).
     *
     * @return string 개인키 PEM
     *
     * @throws RuntimeException 키 생성 실패
     */
    public function privateKey(): string
    {
        $pem = $this->cache->get(self::CACHE_KEY);
        if (is_string($pem) && $pem !== '') {
            return $pem;
        }

        $pem = RsaKeyFactory::generatePem();

        $this->cache->put(self::CACHE_KEY, $pem, MockGate::MAX_MINUTES * 60);

        return $pem;
    }

    /**
     * 공개키 JWK 를 반환합니다.
     *
     * @return array<string, string> JWK
     */
    public function jwk(): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($this->privateKey()));

        return [
            'kty' => 'RSA',
            'kid' => self::KID,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => Base64Url::encode($details['rsa']['n']),
            'e' => Base64Url::encode($details['rsa']['e']),
        ];
    }

    /**
     * 클레임에 RS256 서명을 해 compact JWT 로 만듭니다.
     *
     * @param  array<string, mixed>  $claims  클레임
     * @return string JWT
     */
    public function sign(array $claims): string
    {
        $header = Base64Url::encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => self::KID]));
        $payload = Base64Url::encode(json_encode($claims));
        openssl_sign($header.'.'.$payload, $signature, $this->privateKey(), OPENSSL_ALGO_SHA256);

        return $header.'.'.$payload.'.'.Base64Url::encode($signature);
    }
}
