<?php

namespace Plugins\Sirsoft\SocialLogin\Drivers;

use App\Contracts\Extension\CacheInterface;
use Plugins\Sirsoft\SocialLogin\DTO\AuthorizationRequest;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\DTO\TokenSet;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Services\IdTokenVerifier;

/**
 * 일반 OpenID Connect 제공자 드라이버 (관리자가 추가하는 커스텀 제공자).
 *
 * 주소는 discovery 문서(`/.well-known/openid-configuration`)에서 읽고 하루 캐시한다. discovery 주소가 없으면
 * 관리자가 직접 넣은 주소를 쓴다. `email_verified` 는 불리언 또는 문자열(`"true"`)로 오고, 없으면 알 수 없음(null)이다.
 *
 * @since 1.0.0
 */
class OidcDriver extends GoogleDriver
{
    /** discovery 캐시 TTL(초) */
    public const DISCOVERY_TTL_SECONDS = 86400;

    /** @var array<string, mixed>|null 이번 인스턴스가 읽은 discovery */
    private ?array $discovery = null;

    /**
     * @param  CacheInterface|null  $cache  플러그인 캐시 (서비스 프로바이더의 contextual 바인딩 — 없으면 캐시하지 않음)
     */
    public function __construct(private readonly ?CacheInterface $cache = null) {}

    /**
     * 드라이버 이름을 반환합니다.
     *
     * @return string oidc
     */
    public function driverName(): string
    {
        return 'oidc';
    }

    /**
     * 지원 기능을 반환합니다.
     *
     * @return array<string, mixed> 지원 기능
     */
    public function supports(): array
    {
        return ['oidc' => true, 'revoke' => false] + parent::supports();
    }

    /**
     * 설정 화면 자격증명 필드 정의를 반환합니다.
     *
     * @return array<int, array<string, mixed>> 필드 정의
     */
    public function credentialSchema(): array
    {
        return array_merge(parent::credentialSchema(), [
            ['key' => 'discovery_url', 'type' => 'url', 'required' => false, 'label' => ['ko' => 'Discovery 주소', 'en' => 'Discovery URL']],
        ]);
    }

    /**
     * 기본 주소를 반환합니다 — discovery 문서 또는 직접 입력값.
     *
     * @return array<string, string|null> 주소
     *
     * @throws ProviderException discovery 실패 또는 필수 키 누락
     */
    protected function defaultEndpoints(): array
    {
        $discoveryUrl = (string) ($this->config['discovery_url'] ?? '');

        if ($discoveryUrl === '') {
            return [
                'authorize' => $this->config['authorize_url'] ?? null,
                'token' => $this->config['token_url'] ?? null,
                'userinfo' => $this->config['userinfo_url'] ?? null,
                'jwks' => $this->config['jwks_url'] ?? null,
                'issuer' => $this->config['issuer'] ?? null,
                'revoke' => null,
            ];
        }

        $doc = $this->discovery($discoveryUrl);

        return [
            'authorize' => $doc['authorization_endpoint'],
            'token' => $doc['token_endpoint'],
            'userinfo' => $doc['userinfo_endpoint'] ?? ($this->config['userinfo_url'] ?? null),
            'jwks' => $doc['jwks_uri'],
            'issuer' => $this->config['issuer'] ?? $doc['issuer'],
            'revoke' => $doc['revocation_endpoint'] ?? null,
        ];
    }

    /**
     * discovery 문서를 읽습니다 (하루 캐시).
     *
     * @param  string  $url  discovery 주소
     * @return array<string, mixed> discovery 문서
     *
     * @throws ProviderException 받지 못했거나 필수 키(issuer·authorization_endpoint·token_endpoint·jwks_uri)가 없을 때
     */
    public function discovery(string $url): array
    {
        if ($this->discovery !== null) {
            return $this->discovery;
        }

        $cacheKey = 'discovery:'.sha1($url);
        $doc = $this->cache?->get($cacheKey);

        if (! is_array($doc)) {
            $response = $this->send(fn () => $this->http()->get($url), 'discovery_failed');
            $doc = $response->json();
            if (! $response->successful() || ! is_array($doc)) {
                throw new ProviderException('discovery_failed', '', $response->status());
            }

            foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $required) {
                if (! is_string($doc[$required] ?? null) || $doc[$required] === '') {
                    throw new ProviderException('discovery_incomplete', $required);
                }
            }

            $this->cache?->put($cacheKey, $doc, self::DISCOVERY_TTL_SECONDS);
        }

        return $this->discovery = $doc;
    }

    /**
     * 프로필을 가져와 정규화합니다 — userinfo 주소가 없으면 id_token 클레임만 씁니다.
     *
     * @param  TokenSet  $tokens  토큰
     * @param  AuthorizationRequest  $req  시작 정보
     * @param  array<string, mixed>  $callbackInput  콜백 입력
     * @return NormalizedProfile 정규화 프로필
     *
     * @throws ProviderException 조회·검증 실패
     */
    public function fetchProfile(TokenSet $tokens, AuthorizationRequest $req, array $callbackInput = []): NormalizedProfile
    {
        if ((string) $this->endpoint('userinfo') === '') {
            if ($tokens->idToken === null) {
                throw new ProviderException('profile_fetch_failed', 'userinfo_and_id_token_missing');
            }

            $claims = app(IdTokenVerifier::class)->verify(
                $tokens->idToken,
                (string) $this->endpoint('jwks'),
                (string) $this->endpoint('issuer'),
                (string) ($this->config['client_id'] ?? ''),
                $req->nonce,
                ! $this->isMock(),
            );

            return $this->profileFromClaims($claims);
        }

        return parent::fetchProfile($tokens, $req, $callbackInput);
    }

    /**
     * 프로필 응답을 정규화합니다.
     *
     * @param  array<string, mixed>  $body  userinfo 응답
     * @param  TokenSet  $tokens  토큰
     * @param  AuthorizationRequest  $req  시작 정보
     * @return NormalizedProfile 정규화 프로필
     *
     * @throws ProviderException id_token 검증 실패 또는 sub 불일치
     */
    protected function normalizeProfile(array $body, TokenSet $tokens, AuthorizationRequest $req): NormalizedProfile
    {
        $sub = $this->requireId($body['sub'] ?? null);
        $this->assertIdTokenSubject($tokens, $req, $sub);

        return $this->profileFromClaims($body);
    }

    /**
     * 표준 클레임으로 프로필을 만듭니다.
     *
     * @param  array<string, mixed>  $claims  userinfo 또는 id_token 클레임
     * @return NormalizedProfile 정규화 프로필
     *
     * @throws ProviderException sub 가 없을 때
     */
    protected function profileFromClaims(array $claims): NormalizedProfile
    {
        $email = NormalizedProfile::email($claims['email'] ?? null);
        $username = NormalizedProfile::str($claims['preferred_username'] ?? null);

        return new NormalizedProfile(
            providerUserId: $this->requireId($claims['sub'] ?? null),
            email: $email,
            emailVerified: $email === null ? null : NormalizedProfile::bool($claims['email_verified'] ?? null),
            name: NormalizedProfile::str($claims['name'] ?? null) ?? $username,
            nickname: $username,
            avatarUrl: NormalizedProfile::str($claims['picture'] ?? null),
            raw: $claims,
        );
    }
}
