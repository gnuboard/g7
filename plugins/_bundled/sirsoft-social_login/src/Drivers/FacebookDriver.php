<?php

namespace Plugins\Sirsoft\SocialLogin\Drivers;

use Illuminate\Http\Client\Response;
use Plugins\Sirsoft\SocialLogin\DTO\AuthorizationRequest;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\DTO\TokenSet;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;

/**
 * 페이스북 로그인 드라이버.
 *
 * 토큰 교환은 GET 이고, Graph API 호출에는 `appsecret_proof`(접근 토큰을 앱 비밀값으로 HMAC-SHA256)를 붙인다.
 * 이메일 검증 여부는 알려 주지 않는다(emailVerified = null). Graph API 버전은 코드가 아니라 설정에 둔다.
 *
 * @since 1.0.0
 */
class FacebookDriver extends AbstractOAuth2Driver
{
    /** 설정이 비었을 때의 Graph API 버전 */
    public const DEFAULT_GRAPH_VERSION = 'v26.0';

    /**
     * 드라이버 이름을 반환합니다.
     *
     * @return string facebook
     */
    public function driverName(): string
    {
        return 'facebook';
    }

    /**
     * Graph API 버전을 반환합니다.
     *
     * @return string 버전 (예 v26.0)
     */
    protected function graphVersion(): string
    {
        $version = (string) ($this->config['graph_api_version'] ?? '');

        return preg_match('/^v\d+\.\d+$/', $version) === 1 ? $version : self::DEFAULT_GRAPH_VERSION;
    }

    /**
     * 기본 주소를 반환합니다.
     *
     * @return array<string, string|null> 주소
     */
    protected function defaultEndpoints(): array
    {
        $v = $this->graphVersion();

        return [
            'authorize' => "https://www.facebook.com/{$v}/dialog/oauth",
            'token' => "https://graph.facebook.com/{$v}/oauth/access_token",
            'userinfo' => "https://graph.facebook.com/{$v}/me",
            'revoke' => "https://graph.facebook.com/{$v}/me/permissions",
        ];
    }

    /**
     * 지원 기능을 반환합니다.
     *
     * @return array<string, mixed> 지원 기능
     */
    public function supports(): array
    {
        return ['pkce' => false, 'refresh' => false, 'revoke' => true, 'webhook' => true] + parent::supports();
    }

    /**
     * 기본 요청 범위를 반환합니다.
     *
     * @return array<int, string> 범위 목록
     */
    protected function defaultScopes(): array
    {
        return ['email', 'public_profile'];
    }

    /**
     * 인가 코드를 토큰으로 바꿉니다 — 페이스북은 GET 입니다.
     *
     * @param  string  $code  인가 코드
     * @param  AuthorizationRequest  $req  시작 정보
     * @return TokenSet 토큰
     *
     * @throws ProviderException 교환 실패
     */
    public function exchangeCode(string $code, AuthorizationRequest $req): TokenSet
    {
        $response = $this->send(fn () => $this->http()->get((string) $this->endpoint('token'), [
            'client_id' => (string) ($this->config['client_id'] ?? ''),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $req->callbackUrl,
            'code' => $code,
        ]), 'token_exchange_failed');

        return $this->tokenSetFrom($response, 'token_exchange_failed');
    }

    /**
     * 프로필을 가져와 정규화합니다.
     *
     * @param  TokenSet  $tokens  토큰
     * @param  AuthorizationRequest  $req  시작 정보
     * @param  array<string, mixed>  $callbackInput  콜백 입력
     * @return NormalizedProfile 정규화 프로필
     *
     * @throws ProviderException 조회 실패
     */
    public function fetchProfile(TokenSet $tokens, AuthorizationRequest $req, array $callbackInput = []): NormalizedProfile
    {
        $response = $this->send(fn () => $this->http()->get((string) $this->endpoint('userinfo'), [
            'fields' => 'id,name,email,picture',
            'access_token' => $tokens->accessToken,
            'appsecret_proof' => $this->appSecretProof($tokens->accessToken),
        ]), 'profile_fetch_failed');

        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || isset($body['error'])) {
            throw $this->errorFrom($response, 'profile_fetch_failed');
        }

        return $this->normalizeProfile($body, $tokens, $req);
    }

    /**
     * 프로필 응답을 정규화합니다.
     *
     * @param  array<string, mixed>  $body  `/me` 응답
     * @param  TokenSet  $tokens  토큰
     * @param  AuthorizationRequest  $req  시작 정보
     * @return NormalizedProfile 정규화 프로필
     */
    protected function normalizeProfile(array $body, TokenSet $tokens, AuthorizationRequest $req): NormalizedProfile
    {
        return new NormalizedProfile(
            providerUserId: $this->requireId($body['id'] ?? null),
            email: NormalizedProfile::email($body['email'] ?? null),
            emailVerified: null,
            name: NormalizedProfile::str($body['name'] ?? null),
            nickname: null,
            avatarUrl: NormalizedProfile::str($body['picture']['data']['url'] ?? null),
            raw: $body,
        );
    }

    /**
     * 앱 권한을 회수합니다.
     *
     * @param  TokenSet  $tokens  토큰
     * @param  SocialAccount  $account  연결
     * @return bool 성공 여부
     */
    public function revoke(TokenSet $tokens, SocialAccount $account): bool
    {
        if ($tokens->accessToken === '') {
            return false;
        }

        $url = (string) $this->endpoint('revoke');
        $response = $this->send(fn () => $this->http()->delete($url.'?'.http_build_query([
            'access_token' => $tokens->accessToken,
            'appsecret_proof' => $this->appSecretProof($tokens->accessToken),
        ])), 'revoke_failed');

        return $response->successful();
    }

    /**
     * Graph API 오류 본문(`{error:{code,message}}`)을 읽습니다.
     *
     * @param  Response  $response  응답
     * @param  string  $fallbackCode  기본 오류 코드
     * @return ProviderException 예외
     */
    protected function errorFrom(Response $response, string $fallbackCode): ProviderException
    {
        $error = $response->json('error');
        if (is_array($error)) {
            $code = $error['code'] ?? null;

            return new ProviderException(
                is_scalar($code) && (string) $code !== '' ? 'facebook_'.$code : $fallbackCode,
                mb_substr((string) ($error['message'] ?? ''), 0, 500),
                $response->status(),
            );
        }

        return parent::errorFrom($response, $fallbackCode);
    }

    /**
     * appsecret_proof 를 만듭니다.
     *
     * @param  string  $accessToken  접근 토큰
     * @return string HMAC-SHA256 16진수
     */
    protected function appSecretProof(string $accessToken): string
    {
        return hash_hmac('sha256', $accessToken, $this->clientSecret());
    }
}
