<?php

namespace Plugins\Sirsoft\SocialLogin\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\SocialLogin\Contracts\ProviderDriverInterface;
use Plugins\Sirsoft\SocialLogin\DTO\AuthorizationRequest;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\DTO\TokenSet;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;
use Plugins\Sirsoft\SocialLogin\Support\Jwt\Base64Url;

/**
 * OAuth 2.0 인가 코드 흐름 공통 구현.
 *
 * 제공자별 드라이버는 주소(`defaultEndpoints`)와 프로필 정규화(`normalizeProfile`)만 채우고, 다른 부분은
 * 필요할 때만 덮어쓴다. 주소는 설정 `extra.endpoint_override` 가 있으면 그 값을 먼저 쓴다 — 모의 제공자가
 * 실제 드라이버를 그대로 쓰면서 주소만 바꾸는 통로다.
 *
 * @since 1.0.0
 */
abstract class AbstractOAuth2Driver implements ProviderDriverInterface
{
    /** 외부 요청 제한 시간(초) */
    protected const HTTP_TIMEOUT_SECONDS = 10;

    /** @var array<string, mixed> 정규화한 설정 */
    protected array $config = [];

    /**
     * 기본 주소를 반환합니다.
     *
     * @return array<string, string|null> authorize·token·userinfo·revoke·jwks·issuer
     */
    abstract protected function defaultEndpoints(): array;

    /**
     * 프로필 응답을 정규화합니다.
     *
     * @param  array<string, mixed>  $body  프로필 응답 본문
     * @param  TokenSet  $tokens  토큰
     * @param  AuthorizationRequest  $req  시작 정보
     * @return NormalizedProfile 정규화 프로필
     *
     * @throws ProviderException 제공자 사용자 ID 가 없을 때
     */
    abstract protected function normalizeProfile(array $body, TokenSet $tokens, AuthorizationRequest $req): NormalizedProfile;

    /**
     * 제공자 키를 반환합니다.
     *
     * @return string 제공자 키
     */
    public function key(): string
    {
        return (string) ($this->config['key'] ?? $this->driverName());
    }

    /**
     * 드라이버 이름을 반환합니다.
     *
     * @return string 드라이버 이름 (kakao·naver·google·facebook·oidc)
     */
    abstract public function driverName(): string;

    /**
     * 표시 이름을 반환합니다.
     *
     * @param  string|null  $locale  로케일
     * @return string 표시 이름
     */
    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $label = $this->config['label'] ?? [];

