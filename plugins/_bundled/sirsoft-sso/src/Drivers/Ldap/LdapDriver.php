<?php

namespace Plugins\Sirsoft\Sso\Drivers\Ldap;

use Illuminate\Http\Request;
use Plugins\Sirsoft\Sso\Contracts\CredentialDriverInterface;
use Plugins\Sirsoft\Sso\Contracts\ProtocolDriverInterface;
use Plugins\Sirsoft\Sso\DTO\ConnectionTestResult;
use Plugins\Sirsoft\Sso\DTO\DirectoryEntry;
use Plugins\Sirsoft\Sso\DTO\DriverCapabilities;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\DTO\LoginIntent;
use Plugins\Sirsoft\Sso\DTO\LogoutResult;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoSession;
use Plugins\Sirsoft\Sso\Services\AttributeSyncService;
use Plugins\Sirsoft\Sso\Services\Ldap\DirectoryClientInterface;
use Plugins\Sirsoft\Sso\Services\Ldap\LdapExtension;
use Symfony\Component\HttpFoundation\Response;

/**
 * LDAP / Active Directory 드라이버 — 로그인 칸의 아이디와 비밀번호를 디렉터리로 확인한다(리디렉션 흐름 아님).
 *
 * 인증 순서: 빈 비밀번호는 바인드 전에 거절(익명 바인드를 인증으로 오인하는 고전 결함) → 서비스 계정 바인드 → 사용자 검색(정확히 1건,
 * 0건이나 2건 이상이면 거절) → 그 DN 과 입력 비밀번호로 재바인드. 검색 필터에 넣는 값은 반드시 `ldap_escape(…, LDAP_ESCAPE_FILTER)`
 * (LDAP 주입 방지). AD `objectGUID` 는 바이너리라 GUID 문자열로 바꿔 연결 키로 쓴다.
 *
 * @since 1.0.0
 */
class LdapDriver implements CredentialDriverInterface, ProtocolDriverInterface
{
    /** 프로토콜 키 */
    public const KEY = 'ldap';

    /** AD userAccountControl 의 ACCOUNTDISABLE 비트 */
    public const AD_ACCOUNT_DISABLE = 0x2;

    /** @var array<string, array<string, mixed>> 디렉터리 종류별 기본값 */
    public const PRESETS = [
        'openldap' => [
            'port' => 389,
            'user_filter' => '(&(objectClass=inetOrgPerson)(|(mail={identifier})(uid={identifier})))',
            'subject_attribute' => 'entryUUID',
            'email_attribute' => 'mail',
            'name_attribute' => 'displayName',
            'username_attribute' => 'uid',
            'group_attribute' => 'memberOf',
            'group_filter' => '(&(objectClass=groupOfNames)(member={dn}))',
            'disabled_rule' => 'not_found',
        ],
        'ad' => [
            'port' => 636,
            'user_filter' => '(&(objectCategory=person)(objectClass=user)(|(mail={identifier})(sAMAccountName={identifier})(userPrincipalName={identifier})))',
            'subject_attribute' => 'objectGUID',
            'email_attribute' => 'mail',
            'name_attribute' => 'displayName',
            'username_attribute' => 'sAMAccountName',
            'group_attribute' => 'memberOf',
            'group_filter' => '',
            'disabled_rule' => 'ad_uac',
        ],
    ];

    /** @var array<int, string> 신원 속성으로 함께 가져오는 디렉터리 속성 (그 밖의 속성은 IdP 속성 규칙에 적은 것을 더 가져온다) */
    private const EXTRA_ATTRIBUTES = ['uid', 'samaccountname', 'userprincipalname', 'department', 'title', 'employeenumber', 'telephonenumber', 'mobile', 'cn'];

    /** @var array<int, string> 속성 규칙에 적어도 가져오지 않는 비밀번호류 속성 (소문자) */
    private const SECRET_ATTRIBUTES = ['userpassword', 'unicodepwd', 'dbcspwd', 'lmpwdhistory', 'ntpwdhistory', 'supplementalcredentials', 'sambantpassword', 'sambalmpassword', 'authpassword'];

    /** 속성 이름 형식 (RFC 4512 descr) */
    private const ATTRIBUTE_NAME = '/^[A-Za-z][A-Za-z0-9\-]{0,63}$/';

    /** 여러 값 속성에서 받는 최대 값 수 */
    private const MAX_VALUES = 50;

    /** 입력 아이디 최대 길이 */
    private const MAX_IDENTIFIER = 255;

    /** 입력 비밀번호 최대 길이 */
    private const MAX_PASSWORD = 1024;

