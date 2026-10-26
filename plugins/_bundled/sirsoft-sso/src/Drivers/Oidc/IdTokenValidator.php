<?php

namespace Plugins\Sirsoft\Sso\Drivers\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;

/**
 * OIDC id_token(과 back-channel logout_token) 검증.
 *
 * 서명은 검증된 JWT 라이브러리(`firebase/php-jwt`)가 확인하고, 여기서는 그 앞뒤의 규격 검사를 한다:
 *  - 알고리즘은 허용 목록(RS256, RS384, RS512, ES256, ES384)으로 고정한다 — 헤더의 `alg` 를 그대로 믿으면 `none`, HS256(공개키를
 *    비밀로 쓰는) 위조가 통한다.
 *  - `kid` 가 캐시한 키 묶음에 없으면 JWKS 를 한 번만 다시 받는다(키 회전 대응).
 *  - `iss` 정확 일치, `aud` 에 client_id 포함(여럿이면 `azp` = client_id), `exp`·`iat`(허용 오차 120초), `nonce`, `at_hash`.
 *
 * @since 1.0.0
 */
class IdTokenValidator
{
    /** @var array<int, string> 허용 서명 알고리즘 */
    public const ALLOWED_ALGORITHMS = ['RS256', 'RS384', 'RS512', 'ES256', 'ES384'];

    /** 시계 차이 허용 오차(초) */
    public const LEEWAY_SECONDS = 120;

    /** back-channel 로그아웃 이벤트 이름 */
    public const BACKCHANNEL_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    /**
     * @param  OidcMetadata  $metadata  디스커버리와 JWKS
     */
    public function __construct(private readonly OidcMetadata $metadata) {}

    /**
     * id_token 을 검증하고 클레임을 돌려줍니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $idToken  id_token
     * @param  string  $nonce  state 에 저장한 nonce
     * @param  string|null  $accessToken  같은 응답의 access_token (at_hash 검사)
     * @return array<string, mixed> 클레임
     *
     * @throws SsoException 검증 실패면 `id_token_invalid`
     */
    public function validate(IdentityProvider $idp, string $idToken, string $nonce, ?string $accessToken = null): array
    {
        [$claims, $alg] = $this->decode($idp, $idToken);

        $this->assertIssuerAndAudience($idp, $claims);

        if (! isset($claims['exp']) || ! is_numeric($claims['exp'])) {
            throw new SsoException('id_token_invalid', 'exp missing');
        }
        if (! isset($claims['iat']) || ! is_numeric($claims['iat'])) {
            throw new SsoException('id_token_invalid', 'iat missing');
        }

        if (! is_string($claims['nonce'] ?? null) || $nonce === '' || ! hash_equals($nonce, $claims['nonce'])) {
            throw new SsoException('id_token_invalid', 'nonce mismatch');
        }

        if (isset($claims['at_hash']) && $accessToken !== null && $accessToken !== '') {
            if (! is_string($claims['at_hash']) || ! hash_equals(self::atHash($accessToken, $alg), $claims['at_hash'])) {
                throw new SsoException('id_token_invalid', 'at_hash mismatch');
            }
        }

        return $claims;
    }

    /**
     * back-channel logout_token 을 검증하고 클레임을 돌려줍니다 (OpenID Connect Back-Channel Logout 1.0 §2.6).
     *
     * `events` 에 back-channel 이벤트, `sid` 또는 `sub`, `nonce` 없음을 본다. `jti` 1회 사용은 호출자가 캐시로 막는다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $logoutToken  logout_token
     * @return array<string, mixed> 클레임
     *
     * @throws SsoException 검증 실패면 `id_token_invalid`
     */
    public function validateLogoutToken(IdentityProvider $idp, string $logoutToken): array
    {
        [$claims] = $this->decode($idp, $logoutToken);

        $this->assertIssuerAndAudience($idp, $claims);

        $events = $claims['events'] ?? null;
        if (! is_array($events) || ! array_key_exists(self::BACKCHANNEL_EVENT, $events)) {
            throw new SsoException('id_token_invalid', 'logout event missing');
        }
        if (array_key_exists('nonce', $claims)) {
            throw new SsoException('id_token_invalid', 'logout token has nonce');
        }
        if (! is_string($claims['sid'] ?? null) && ! is_string($claims['sub'] ?? null)) {
            throw new SsoException('id_token_invalid', 'sid and sub missing');
        }
        if (! is_string($claims['jti'] ?? null) || $claims['jti'] === '') {
            throw new SsoException('id_token_invalid', 'jti missing');
        }

        return $claims;
    }

    /**
     * at_hash 기대값을 만듭니다 — 서명 알고리즘의 해시로 access_token 을 해시한 왼쪽 절반의 base64url.
     *
     * @param  string  $accessToken  access_token
     * @param  string  $alg  서명 알고리즘
     * @return string at_hash
     */
    public static function atHash(string $accessToken, string $alg): string
    {
        $bits = (int) substr($alg, 2);
        $algo = match ($bits) {
            384 => 'sha384',
            512 => 'sha512',
            default => 'sha256',
        };
        $hash = hash($algo, $accessToken, true);

        return rtrim(strtr(base64_encode(substr($hash, 0, intdiv(strlen($hash), 2))), '+/', '-_'), '=');
    }

