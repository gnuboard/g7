<?php

namespace Plugins\Sirsoft\Sso\Drivers\Oidc;

use App\Contracts\Extension\CacheInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;

/**
 * OIDC 디스커버리 문서와 JWKS 조회 (캐시 포함).
 *
 * 디스커버리는 1일, JWKS 는 1시간 캐시한다. id_token 의 `kid` 가 캐시한 키 묶음에 없으면 호출자가 `jwks(force: true)` 로 한 번만
 * 다시 받는다 — IdP 키 회전 뒤에도 로그인이 이어지고, 위조 kid 로 IdP 를 두드리는 요청은 1회로 묶인다.
 *
 * @since 1.0.0
 */
class OidcMetadata
{
    /** 디스커버리 캐시 수명(초) */
    public const DISCOVERY_TTL = 86400;

    /** JWKS 캐시 수명(초) */
    public const JWKS_TTL = 3600;

    /** IdP HTTP 시간 제한(초) */
    public const HTTP_TIMEOUT = 10;

    /** @var array<int, string> 디스커버리 필수 키 */
    public const REQUIRED_KEYS = ['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'];

    /**
     * @param  CacheInterface  $cache  플러그인 캐시
     */
    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * IdP 로 가는 HTTP 클라이언트를 만듭니다 (시간 제한, JSON).
     *
     * 모의 IdP(키 접두 `mock_`)만 TLS 인증서 검증을 끈다 — 모의 IdP 는 이 사이트 자신이라, 자체 서명 인증서를 쓰는 개발 사이트에서 서버가
     * 자기 주소를 부를 때 검증에 막힌다. 모의 IdP 는 게이트가 켜졌을 때만 시작되고 실제 IdP 에는 이 분기가 닿지 않는다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return PendingRequest 클라이언트
     */
    public function http(IdentityProvider $idp): PendingRequest
    {
        $client = Http::timeout(self::HTTP_TIMEOUT)->acceptJson();

        return $idp->isMock() ? $client->withoutVerifying() : $client;
    }

    /**
     * 디스커버리 문서를 반환합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  bool  $force  캐시를 무시하고 다시 받을지
     * @return array<string, mixed> 디스커버리 문서
     *
     * @throws SsoException 조회 실패, 필수 키 누락이면 `token_error`
     */
    public function discovery(IdentityProvider $idp, bool $force = false): array
    {
        $url = (string) $idp->configValue('discovery_url', '');
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            throw new SsoException('token_error', 'discovery_url missing');
        }

        $key = 'oidc:disc:'.$idp->getKey().':'.hash('sha256', $url);
        if (! $force) {
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $response = $this->http($idp)->get($url);
        } catch (\Throwable $e) {
            throw new SsoException('token_error', 'discovery fetch failed: '.class_basename($e), 422, $e);
        }

        $document = $response->successful() ? $response->json() : null;
        if (! is_array($document)) {
            throw new SsoException('token_error', 'discovery status '.$response->status());
        }

        foreach (self::REQUIRED_KEYS as $required) {
            if (! is_string($document[$required] ?? null) || $document[$required] === '') {
                throw new SsoException('token_error', 'discovery missing '.$required);
            }
        }

        $this->cache->put($key, $document, self::DISCOVERY_TTL);

        return $document;
    }

    /**
     * JWKS(공개키 묶음)를 반환합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  bool  $force  캐시를 무시하고 다시 받을지 (kid 불일치 1회 갱신)
     * @return array{keys: array<int, array<string, mixed>>} JWKS
     *
     * @throws SsoException 조회 실패면 `id_token_invalid`
     */
    public function jwks(IdentityProvider $idp, bool $force = false): array
    {
        $uri = (string) ($this->discovery($idp)['jwks_uri'] ?? '');
        $key = 'oidc:jwks:'.$idp->getKey().':'.hash('sha256', $uri);

        if (! $force) {
            $cached = $this->cache->get($key);
            if (is_array($cached) && is_array($cached['keys'] ?? null)) {
                return $cached;
            }
        }

        try {
            $response = $this->http($idp)->get($uri);
        } catch (\Throwable $e) {
            throw new SsoException('id_token_invalid', 'jwks fetch failed: '.class_basename($e), 422, $e);
        }

        $jwks = $response->successful() ? $response->json() : null;
        if (! is_array($jwks) || ! is_array($jwks['keys'] ?? null)) {
            throw new SsoException('id_token_invalid', 'jwks status '.$response->status());
        }

        $jwks = ['keys' => array_values(array_filter($jwks['keys'], 'is_array'))];
        $this->cache->put($key, $jwks, self::JWKS_TTL);

        return $jwks;
    }

    /**
     * 이 IdP 의 캐시를 지웁니다 (설정 변경, 연결 테스트 직전).
     *
     * @param  IdentityProvider  $idp  IdP
     * @return void 반환값 없음
     */
    public function forget(IdentityProvider $idp): void
    {
        $url = (string) $idp->configValue('discovery_url', '');
        $discKey = 'oidc:disc:'.$idp->getKey().':'.hash('sha256', $url);
        $document = $this->cache->get($discKey);
        if (is_array($document) && is_string($document['jwks_uri'] ?? null)) {
            $this->cache->forget('oidc:jwks:'.$idp->getKey().':'.hash('sha256', $document['jwks_uri']));
        }
        $this->cache->forget($discKey);
    }
}
