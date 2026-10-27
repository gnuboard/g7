<?php

namespace Plugins\Sirsoft\Sso\Mock\Oidc;

use App\Contracts\Extension\CacheInterface;
use Firebase\JWT\JWT;
use Plugins\Sirsoft\Sso\Mock\MockGate;
use RuntimeException;

/**
 * 모의 OIDC IdP 의 서명 키.
 *
 * 켜질 때 RSA 키를 새로 만들어 모의 활성 시간 동안만 캐시에 둔다. 저장소에 고정 키를 두지 않는다 — 고정 키는 누구나 알 수 있어,
 * 모의 발급자를 실수로 실제 IdP 설정에 넣었을 때 위조 토큰을 만들 수 있다.
 *
 * 「회전된 키」(`kid_rotated` 변종)는 처음 쓰일 때 만들고 그때부터 JWKS 에 함께 싣는다 — 검증 쪽이 캐시한 옛 묶음에 없는 kid 를
 * 받아 한 번 다시 받는 경로를 밟게 한다.
 *
 * @since 1.0.0
 */
class MockOidcKeys
{
    /** 주 키 캐시 키 */
    private const PRIMARY = 'mock_oidc_rsa_primary';

    /** 회전 키 캐시 키 */
    private const ROTATED = 'mock_oidc_rsa_rotated';

    /**
     * @param  CacheInterface  $cache  플러그인 캐시
     */
    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * 주 키로 RS256 서명합니다.
     *
     * @param  array<string, mixed>  $claims  클레임
     * @param  bool  $rotated  회전 키로 서명할지
     * @return string JWT
     */
    public function sign(array $claims, bool $rotated = false): string
    {
        $pem = $rotated ? $this->rotatedKey() : $this->primaryKey();

        return JWT::encode($claims, $pem, 'RS256', self::kid($pem));
    }

    /**
     * 공개키 PEM 을 비밀값처럼 써서 HS256 서명합니다 (`alg_hs256` 위조 변종).
     *
     * @param  array<string, mixed>  $claims  클레임
     * @return string JWT
     */
    public function signHs256WithPublicKey(array $claims): string
    {
        $pem = $this->primaryKey();
        $public = (string) openssl_pkey_get_details(openssl_pkey_get_private($pem))['key'];

        return JWT::encode($claims, $public, 'HS256', self::kid($pem));
    }

    /**
     * 서명 없는 토큰을 만듭니다 (`alg_none` 위조 변종).
     *
     * @param  array<string, mixed>  $claims  클레임
     * @return string JWT (서명 부분이 빈 문자열)
     */
    public function unsigned(array $claims): string
    {
        $header = JWT::urlsafeB64Encode((string) json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => self::kid($this->primaryKey())]));

        return $header.'.'.JWT::urlsafeB64Encode((string) json_encode($claims)).'.';
    }

    /**
     * JWKS 를 반환합니다 — 주 키와, 만들어졌다면 회전 키.
     *
     * @return array{keys: array<int, array<string, string>>} JWKS
     */
    public function jwks(): array
    {
        $keys = [self::jwk($this->primaryKey())];
        $rotated = $this->cache->get(self::ROTATED);
        if (is_string($rotated) && $rotated !== '') {
            $keys[] = self::jwk($rotated);
        }

        return ['keys' => $keys];
    }

    /**
     * 키를 모두 지웁니다 (게이트를 끌 때).
     *
     * @return void 반환값 없음
     */
    public function forget(): void
    {
        $this->cache->forget(self::PRIMARY);
        $this->cache->forget(self::ROTATED);
    }

    /**
     * 주 키 PEM.
     *
     * @return string 개인키 PEM
     */
    private function primaryKey(): string
    {
        return $this->keyFor(self::PRIMARY);
    }

    /**
     * 회전 키 PEM.
     *
     * @return string 개인키 PEM
     */
    private function rotatedKey(): string
    {
        return $this->keyFor(self::ROTATED);
    }

    /**
     * 캐시의 키를 꺼내거나 새로 만듭니다.
     *
     * @param  string  $cacheKey  캐시 키
     * @return string 개인키 PEM
     *
     * @throws RuntimeException 키 생성 실패
     */
    private function keyFor(string $cacheKey): string
    {
        $pem = $this->cache->get($cacheKey);
        if (is_string($pem) && $pem !== '') {
            return $pem;
        }

        // openssl 이 기본 설정 파일을 찾지 못하는 서버(OPENSSL_CONF 미설정 — Windows 개발 PC 등)는 플러그인의 최소 설정으로 다시 시도한다.
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $fallback = $options + ['config' => dirname(__DIR__, 3).'/resources/openssl/minimal.cnf'];
        $pem = null;
        foreach ([$options, $fallback] as $attempt) {
            $resource = @openssl_pkey_new($attempt);
            if ($resource !== false && @openssl_pkey_export($resource, $pem, null, $attempt)) {
                break;
            }
            $pem = null;
        }

        while (openssl_error_string() !== false) {
            // openssl 오류 큐를 비운다 — 남겨 두면 뒤따르는 openssl 호출의 오류 판정이 섞인다.
        }

        if (! is_string($pem) || $pem === '') {
            throw new RuntimeException('mock key generation failed');
        }

        $this->cache->put($cacheKey, $pem, MockGate::MAX_MINUTES * 60);

        return $pem;
    }

    /**
     * 키 ID — 공개키 모듈러스에서 만든다(키가 바뀌면 kid 도 바뀐다).
     *
     * @param  string  $pem  개인키 PEM
     * @return string kid
     */
    private static function kid(string $pem): string
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($pem));

        return 'mock-'.substr(hash('sha256', (string) $details['rsa']['n']), 0, 16);
    }

    /**
     * 공개키 JWK.
     *
     * @param  string  $pem  개인키 PEM
     * @return array<string, string> JWK
     */
    private static function jwk(string $pem): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($pem));

        return [
            'kty' => 'RSA',
            'kid' => self::kid($pem),
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => JWT::urlsafeB64Encode((string) $details['rsa']['n']),
            'e' => JWT::urlsafeB64Encode((string) $details['rsa']['e']),
        ];
    }
}