    /**
     * @param  LdapExtension  $extension  ext-ldap 유무
     */
    public function __construct(private readonly LdapExtension $extension) {}

    /**
     * 프로토콜 키를 반환합니다.
     *
     * @return string ldap
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
        return (string) __('sirsoft-sso::ldap.label', [], $locale);
    }

    /**
     * 지원 기능을 반환합니다.
     *
     * @return DriverCapabilities 기능 표
     */
    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(credential: true, groups: true, periodicCheck: true);
    }

    /**
     * 서버에 PHP LDAP 확장이 있는지 확인합니다 — 없으면 이 드라이버만 「사용 불가」.
     *
     * @return bool 쓸 수 있으면 true
     */
    public function isAvailable(): bool
    {
        return $this->extension->loaded();
    }

    /**
     * 설정 칸 정의를 반환합니다.
     *
     * `directory_type` 을 고르면 화면이 `PRESETS` 의 기본값(필터, 고유 식별자 속성, 비활성 규칙)을 채운다(`presets` 칸).
     *
     * @return array<int, array<string, mixed>> 칸 정의
     */
    public function configSchema(): array
    {
        $field = static fn (string $key, string $type, array $extra = []): array => array_merge([
            'key' => $key,
            'type' => $type,
            'required' => false,
            'label_key' => 'sirsoft-sso::ldap.config.'.$key.'.label',
            'hint_key' => 'sirsoft-sso::ldap.config.'.$key.'.hint',
        ], $extra);

        return [
            $field('directory_type', 'select', ['default' => 'openldap', 'options' => array_keys(self::PRESETS), 'presets' => self::PRESETS]),
            $field('host', 'string', ['required' => true]),
            $field('port', 'string', ['default' => 389]),
            $field('encryption', 'select', ['default' => 'starttls', 'options' => ['starttls', 'ldaps', 'none']]),
            $field('verify_certificate', 'boolean', ['default' => true]),
            $field('ca_bundle', 'textarea'),
            $field('bind_dn', 'string'),
            $field('bind_password', 'secret', ['secret' => true]),
            $field('base_dn', 'string', ['required' => true]),
            $field('user_filter', 'string', ['default' => self::PRESETS['openldap']['user_filter']]),
            $field('subject_attribute', 'string', ['default' => 'entryUUID']),
            $field('email_attribute', 'string', ['default' => 'mail']),
            $field('name_attribute', 'string', ['default' => 'displayName']),
            $field('group_attribute', 'string', ['default' => 'memberOf']),
            $field('group_filter', 'string', ['default' => '']),
            $field('group_name_from_dn', 'boolean', ['default' => true]),
            $field('accept_username', 'boolean', ['default' => false]),
            $field('disabled_rule', 'select', ['default' => 'not_found', 'options' => ['not_found', 'ad_uac', 'attribute']]),
            $field('disabled_attribute', 'string'),
            $field('disabled_value', 'string'),
            $field('timeout', 'string', ['default' => 5]),
        ];
    }

    /**
     * 설정값을 검증합니다.
     *
     * 암호화 없음(`none`)은 운영 환경에서 거절한다(비밀번호가 평문으로 오간다). 개발 환경에서는 저장되지만 화면이 경고한다.
     *
     * @param  array<string, mixed>  $config  비밀이 아닌 설정
     * @param  array<string, mixed>  $secrets  비밀값
     * @return array<string, string> 칸 키 → 오류 문구 키
     */
    public function validateConfig(array $config, array $secrets): array
    {
        $errors = [];
        $host = trim((string) ($config['host'] ?? ''));
        if ($host === '' || preg_match('/^[A-Za-z0-9.\-:\[\]]{1,255}$/', $host) !== 1 || str_contains($host, '://')) {
            $errors['host'] = 'sirsoft-sso::ldap.validation.host';
        }

        $port = (int) ($config['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            $errors['port'] = 'sirsoft-sso::ldap.validation.port';
        }

        $encryption = (string) ($config['encryption'] ?? 'starttls');
        if (! in_array($encryption, ['starttls', 'ldaps', 'none'], true)) {
            $errors['encryption'] = 'sirsoft-sso::validation.in';
        } elseif ($encryption === 'none' && app()->environment('production')) {
            $errors['encryption'] = 'sirsoft-sso::ldap.validation.plaintext_refused';
        }

        if (trim((string) ($config['base_dn'] ?? '')) === '') {
            $errors['base_dn'] = 'sirsoft-sso::validation.required';
        }

        $filter = (string) ($config['user_filter'] ?? self::PRESETS['openldap']['user_filter']);
        if (! str_contains($filter, '{identifier}') || ! str_starts_with(trim($filter), '(') || substr_count($filter, '(') !== substr_count($filter, ')')) {
            $errors['user_filter'] = 'sirsoft-sso::ldap.validation.user_filter';
        }

        $groupFilter = trim((string) ($config['group_filter'] ?? ''));
        if ($groupFilter !== '' && (! str_contains($groupFilter, '{dn}') || substr_count($groupFilter, '(') !== substr_count($groupFilter, ')'))) {
            $errors['group_filter'] = 'sirsoft-sso::ldap.validation.group_filter';
        }

        foreach (['subject_attribute', 'email_attribute', 'name_attribute', 'group_attribute'] as $key) {
            $value = trim((string) ($config[$key] ?? ''));
            if ($value !== '' && preg_match('/^[A-Za-z][A-Za-z0-9\-]{0,63}$/', $value) !== 1) {
                $errors[$key] = 'sirsoft-sso::ldap.validation.attribute';
            }
        }

        $rule = (string) ($config['disabled_rule'] ?? 'not_found');
        if (! in_array($rule, ['not_found', 'ad_uac', 'attribute'], true)) {
            $errors['disabled_rule'] = 'sirsoft-sso::validation.in';
        } elseif ($rule === 'attribute' && trim((string) ($config['disabled_attribute'] ?? '')) === '') {
            $errors['disabled_attribute'] = 'sirsoft-sso::validation.required';
        }

        $timeout = (int) ($config['timeout'] ?? 5);
        if ($timeout < 1 || $timeout > 30) {
            $errors['timeout'] = 'sirsoft-sso::ldap.validation.timeout';
        }

        if (trim((string) ($config['bind_dn'] ?? '')) !== '' && trim((string) ($secrets['bind_password'] ?? '')) === '') {
            $errors['bind_password'] = 'sirsoft-sso::validation.required';
        }

        return $errors;
    }

    /**
     * 리디렉션 로그인은 지원하지 않습니다 — LDAP 은 로그인 칸으로 들어온다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  LoginIntent  $intent  시작 정보
     * @return Response 응답 (돌려주지 않음)
     *
     * @throws SsoException 항상 `driver_unavailable`
     */
    public function beginLogin(IdentityProvider $idp, LoginIntent $intent): Response
    {
        throw new SsoException('driver_unavailable', 'ldap has no redirect login');
    }

    /**
     * 리디렉션 콜백은 지원하지 않습니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  Request  $request  요청
     * @param  LoginIntent|null  $intent  시작 정보
     * @return ExternalIdentity 신원 (돌려주지 않음)
     *
     * @throws SsoException 항상 `driver_unavailable`
     */
    public function completeLogin(IdentityProvider $idp, Request $request, ?LoginIntent $intent): ExternalIdentity
    {
        throw new SsoException('driver_unavailable', 'ldap has no redirect login');
    }

    /**
     * IdP 로그아웃은 없습니다 (디렉터리에는 세션이 없다).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  SsoSession  $session  세션
     * @return Response|null 항상 null
     */
    public function beginLogout(IdentityProvider $idp, SsoSession $session): ?Response
    {
        return null;
    }

    /**
     * IdP 발 로그아웃은 없습니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  Request  $request  요청
     * @return LogoutResult 결과 (돌려주지 않음)
     *
     * @throws SsoException 항상 `driver_unavailable`
     */
    public function handleLogoutRequest(IdentityProvider $idp, Request $request): LogoutResult
    {
        throw new SsoException('driver_unavailable', 'ldap has no logout channel', 404);
    }

    /**
     * 아이디와 비밀번호를 디렉터리로 확인합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $identifier  이메일 또는 사내 아이디
     * @param  string  $password  비밀번호
     * @return ExternalIdentity 신원
     *
     * @throws SsoException `ldap_invalid_credentials`, `ldap_unavailable`, `deprovisioned`, `driver_unavailable`
     */
    public function authenticate(IdentityProvider $idp, string $identifier, string $password): ExternalIdentity
    {
        $identifier = trim($identifier);

        // 빈 비밀번호는 서버에 닿기 전에 거절한다 — 많은 디렉터리가 빈 비밀번호 바인드를 「익명 바인드 성공」으로 돌려준다.
        if ($identifier === '' || $password === '' || mb_strlen($identifier) > self::MAX_IDENTIFIER || strlen($password) > self::MAX_PASSWORD) {
            throw new SsoException('ldap_invalid_credentials', 'empty or oversized credential');
        }

        $client = $this->open($idp);

        try {
            $entries = $client->search($this->baseDn($idp), $this->userFilter($idp, $identifier), $this->attributes($idp), 2);
            if (count($entries) !== 1 || $entries[0]['dn'] === '') {
                throw new SsoException('ldap_invalid_credentials', 'user search matched '.count($entries));
            }

            // 그룹 검색은 서비스 계정 권한으로 한다 — 사용자로 재바인드한 뒤에는 그룹을 읽지 못하는 디렉터리가 있다.
            $directory = $this->toDirectoryEntry($idp, $entries[0], $client);
            if (! $client->bind($entries[0]['dn'], $password)) {
                throw new SsoException('ldap_invalid_credentials', 'user bind rejected');
            }
        } finally {
            $client->close();
        }

        if (! $directory->active) {
            throw new SsoException('deprovisioned', 'directory account disabled');
        }

        return $this->toIdentity($idp, $directory);
    }

    /**
     * 디렉터리에서 계정을 찾습니다 (주기 점검, SAML 보조 조회, 로그인 전 회원 확인).
     *
     * 고유 식별자 속성 값(GUID 문자열 포함) 또는 로그인 필터(이메일, 사내 아이디)로 찾는다. 2건 이상이면 판정을 미룬다(예외) — 엉뚱한
     * 계정으로 재직 여부를 정하지 않는다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $subject  찾을 값 (연결의 subject, 이메일 또는 사내 아이디)
     * @return DirectoryEntry|null 계정, 없으면 null
     *
     * @throws SsoException `directory_unavailable`
     */
    public function lookup(IdentityProvider $idp, string $subject): ?DirectoryEntry
    {
        $subject = trim($subject);
        if ($subject === '' || mb_strlen($subject) > self::MAX_IDENTIFIER) {
            return null;
        }

        try {
            $client = $this->open($idp);
        } catch (SsoException $e) {
            throw new SsoException('directory_unavailable', $e->getMessage(), 503, $e);
        }

        try {
            $filters = [$this->userFilter($idp, $subject)];
            $bySubject = $this->subjectFilter($idp, $subject);
            if ($bySubject !== null) {
                $filters[] = $bySubject;
            }
            $entries = $client->search($this->baseDn($idp), '(|'.implode('', $filters).')', $this->attributes($idp), 2);

            if (count($entries) > 1) {
                throw new SsoException('directory_unavailable', 'lookup matched '.count($entries));
            }

            return $entries === [] ? null : $this->toDirectoryEntry($idp, $entries[0], $client);
        } catch (SsoException $e) {
            if ($e->errorCode === 'directory_unavailable') {
                throw $e;
            }

            throw new SsoException('directory_unavailable', $e->getMessage(), 503, $e);
        } finally {
            $client->close();
        }
    }

    /**
     * 서버 쪽 연결 시험 — 확장, 연결과 TLS, 서비스 바인드, 기준 DN 검색.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return ConnectionTestResult 결과
     */
    public function testConnection(IdentityProvider $idp): ConnectionTestResult
    {
        if (! $this->isAvailable()) {
            return ConnectionTestResult::fromChecks([['key' => 'extension', 'ok' => false, 'message_key' => 'sirsoft-sso::ldap.test.extension_missing', 'detail' => null]]);
        }

        $checks = [];
        $client = app(DirectoryClientInterface::class);
        $options = $this->connectionOptions($idp);

        $target = $options['host'].':'.$options['port'].' ('.$options['encryption'].')';

        // ext-ldap 의 연결은 첫 바인드 때 실제로 열린다 — 서비스 바인드가 서버에 닿았는지로 연결과 TLS 를 판정한다.
        try {
            $client->connect($options);
            $bound = $this->serviceBind($idp, $client, false);
            $checks[] = ['key' => 'connect', 'ok' => true, 'message_key' => 'sirsoft-sso::ldap.test.connect_ok', 'detail' => $target];
        } catch (SsoException $e) {
            $client->close();
            $checks[] = ['key' => 'connect', 'ok' => false, 'message_key' => 'sirsoft-sso::ldap.test.connect_failed', 'detail' => $target.' — '.$e->getMessage()];

            return ConnectionTestResult::fromChecks($checks);
        }

        try {
            if ($options['encryption'] === 'none') {
                $checks[] = ['key' => 'tls', 'ok' => false, 'message_key' => 'sirsoft-sso::ldap.test.plaintext', 'detail' => null];
            }

            $checks[] = ['key' => 'bind', 'ok' => $bound, 'message_key' => $bound ? 'sirsoft-sso::ldap.test.bind_ok' : 'sirsoft-sso::ldap.test.bind_failed', 'detail' => $this->bindDn($idp) ?? '(anonymous)'];
            if (! $bound) {
                return ConnectionTestResult::fromChecks($checks);
            }

            $found = $client->search($this->baseDn($idp), '(objectClass=*)', ['dn'], 1);
            $checks[] = ['key' => 'base_dn', 'ok' => $found !== [], 'message_key' => $found !== [] ? 'sirsoft-sso::ldap.test.base_dn_ok' : 'sirsoft-sso::ldap.test.base_dn_empty', 'detail' => $this->baseDn($idp)];
        } catch (SsoException $e) {
            $checks[] = ['key' => 'search', 'ok' => false, 'message_key' => 'sirsoft-sso::ldap.test.search_failed', 'detail' => $e->getMessage()];
        } finally {
            $client->close();
        }

        return ConnectionTestResult::fromChecks($checks);
    }

    /**
     * 「계정으로 시험」 — 아이디와 비밀번호로 인증해 보고 받은 신원을 가린 값으로 돌려줍니다(저장하지 않는다).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $identifier  아이디
     * @param  string  $password  비밀번호
     * @return ConnectionTestResult 결과 (`account` 항목 하나)
     */
    public function testAccount(IdentityProvider $idp, string $identifier, string $password): ConnectionTestResult
    {
        try {
            $identity = $this->authenticate($idp, $identifier, $password);
            $detail = 'subject='.self::mask($identity->subject).($identity->email !== null ? ', email='.self::mask($identity->email) : '').', groups='.count($identity->groups)
                .$this->attributeSummary($idp, $identity);

            return ConnectionTestResult::fromChecks([['key' => 'account', 'ok' => true, 'message_key' => 'sirsoft-sso::ldap.test.account_ok', 'detail' => $detail]]);
        } catch (SsoException $e) {
            return ConnectionTestResult::fromChecks([['key' => 'account', 'ok' => false, 'message_key' => 'sirsoft-sso::messages.error.'.$e->errorCode, 'detail' => $e->errorCode]]);
        }
    }

    /**
     * 「계정으로 시험」에 덧붙일 속성 요약 — 받은 속성 이름과, 속성 규칙에 적었는데 디렉터리가 주지 않은 출처 이름(값은 싣지 않는다).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  ExternalIdentity  $identity  받은 신원
     * @return string 요약 (`, attributes=…, missing=…`)
     */
    private function attributeSummary(IdentityProvider $idp, ExternalIdentity $identity): string
    {
        $received = array_keys($identity->attributes);
        sort($received);
        $summary = ', attributes='.implode(',', $received);

        $missing = array_values(array_filter(
            AttributeSyncService::requestedSources($idp),
            static fn (string $source) => ! array_key_exists(mb_strtolower($source), $identity->attributes)
                && ! (strcasecmp($source, 'displayName') === 0 && $identity->displayName !== null),
        ));

        return $missing !== [] ? $summary.', missing='.implode(',', $missing) : $summary;
    }

    /**
     * 운영자에게 보일 값 — LDAP 은 IdP 콘솔에 등록할 값이 없고 로그인 칸 안내만 있다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return array<int, array{key: string, label_key: string, value: string}> 표시 행
     */
    public function serviceProviderInfo(IdentityProvider $idp): array
    {
        return [
            ['key' => 'login_field', 'label_key' => 'sirsoft-sso::ldap.sp.login_field', 'value' => (bool) $idp->configValue('accept_username', false) ? 'email_or_username' : 'email'],
            ['key' => 'directory', 'label_key' => 'sirsoft-sso::ldap.sp.directory', 'value' => (string) $idp->configValue('host', '').':'.(int) $idp->configValue('port', 389)],
        ];
    }

    /**
     * AD objectGUID(16바이트, 앞 세 덩어리 리틀 엔디언)를 GUID 문자열로 바꿉니다.
     *
     * @param  string  $binary  바이너리
     * @return string|null GUID 문자열 (소문자), 16바이트가 아니면 null
     */
    public static function guidToString(string $binary): ?string
    {
        if (strlen($binary) !== 16) {
            return null;
        }

        $hex = bin2hex($binary);

        return implode('-', [
            implode('', array_reverse(str_split(substr($hex, 0, 8), 2))),
            implode('', array_reverse(str_split(substr($hex, 8, 4), 2))),
            implode('', array_reverse(str_split(substr($hex, 12, 4), 2))),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }

    /**
     * GUID 문자열을 LDAP 필터용 바이너리 이스케이프(`\xx`)로 바꿉니다.
     *
     * @param  string  $guid  GUID 문자열
     * @return string|null 필터 값, GUID 형식이 아니면 null
     */
    public static function guidToFilterValue(string $guid): ?string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $guid) !== 1) {
            return null;
        }

        $hex = strtolower(str_replace('-', '', $guid));
        $ordered = implode('', array_reverse(str_split(substr($hex, 0, 8), 2)))
            .implode('', array_reverse(str_split(substr($hex, 8, 4), 2)))
            .implode('', array_reverse(str_split(substr($hex, 12, 4), 2)))
            .substr($hex, 16);

        return '\\'.implode('\\', str_split($ordered, 2));
    }

    /**
     * 연결과 서비스 바인드까지 마친 디렉터리를 엽니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return DirectoryClientInterface 연결
     *
     * @throws SsoException 확장 없음 `driver_unavailable`, 서버 장애·서비스 바인드 실패 `ldap_unavailable`
     */
    private function open(IdentityProvider $idp): DirectoryClientInterface
    {
        if (! $this->isAvailable()) {
            throw new SsoException('driver_unavailable', 'ext-ldap missing');
        }

        $client = app(DirectoryClientInterface::class);
        $client->connect($this->connectionOptions($idp));

        try {
            $this->serviceBind($idp, $client, true);
        } catch (SsoException $e) {
            $client->close();

            throw $e;
        }

        return $client;
    }

    /**
     * 서비스 계정으로 바인드합니다 (바인드 DN 이 없으면 익명 바인드로 검색).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  DirectoryClientInterface  $client  연결
     * @param  bool  $throw  실패면 예외를 던질지
     * @return bool 성공이면 true
     *
     * @throws SsoException 서비스 계정 자격 증명 오류는 설정 문제라 `ldap_unavailable`(로그인 실패 횟수에 세지 않는다)
     */
    private function serviceBind(IdentityProvider $idp, DirectoryClientInterface $client, bool $throw): bool
    {
        $dn = $this->bindDn($idp);
        $bound = $client->bind($dn, $dn !== null ? (string) ($idp->secret('bind_password') ?? '') : null);
        if (! $bound && $throw) {
            throw new SsoException('ldap_unavailable', 'service bind rejected');
        }

        return $bound;
    }

    /**
     * 연결 옵션.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return array{host: string, port: int, encryption: string, verify_certificate: bool, ca_bundle: string|null, timeout: int} 옵션
     */
    private function connectionOptions(IdentityProvider $idp): array
    {
        $encryption = (string) $idp->configValue('encryption', 'starttls');

        return [
            'host' => trim((string) $idp->configValue('host', '')),
            'port' => (int) $idp->configValue('port', $encryption === 'ldaps' ? 636 : 389),
            'encryption' => in_array($encryption, ['starttls', 'ldaps', 'none'], true) ? $encryption : 'starttls',
            'verify_certificate' => (bool) $idp->configValue('verify_certificate', true),
            'ca_bundle' => is_string($idp->configValue('ca_bundle')) ? (string) $idp->configValue('ca_bundle') : null,
            'timeout' => max(1, min(30, (int) $idp->configValue('timeout', 5))),
        ];
    }

    /**
     * 사용자 검색 필터 — `{identifier}` 를 필터 이스케이프한 값으로 바꾼다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $identifier  입력값
     * @return string 필터
     */
    private function userFilter(IdentityProvider $idp, string $identifier): string
    {
        $template = (string) $idp->configValue('user_filter', $this->preset($idp)['user_filter']);

        return str_replace('{identifier}', ldap_escape($identifier, '', LDAP_ESCAPE_FILTER), $template);
    }

    /**
     * 고유 식별자 속성으로 찾는 필터 — objectGUID 는 바이너리 이스케이프.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $subject  값
     * @return string|null 필터, 형식이 맞지 않으면 null
     */
    private function subjectFilter(IdentityProvider $idp, string $subject): ?string
    {
        $attribute = $this->subjectAttribute($idp);
        if (strcasecmp($attribute, 'objectGUID') === 0) {
            $value = self::guidToFilterValue($subject);

            return $value !== null ? '('.$attribute.'='.$value.')' : null;
        }

        return '('.$attribute.'='.ldap_escape($subject, '', LDAP_ESCAPE_FILTER).')';
    }

    /**
     * 가져올 속성 목록.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return array<int, string> 속성
     */
    private function attributes(IdentityProvider $idp): array
    {
        $attributes = [
            $this->subjectAttribute($idp),
            (string) $idp->configValue('email_attribute', 'mail'),
            (string) $idp->configValue('name_attribute', 'displayName'),
            (string) $idp->configValue('group_attribute', 'memberOf'),
            'userAccountControl',
        ];

        if ((string) $idp->configValue('disabled_rule', $this->preset($idp)['disabled_rule']) === 'attribute') {
            $attributes[] = (string) $idp->configValue('disabled_attribute', '');
        }

        $requested = [];
        foreach (array_merge($attributes, self::EXTRA_ATTRIBUTES, $this->ruleAttributes($idp)) as $attribute) {
            if ($attribute !== '') {
                $requested[mb_strtolower($attribute)] ??= $attribute;
            }
        }

        return array_values($requested);
    }

    /**
     * IdP 속성 규칙에 적은 출처 중 디렉터리에 요청할 속성 (소문자) — 속성 이름 형식이 아니거나 비밀번호류 속성이면 뺀다.
     *
     * LDAP 속성 이름은 대소문자를 가리지 않으므로 규칙에 `employeeNumber` 로 적든 `employeenumber` 로 적든 같은 속성이다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return array<int, string> 속성
     */
    private function ruleAttributes(IdentityProvider $idp): array
    {
        $attributes = [];
        foreach (AttributeSyncService::requestedSources($idp) as $source) {
            $name = mb_strtolower($source);
            if (preg_match(self::ATTRIBUTE_NAME, $source) === 1 && ! in_array($name, self::SECRET_ATTRIBUTES, true)) {
                $attributes[] = $name;
            }
        }

        return array_values(array_unique($attributes));
    }

    /**
     * 디렉터리 항목을 정리합니다 — subject, 활성 여부, 이메일, 이름, 그룹, 그 밖의 속성.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  array{dn: string, attributes: array<string, array<int, string>>}  $entry  항목
     * @param  DirectoryClientInterface  $client  연결 (그룹 검색)
     * @return DirectoryEntry 계정
     *
     * @throws SsoException 고유 식별자 속성이 없으면 `ldap_unavailable`(설정 문제)
     */
    private function toDirectoryEntry(IdentityProvider $idp, array $entry, DirectoryClientInterface $client): DirectoryEntry
    {
        $values = $entry['attributes'];
        $subjectAttribute = mb_strtolower($this->subjectAttribute($idp));
        $rawSubject = $values[$subjectAttribute][0] ?? null;
        $subject = $rawSubject !== null && $subjectAttribute === 'objectguid' ? self::guidToString($rawSubject) : $rawSubject;
        if (! is_string($subject) || trim($subject) === '' || mb_strlen($subject) > 191 || ! mb_check_encoding($subject, 'UTF-8')) {
            throw new SsoException('ldap_unavailable', 'subject attribute missing: '.$subjectAttribute);
        }

        $email = $values[mb_strtolower((string) $idp->configValue('email_attribute', 'mail'))][0] ?? null;
        $email = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($email) : null;
        $name = $values[mb_strtolower((string) $idp->configValue('name_attribute', 'displayName'))][0] ?? ($values['cn'][0] ?? null);

        // 속성 이름은 디렉터리 연결이 소문자로 돌려준다 — 기본 목록과 속성 규칙의 출처를 소문자 이름으로 담는다. 여러 값이면 목록으로.
        $attributes = [];
        foreach (array_unique(array_merge(self::EXTRA_ATTRIBUTES, $this->ruleAttributes($idp))) as $key) {
            $list = [];
            foreach (array_slice($values[$key] ?? [], 0, self::MAX_VALUES) as $value) {
                if (is_string($value) && $value !== '' && mb_check_encoding($value, 'UTF-8')) {
                    $list[] = mb_substr($value, 0, 1000);
                }
            }
            if ($list !== []) {
                $attributes[$key] = count($list) === 1 ? $list[0] : $list;
            }
        }

        return new DirectoryEntry(
            subject: trim($subject),
            active: $this->isActive($idp, $values),
            email: $email,
            displayName: is_string($name) && $name !== '' ? mb_substr($name, 0, 255) : null,
            attributes: $attributes,
            groups: $this->groups($idp, $entry, $client),
        );
    }

    /**
     * 비활성 판정 — AD 는 userAccountControl 의 ACCOUNTDISABLE 비트, 속성 규칙은 그 값이 같은지. 「검색 결과 없음」 규칙은 찾았으면 활성.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  array<string, array<int, string>>  $values  속성
     * @return bool 활성이면 true
     */
    private function isActive(IdentityProvider $idp, array $values): bool
    {
        $rule = (string) $idp->configValue('disabled_rule', $this->preset($idp)['disabled_rule']);

        if ($rule === 'ad_uac') {
            $flags = (int) ($values['useraccountcontrol'][0] ?? 0);

            return ($flags & self::AD_ACCOUNT_DISABLE) === 0;
        }

        if ($rule === 'attribute') {
            $attribute = mb_strtolower((string) $idp->configValue('disabled_attribute', ''));
            $disabled = (string) $idp->configValue('disabled_value', '');

            return ! in_array($disabled, $values[$attribute] ?? [], true);
        }

        return true;
    }

    /**
     * 그룹 이름 — 그룹 속성(memberOf DN 이면 첫 RDN 값) + 그룹 검색 필터 결과의 cn.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  array{dn: string, attributes: array<string, array<int, string>>}  $entry  항목
     * @param  DirectoryClientInterface  $client  연결
     * @return array<int, string> 그룹
     */
    private function groups(IdentityProvider $idp, array $entry, DirectoryClientInterface $client): array
    {
        $fromDn = (bool) $idp->configValue('group_name_from_dn', true);
        $groups = [];
        foreach ($entry['attributes'][mb_strtolower((string) $idp->configValue('group_attribute', 'memberOf'))] ?? [] as $value) {
            $groups[] = $fromDn ? (self::firstRdnValue($value) ?? $value) : $value;
        }

        $groupFilter = trim((string) $idp->configValue('group_filter', ''));
        if ($groupFilter !== '' && $entry['dn'] !== '') {
            try {
                $filter = str_replace('{dn}', ldap_escape($entry['dn'], '', LDAP_ESCAPE_FILTER), $groupFilter);
                foreach ($client->search($this->baseDn($idp), $filter, ['cn'], 200) as $group) {
                    $name = $group['attributes']['cn'][0] ?? null;
                    if (is_string($name)) {
                        $groups[] = $name;
                    }
                }
            } catch (SsoException $e) {
                report($e);
            }
        }

        $clean = [];
        foreach ($groups as $group) {
            if (is_string($group) && trim($group) !== '' && mb_check_encoding($group, 'UTF-8')) {
                $clean[] = mb_substr(trim($group), 0, 255);
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * DN 의 첫 RDN 값 (`CN=인사팀,OU=Groups,…` → 인사팀). `\,` 와 `\xx` 이스케이프를 푼다.
     *
     * @param  string  $dn  DN
     * @return string|null 값, DN 이 아니면 null
     */
    public static function firstRdnValue(string $dn): ?string
    {
        if (preg_match('/^\s*[A-Za-z][A-Za-z0-9\-]*=((?:\\\\.|[^,\\\\])*)/', $dn, $m) !== 1) {
            return null;
        }

        $value = preg_replace_callback('/\\\\([0-9A-Fa-f]{2})/', static fn ($h) => chr((int) hexdec($h[1])), $m[1]) ?? $m[1];

        return trim((string) preg_replace('/\\\\(.)/', '$1', $value));
    }

    /**
     * 디렉터리 항목을 신원으로 바꿉니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  DirectoryEntry  $entry  계정
     * @return ExternalIdentity 신원
     */
    private function toIdentity(IdentityProvider $idp, DirectoryEntry $entry): ExternalIdentity
    {
        return new ExternalIdentity(
            subject: $entry->subject,
            subjectFormat: mb_substr($this->subjectAttribute($idp), 0, 100),
            email: $entry->email,
            emailVerified: null,
            displayName: $entry->displayName,
            attributes: $entry->attributes,
            groups: $entry->groups,
        );
    }

    /**
     * 기준 DN.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return string DN
     */
    private function baseDn(IdentityProvider $idp): string
    {
        return trim((string) $idp->configValue('base_dn', ''));
    }

    /**
     * 서비스 바인드 DN.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return string|null DN, 없으면 익명
     */
    private function bindDn(IdentityProvider $idp): ?string
    {
        $dn = trim((string) $idp->configValue('bind_dn', ''));

        return $dn !== '' ? $dn : null;
    }

    /**
     * 고유 식별자 속성 이름.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return string 속성
     */
    private function subjectAttribute(IdentityProvider $idp): string
    {
        return (string) $idp->configValue('subject_attribute', $this->preset($idp)['subject_attribute']);
    }

    /**
     * 디렉터리 종류의 기본값.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return array<string, mixed> 기본값
     */
    private function preset(IdentityProvider $idp): array
    {
        return self::PRESETS[(string) $idp->configValue('directory_type', 'openldap')] ?? self::PRESETS['openldap'];
    }

    /**
     * 값을 가립니다 (앞 2자만).
     *
     * @param  string  $value  값
     * @return string 가린 값
     */
    private static function mask(string $value): string
    {
        return mb_substr($value, 0, 2).str_repeat('*', max(3, min(8, mb_strlen($value) - 2)));
    }
}
