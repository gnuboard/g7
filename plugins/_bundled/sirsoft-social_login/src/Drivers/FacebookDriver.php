<?php

namespace Plugins\Sirsoft\SocialLogin\Drivers;

use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Plugins\Sirsoft\SocialLogin\DTO\AuthorizationRequest;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\DTO\TokenSet;
use Plugins\Sirsoft\SocialLogin\Enums\WebhookEventType;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;
use Plugins\Sirsoft\SocialLogin\Support\Jwt\Base64Url;

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
     * 페이스북은 거절한 권한을 `auth_type=rerequest` 로 다시 묻는다.
     *
     * @param  array<string, string>  $query  지금까지 만든 인가 파라미터
     * @return array<string, string> 덧붙일 파라미터
     */
    protected function reconsentParams(array $query): array
    {
        return ['auth_type' => 'rerequest'];
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
     * 페이스북 「앱 권한 해제」·「데이터 삭제 요청」 콜백을 검증해 이벤트로 바꿉니다.
     *
     * `signed_request` = base64url(서명).base64url(본문). 서명은 본문 원문(base64url 문자열 그대로)을 앱 비밀값으로
     * HMAC-SHA256 한 값이고 시간 일정 비교로 맞춘다. 본문 `algorithm` 은 HMAC-SHA256 만 받는다. 어느 콜백인지는 라우트가
     * 요청 속성 `webhook_kind`(`deauthorize`·`data_deletion`)로 알려 준다.
     *
     * @param  Request  $request  콜백 요청
     * @return SocialLoginWebhookEvent 저장 전 이벤트
     *
     * @throws ProviderException 서명(`webhook_signature_invalid`)·본문(`webhook_payload_invalid`)
     */
    public function verifyWebhook(Request $request): ?SocialLoginWebhookEvent
    {
        $secret = $this->clientSecret();
        $parts = explode('.', (string) $request->input('signed_request', ''), 2);
        if ($secret === '' || count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new ProviderException('webhook_signature_invalid');
        }

        $signature = Base64Url::decode($parts[0]);
        if ($signature === '' || ! hash_equals(hash_hmac('sha256', $parts[1], $secret, true), $signature)) {
            throw new ProviderException('webhook_signature_invalid');
        }

        $payload = json_decode(Base64Url::decode($parts[1]), true);
        if (! is_array($payload) || strtoupper((string) ($payload['algorithm'] ?? '')) !== 'HMAC-SHA256') {
            throw new ProviderException('webhook_payload_invalid');
        }

        $userId = NormalizedProfile::str($payload['user_id'] ?? null);
        if ($userId === null) {
            throw new ProviderException('webhook_payload_invalid');
        }

        $deletion = $request->attributes->get('webhook_kind') === 'data_deletion';
        $issuedAt = (string) (int) ($payload['issued_at'] ?? 0);

        return new SocialLoginWebhookEvent([
            'provider_key' => $this->key(),
            'event_key' => 'facebook:'.($deletion ? 'deletion' : 'deauth').':'.$userId.':'.$issuedAt,
            'event_type' => ($deletion ? WebhookEventType::DataDeletion : WebhookEventType::Deauthorize)->value,
            'provider_user_id' => $userId,
            'payload' => ['issued_at' => $issuedAt],
        ]);
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