    /**
     * 헤더를 검사하고 서명을 확인한 뒤 클레임을 꺼냅니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $jwt  토큰
     * @return array{0: array<string, mixed>, 1: string} 클레임과 알고리즘
     *
     * @throws SsoException 검증 실패
     */
    private function decode(IdentityProvider $idp, string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new SsoException('id_token_invalid', 'segments');
        }

        $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true);
        $alg = is_array($header) ? ($header['alg'] ?? null) : null;
        if (! is_string($alg) || ! in_array($alg, self::ALLOWED_ALGORITHMS, true)) {
            throw new SsoException('id_token_invalid', 'alg not allowed: '.(is_string($alg) ? $alg : '?'));
        }
        $kid = is_string($header['kid'] ?? null) ? $header['kid'] : null;

        $keys = $this->keySet($idp, $alg, false);
        if ($kid !== null && ! isset($keys[$kid])) {
            // 키 회전 — 한 번만 다시 받는다.
            $keys = $this->keySet($idp, $alg, true);
        }
        if ($kid !== null && ! isset($keys[$kid])) {
            throw new SsoException('id_token_invalid', 'kid not found');
        }

        $keyMaterial = $kid !== null ? $keys[$kid] : $this->singleKey($keys);

        $previousLeeway = JWT::$leeway;
        $previousTimestamp = JWT::$timestamp;
        JWT::$leeway = self::LEEWAY_SECONDS;
        JWT::$timestamp = now()->getTimestamp();

        try {
            $payload = JWT::decode($jwt, $keyMaterial);
        } catch (\Throwable $e) {
            throw new SsoException('id_token_invalid', class_basename($e).': '.$e->getMessage(), 422, $e);
        } finally {
            JWT::$leeway = $previousLeeway;
            JWT::$timestamp = $previousTimestamp;
        }

        $claims = json_decode((string) json_encode($payload), true);
        if (! is_array($claims)) {
            throw new SsoException('id_token_invalid', 'claims');
        }

        return [$claims, $alg];
    }

    /**
     * JWKS 를 라이브러리 키 묶음으로 바꿉니다 — 서명용(`use` 가 없거나 `sig`)이고 헤더 알고리즘과 맞는 키만.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $alg  헤더 알고리즘 (허용 목록 통과)
     * @param  bool  $force  다시 받을지
     * @return array<string, Key> kid → 키
     *
     * @throws SsoException 키가 하나도 없으면 `id_token_invalid`
     */
    private function keySet(IdentityProvider $idp, string $alg, bool $force): array
    {
        $kty = str_starts_with($alg, 'ES') ? 'EC' : 'RSA';
        $usable = [];
        foreach ($this->metadata->jwks($idp, $force)['keys'] as $index => $jwk) {
            if (($jwk['kty'] ?? null) !== $kty || (isset($jwk['use']) && $jwk['use'] !== 'sig')) {
                continue;
            }
            if (isset($jwk['alg']) && $jwk['alg'] !== $alg) {
                continue;
            }
            $jwk['kid'] = is_string($jwk['kid'] ?? null) && $jwk['kid'] !== '' ? $jwk['kid'] : 'idx-'.$index;
            $usable[] = $jwk;
        }

        if ($usable === []) {
            throw new SsoException('id_token_invalid', 'no usable key');
        }

        try {
            return JWK::parseKeySet(['keys' => $usable], $alg);
        } catch (\Throwable $e) {
            throw new SsoException('id_token_invalid', 'jwks parse: '.class_basename($e), 422, $e);
        }
    }

    /**
     * kid 없는 토큰 — 쓸 수 있는 키가 정확히 하나일 때만 그 키를 쓴다.
     *
     * @param  array<string, Key>  $keys  키 묶음
     * @return Key 키
     *
     * @throws SsoException 키가 여럿이면 `id_token_invalid`
     */
    private function singleKey(array $keys): Key
    {
        if (count($keys) !== 1) {
            throw new SsoException('id_token_invalid', 'kid missing with multiple keys');
        }

        return array_values($keys)[0];
    }

    /**
     * 발급자와 수신자를 검사합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  array<string, mixed>  $claims  클레임
     * @return void 반환값 없음
     *
     * @throws SsoException 불일치면 `id_token_invalid`
     */
    private function assertIssuerAndAudience(IdentityProvider $idp, array $claims): void
    {
        $issuer = (string) ($this->metadata->discovery($idp)['issuer'] ?? '');
        if ($issuer === '' || ! is_string($claims['iss'] ?? null) || ! hash_equals($issuer, $claims['iss'])) {
            throw new SsoException('id_token_invalid', 'iss mismatch');
        }

        $clientId = (string) $idp->configValue('client_id', '');
        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? array_values(array_filter($aud, 'is_string')) : (is_string($aud) ? [$aud] : []);
        if ($clientId === '' || ! in_array($clientId, $audiences, true)) {
            throw new SsoException('id_token_invalid', 'aud mismatch');
        }

        if (count($audiences) > 1 && (! is_string($claims['azp'] ?? null) || $claims['azp'] !== $clientId)) {
            throw new SsoException('id_token_invalid', 'azp mismatch');
        }
    }
}
