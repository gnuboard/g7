<?php

namespace Plugins\Sirsoft\Sso\Drivers\Oidc;

use App\Contracts\Extension\CacheInterface;
use App\Support\SiteUrl;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Sso\Contracts\ProtocolDriverInterface;
use Plugins\Sirsoft\Sso\DTO\ConnectionTestResult;
use Plugins\Sirsoft\Sso\DTO\DriverCapabilities;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\DTO\LoginIntent;
use Plugins\Sirsoft\Sso\DTO\LogoutResult;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * 기업용 OpenID Connect 드라이버 (인가 코드 흐름 + PKCE S256).
 *
 * id_token 서명과 클레임 검사는 `IdTokenValidator` 가 한다. 신원의 subject 는 IdP 마다 고른 클레임(`sub` 기본, Entra ID 처럼 앱마다
 * `sub` 가 다른 IdP 는 `oid`)이고, 이메일 검증 표시는 문자열 `"true"` 와 boolean 을 모두 받는다.
 *
 * 로그아웃은 RP-initiated(`end_session_endpoint` + `client_id` + `post_logout_redirect_uri`)다 — id_token 원문을 저장하지 않으므로
 * `id_token_hint` 대신 `client_id` 를 보낸다(규격이 허용, 개인정보 축소).
 *
 * @since 1.0.0
 */
class OidcDriver implements ProtocolDriverInterface
{
    /** 프로토콜 키 */
    public const KEY = 'oidc';

    /** 기본 범위 */
    public const DEFAULT_SCOPES = 'openid email profile';

    /** @var array<int, string> 다중 인증을 뜻하는 amr 값 */
    public const MFA_AMR = ['mfa', 'otp', 'hwk', 'swk'];

    /** back-channel jti 1회 사용 보관(초) */
    private const JTI_TTL = 600;

    /** @var string|null 마지막 토큰 교환의 refresh_token (주기 점검용 — 신원 객체에 싣지 않는다) */
    private ?string $lastRefreshToken = null;

