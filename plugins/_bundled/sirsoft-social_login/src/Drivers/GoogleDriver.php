<?php

namespace Plugins\Sirsoft\SocialLogin\Drivers;

use Plugins\Sirsoft\SocialLogin\DTO\AuthorizationRequest;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\DTO\TokenSet;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Services\IdTokenVerifier;

/**
 * 구글 로그인 드라이버 (OpenID Connect).
 *
 * id_token 을 받으면 서명·클레임·nonce 를 검증하고, 사용자 정보 API 도 늘 부른다. 두 응답의 `sub` 가 다르면 거절한다.
 *
 * @since 1.0.0
 */
class GoogleDriver extends AbstractOAuth2Driver
{
    /** 구글 id_token 발급자 */
    public const ISSUER = 'https://accounts.google.com';

    /** 구글 문서가 함께 허용하는 발급자 형태(스킴 없음) — 이 값으로 와도 같은 발급자다 */
    public const ISSUER_WITHOUT_SCHEME = 'accounts.google.com';

    /**
     * 드라이버 이름을 반환합니다.
     *
     * @return string google
     */
    public function driverName(): string
    {
        return 'google';
    }

    /**
     * 기본 주소를 반환합니다.
     *
     * @return array<string, string|null> 주소
     */
    protected function defaultEndpoints(): array
    {
        return [
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'revoke' => 'https://oauth2.googleapis.com/revoke',
            'jwks' => 'https://www.googleapis.com/oauth2/v3/certs',
            'issuer' => self::ISSUER,
        ];
    }

    /**
     * 지원 기능을 반환합니다.
     *
     * @return array<string, mixed> 지원 기능
     */
    public function supports(): array
    {
        return ['oidc' => true, 'revoke' => true] + parent::supports();
    }

    /**
     * 기본 요청 범위를 반환합니다.
     *
     * @return array<int, string> 범위 목록
     */
    protected function defaultScopes(): array
    {
        return ['openid', 'email', 'profile'];
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

        $email = NormalizedProfile::email($body['email'] ?? null);

        return new NormalizedProfile(
            providerUserId: $sub,
            email: $email,
            emailVerified: $email === null ? null : (NormalizedProfile::bool($body['email_verified'] ?? null) ?? false),
            name: NormalizedProfile::str($body['name'] ?? null),
            nickname: null,
            avatarUrl: NormalizedProfile::str($body['picture'] ?? null),
            raw: $body,
        );
    }

    /**
     * id_token 이 있으면 검증하고 sub 가 userinfo 와 같은지 확인합니다.
     *
     * @param  TokenSet  $tokens  토큰
     * @param  AuthorizationRequest  $req  시작 정보
     * @param  string  $sub  userinfo 의 sub
     * @return void
     *
     * @throws ProviderException 검증 실패
     */
    protected function assertIdTokenSubject(TokenSet $tokens, AuthorizationRequest $req, string $sub): void
    {
        if ($tokens->idToken === null) {
            return;
        }

        $issuer = (string) $this->endpoint('issuer');

        $claims = app(IdTokenVerifier::class)->verify(
            $tokens->idToken,
            (string) $this->endpoint('jwks'),
            $issuer === self::ISSUER ? [self::ISSUER, self::ISSUER_WITHOUT_SCHEME] : $issuer,
            (string) ($this->config['client_id'] ?? ''),
            $req->nonce,
            ! $this->isMock(),
        );

        if ((string) ($claims['sub'] ?? '') !== $sub) {
            throw new ProviderException('id_token_sub_mismatch');
        }
    }

    /**
     * 구글 쪽 토큰을 폐기합니다.
     *
     * @param  TokenSet  $tokens  토큰
     * @param  SocialAccount  $account  연결
     * @return bool 성공 여부
     */
    public function revoke(TokenSet $tokens, SocialAccount $account): bool
    {
        $token = $tokens->refreshToken ?? $tokens->accessToken;
        if ($token === '') {
            return false;
        }

        $response = $this->send(
            fn () => $this->http()->asForm()->post((string) $this->endpoint('revoke'), ['token' => $token]),
            'revoke_failed',
        );

        return $response->successful();
    }
}
