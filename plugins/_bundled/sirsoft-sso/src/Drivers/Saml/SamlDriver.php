<?php

namespace Plugins\Sirsoft\Sso\Drivers\Saml;

use App\Contracts\Extension\CacheInterface;
use App\Support\SiteUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\AuthnRequest;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Error as SamlError;
use OneLogin\Saml2\LogoutRequest;
use OneLogin\Saml2\LogoutResponse;
use OneLogin\Saml2\Response as SamlResponse;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use OneLogin\Saml2\ValidationError;
use Plugins\Sirsoft\Sso\Contracts\ProtocolDriverInterface;
use Plugins\Sirsoft\Sso\DTO\ConnectionTestResult;
use Plugins\Sirsoft\Sso\DTO\DriverCapabilities;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\DTO\LoginIntent;
use Plugins\Sirsoft\Sso\DTO\LogoutResult;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoSession;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Services\SafeRedirect;
use Plugins\Sirsoft\Sso\Services\StateStore;
use Plugins\Sirsoft\Sso\Support\BridgeScript;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Symfony\Component\HttpFoundation\Response;

/**
 * SAML 2.0 SP 드라이버.
 *
 * 서명, 암호, 스키마, 시각, 대상, 발급자 검증은 전부 `onelogin/php-saml` 이 한다 — 이 드라이버는 XML 서명을 직접 다루지 않는다.
 * 라이브러리 `Auth` 의 응답 처리 메서드는 PHP 전역 값으로 현재 주소를 만들므로 쓰지 않고, `Response` + `isValid()` 를 부른다.
 * 현재 주소는 사이트 주소(SiteUrl)와 이 IdP 의 고정 경로로 맞춘다(`SamlEnvironment`). 시작과 로그아웃 주소는 `stay: true` 로
 * 받아 우리가 302 를 만든다(라이브러리가 `exit` 하지 않게).
 *
 * SP-initiated 응답은 시작 때 state 에 넣어 둔 AuthnRequest ID 와 `InResponseTo` 를 대조한다. IdP-initiated 는 IdP 설정이 켜져
 * 있을 때만 받고, 응답(어설션) ID 를 유효 시간까지 1회만 받는다.
 *
 * @since 1.0.0
 */
class SamlDriver implements ProtocolDriverInterface
{
    /** 프로토콜 키 */
    public const KEY = 'saml';

    /** 처리 전 응답 크기 상한(디코드 뒤 바이트) */
    public const MAX_RESPONSE_BYTES = 262144;

    /** 이전 SP 키를 함께 쓰는 기간(일) — 키 교체 뒤 IdP 가 새 인증서를 받아 갈 때까지 */
    public const PREVIOUS_KEY_DAYS = 30;

    /** SP 인증서 유효 기간(일) */
    public const SP_CERT_DAYS = 3650;

    /** 서명 다이제스트 알고리즘 URI (SHA-256) — 라이브러리 설정 값 */
    private const DIGEST_SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';

    /** SP 발 로그아웃 뒤 IdP 가 돌려보내는 G7 경로 */
    public const LOGOUT_RETURN_PATH = '/plugins/sirsoft-sso/logout/return';

    /** state 에 넣는 AuthnRequest ID 칸 이름 */
    public const EXTRA_REQUEST_ID = 'saml_request_id';

    /** @var array<string, string> NameID 형식 선택값 → URN */
    public const NAME_ID_FORMATS = [
        'persistent' => Constants::NAMEID_PERSISTENT,
        'emailAddress' => Constants::NAMEID_EMAIL_ADDRESS,
        'unspecified' => Constants::NAMEID_UNSPECIFIED,
    ];

    /**
     * @param  SamlMetadata  $metadata  IdP 메타데이터
     * @param  StateStore  $states  state 저장 (AuthnRequest ID 보관)
     * @param  CacheInterface  $cache  플러그인 캐시 (응답 1회 사용)
     * @param  SsoAccountRepositoryInterface  $accounts  연결 저장소 (SP 발 로그아웃의 NameID 형식)
     */
    public function __construct(
        private readonly SamlMetadata $metadata,
        private readonly StateStore $states,
        private readonly CacheInterface $cache,
        private readonly SsoAccountRepositoryInterface $accounts,
    ) {}

