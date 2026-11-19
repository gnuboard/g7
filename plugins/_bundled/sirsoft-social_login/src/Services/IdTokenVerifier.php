<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Contracts\Extension\CacheInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Support\Jwt\JwkToPem;
use Plugins\Sirsoft\SocialLogin\Support\Jwt\JwtClaims;
use Plugins\Sirsoft\SocialLogin\Support\Jwt\Rs256Verifier;

/**
 * OIDC id_token 검증.
 *
 * 서명 키는 제공자 JWKS 에서 `kid` 로 고르고 1시간 캐시한다. 캐시에 없는 `kid` 가 오면 제공자가 키를 돌린 것일 수
 * 있으므로 한 번만 강제로 다시 받는다 — 그래도 없으면 거절한다(위조 토큰이 매번 재조회를 일으키지 않게 1회 한정).
 *
 * @since 1.0.0
 */
class IdTokenVerifier
{
    /** JWKS 캐시 TTL(초) */
    public const JWKS_TTL_SECONDS = 3600;

    /**
     * @param  CacheInterface  $cache  플러그인 캐시
     */
    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * id_token 을 검증하고 클레임을 반환합니다.
     *
     * @param  string  $idToken  id_token
     * @param  string  $jwksUrl  JWKS 주소
     * @param  string|list<string>  $issuer  기대 발급자 (공식 형태가 여럿이면 그 목록)
     * @param  string  $audience  기대 수신자(client_id)
     * @param  string|null  $nonce  기대 nonce
     * @param  bool  $verifyTls  JWKS 요청의 TLS 인증서 검증 여부 (모의 제공자만 false)
     * @return array<string, mixed> 검증된 클레임
     *
     * @throws ProviderException 서명·클레임 검사 실패
     */
    public function verify(string $idToken, string $jwksUrl, string|array $issuer, string $audience, ?string $nonce, bool $verifyTls = true): array
    {
        $kid = (string) (Rs256Verifier::header($idToken)['kid'] ?? '');

        $jwk = $this->findKey($jwksUrl, $kid, false, $verifyTls) ?? $this->findKey($jwksUrl, $kid, true, $verifyTls);
        if ($jwk === null) {
            throw new ProviderException('id_token_kid_unknown');
        }

        try {
            $pem = JwkToPem::convert($jwk);
        } catch (InvalidArgumentException $e) {
            throw new ProviderException('id_token_key_invalid', $e->getMessage());
        }

        if (! Rs256Verifier::verify($idToken, $pem)) {
            throw new ProviderException('id_token_signature_invalid');
        }

        $claims = JwtClaims::decode($idToken);
        $failure = JwtClaims::validate($claims, $issuer, $audience, $nonce);
        if ($failure !== null) {
            throw new ProviderException($failure);
        }

        return $claims;
    }

    /**
     * JWKS 에서 kid 에 맞는 키를 찾습니다.
     *
     * @param  string  $jwksUrl  JWKS 주소
     * @param  string  $kid  키 ID (빈 값이면 RSA 키가 하나뿐일 때 그 키)
     * @param  bool  $refresh  캐시를 무시하고 다시 받을지
     * @param  bool  $verifyTls  TLS 인증서 검증 여부
     * @return array<string, mixed>|null 키, 없으면 null
     *
     * @throws ProviderException JWKS 를 받지 못했을 때
     */
    private function findKey(string $jwksUrl, string $kid, bool $refresh, bool $verifyTls = true): ?array
    {
        $cacheKey = 'jwks:'.sha1($jwksUrl);

        if ($refresh) {
            $this->cache->forget($cacheKey);
        }

        $keys = $this->cache->get($cacheKey);
        if (! is_array($keys)) {
            $client = Http::timeout(10)->acceptJson();
            try {
                $response = ($verifyTls ? $client : $client->withoutVerifying())->get($jwksUrl);
            } catch (ConnectionException $e) {
                // 연결 실패도 제공자 오류로 바꿔 콜백이 시도 기록과 안내 문구로 끝나게 한다.
                throw new ProviderException('jwks_fetch_failed', 'connection_failed', null, $e);
            }
            if (! $response->successful() || ! is_array($response->json('keys'))) {
                throw new ProviderException('jwks_fetch_failed', '', $response->status());
            }
            $keys = array_values(array_filter($response->json('keys'), 'is_array'));
            $this->cache->put($cacheKey, $keys, self::JWKS_TTL_SECONDS);
        }

        $rsa = array_values(array_filter($keys, fn (array $k) => ($k['kty'] ?? null) === 'RSA'));

        if ($kid === '') {
            return count($rsa) === 1 ? $rsa[0] : null;
        }

        foreach ($rsa as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return $key;
            }
        }

        return null;
    }
}