        return (string) ($label[$locale] ?? $label['ko'] ?? $this->key());
    }

    /**
     * 지원 기능을 반환합니다.
     *
     * @return array<string, mixed> 지원 기능
     */
    public function supports(): array
    {
        return [
            'pkce' => true,
            'oidc' => false,
            'refresh' => true,
            'revoke' => false,
            'webhook' => false,
            'callback_methods' => ['GET'],
        ];
    }

    /**
     * 설정 화면 자격증명 필드 정의를 반환합니다.
     *
     * @return array<int, array<string, mixed>> 필드 정의
     */
    public function credentialSchema(): array
    {
        return [
            ['key' => 'client_id', 'type' => 'text', 'required' => true, 'label' => ['ko' => '클라이언트 ID', 'en' => 'Client ID']],
            ['key' => 'client_secret', 'type' => 'secret', 'required' => true, 'label' => ['ko' => '클라이언트 비밀값', 'en' => 'Client Secret']],
        ];
    }

    /**
     * 콜백 주소 주의사항 다국어 키를 반환합니다.
     *
     * @return array<int, string> 다국어 키
     */
    public function redirectNotes(): array
    {
        return ['sirsoft-social_login::messages.redirect_notes.exact_match'];
    }

    /**
     * 설정을 입힌 새 인스턴스를 반환합니다.
     *
     * @param  SocialLoginProvider|array<string, mixed>  $config  제공자 행 또는 설정 배열
     * @return static 설정된 드라이버
     */
    public function withConfig(SocialLoginProvider|array $config): static
    {
        $clone = clone $this;

        if ($config instanceof SocialLoginProvider) {
            $config = array_merge($config->attributesToArray(), [
                'client_secret' => $config->client_secret,
                'admin_key' => $config->admin_key,
            ]);
        }

        $clone->config = $config;

        return $clone;
    }

    /**
     * 비밀값이 아닌 제공자 설정 값 하나를 반환합니다 (가입 허용·이메일 정책·버튼 모양 등).
     *
     * 테이블에 행이 없는 제공자(모의 제공자처럼 확장이 등록한 것)도 같은 정책 판정을 받게 한다. 자격증명은 돌려주지 않는다.
     *
     * @param  string  $name  설정 이름
     * @param  mixed  $default  없을 때 값
     * @return mixed 설정 값
     */
    public function setting(string $name, mixed $default = null): mixed
    {
        if (in_array($name, ['client_secret', 'admin_key'], true)) {
            return $default;
        }

        return $this->config[$name] ?? $default;
    }

    /**
     * 클라이언트 비밀값을 반환합니다.
     *
     * @return string 비밀값
     */
    public function clientSecret(): string
    {
        return (string) ($this->config['client_secret'] ?? '');
    }

    /**
     * 주소 하나를 반환합니다 — 설정 덮어쓰기가 있으면 그 값.
     *
     * @param  string  $name  authorize·token·userinfo·revoke·jwks·issuer
     * @return string|null 주소
     */
    public function endpoint(string $name): ?string
    {
        $override = $this->config['extra']['endpoint_override'][$name] ?? null;
        if (is_string($override) && $override !== '') {
            return $override;
        }

        return $this->defaultEndpoints()[$name] ?? null;
    }

    /**
     * 요청 범위 목록을 반환합니다.
     *
     * @return array<int, string> 범위 목록
     */
    protected function scopes(): array
    {
        $scopes = $this->config['scopes'] ?? null;

        return is_array($scopes) && $scopes !== [] ? array_values($scopes) : $this->defaultScopes();
    }

    /**
     * 기본 요청 범위를 반환합니다.
     *
     * @return array<int, string> 범위 목록
     */
    protected function defaultScopes(): array
    {
        return [];
    }

    /**
     * 인가 화면 주소를 만듭니다.
     *
     * @param  AuthorizationRequest  $req  시작 정보
     * @return string 인가 주소
     */
    public function authorizeUrl(AuthorizationRequest $req): string
    {
        $query = [
            'response_type' => 'code',
            'client_id' => (string) ($this->config['client_id'] ?? ''),
            'redirect_uri' => $req->callbackUrl,
            'state' => $req->state,
        ];

        $scopes = $this->scopes();
        if ($scopes !== []) {
            $query['scope'] = implode(' ', $scopes);
        }

        if ($req->codeVerifier !== null && $this->supports()['pkce']) {
            $query['code_challenge'] = Base64Url::encode(hash('sha256', $req->codeVerifier, true));
            $query['code_challenge_method'] = 'S256';
        }

        if ($req->nonce !== null) {
            $query['nonce'] = $req->nonce;
        }

        $query += $this->extraAuthorizeParams($req);

        $base = (string) $this->endpoint('authorize');

        return $base.(str_contains($base, '?') ? '&' : '?').http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 제공자별 추가 인가 파라미터를 반환합니다.
     *
     * @param  AuthorizationRequest  $req  시작 정보
     * @return array<string, string> 추가 파라미터
     */
    protected function extraAuthorizeParams(AuthorizationRequest $req): array
    {
        return [];
    }

    /**
     * 인가 코드를 토큰으로 바꿉니다.
     *
     * @param  string  $code  인가 코드
     * @param  AuthorizationRequest  $req  시작 정보
     * @return TokenSet 토큰
     *
     * @throws ProviderException 교환 실패
     */
    public function exchangeCode(string $code, AuthorizationRequest $req): TokenSet
    {
        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $req->callbackUrl,
            'client_id' => (string) ($this->config['client_id'] ?? ''),
            'client_secret' => $this->clientSecret(),
        ];

        if ($req->codeVerifier !== null && $this->supports()['pkce']) {
            $params['code_verifier'] = $req->codeVerifier;
        }

        $params += $this->extraTokenParams($req);

        $response = $this->send(fn () => $this->http()->asForm()->post((string) $this->endpoint('token'), $params), 'token_exchange_failed');

        return $this->tokenSetFrom($response, 'token_exchange_failed');
    }

    /**
     * 제공자가 토큰 발급 요청에 더 요구하는 값을 반환합니다 (기본 없음 — 공통 값은 덮어쓰지 않는다).
     *
     * @param  AuthorizationRequest  $req  시작 정보
     * @return array<string, string> 추가 요청 값
     */
    protected function extraTokenParams(AuthorizationRequest $req): array
    {
        return [];
    }

    /**
     * 토큰 응답을 검사해 정규화합니다.
     *
     * 오류가 200 으로 오는 제공자(네이버)도 있어 상태 코드와 본문을 함께 본다.
     *
     * @param  Response  $response  응답
     * @param  string  $fallbackCode  기본 오류 코드
     * @return TokenSet 토큰
     *
     * @throws ProviderException 오류 응답
     */
    protected function tokenSetFrom(Response $response, string $fallbackCode): TokenSet
    {
        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || isset($body['error']) || empty($body['access_token'])) {
            throw $this->errorFrom($response, $fallbackCode);
        }

        return TokenSet::fromResponse($body);
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
        $response = $this->send(
            fn () => $this->http()->withToken($tokens->accessToken)->get((string) $this->endpoint('userinfo')),
            'profile_fetch_failed',
        );

        $body = $response->json();
        if (! $response->successful() || ! is_array($body)) {
            throw $this->errorFrom($response, 'profile_fetch_failed');
        }

        return $this->normalizeProfile($body, $tokens, $req);
    }

    /**
     * 제공자 쪽 연결을 끊습니다 (기본: 지원하지 않음).
     *
     * @param  TokenSet  $tokens  토큰
     * @param  SocialAccount  $account  연결
     * @return bool 성공 여부
     */
    public function revoke(TokenSet $tokens, SocialAccount $account): bool
    {
        return false;
    }

    /**
     * 웹훅 요청을 검증합니다 (기본: 지원하지 않음).
     *
     * @param  Request  $request  웹훅 요청
     * @return SocialLoginWebhookEvent|null 이벤트
     */
    public function verifyWebhook(Request $request): ?SocialLoginWebhookEvent
    {
        return null;
    }

    /**
     * 공통 HTTP 클라이언트를 만듭니다.
     *
     * @return PendingRequest 클라이언트
     */
    protected function http(): PendingRequest
    {
        $client = Http::timeout(self::HTTP_TIMEOUT_SECONDS)->acceptJson();

        // 모의 제공자는 이 사이트 자신을 부른다. 개발 환경의 자체 서명 인증서에서 TLS 검증이 실패하므로 모의일 때만 끈다.
        // 실제 제공자 요청은 언제나 검증한다.
        return $this->isMock() ? $client->withoutVerifying() : $client;
    }

    /**
     * 모의 제공자 설정인지 확인합니다 (`extra.mock` — 모의 리스너만 넣는다).
     *
     * @return bool 모의 제공자면 true
     */
    public function isMock(): bool
    {
        return ($this->config['extra']['mock'] ?? false) === true;
    }

    /**
     * 요청을 보내고 연결 실패를 제공자 오류로 바꿉니다.
     *
     * @param  callable(): Response  $call  요청
     * @param  string  $code  연결 실패 때의 오류 코드
     * @return Response 응답
     *
     * @throws ProviderException 연결 실패
     */
    protected function send(callable $call, string $code): Response
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            throw new ProviderException($code, 'connection_failed', null, $e);
        }
    }

    /**
     * 오류 응답을 제공자 예외로 바꿉니다.
     *
     * 본문의 제공자 코드(카카오 `error_code`, OAuth `error`)를 우선 쓰고, 없으면 기본 코드를 쓴다.
     * 메시지는 제공자가 준 설명을 500자까지만 남긴다(비밀값은 응답 본문에 오지 않는다).
     *
     * @param  Response  $response  응답
     * @param  string  $fallbackCode  기본 오류 코드
     * @return ProviderException 예외
     */
    protected function errorFrom(Response $response, string $fallbackCode): ProviderException
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        $code = $body['error_code'] ?? $body['error'] ?? null;
        $code = is_scalar($code) && (string) $code !== '' ? (string) $code : $fallbackCode;
        $message = $body['error_description'] ?? $body['msg'] ?? $body['message'] ?? '';

        return new ProviderException($code, mb_substr(is_scalar($message) ? (string) $message : '', 0, 500), $response->status());
    }

    /**
     * 제공자 사용자 ID 를 문자열로 읽고, 없으면 실패시킵니다.
     *
     * @param  mixed  $value  원본 ID (정수 ID 도 문자열로)
     * @return string 제공자 사용자 ID
     *
     * @throws ProviderException ID 가 없을 때
     */
    protected function requireId(mixed $value): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            // PHP 정수 범위를 넘어 실수로 디코딩된 ID — 지수 표기 없이 정수 자릿수로 쓴다.
            $value = number_format($value, 0, '', '');
        }

        $id = NormalizedProfile::str($value);
        if ($id === null) {
            throw new ProviderException('profile_missing_id');
        }

        return $id;
    }
}