    /**
     * @param  OidcMetadata  $metadata  디스커버리와 JWKS
     * @param  IdTokenValidator  $validator  id_token 검증
     * @param  CacheInterface  $cache  플러그인 캐시 (back-channel jti)
     */
    public function __construct(
        private readonly OidcMetadata $metadata,
        private readonly IdTokenValidator $validator,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 프로토콜 키를 반환합니다.
     *
     * @return string oidc
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * 화면에 보일 프로토콜 이름을 반환합니다.
     *
     * @param  string|null  $locale  로케일
     * @return string 이름
     */
    public function label(?string $locale = null): string
    {
        return (string) __('sirsoft-sso::oidc.label', [], $locale);
    }

    /**
     * 지원 기능을 반환합니다.
     *
     * @return DriverCapabilities 기능 표
     */
    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            redirect: true,
            slo: true,
            backchannel: true,
            mfaSignal: true,
            groups: true,
            periodicCheck: true,
            metadata: true,
        );
    }

    /**
     * 서버에서 쓸 수 있는지 확인합니다 (서명 검증에 openssl 필요).
     *
     * @return bool 쓸 수 있으면 true
     */
    public function isAvailable(): bool
    {
        return extension_loaded('openssl');
    }

    /**
     * 설정 칸 정의를 반환합니다.
     *
     * @return array<int, array<string, mixed>> 칸 정의
     */
    public function configSchema(): array
    {
        $field = static fn (string $key, string $type, array $extra = []): array => array_merge([
            'key' => $key,
            'type' => $type,
            'required' => false,
            'label_key' => 'sirsoft-sso::oidc.config.'.$key.'.label',
            'hint_key' => 'sirsoft-sso::oidc.config.'.$key.'.hint',
        ], $extra);

        return [
            $field('discovery_url', 'url', ['required' => true]),
            $field('client_id', 'string', ['required' => true]),
            $field('client_secret', 'secret', ['required' => true, 'secret' => true]),
            $field('scopes', 'string', ['default' => self::DEFAULT_SCOPES]),
            $field('subject_claim', 'select', ['default' => 'sub', 'options' => ['sub', 'oid', 'custom']]),
            $field('subject_claim_custom', 'string'),
            $field('claim_email', 'string', ['default' => 'email']),
            $field('claim_email_verified', 'string', ['default' => 'email_verified']),
            $field('claim_name', 'string', ['default' => 'name']),
            $field('claim_groups', 'string', ['default' => 'groups']),
            $field('mfa_acr_values', 'list', ['default' => []]),
            $field('use_pkce', 'boolean', ['default' => true]),
            $field('token_endpoint_auth', 'select', ['default' => 'client_secret_post', 'options' => ['client_secret_post', 'client_secret_basic']]),
            $field('fetch_userinfo', 'boolean', ['default' => false]),
        ];
    }

    /**
     * 설정값을 검증합니다.
     *
     * @param  array<string, mixed>  $config  비밀이 아닌 설정
     * @param  array<string, mixed>  $secrets  비밀값
     * @return array<string, string> 칸 키 → 오류 문구 키
     */
    public function validateConfig(array $config, array $secrets): array
    {
        $errors = [];
        $url = (string) ($config['discovery_url'] ?? '');
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false || ! preg_match('#^https?://#i', $url)) {
            $errors['discovery_url'] = 'sirsoft-sso::validation.url';
        }
        if (trim((string) ($config['client_id'] ?? '')) === '') {
            $errors['client_id'] = 'sirsoft-sso::validation.required';
        }
        if (trim((string) ($secrets['client_secret'] ?? '')) === '') {
            $errors['client_secret'] = 'sirsoft-sso::validation.required';
        }
        if (($config['subject_claim'] ?? 'sub') === 'custom' && trim((string) ($config['subject_claim_custom'] ?? '')) === '') {
            $errors['subject_claim_custom'] = 'sirsoft-sso::validation.required';
        }
        $auth = (string) ($config['token_endpoint_auth'] ?? 'client_secret_post');
        if (! in_array($auth, ['client_secret_post', 'client_secret_basic'], true)) {
            $errors['token_endpoint_auth'] = 'sirsoft-sso::validation.in';
        }

        return $errors;
    }

    /**
     * IdP 인가 화면으로 보내는 302 를 만듭니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  LoginIntent  $intent  시작 정보
     * @return Response 302
     *
     * @throws SsoException 디스커버리 실패
     */
    public function beginLogin(IdentityProvider $idp, LoginIntent $intent): Response
    {
        $discovery = $this->metadata->discovery($idp);

        $query = [
            'response_type' => 'code',
            'client_id' => (string) $idp->configValue('client_id', ''),
            'redirect_uri' => self::callbackUrl($idp),
            'scope' => $this->scopes($idp),
            'state' => $intent->stateId,
            'nonce' => $intent->nonce,
            'response_mode' => 'query',
        ];

        if ($this->usesPkce($idp)) {
            $query['code_challenge'] = self::codeChallenge($intent->codeVerifier);
            $query['code_challenge_method'] = 'S256';
        }

        // 재확인은 IdP 에 다시 묻도록 한다 — IdP 세션이 살아 있어도 비밀번호를 다시 받는다.
        if ($intent->intent === LoginIntent::REAUTH) {
            $query['prompt'] = 'login';
            $query['max_age'] = '0';
        }

        $endpoint = (string) $discovery['authorization_endpoint'];
        $separator = str_contains($endpoint, '?') ? '&' : '?';

        return redirect()->away($endpoint.$separator.http_build_query($query));
    }

    /**
     * 콜백의 인가 코드를 토큰으로 바꾸고 id_token 을 검증해 신원을 돌려줍니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  Request  $request  콜백 요청
     * @param  LoginIntent|null  $intent  시작 정보
     * @return ExternalIdentity 신원
     *
     * @throws SsoException `access_denied`, `token_error`, `id_token_invalid`
     */
    public function completeLogin(IdentityProvider $idp, Request $request, ?LoginIntent $intent): ExternalIdentity
    {
        if ($intent === null) {
            throw new SsoException('state_expired', 'oidc requires state');
        }

        $error = $request->input('error');
        if (is_string($error) && $error !== '') {
            throw new SsoException($error === 'access_denied' ? 'access_denied' : 'token_error', 'idp error: '.mb_substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $error) ?? '', 0, 50));
        }

        $code = (string) $request->input('code', '');
        if ($code === '' || strlen($code) > 2048) {
            throw new SsoException('token_error', 'code missing');
        }

        $tokens = $this->exchange($idp, $code, $intent);
        $idToken = $tokens['id_token'] ?? null;
        if (! is_string($idToken) || $idToken === '') {
            throw new SsoException('id_token_invalid', 'id_token missing');
        }

        $accessToken = is_string($tokens['access_token'] ?? null) ? $tokens['access_token'] : null;
        $claims = $this->validator->validate($idp, $idToken, $intent->nonce, $accessToken);

        $this->lastRefreshToken = is_string($tokens['refresh_token'] ?? null) && $tokens['refresh_token'] !== '' ? $tokens['refresh_token'] : null;

        $emailClaim = (string) $idp->configValue('claim_email', 'email');
        if (! isset($claims[$emailClaim]) && (bool) $idp->configValue('fetch_userinfo', false) && $accessToken !== null) {
            $claims = array_merge($this->userinfo($idp, $accessToken, (string) ($claims['sub'] ?? '')), $claims);
        }

        return $this->identityFromClaims($idp, $claims);
    }

    /**
     * 클레임에서 신원을 만듭니다 (시험과 userinfo 병합 뒤 공용).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  array<string, mixed>  $claims  검증된 클레임
     * @return ExternalIdentity 신원
     *
     * @throws SsoException subject 가 비면 `id_token_invalid`
     */
    public function identityFromClaims(IdentityProvider $idp, array $claims): ExternalIdentity
    {
        $subjectClaim = match ((string) $idp->configValue('subject_claim', 'sub')) {
            'oid' => 'oid',
            'custom' => (string) $idp->configValue('subject_claim_custom', 'sub'),
            default => 'sub',
        };
        $subject = $claims[$subjectClaim] ?? null;
        if (! is_scalar($subject) || trim((string) $subject) === '' || mb_strlen((string) $subject) > 191) {
            throw new SsoException('id_token_invalid', 'subject claim missing: '.$subjectClaim);
        }

        $email = $claims[(string) $idp->configValue('claim_email', 'email')] ?? null;
        $email = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($email) : null;

        $verifiedRaw = $claims[(string) $idp->configValue('claim_email_verified', 'email_verified')] ?? null;
        $emailVerified = match (true) {
            is_bool($verifiedRaw) => $verifiedRaw,
            is_string($verifiedRaw) => strtolower($verifiedRaw) === 'true',
            default => null,
        };

        $name = $claims[(string) $idp->configValue('claim_name', 'name')] ?? null;

        $attributes = [];
        foreach (['given_name', 'family_name', 'preferred_username', 'locale', 'phone_number', 'department', 'title', 'employee_number'] as $key) {
            if (is_string($claims[$key] ?? null) && $claims[$key] !== '') {
                $attributes[$key] = $claims[$key];
            }
        }

        return new ExternalIdentity(
            subject: (string) $subject,
            subjectFormat: $subjectClaim,
            email: $email,
            emailVerified: $email !== null ? $emailVerified : null,
            displayName: is_string($name) && $name !== '' ? mb_substr($name, 0, 255) : null,
            attributes: $attributes,
            groups: $this->groups($claims[(string) $idp->configValue('claim_groups', 'groups')] ?? null),
            mfa: $this->mfa($idp, $claims),
            sessionRef: is_string($claims['sid'] ?? null) ? mb_substr($claims['sid'], 0, 255) : null,
        );
    }

    /**
     * 마지막 토큰 교환의 refresh_token 을 꺼냅니다 (한 번 꺼내면 비운다).
     *
     * 주기 점검을 켠 IdP 에서만 저장한다 — 신원 객체(훅 인자)에 싣지 않으려고 따로 둔다.
     *
     * @return string|null refresh_token
     */
    public function pullRefreshToken(): ?string
    {
        $token = $this->lastRefreshToken;
        $this->lastRefreshToken = null;

        return $token;
    }

    /**
     * 대기(동의, 연결 확인)를 거쳐 다음 요청에서 회원이 정해질 때, 콜백 요청이 대기 값에 실어 둔 refresh_token 을 되돌려 둡니다.
     *
     * 완료 요청은 새 프로세스라 콜백 때의 필드가 비어 있다 — 되돌려 두면 회원 결정 훅 구독자가 `pullRefreshToken()` 으로 그대로 꺼낸다.
     *
     * @param  string|null  $token  refresh_token
     * @return void 반환값 없음
     */
    public function holdRefreshToken(?string $token): void
    {
        $this->lastRefreshToken = is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * refresh_token 으로 토큰 갱신을 시도합니다 (주기 점검 — `invalid_grant` 면 비활성).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $refreshToken  refresh_token
     * @return array{active: bool, refresh_token: string|null} 활성 여부와 새 refresh_token(회전하는 IdP)
     *
     * @throws SsoException IdP 장애면 `token_error` (비활성으로 오판하지 않는다)
     */
    public function refresh(IdentityProvider $idp, string $refreshToken): array
    {
        $discovery = $this->metadata->discovery($idp);
        $response = $this->tokenRequest($idp, (string) $discovery['token_endpoint'], [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if ($response['status'] >= 400) {
            if (($response['body']['error'] ?? null) === 'invalid_grant') {
                return ['active' => false, 'refresh_token' => null];
            }

            throw new SsoException('token_error', 'refresh status '.$response['status']);
        }

        $next = $response['body']['refresh_token'] ?? null;

        return ['active' => true, 'refresh_token' => is_string($next) && $next !== '' ? $next : $refreshToken];
    }

    /**
     * IdP 로그아웃(RP-initiated)으로 보낼 302 를 만듭니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  SsoSession  $session  끝낼 SSO 세션
     * @return Response|null 302, IdP 가 end_session_endpoint 를 알리지 않으면 null
     */
    public function beginLogout(IdentityProvider $idp, SsoSession $session): ?Response
    {
        try {
            $endpoint = $this->metadata->discovery($idp)['end_session_endpoint'] ?? null;
        } catch (SsoException) {
            return null;
        }

        if (! is_string($endpoint) || $endpoint === '') {
            return null;
        }

        $query = [
            'client_id' => (string) $idp->configValue('client_id', ''),
            'post_logout_redirect_uri' => self::postLogoutRedirectUrl(),
        ];
        $separator = str_contains($endpoint, '?') ? '&' : '?';

        return redirect()->away($endpoint.$separator.http_build_query($query));
    }

    /**
     * back-channel 로그아웃 요청을 검증하고 끝낼 세션을 고릅니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  Request  $request  IdP 요청 (`logout_token` 폼 값)
     * @return LogoutResult 끝낼 세션과 200 응답
     *
     * @throws SsoException 검증 실패, jti 재사용이면 `id_token_invalid` / `response_replayed`
     */
    public function handleLogoutRequest(IdentityProvider $idp, Request $request): LogoutResult
    {
        $token = (string) $request->input('logout_token', '');
        if ($token === '') {
            throw new SsoException('id_token_invalid', 'logout_token missing', 400);
        }

        $claims = $this->validator->validateLogoutToken($idp, $token);

        $jtiKey = 'oidc:jti:'.$idp->getKey().':'.hash('sha256', (string) $claims['jti']);
        if ($this->cache->has($jtiKey)) {
            throw new SsoException('response_replayed', 'logout jti reused', 400);
        }
        $this->cache->put($jtiKey, 1, self::JTI_TTL);

        $sessions = [];
        if (class_exists(SsoSession::class)) {
            $query = SsoSession::query()->where('idp_id', $idp->getKey())->whereNull('ended_at');
            if (is_string($claims['sid'] ?? null)) {
                $query->where('external_session', $claims['sid']);
            } else {
                $query->where('name_id', (string) $claims['sub']);
            }
            $sessions = $query->get()->all();
        }

        return new LogoutResult($sessions, response('', 200, ['Cache-Control' => 'no-store']));
    }

    /**
     * 서버 쪽 연결 시험 — 디스커버리, 필수 메타 키, JWKS.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return ConnectionTestResult 결과
     */
    public function testConnection(IdentityProvider $idp): ConnectionTestResult
    {
        $this->metadata->forget($idp);
        $checks = [];

        try {
            $discovery = $this->metadata->discovery($idp, true);
            $checks[] = ['key' => 'discovery', 'ok' => true, 'message_key' => 'sirsoft-sso::oidc.test.discovery_ok', 'detail' => (string) $discovery['issuer']];
        } catch (SsoException $e) {
            $checks[] = ['key' => 'discovery', 'ok' => false, 'message_key' => 'sirsoft-sso::oidc.test.discovery_failed', 'detail' => $e->getMessage()];

            return ConnectionTestResult::fromChecks($checks);
        }

        $checks[] = ['key' => 'metadata', 'ok' => true, 'message_key' => 'sirsoft-sso::oidc.test.metadata_ok', 'detail' => implode(', ', OidcMetadata::REQUIRED_KEYS)];

        try {
            $count = count($this->metadata->jwks($idp, true)['keys']);
            $checks[] = ['key' => 'jwks', 'ok' => $count > 0, 'message_key' => $count > 0 ? 'sirsoft-sso::oidc.test.jwks_ok' : 'sirsoft-sso::oidc.test.jwks_empty', 'detail' => (string) $count];
        } catch (SsoException $e) {
            $checks[] = ['key' => 'jwks', 'ok' => false, 'message_key' => 'sirsoft-sso::oidc.test.jwks_failed', 'detail' => $e->getMessage()];
        }

        $checks[] = ['key' => 'redirect_uri', 'ok' => true, 'message_key' => 'sirsoft-sso::oidc.test.redirect_uri', 'detail' => self::callbackUrl($idp)];

        return ConnectionTestResult::fromChecks($checks);
    }

    /**
     * 운영자가 IdP 콘솔에 넣을 값을 반환합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return array<int, array{key: string, label_key: string, value: string}> 표시 행
     */
    public function serviceProviderInfo(IdentityProvider $idp): array
    {
        return [
            ['key' => 'redirect_uri', 'label_key' => 'sirsoft-sso::oidc.sp.redirect_uri', 'value' => self::callbackUrl($idp)],
            ['key' => 'post_logout_redirect_uri', 'label_key' => 'sirsoft-sso::oidc.sp.post_logout_redirect_uri', 'value' => self::postLogoutRedirectUrl()],
            ['key' => 'backchannel_logout_uri', 'label_key' => 'sirsoft-sso::oidc.sp.backchannel_logout_uri', 'value' => SiteUrl::absolute('/plugins/sirsoft-sso/oidc/'.$idp->key.'/backchannel-logout')],
            ['key' => 'scopes', 'label_key' => 'sirsoft-sso::oidc.sp.scopes', 'value' => $this->scopes($idp)],
        ];
    }

    /**
     * 콜백 절대 주소 (IdP 콘솔의 Redirect URI — 요청 Host 가 아니라 사이트 주소 기준).
     *
     * @param  IdentityProvider  $idp  IdP
     * @return string 주소
     */
    public static function callbackUrl(IdentityProvider $idp): string
    {
        return SiteUrl::absolute('/plugins/sirsoft-sso/oidc/'.$idp->key.'/callback');
    }

    /**
     * 로그아웃 뒤 복귀 절대 주소.
     *
     * @return string 주소
     */
    public static function postLogoutRedirectUrl(): string
    {
        return SiteUrl::absolute('/plugins/sirsoft-sso/logout/return');
    }

    /**
     * PKCE S256 code_challenge 를 만듭니다.
     *
     * @param  string  $verifier  code_verifier
     * @return string code_challenge
     */
    public static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * 인가 코드를 토큰으로 바꿉니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $code  인가 코드
     * @param  LoginIntent  $intent  시작 정보
     * @return array<string, mixed> 토큰 응답
     *
     * @throws SsoException 교환 실패면 `token_error`
     */
    private function exchange(IdentityProvider $idp, string $code, LoginIntent $intent): array
    {
        $discovery = $this->metadata->discovery($idp);
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::callbackUrl($idp),
        ];
        if ($this->usesPkce($idp)) {
            $form['code_verifier'] = $intent->codeVerifier;
        }

        $response = $this->tokenRequest($idp, (string) $discovery['token_endpoint'], $form);
        if ($response['status'] >= 400 || ! is_array($response['body'])) {
            $error = is_array($response['body']) ? (string) ($response['body']['error'] ?? '') : '';

            throw new SsoException('token_error', 'token status '.$response['status'].($error !== '' ? ' '.mb_substr($error, 0, 40) : ''));
        }

        return $response['body'];
    }

    /**
     * 토큰 주소에 클라이언트 인증을 붙여 요청합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $endpoint  토큰 주소
     * @param  array<string, string>  $form  폼 값
     * @return array{status: int, body: array<string, mixed>|null} 응답
     *
     * @throws SsoException 연결 실패면 `token_error`
     */
    private function tokenRequest(IdentityProvider $idp, string $endpoint, array $form): array
    {
        $clientId = (string) $idp->configValue('client_id', '');
        $secret = (string) ($idp->secret('client_secret') ?? '');
        $request = $this->metadata->http($idp)->asForm();

        if ($idp->configValue('token_endpoint_auth', 'client_secret_post') === 'client_secret_basic') {
            $request = $request->withBasicAuth(rawurlencode($clientId), rawurlencode($secret));
        } else {
            $form['client_id'] = $clientId;
            $form['client_secret'] = $secret;
        }

        try {
            $response = $request->post($endpoint, $form);
        } catch (\Throwable $e) {
            throw new SsoException('token_error', 'token fetch failed: '.class_basename($e), 422, $e);
        }

        $body = $response->json();

        return ['status' => $response->status(), 'body' => is_array($body) ? $body : null];
    }

    /**
     * userinfo 를 받아 클레임으로 돌려줍니다 — `sub` 가 id_token 과 다르면 버린다(규격 §5.3.2).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $accessToken  access_token
     * @param  string  $subject  id_token 의 sub
     * @return array<string, mixed> 클레임
     */
    private function userinfo(IdentityProvider $idp, string $accessToken, string $subject): array
    {
        $endpoint = $this->metadata->discovery($idp)['userinfo_endpoint'] ?? null;
        if (! is_string($endpoint) || $endpoint === '') {
            return [];
        }

        try {
            $body = $this->metadata->http($idp)->withToken($accessToken)->get($endpoint)->json();
        } catch (\Throwable) {
            return [];
        }

        return is_array($body) && ($body['sub'] ?? null) === $subject ? $body : [];
    }

    /**
     * 그룹 클레임을 목록으로 바꿉니다 (배열 또는 공백 구분 문자열).
     *
     * @param  mixed  $raw  클레임 값
     * @return array<int, string> 그룹
     */
    private function groups(mixed $raw): array
    {
        $values = match (true) {
            is_array($raw) => $raw,
            is_string($raw) => preg_split('/\s+/', trim($raw)) ?: [],
            default => [],
        };

        $groups = [];
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $groups[] = mb_substr(trim((string) $value), 0, 255);
            }
        }

        return array_values(array_unique($groups));
    }

    /**
     * 다중 인증 신호를 판정합니다 — amr 에 다중 인증 값 또는 설정한 acr 값. 둘 다 없으면 알 수 없음(null).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  array<string, mixed>  $claims  클레임
     * @return bool|null 다중 인증 여부
     */
    private function mfa(IdentityProvider $idp, array $claims): ?bool
    {
        $amr = $claims['amr'] ?? null;
        $acr = $claims['acr'] ?? null;
        if ($amr === null && $acr === null) {
            return null;
        }

        if (is_array($amr) && array_intersect(array_map('strval', $amr), self::MFA_AMR) !== []) {
            return true;
        }

        $acrValues = $idp->configValue('mfa_acr_values', []);
        if (is_string($acr) && is_array($acrValues) && in_array($acr, array_map('strval', $acrValues), true)) {
            return true;
        }

        return false;
    }

    /**
     * 요청할 범위를 반환합니다 — openid 는 항상 넣는다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return string 공백 구분 범위
     */
    private function scopes(IdentityProvider $idp): string
    {
        $scopes = preg_split('/\s+/', trim((string) $idp->configValue('scopes', self::DEFAULT_SCOPES))) ?: [];
        if (! in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid');
        }

        return implode(' ', array_values(array_unique(array_filter($scopes))));
    }

    /**
     * PKCE 를 쓰는지 확인합니다 (기본 켬).
     *
     * @param  IdentityProvider  $idp  IdP
     * @return bool 쓰면 true
     */
    private function usesPkce(IdentityProvider $idp): bool
    {
        return (bool) $idp->configValue('use_pkce', true);
    }
}