    /**
     * 프로토콜 키를 반환합니다.
     *
     * @return string saml
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
        return (string) __('sirsoft-sso::saml.label', [], $locale);
    }

    /**
     * 지원 기능을 반환합니다.
     *
     * 재직 여부 주기 점검은 SAML 자체로는 할 수 없고 IdP 설정의 「재직 조회용 디렉터리」(같은 회사의 LDAP IdP)를 고른 경우에만 된다.
     *
     * @return DriverCapabilities 기능 표
     */
    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            redirect: true,
            slo: true,
            backchannel: true,
            idpInitiated: true,
            mfaSignal: true,
            groups: true,
            periodicCheck: true,
            metadata: true,
        );
    }

    /**
     * 서버에서 쓸 수 있는지 확인합니다 (서명, 압축, XML 처리에 openssl, zlib, dom 필요).
     *
     * @return bool 쓸 수 있으면 true
     */
    public function isAvailable(): bool
    {
        return extension_loaded('openssl') && extension_loaded('dom') && extension_loaded('zlib')
            && class_exists(SamlResponse::class);
    }

    /**
     * 설정 칸 정의를 반환합니다.
     *
     * `lookup_directory_idp_id` 의 선택지는 같은 사이트의 LDAP IdP 목록이다(`options_source: ldap_idps` — 화면이 채운다).
     *
     * @return array<int, array<string, mixed>> 칸 정의
     */
    public function configSchema(): array
    {
        $field = static fn (string $key, string $type, array $extra = []): array => array_merge([
            'key' => $key,
            'type' => $type,
            'required' => false,
            'label_key' => 'sirsoft-sso::saml.config.'.$key.'.label',
            'hint_key' => 'sirsoft-sso::saml.config.'.$key.'.hint',
        ], $extra);

        return [
            $field('idp_metadata_url', 'url'),
            $field('idp_metadata_xml', 'textarea'),
            $field('sp_entity_id', 'string'),
            $field('name_id_format', 'select', ['default' => 'persistent', 'options' => array_keys(self::NAME_ID_FORMATS)]),
            $field('subject_source', 'select', ['default' => 'name_id', 'options' => ['name_id', 'attribute']]),
            $field('subject_attribute', 'string'),
            $field('attr_email', 'string', ['default' => 'email']),
            $field('attr_name', 'string', ['default' => 'displayName']),
            $field('attr_groups', 'string', ['default' => 'groups']),
            $field('authn_request_binding', 'select', ['default' => 'redirect', 'options' => ['redirect', 'post']]),
            $field('want_assertions_signed', 'boolean', ['default' => true, 'readonly' => true]),
            $field('want_messages_signed', 'boolean', ['default' => false]),
            $field('sign_authn_requests', 'boolean', ['default' => false]),
            $field('want_assertions_encrypted', 'boolean', ['default' => false]),
            $field('requested_authn_context', 'list', ['default' => []]),
            $field('mfa_authn_contexts', 'list', ['default' => []]),
            $field('lookup_directory_idp_id', 'select', ['options' => [], 'options_source' => 'ldap_idps']),
            $field('lookup_match', 'select', ['default' => 'email', 'options' => ['email', 'subject']]),
            $field('sp_certificate', 'secret', ['secret' => true]),
            $field('sp_private_key', 'secret', ['secret' => true]),
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
        $url = trim((string) ($config['idp_metadata_url'] ?? ''));
        $xml = trim((string) ($config['idp_metadata_xml'] ?? ''));

        if ($url === '' && $xml === '') {
            $errors['idp_metadata_url'] = 'sirsoft-sso::saml.validation.metadata_required';
        }
        if ($url !== '' && (filter_var($url, FILTER_VALIDATE_URL) === false || ! preg_match('#^https?://#i', $url))) {
            $errors['idp_metadata_url'] = 'sirsoft-sso::validation.url';
        }
        // 붙여 넣은 XML 은 주소보다 먼저 쓰인다 — 로그인 때와 같은 파서로 읽어 보고 깨졌으면 저장 때 거절한다(주소는 저장 때 받지 않는다)
        if ($xml !== '' && strlen($xml) > SamlMetadata::MAX_BYTES) {
            $errors['idp_metadata_xml'] = 'sirsoft-sso::saml.validation.metadata_too_large';
        } elseif ($xml !== '') {
            try {
                $info = SamlMetadata::parse($xml);
                // 서명 인증서는 있는지만이 아니라 읽히는지까지 — 못 읽는 인증서는 로그인 때 서명 검증이 모두 실패한다
                foreach (SamlMetadata::signingCertificates($info) as $certificate) {
                    if (@openssl_x509_read(SamlMetadata::pem($certificate)) === false) {
                        throw new SsoException('saml_invalid', 'metadata signing certificate unreadable');
                    }
                }
            } catch (SsoException) {
                $errors['idp_metadata_xml'] = 'sirsoft-sso::saml.validation.metadata_invalid';
            }
        }

        $entityId = trim((string) ($config['sp_entity_id'] ?? ''));
        if ($entityId !== '' && mb_strlen($entityId) > 255) {
            $errors['sp_entity_id'] = 'sirsoft-sso::saml.validation.entity_id_too_long';
        }

        foreach (['name_id_format' => array_keys(self::NAME_ID_FORMATS), 'subject_source' => ['name_id', 'attribute'], 'authn_request_binding' => ['redirect', 'post'], 'lookup_match' => ['email', 'subject']] as $key => $allowed) {
            if (isset($config[$key]) && $config[$key] !== '' && ! in_array($config[$key], $allowed, true)) {
                $errors[$key] = 'sirsoft-sso::validation.in';
            }
        }

        if (($config['subject_source'] ?? 'name_id') === 'attribute' && trim((string) ($config['subject_attribute'] ?? '')) === '') {
            $errors['subject_attribute'] = 'sirsoft-sso::validation.required';
        }

        if (array_key_exists('want_assertions_signed', $config) && $config['want_assertions_signed'] === false) {
            $errors['want_assertions_signed'] = 'sirsoft-sso::saml.validation.assertions_must_be_signed';
        }

        $needsKey = (bool) ($config['sign_authn_requests'] ?? false) || (bool) ($config['want_assertions_encrypted'] ?? false);
        $hasKey = trim((string) ($secrets['sp_private_key'] ?? '')) !== '' && trim((string) ($secrets['sp_certificate'] ?? '')) !== '';
        if ($needsKey && ! $hasKey) {
            $errors['sp_private_key'] = 'sirsoft-sso::saml.validation.sp_key_required';
        }

        if ($hasKey && ! self::keyMatchesCertificate((string) $secrets['sp_private_key'], (string) $secrets['sp_certificate'])) {
            $errors['sp_certificate'] = 'sirsoft-sso::saml.validation.sp_key_mismatch';
        }

        return $errors;
    }

    /**
     * IdP 로 보내는 응답을 만듭니다 — Redirect 바인딩이면 302, POST 바인딩이면 자동 제출 폼.
     *
     * AuthnRequest ID 를 state 에 더해 두고 응답의 `InResponseTo` 와 대조한다. 재확인 의도면 IdP 에 다시 인증을 요구한다
     * (`ForceAuthn`).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  LoginIntent  $intent  시작 정보
     * @return Response 302 또는 폼
     *
     * @throws SsoException 메타데이터, 설정 오류면 `saml_invalid`
     */
    public function beginLogin(IdentityProvider $idp, LoginIntent $intent): Response
    {
        $force = $intent->intent === LoginIntent::REAUTH;

        try {
            $settings = $this->settingsArray($idp);
            if ($idp->configValue('authn_request_binding', 'redirect') === 'post') {
                $settingsObject = new Settings($settings);
                $request = new AuthnRequest($settingsObject, $force);
                $xml = $request->getXML();
                if ($settings['security']['authnRequestsSigned']) {
                    $xml = Utils::addSign($xml, $settingsObject->getSPkey(), $settingsObject->getSPcert());
                }
                $this->states->update($intent->withExtra([self::EXTRA_REQUEST_ID => $request->getId()]));

                return $this->postForm((string) $settings['idp']['singleSignOnService']['url'], [
                    'SAMLRequest' => base64_encode($xml),
                    'RelayState' => $intent->stateId,
                ]);
            }

            $auth = new Auth($settings);
            $url = SamlEnvironment::run(self::acsPath($idp), null, fn () => $auth->login($intent->stateId, [], $force, false, stay: true));
            $this->states->update($intent->withExtra([self::EXTRA_REQUEST_ID => (string) $auth->getLastRequestID()]));
        } catch (\Throwable $e) {
            // 화면에는 오류 코드만 보이므로 운영자 진단용 요약을 남긴다(비밀값, 메타데이터 원문 없음).
            Log::warning('[sirsoft-sso] SAML 로그인 시작 실패', [
                'idp' => $idp->key,
                'reason' => mb_substr($e->getMessage(), 0, 300),
                'cause' => $e->getPrevious() !== null ? class_basename($e->getPrevious()).': '.mb_substr($e->getPrevious()->getMessage(), 0, 300) : null,
            ]);

            throw $e instanceof SsoException ? $e : new SsoException('saml_invalid', 'authn request build failed: '.class_basename($e), 422, $e);
        }

        return redirect()->away((string) $url);
    }

    /**
     * ACS 로 온 응답을 검증하고 신원을 돌려줍니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  Request  $request  ACS 요청 (`SAMLResponse`)
     * @param  LoginIntent|null  $intent  state 로 찾은 시작 정보 (IdP-initiated 면 null)
     * @return ExternalIdentity 신원
     *
     * @throws SsoException `saml_invalid`, `saml_unsolicited`, `response_replayed`, `access_denied`
     */
    public function completeLogin(IdentityProvider $idp, Request $request, ?LoginIntent $intent): ExternalIdentity
    {
        $raw = $request->input('SAMLResponse');
        if (! is_string($raw) || $raw === '' || strlen($raw) > (int) ceil(self::MAX_RESPONSE_BYTES * 4 / 3) + 4) {
            throw new SsoException('saml_invalid', 'SAMLResponse missing or too large');
        }

        $requestId = null;
        if ($intent !== null) {
            $requestId = $intent->extra[self::EXTRA_REQUEST_ID] ?? null;
            if (! is_string($requestId) || $requestId === '') {
                throw new SsoException('saml_invalid', 'request id missing in state');
            }
        } elseif (! $idp->idp_initiated) {
            throw new SsoException('saml_unsolicited', 'idp-initiated disabled');
        }

        try {
            $response = $this->validatedResponse($idp, $raw, $requestId, $intent === null);
            $this->rememberResponse($idp, $response);

            return $this->identityFromResponse($idp, $response);
        } catch (SsoException $e) {
            throw $e;
        } catch (SamlError|ValidationError $e) {
            // 라이브러리 예외가 흐름 서비스의 모르는 예외 처리(token_error)로 가지 않게 SAML 오류로 바꾼다
            throw self::libraryError('response rejected', $e);
        }
    }

    /**
     * php-saml 라이브러리 예외를 `saml_invalid` 로 바꿉니다 (detail 에 라이브러리 오류 코드).
     *
     * @param  string  $stage  실패한 단계
     * @param  \Throwable  $e  라이브러리 예외
     * @return SsoException `saml_invalid`
     */
    private static function libraryError(string $stage, \Throwable $e): SsoException
    {
        return new SsoException('saml_invalid', $stage.': '.class_basename($e).' '.$e->getCode().' '.mb_substr($e->getMessage(), 0, 200), 422, $e);
    }

    /**
     * 검증된 응답에서 신원을 만듭니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  SamlResponse  $response  검증을 마친 응답
     * @return ExternalIdentity 신원
     *
     * @throws SsoException subject 를 정할 수 없으면 `saml_invalid`
     */
    public function identityFromResponse(IdentityProvider $idp, SamlResponse $response): ExternalIdentity
    {
        try {
            $nameIdData = $response->getNameIdData();
        } catch (\Throwable $e) {
            throw new SsoException('saml_invalid', 'NameID missing', 422, $e);
        }

        $nameId = is_string($nameIdData['Value'] ?? null) ? trim($nameIdData['Value']) : '';
        $format = is_string($nameIdData['Format'] ?? null) ? $nameIdData['Format'] : null;
        try {
            $attributes = self::flattenAttributes($response->getAttributes());
        } catch (\Throwable $e) {
            throw self::libraryError('attributes unreadable', $e);
        }

        if ($idp->configValue('subject_source', 'name_id') === 'attribute') {
            $subject = self::first($attributes[(string) $idp->configValue('subject_attribute', '')] ?? null) ?? '';
        } else {
            if ($format === Constants::NAMEID_TRANSIENT) {
                throw new SsoException('saml_invalid', 'transient NameID cannot identify an account');
            }
            $subject = $nameId;
        }

        if ($subject === '' || mb_strlen($subject) > 191) {
            throw new SsoException('saml_invalid', 'subject missing or too long');
        }

        $email = self::first($attributes[(string) $idp->configValue('attr_email', 'email')] ?? null);
        if ($email === null && $format === Constants::NAMEID_EMAIL_ADDRESS) {
            $email = $nameId;
        }
        $email = $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($email) : null;

        $name = self::first($attributes[(string) $idp->configValue('attr_name', 'displayName')] ?? null);
        $groupValues = $attributes[(string) $idp->configValue('attr_groups', 'groups')] ?? [];
        $groups = [];
        foreach (is_array($groupValues) ? $groupValues : [$groupValues] as $group) {
            if (is_string($group) && trim($group) !== '') {
                $groups[] = mb_substr(trim($group), 0, 255);
            }
        }

        $sessionIndex = $response->getSessionIndex();

        return new ExternalIdentity(
            subject: $subject,
            subjectFormat: $format !== null ? mb_substr($format, 0, 100) : null,
            email: $email,
            emailVerified: null,
            displayName: $name !== null && $name !== '' ? mb_substr($name, 0, 255) : null,
            attributes: $attributes,
            groups: array_values(array_unique($groups)),
            mfa: $this->mfa($idp, $response),
            sessionRef: is_string($sessionIndex) && $sessionIndex !== '' ? mb_substr($sessionIndex, 0, 255) : null,
            nameId: $nameId !== '' ? mb_substr($nameId, 0, 255) : null,
        );
    }

    /**
     * IdP 로그아웃(SP-initiated SLO)으로 보낼 302 를 만듭니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  SsoSession  $session  끝낼 SSO 세션 (`name_id`, `external_session`)
     * @return Response|null 302, IdP 가 로그아웃 주소를 알리지 않았거나 NameID 가 없으면 null
     */
    public function beginLogout(IdentityProvider $idp, SsoSession $session): ?Response
    {
        $nameId = is_string($session->name_id ?? null) ? $session->name_id : '';
        if ($nameId === '') {
            return null;
        }

        try {
            $settings = $this->settingsArray($idp);
            if (! is_string($settings['idp']['singleLogoutService']['url'] ?? null)) {
                return null;
            }

            $format = $this->accounts->findByIdpUser((int) $idp->getKey(), (int) ($session->user_id ?? 0))?->subject_format;

            $auth = new Auth($settings);
            $sessionIndex = is_string($session->external_session ?? null) && $session->external_session !== '' ? $session->external_session : null;
            $url = SamlEnvironment::run(self::slsPath($idp), null, fn () => $auth->logout(
                SiteUrl::absolute(self::LOGOUT_RETURN_PATH), [], $nameId, $sessionIndex, stay: true, nameIdFormat: is_string($format) ? $format : null,
            ));
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return redirect()->away((string) $url);
    }

    /**
     * SLS 로 온 요청을 처리합니다.
     *
     * - `SAMLRequest`(IdP 발 로그아웃): 서명이 있어야 받는다(Redirect 바인딩 `Signature` 또는 POST 바인딩 XML 서명) — 서명 없는
     *   요청을 받으면 NameID 만 알아도 남을 로그아웃시킬 수 있다. 검증 뒤 NameID·SessionIndex 로 세션을 고르고 LogoutResponse 로 302.
     * - `SAMLResponse`(우리 LogoutRequest 의 답): 검증 결과와 무관하게 로컬 로그아웃은 이미 끝났으므로 복귀 화면으로 보낸다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  Request  $request  SLS 요청
     * @return LogoutResult 끝낼 세션과 응답
     *
     * @throws SsoException 검증 실패면 `saml_invalid`(400)
     */
    public function handleLogoutRequest(IdentityProvider $idp, Request $request): LogoutResult
    {
        $samlRequest = $request->input('SAMLRequest');
        if (is_string($samlRequest) && $samlRequest !== '') {
            return $this->handleIdpLogoutRequest($idp, $request, $samlRequest);
        }

        $samlResponse = $request->input('SAMLResponse');
        if (is_string($samlResponse) && $samlResponse !== '') {
            try {
                $settings = new Settings($this->settingsArray($idp));
                $logoutResponse = new LogoutResponse($settings, $samlResponse);
                $valid = SamlEnvironment::run(self::slsPath($idp), $request, fn () => $logoutResponse->isValid(null, $request->isMethod('GET')));
                if (! $valid) {
                    report(new SsoException('saml_invalid', 'logout response: '.mb_substr((string) $logoutResponse->getError(), 0, 200)));
                }
            } catch (\Throwable $e) {
                report($e);
            }

            $relay = SafeRedirect::path(is_string($request->input('RelayState')) ? $request->input('RelayState') : null, '');
            $target = str_starts_with($relay, self::LOGOUT_RETURN_PATH) ? $relay : '/login';

            return new LogoutResult([], redirect()->to(SiteUrl::absolute($target)));
        }

        throw new SsoException('saml_invalid', 'SAMLRequest or SAMLResponse required', 400);
    }

    /**
     * 서버 쪽 연결 시험 — 메타데이터 받기와 읽기, IdP 서명 인증서 유효 기간, SSO 주소, SP 메타데이터 주소.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return ConnectionTestResult 결과
     */
    public function testConnection(IdentityProvider $idp): ConnectionTestResult
    {
        // 라이브러리는 ACS·SLS 주소를 PHP URL 검사(FILTER_VALIDATE_URL)로 거른다 — 사이트 호스트에 밑줄(_)이 있으면 모든 SAML 요청이
        // 설정 오류로 끝나므로 먼저 알린다.
        $acs = SiteUrl::absolute(self::acsPath($idp));
        if (filter_var($acs, FILTER_VALIDATE_URL) === false) {
            return ConnectionTestResult::fromChecks([['key' => 'site_url', 'ok' => false, 'message_key' => 'sirsoft-sso::saml.test.site_url_invalid', 'detail' => $acs]]);
        }

        $checks = [];

        try {
            $info = $this->metadata->idp($idp, true);
            $checks[] = ['key' => 'metadata', 'ok' => true, 'message_key' => 'sirsoft-sso::saml.test.metadata_ok', 'detail' => (string) $info['entityId']];
        } catch (SsoException $e) {
            $checks[] = ['key' => 'metadata', 'ok' => false, 'message_key' => 'sirsoft-sso::saml.test.metadata_failed', 'detail' => $e->getMessage()];

            return ConnectionTestResult::fromChecks($checks);
        }

        $expires = SamlMetadata::earliestExpiry(SamlMetadata::signingCertificates($info));
        $certOk = $expires !== null && $expires->isFuture();
        $checks[] = [
            'key' => 'certificate',
            'ok' => $certOk,
            'message_key' => $certOk ? 'sirsoft-sso::saml.test.certificate_ok' : 'sirsoft-sso::saml.test.certificate_expired',
            'detail' => $expires?->toIso8601String(),
        ];

        $checks[] = ['key' => 'sso_url', 'ok' => true, 'message_key' => 'sirsoft-sso::saml.test.sso_url', 'detail' => (string) $info['singleSignOnService']['url']];

        $needsKey = (bool) $idp->configValue('sign_authn_requests', false) || (bool) $idp->configValue('want_assertions_encrypted', false);
        if ($needsKey) {
            $hasKey = $idp->secret('sp_private_key') !== null && $idp->secret('sp_certificate') !== null;
            $checks[] = ['key' => 'sp_key', 'ok' => $hasKey, 'message_key' => $hasKey ? 'sirsoft-sso::saml.test.sp_key_ok' : 'sirsoft-sso::saml.test.sp_key_missing', 'detail' => null];
        }

        $checks[] = ['key' => 'sp_metadata', 'ok' => true, 'message_key' => 'sirsoft-sso::saml.test.sp_metadata', 'detail' => self::metadataUrl($idp)];

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
            ['key' => 'entity_id', 'label_key' => 'sirsoft-sso::saml.sp.entity_id', 'value' => self::spEntityId($idp)],
            ['key' => 'acs_url', 'label_key' => 'sirsoft-sso::saml.sp.acs_url', 'value' => SiteUrl::absolute(self::acsPath($idp))],
            ['key' => 'sls_url', 'label_key' => 'sirsoft-sso::saml.sp.sls_url', 'value' => SiteUrl::absolute(self::slsPath($idp))],
            ['key' => 'metadata_url', 'label_key' => 'sirsoft-sso::saml.sp.metadata_url', 'value' => self::metadataUrl($idp)],
            ['key' => 'name_id_format', 'label_key' => 'sirsoft-sso::saml.sp.name_id_format', 'value' => self::nameIdFormat($idp)],
        ];
    }

    /**
     * SP 메타데이터 XML 을 만듭니다 (서명하지 않는다 — IdP 는 주소와 인증서를 운영자 확인으로 신뢰한다).
     *
     * @param  IdentityProvider  $idp  IdP
     * @return string XML
     *
     * @throws SsoException 설정 오류면 `saml_invalid`
     */
    public function spMetadataXml(IdentityProvider $idp): string
    {
        try {
            $settings = new Settings($this->spSettingsArray($idp), true);
            $xml = $settings->getSPMetadata(true);
        } catch (\Throwable $e) {
            throw new SsoException('saml_invalid', 'sp metadata build failed: '.class_basename($e), 422, $e);
        }

        if ($settings->validateMetadata($xml) !== []) {
            throw new SsoException('saml_invalid', 'sp metadata invalid');
        }

        return $xml;
    }

    /**
     * 새 SP 키 쌍(2048비트 RSA, 자체 서명 10년)을 만듭니다 — 저장은 호출자(관리자 API)가 한다.
     *
     * 저장 위치: `secrets.sp_certificate`, `secrets.sp_private_key`. 교체 때 이전 값은 `sp_previous_certificate`,
     * `sp_previous_private_key`, `sp_previous_until`(지금 + 30일)로 옮긴다 — 그 기간 동안 메타데이터에 두 인증서를 함께 싣고,
     * 암호화 어설션을 새 키로 못 풀면 이전 키로 한 번 더 푼다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return array{certificate: string, private_key: string, expires_at: string} 키 쌍 (만료는 ISO 8601)
     */
    public function generateSpKeys(IdentityProvider $idp): array
    {
        $host = (string) (parse_url(SiteUrl::base(), PHP_URL_HOST) ?: 'g7');
        $pair = X509KeyPair::generate('G7 SAML SP '.$host.' '.$idp->key, self::SP_CERT_DAYS);

        return [
            'certificate' => $pair->certificate,
            'private_key' => $pair->privateKey,
            'expires_at' => $pair->expiresAt->toIso8601String(),
        ];
    }

    /**
     * 메타데이터를 다시 받아 서명 인증서의 가장 이른 만료 시각을 돌려줍니다 (매일 갱신 명령).
     *
     * @param  IdentityProvider  $idp  IdP
     * @return CarbonImmutable|null 만료 시각
     *
     * @throws SsoException 받기, 읽기 실패면 `saml_invalid`
     */
    public function refreshMetadata(IdentityProvider $idp): ?CarbonImmutable
    {
        $this->metadata->forget($idp);

        return SamlMetadata::earliestExpiry(SamlMetadata::signingCertificates($this->metadata->idp($idp, true)));
    }

    /**
     * ACS 경로.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return string 경로
     */
    public static function acsPath(IdentityProvider $idp): string
    {
        return '/plugins/sirsoft-sso/saml/'.$idp->key.'/acs';
    }

    /**
     * SLS 경로.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return string 경로
     */
    public static function slsPath(IdentityProvider $idp): string
    {
        return '/plugins/sirsoft-sso/saml/'.$idp->key.'/sls';
    }

    /**
     * SP 메타데이터 절대 주소.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return string 주소
     */
    public static function metadataUrl(IdentityProvider $idp): string
    {
        return SiteUrl::absolute('/plugins/sirsoft-sso/saml/'.$idp->key.'/metadata');
    }

    /**
     * SP Entity ID — 설정값, 없으면 SP 메타데이터 주소.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return string Entity ID
     */
    public static function spEntityId(IdentityProvider $idp): string
    {
        $configured = trim((string) $idp->configValue('sp_entity_id', ''));

        return $configured !== '' ? $configured : self::metadataUrl($idp);
    }

    /**
     * 요청할 NameID 형식 URN.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return string URN
     */
    public static function nameIdFormat(IdentityProvider $idp): string
    {
        return self::NAME_ID_FORMATS[(string) $idp->configValue('name_id_format', 'persistent')] ?? Constants::NAMEID_PERSISTENT;
    }

    /**
     * 라이브러리 설정 배열을 만듭니다 (IdP 메타데이터 포함).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  bool  $previousKey  이전 SP 키로 만들지 (암호화 어설션 재시도)
     * @return array<string, mixed> 설정
     *
     * @throws SsoException 메타데이터 오류면 `saml_invalid`
     */
    public function settingsArray(IdentityProvider $idp, bool $previousKey = false): array
    {
        $settings = $this->spSettingsArray($idp, $previousKey);
        $info = $this->metadata->idp($idp);

        $idpSettings = [
            'entityId' => $info['entityId'],
            'singleSignOnService' => $info['singleSignOnService'],
        ];
        if (isset($info['singleLogoutService']['url'])) {
            $idpSettings['singleLogoutService'] = $info['singleLogoutService'];
        }
        if (isset($info['x509cert'])) {
            $idpSettings['x509cert'] = $info['x509cert'];
        } else {
            $idpSettings['x509certMulti'] = $info['x509certMulti'];
        }
        $settings['idp'] = $idpSettings;

        return $settings;
    }

    /**
     * SP 쪽 설정과 보안 옵션.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  bool  $previousKey  이전 SP 키로 만들지
     * @return array<string, mixed> 설정
     */
    private function spSettingsArray(IdentityProvider $idp, bool $previousKey = false): array
    {
        $certificate = $previousKey ? $idp->secret('sp_previous_certificate') : $idp->secret('sp_certificate');
        $privateKey = $previousKey ? $idp->secret('sp_previous_private_key') : $idp->secret('sp_private_key');
        $hasKey = $certificate !== null && $privateKey !== null;

        $sp = [
            'entityId' => self::spEntityId($idp),
            'assertionConsumerService' => ['url' => SiteUrl::absolute(self::acsPath($idp)), 'binding' => Constants::BINDING_HTTP_POST],
            'singleLogoutService' => ['url' => SiteUrl::absolute(self::slsPath($idp)), 'binding' => Constants::BINDING_HTTP_REDIRECT],
            'NameIDFormat' => self::nameIdFormat($idp),
        ];

        if ($hasKey) {
            $sp['x509cert'] = X509KeyPair::certificateBody($certificate);
            $sp['privateKey'] = $privateKey;

            $previous = $idp->secret('sp_previous_certificate');
            if (! $previousKey && $previous !== null && $this->previousKeyActive($idp)) {
                // 키 교체 직후 — IdP 가 새 인증서를 받아 갈 때까지 메타데이터에 이전 인증서도 싣는다.
                $sp['x509certNew'] = X509KeyPair::certificateBody($previous);
            }
        }

        $requested = $idp->configValue('requested_authn_context', []);
        $requested = is_array($requested) ? array_values(array_filter(array_map('strval', $requested))) : [];

        return [
            'strict' => true,
            'debug' => false,
            'baseurl' => SiteUrl::base(),
            'sp' => $sp,
            'security' => [
                'nameIdEncrypted' => false,
                'authnRequestsSigned' => $hasKey && (bool) $idp->configValue('sign_authn_requests', false),
                'logoutRequestSigned' => $hasKey,
                'logoutResponseSigned' => $hasKey,
                'signMetadata' => false,
                'wantMessagesSigned' => (bool) $idp->configValue('want_messages_signed', false),
                'wantAssertionsSigned' => true,
                'wantAssertionsEncrypted' => (bool) $idp->configValue('want_assertions_encrypted', false),
                'wantNameId' => true,
                'wantNameIdEncrypted' => false,
                'requestedAuthnContext' => $requested !== [] ? $requested : false,
                'wantXMLValidation' => true,
                'relaxDestinationValidation' => false,
                'destinationStrictlyMatches' => true,
                // 같은 이름 속성을 여러 번 보내는 IdP(Keycloak 기본 role_list 의 Role 등)를 받아 값을 합친다 — 서명 검증과 무관한 상호운용 설정
                'allowRepeatAttributeName' => true,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'signatureAlgorithm' => XMLSecurityKey::RSA_SHA256,
                'digestAlgorithm' => self::DIGEST_SHA256,
                'lowercaseUrlencoding' => false,
            ],
        ];
    }

    /**
     * 응답을 라이브러리로 검증합니다 — 암호화 어설션을 새 키로 못 풀면 교체 기간 안의 이전 키로 한 번 더.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $raw  base64 응답
     * @param  string|null  $requestId  SP-initiated 의 AuthnRequest ID
     * @param  bool  $unsolicited  IdP-initiated 인지
     * @return SamlResponse 검증된 응답
     *
     * @throws SsoException 검증 실패
     */
    private function validatedResponse(IdentityProvider $idp, string $raw, ?string $requestId, bool $unsolicited): SamlResponse
    {
        $decoded = base64_decode($raw, true);
        if ($decoded === false || strlen($decoded) > self::MAX_RESPONSE_BYTES) {
            throw new SsoException('saml_invalid', 'SAMLResponse not base64 or too large');
        }

        $attempts = [false];
        if ($idp->secret('sp_previous_private_key') !== null && $this->previousKeyActive($idp)) {
            $attempts[] = true;
        }

        $lastError = null;
        foreach ($attempts as $previous) {
            try {
                $settings = new Settings($this->settingsArray($idp, $previous));
                [$response, $valid] = SamlEnvironment::run(self::acsPath($idp), null, function () use ($settings, $raw, $requestId) {
                    $response = new SamlResponse($settings, $raw);

                    return [$response, $response->isValid($requestId)];
                });
            } catch (SsoException $e) {
                throw $e;
            } catch (\Throwable $e) {
                // 암호화 어설션을 이 키로 풀지 못한 경우 — 다음 키로 다시 시도한다.
                $lastError = $e;

                continue;
            }

            if ($valid) {
                if ($unsolicited) {
                    $this->assertUnsolicitedLifetime($response);
                }

                return $response;
            }

            throw $this->failure($response, $unsolicited);
        }

        throw new SsoException('saml_invalid', 'response unreadable: '.($lastError !== null ? class_basename($lastError) : 'unknown'), 422, $lastError);
    }

    /**
     * 검증 실패를 오류 코드로 바꿉니다.
     *
     * @param  SamlResponse  $response  응답
     * @param  bool  $unsolicited  IdP-initiated 인지
     * @return SsoException 예외
     */
    private function failure(SamlResponse $response, bool $unsolicited): SsoException
    {
        $error = $response->getErrorException();
        $code = $error instanceof ValidationError ? $error->getCode() : -1;
        $detail = mb_substr((string) $response->getError(false), 0, 200);
        $summary = self::responseSummary($response);

        if ($unsolicited && $code === ValidationError::WRONG_INRESPONSETO) {
            return new SsoException('saml_unsolicited', $detail, responseSummary: $summary);
        }

        if ($code === ValidationError::STATUS_CODE_IS_NOT_SUCCESS) {
            $denied = str_contains($detail, 'RequestDenied') || str_contains($detail, 'AuthnFailed');

            return new SsoException($denied ? 'access_denied' : 'saml_invalid', $detail, responseSummary: $summary);
        }

        return new SsoException('saml_invalid', $detail, responseSummary: $summary);
    }

    /**
     * 진단용 IdP 응답 요약 — 서명이 붙어 있는지(응답 또는 어설션), 어설션 유효 시각(`Conditions/@NotOnOrAfter`), 대상(Audience).
     *
     * 서명을 검증하지 않고 붙어 있는지만 본다(검증은 라이브러리 `isValid()` 몫). 운영자가 「서명이 없다」·「시계가 어긋났다」·「SP 엔티티
     * ID 가 다르다」를 가려 보게 하는 값이고, 응답 원문은 남기지 않는다(§7 L-3).
     *
     * @param  SamlResponse  $response  응답
     * @return array{signed: bool, not_on_or_after: string|null, audiences: array<int, string>}|null 요약 (읽을 수 없으면 null)
     */
    private static function responseSummary(SamlResponse $response): ?array
    {
        try {
            $document = $response->getXMLDocument();
            $signed = Utils::query($document, '/samlp:Response/ds:Signature')->length > 0
                || Utils::query($document, '/samlp:Response/saml:Assertion/ds:Signature')->length > 0;
            $conditions = Utils::query($document, '/samlp:Response/saml:Assertion/saml:Conditions')->item(0);
            $notOnOrAfter = $conditions instanceof \DOMElement && $conditions->hasAttribute('NotOnOrAfter')
                ? mb_substr($conditions->getAttribute('NotOnOrAfter'), 0, 40)
                : null;
            $audiences = array_values(array_map(static fn ($a) => mb_substr((string) $a, 0, 255), array_slice((array) $response->getAudiences(), 0, 5)));

            return ['signed' => $signed, 'not_on_or_after' => $notOnOrAfter, 'audiences' => $audiences];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * IdP-initiated 응답은 유효 시각이 반드시 있어야 받는다 — 1회 사용 기록을 그 시각까지 두므로.
     *
     * @param  SamlResponse  $response  응답
     * @return void 반환값 없음
     *
     * @throws SsoException 유효 시각 없음
     */
    private function assertUnsolicitedLifetime(SamlResponse $response): void
    {
        if ($response->getAssertionNotOnOrAfter() === null) {
            throw new SsoException('saml_invalid', 'unsolicited response without NotOnOrAfter');
        }
    }

    /**
     * 응답(어설션) ID 를 유효 시각까지 기록합니다 — 같은 응답을 두 번 받지 않는다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  SamlResponse  $response  검증된 응답
     * @return void 반환값 없음
     *
     * @throws SsoException 이미 받은 응답이면 `response_replayed`
     */
    private function rememberResponse(IdentityProvider $idp, SamlResponse $response): void
    {
        $id = (string) $response->getId().'|'.(string) $response->getAssertionId();
        $key = 'saml:resp:'.$idp->getKey().':'.hash('sha256', $id);
        $notOnOrAfter = $response->getAssertionNotOnOrAfter();
        $ttl = $notOnOrAfter !== null ? (int) $notOnOrAfter - time() + Constants::ALLOWED_CLOCK_DRIFT : 600;
        $ttl = max(60, min(86400, $ttl));

        $fresh = Cache::lock('sirsoft-sso:lock:'.$key, 5)->get(function () use ($key, $ttl) {
            if ($this->cache->has($key)) {
                return false;
            }
            $this->cache->put($key, 1, $ttl);

            return true;
        });

        if ($fresh !== true) {
            throw new SsoException('response_replayed', 'saml response reused');
        }
    }

    /**
     * 다중 인증 신호 — AuthnContextClassRef 가 설정 목록에 들면 true, 목록이 비면 알 수 없음(null).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  SamlResponse  $response  검증된 응답 (어설션 1개가 보장된다)
     * @return bool|null 다중 인증 여부
     */
    private function mfa(IdentityProvider $idp, SamlResponse $response): ?bool
    {
        $configured = $idp->configValue('mfa_authn_contexts', []);
        $configured = is_array($configured) ? array_values(array_filter(array_map('strval', $configured))) : [];
        if ($configured === []) {
            return null;
        }

        $nodes = Utils::query($response->getXMLDocument(), '/samlp:Response/saml:Assertion/saml:AuthnStatement/saml:AuthnContext/saml:AuthnContextClassRef');
        foreach ($nodes as $node) {
            if (in_array(trim((string) $node->textContent), $configured, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * IdP 발 LogoutRequest 를 검증하고 LogoutResponse 로 302 를 만듭니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  Request  $request  요청
     * @param  string  $samlRequest  base64 요청
     * @return LogoutResult 결과
     *
     * @throws SsoException 검증 실패면 `saml_invalid`(400)
     */
    private function handleIdpLogoutRequest(IdentityProvider $idp, Request $request, string $samlRequest): LogoutResult
    {
        try {
            $settingsArray = $this->settingsArray($idp);
            $settings = new Settings($settingsArray);
            $logoutRequest = new LogoutRequest($settings, $samlRequest);
            $xml = $logoutRequest->getXML();
        } catch (\Throwable $e) {
            throw new SsoException('saml_invalid', 'logout request unreadable', 400, $e);
        }

        $isGet = $request->isMethod('GET');
        if ($isGet) {
            if (! is_string($request->query('Signature')) || $request->query('Signature') === '') {
                throw new SsoException('saml_invalid', 'logout request must be signed', 400);
            }
        } else {
            $idpData = $settings->getIdPData();
            $signed = Utils::validateSign($xml, $idpData['x509cert'] ?? null, null, 'sha1', '/samlp:LogoutRequest/ds:Signature', $idpData['x509certMulti']['signing'] ?? null);
            if (! $signed) {
                throw new SsoException('saml_invalid', 'logout request must be signed', 400);
            }
        }

        $valid = SamlEnvironment::run(self::slsPath($idp), $request, fn () => $logoutRequest->isValid($isGet));
        if (! $valid) {
            throw new SsoException('saml_invalid', 'logout request: '.mb_substr((string) $logoutRequest->getError(), 0, 200), 400);
        }

        $nameId = (string) LogoutRequest::getNameId($xml, $settings->getSPkey());
        $sessionIndexes = LogoutRequest::getSessionIndexes($xml);
        $requestId = (string) LogoutRequest::getID($xml);

        $sessions = [];
        if (class_exists(SsoSession::class) && $nameId !== '') {
            $query = SsoSession::query()->where('idp_id', $idp->getKey())->whereNull('ended_at')->where('name_id', $nameId);
            if ($sessionIndexes !== []) {
                $query->whereIn('external_session', array_map('strval', $sessionIndexes));
            }
            $sessions = $query->get()->all();
        }

        $logoutResponse = new LogoutResponse($settings);
        $logoutResponse->build($requestId);
        $encoded = $logoutResponse->getResponse();

        $parameters = ['SAMLResponse' => $encoded];
        $relayState = $request->input('RelayState');
        if (is_string($relayState) && $relayState !== '') {
            $parameters['RelayState'] = $relayState;
        }
        if ($settingsArray['security']['logoutResponseSigned']) {
            $auth = new Auth($settingsArray);
            $parameters['SigAlg'] = XMLSecurityKey::RSA_SHA256;
            $parameters['Signature'] = $auth->buildResponseSignature($encoded, $parameters['RelayState'] ?? null, XMLSecurityKey::RSA_SHA256);
        }

        $target = $settingsArray['idp']['singleLogoutService']['responseUrl'] ?? $settingsArray['idp']['singleLogoutService']['url'] ?? null;
        if (! is_string($target) || $target === '') {
            return new LogoutResult($sessions, response('', 200, ['Cache-Control' => 'no-store']));
        }

        return new LogoutResult($sessions, redirect()->away($target.(str_contains($target, '?') ? '&' : '?').http_build_query($parameters)));
    }

    /**
     * 이전 SP 키 병행 기간인지 확인합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return bool 기간 안이면 true
     */
    private function previousKeyActive(IdentityProvider $idp): bool
    {
        $until = $idp->secret('sp_previous_until');
        if ($until === null) {
            return false;
        }

        try {
            return CarbonImmutable::parse($until)->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 자동 제출 폼(POST 바인딩)을 만듭니다 — 스크립트가 꺼져 있으면 버튼을 누른다.
     *
     * @param  string  $action  IdP 주소
     * @param  array<string, string>  $fields  폼 값
     * @return Response 화면
     */
    private function postForm(string $action, array $fields): Response
    {
        return response(self::autoSubmitHtml($action, $fields), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * 자동 제출 폼 HTML — 현재 로케일 문구로 그린다.
     *
     * @param  string  $action  제출 주소
     * @param  array<string, string>  $fields  폼 값
     * @return string HTML
     */
    public static function autoSubmitHtml(string $action, array $fields): string
    {
        $inputs = '';
        foreach ($fields as $name => $value) {
            $inputs .= '<input type="hidden" name="'.e($name).'" value="'.e($value).'">';
        }

        return '<!doctype html><html lang="'.e(app()->getLocale()).'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            .'<title>'.e(__('sirsoft-sso::saml.autosubmit.title')).'</title>'
            .'<style>body{font-family:system-ui,sans-serif;max-width:28rem;margin:3rem auto;padding:0 1rem;text-align:center}'
            .'button{margin-top:1rem;padding:.6rem 1.2rem}</style></head>'
            .'<body><p>'.e(__('sirsoft-sso::saml.autosubmit.message')).'</p>'
            .'<form method="post" action="'.e($action).'">'.$inputs
            .'<noscript><button type="submit">'.e(__('sirsoft-sso::saml.autosubmit.continue')).'</button></noscript>'
            // 자동 제출은 정적 파일이 한다 — 인라인 onload 는 strict CSP(script-src 'self')에서 실행되지 않는다(#158)
            .'</form>'.BridgeScript::tag('saml-autosubmit.js').'</body></html>';
    }

    /**
     * 속성 값을 문자열 또는 문자열 목록으로 정리합니다 (값 1개면 문자열).
     *
     * @param  array<string, array<int, mixed>>  $attributes  라이브러리 속성
     * @return array<string, string|array<int, string>> 정리한 속성
     */
    private static function flattenAttributes(array $attributes): array
    {
        $flat = [];
        foreach ($attributes as $name => $values) {
            $clean = [];
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (is_scalar($value)) {
                    $clean[] = mb_substr((string) $value, 0, 1000);
                }
            }
            if ($clean === [] || mb_strlen((string) $name) > 255) {
                continue;
            }
            $flat[(string) $name] = count($clean) === 1 ? $clean[0] : array_slice($clean, 0, 200);
        }

        return $flat;
    }

    /**
     * 속성 값의 첫 문자열.
     *
     * @param  mixed  $value  속성 값
     * @return string|null 값
     */
    private static function first(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * 개인키와 인증서가 짝인지 확인합니다.
     *
     * @param  string  $privateKey  개인키 PEM
     * @param  string  $certificate  인증서 PEM 또는 본문
     * @return bool 짝이면 true
     */
    private static function keyMatchesCertificate(string $privateKey, string $certificate): bool
    {
        $cert = @openssl_x509_read(SamlMetadata::pem($certificate));
        $key = @openssl_pkey_get_private($privateKey);

        return $cert !== false && $key !== false && @openssl_x509_check_private_key($cert, $key);
    }
}
