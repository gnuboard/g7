<?php

namespace Plugins\Sirsoft\Sso\Tests\Unit\Ldap;

use Plugins\Sirsoft\Sso\Drivers\Ldap\LdapDriver;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Services\ProtocolRegistry;
use Plugins\Sirsoft\Sso\Tests\Fixtures\Ldap\LdapFixtures;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * LDAP 드라이버 — 가짜 디렉터리로 인증 순서, 필터 이스케이프, 빈 비밀번호, 0/2건, AD 비활성, objectGUID, 장애를 확인한다.
 *
 * @since 1.0.0
 */
class LdapDriverTest extends PluginTestCase
{
    use LdapFixtures;

    /**
     * 레지스트리의 LDAP 드라이버.
     *
     * @return LdapDriver 드라이버
     */
    private function driver(): LdapDriver
    {
        return app(ProtocolRegistry::class)->get('ldap');
    }

    /**
     * 인증 실패 코드를 꺼냅니다.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  string  $identifier  아이디
     * @param  string  $password  비밀번호
     * @return string 오류 코드 (성공하면 빈 문자열)
     */
    private function failure(IdentityProvider $idp, string $identifier, string $password): string
    {
        try {
            $this->driver()->authenticate($idp, $identifier, $password);

            return '';
        } catch (SsoException $e) {
            return $e->errorCode;
        }
    }

    /**
     * 이메일과 사내 아이디 모두 같은 신원(entryUUID)으로 인증하고, 그룹 검색 필터로 그룹을 받는다.
     *
     * @scenario directory=openldap, identifier=email|username
     *
     * @effects identity_subject_entry_uuid, groups_from_filter, service_bind_then_user_bind
     *
     * @return void 반환값 없음
     */
    public function test_openldap_email_and_username_authenticate(): void
    {
        $this->fakeDirectory();
        $idp = $this->ldapIdp();

        $byEmail = $this->driver()->authenticate($idp, 'lee@corp798.test', self::FAKE_USER_PASSWORD);
        $byUid = $this->driver()->authenticate($idp, 'ldap.lee', self::FAKE_USER_PASSWORD);

        $this->assertSame(self::LEE_UUID, $byEmail->subject);
        $this->assertSame($byEmail->subject, $byUid->subject);
        $this->assertSame('entryUUID', $byEmail->subjectFormat);
        $this->assertSame('lee@corp798.test', $byEmail->email);
        $this->assertSame('이엘답', $byEmail->displayName);
        $this->assertSame(['인사팀'], $byEmail->groups);
        $this->assertSame('인사부', $byEmail->attributes['department'] ?? null);
        $this->assertNull($byEmail->sessionRef);

        $binds = array_values(array_filter($this->directory->calls, static fn ($c) => $c[0] === 'bind'));
        $this->assertSame('cn=admin,dc=corp798,dc=test', $binds[0][1]['dn'], '서비스 계정 바인드가 먼저');
        $this->assertSame('uid=ldap.lee,ou=people,dc=corp798,dc=test', $binds[1][1]['dn'], '그 다음 찾은 사용자 DN 으로 재바인드');
    }

    /**
     * 필터 특수문자를 넣은 아이디는 이스케이프되어 글자 그대로 비교된다 — 아무 계정도 찾지 못한다.
     *
     * @scenario identifier=injection(*)(uid=*)
     *
     * @effects filter_escaped, no_match, no_user_bind
     *
     * @return void 반환값 없음
     */
    public function test_filter_escape_blocks_injection(): void
    {
        $this->fakeDirectory();
        $idp = $this->ldapIdp();

        $this->assertSame('ldap_invalid_credentials', $this->failure($idp, '*)(uid=*', self::FAKE_USER_PASSWORD));

        $filter = $this->directory->filters()[0];
        $this->assertStringContainsString('\2a\29\28uid=\2a', $filter, '* ( ) 가 \2a \28 \29 로 이스케이프된다');
        $this->assertStringNotContainsString('(uid=*)', $filter);
        $this->assertSame(1, $this->directory->count('bind'), '사용자 재바인드 시도 없음(서비스 바인드 1회뿐)');
    }

    /**
     * 빈 비밀번호는 디렉터리에 닿기 전에 거절한다 — 익명 바인드 성공을 인증으로 오인하지 않는다.
     *
     * @scenario password=empty
     *
     * @effects rejected_before_connect, bind_attempts_zero
     *
     * @return void 반환값 없음
     */
    public function test_empty_password_never_binds(): void
    {
        $this->fakeDirectory();
        $idp = $this->ldapIdp();

        $this->assertSame('ldap_invalid_credentials', $this->failure($idp, 'ldap.lee', ''));
        $this->assertSame(0, $this->directory->count('connect'));
        $this->assertSame(0, $this->directory->count('bind'));
    }

    /**
     * 검색 결과가 0건이거나 2건 이상이면 거절한다(다른 사람 계정으로 로그인하지 않게).
     *
     * @scenario search_result=0|2
     *
     * @effects rejected, no_user_bind
     *
     * @return void 반환값 없음
     */
    public function test_zero_or_multiple_matches_are_rejected(): void
    {
        $this->fakeDirectory()->add('uid=lee.dup,ou=people,dc=corp798,dc=test', [
            'objectClass' => ['inetOrgPerson'], 'uid' => 'lee.dup', 'mail' => 'dup@corp798.test', 'entryUUID' => 'dup-1',
        ], self::FAKE_USER_PASSWORD)->add('uid=lee.dup2,ou=people,dc=corp798,dc=test', [
            'objectClass' => ['inetOrgPerson'], 'uid' => 'lee.dup2', 'mail' => 'dup@corp798.test', 'entryUUID' => 'dup-2',
        ], self::FAKE_USER_PASSWORD);
        $idp = $this->ldapIdp();

        $this->assertSame('ldap_invalid_credentials', $this->failure($idp, 'nobody@corp798.test', self::FAKE_USER_PASSWORD));
        $this->assertSame('ldap_invalid_credentials', $this->failure($idp, 'dup@corp798.test', self::FAKE_USER_PASSWORD));
        $userBinds = array_filter($this->directory->calls, static fn ($c) => $c[0] === 'bind' && $c[1]['dn'] !== 'cn=admin,dc=corp798,dc=test');
        $this->assertSame([], $userBinds);
    }

    /**
     * 틀린 비밀번호는 자격 증명 오류, 서비스 계정 오류와 서버 장애는 연결 불가로 구분한다.
     *
     * @scenario password=wrong, service_bind=wrong, server=down
     *
     * @effects invalid_credentials_vs_unavailable
     *
     * @return void 반환값 없음
     */
    public function test_wrong_password_and_outage_codes(): void
    {
        $this->fakeDirectory();
        $idp = $this->ldapIdp();
        $this->assertSame('ldap_invalid_credentials', $this->failure($idp, 'ldap.lee', 'wrong'));

        $badService = $this->ldapIdp(['secrets' => ['bind_password' => 'not-the-service-secret']], 'ldap_bad');
        $this->assertSame('ldap_unavailable', $this->failure($badService, 'ldap.lee', self::FAKE_USER_PASSWORD));

        $this->directory->failOn = 'connect';
        $this->assertSame('ldap_unavailable', $this->failure($idp, 'ldap.lee', self::FAKE_USER_PASSWORD), '타임아웃·연결 실패');
    }

    /**
     * AD 모양 — sAMAccountName, UPN, 이메일 모두 같은 objectGUID 문자열 신원, memberOf DN 의 첫 이름이 그룹.
     *
     * @scenario directory=ad, identifier=sAMAccountName|UPN|mail
     *
     * @effects guid_string_subject, groups_from_member_of
     *
     * @return void 반환값 없음
     */
    public function test_active_directory_guid_and_groups(): void
    {
        $this->fakeDirectory();
        $idp = $this->adIdp();

        foreach (['ad.park', 'ad.park@corp798.test', 'park@corp798.test'] as $identifier) {
            $identity = $this->driver()->authenticate($idp, $identifier, self::FAKE_USER_PASSWORD);
            $this->assertSame(self::PARK_GUID, $identity->subject, $identifier);
        }

        $this->assertSame(['인사팀', 'Domain Users'], $identity->groups);
        $this->assertSame('objectGUID', $identity->subjectFormat);
    }

    /**
     * AD 계정 사용 안 함(ACCOUNTDISABLE 비트)은 비밀번호가 맞아도 퇴사로 거절하고, 조회는 비활성으로 돌려준다.
     *
     * @scenario directory=ad, userAccountControl=514
     *
     * @effects login_rejected_deprovisioned, lookup_inactive
     *
     * @return void 반환값 없음
     */
    public function test_ad_disabled_account(): void
    {
        $directory = $this->fakeDirectory();
        $directory->entries['CN=Park AD,CN=Users,DC=corp798,DC=test']['attributes']['useraccountcontrol'] = ['514'];
        $idp = $this->adIdp();

        $this->assertSame('deprovisioned', $this->failure($idp, 'ad.park', self::FAKE_USER_PASSWORD));

        $entry = $this->driver()->lookup($idp, self::PARK_GUID);
        $this->assertNotNull($entry);
        $this->assertFalse($entry->active);
    }

    /**
     * 조회는 GUID 문자열(바이너리 이스케이프), 이메일, 사내 아이디로 같은 계정을 찾고, 없으면 null 이다.
     *
     * @scenario lookup=guid|email|username|missing
     *
     * @effects guid_binary_escaped, entry_or_null
     *
     * @return void 반환값 없음
     */
    public function test_lookup_by_guid_email_and_missing(): void
    {
        $this->fakeDirectory();
        $ad = $this->adIdp();
        $open = $this->ldapIdp();

        $this->assertSame(self::PARK_GUID, $this->driver()->lookup($ad, self::PARK_GUID)?->subject);
        $this->assertStringContainsString('(objectGUID='.LdapDriver::guidToFilterValue(self::PARK_GUID).')', $this->directory->filters()[0], 'GUID 는 바이트 단위 \xx 로 찾는다');
        $this->assertSame(self::LEE_UUID, $this->driver()->lookup($open, 'lee@corp798.test')?->subject);
        $this->assertSame(self::LEE_UUID, $this->driver()->lookup($open, 'ldap.lee')?->subject);
        $this->assertTrue($this->driver()->lookup($open, self::LEE_UUID)->active);
        $this->assertNull($this->driver()->lookup($open, 'gone@corp798.test'));
    }

    /**
     * 「속성 값」 비활성 규칙 — 지정한 속성이 그 값이면 퇴사로 본다.
     *
     * @scenario disabled_rule=attribute
     *
     * @effects rejected_deprovisioned
     *
     * @return void 반환값 없음
     */
    public function test_attribute_disabled_rule(): void
    {
        $directory = $this->fakeDirectory();
        $directory->entries['uid=ldap.lee,ou=people,dc=corp798,dc=test']['attributes']['employeestatus'] = ['left'];
        $idp = $this->ldapIdp(['config' => ['disabled_rule' => 'attribute', 'disabled_attribute' => 'employeeStatus', 'disabled_value' => 'left']]);

        $this->assertSame('deprovisioned', $this->failure($idp, 'ldap.lee', self::FAKE_USER_PASSWORD));
    }

    /**
     * 조회 중 디렉터리 장애는 「판정 보류」(directory_unavailable)로 — 퇴사로 오판하지 않는다.
     *
     * @scenario lookup=true, server=down
     *
     * @effects directory_unavailable
     *
     * @return void 반환값 없음
     */
    public function test_lookup_outage_is_directory_unavailable(): void
    {
        $this->fakeDirectory()->failOn = 'search';
        $idp = $this->ldapIdp();

        try {
            $this->driver()->lookup($idp, 'ldap.lee');
            $this->fail('장애인데 조회가 결과를 냈다');
        } catch (SsoException $e) {
            $this->assertSame('directory_unavailable', $e->errorCode);
        }
    }

    /**
     * objectGUID 바이트 순서 변환과 DN 첫 이름 풀기.
     *
     * @scenario helper=guid|rdn
     *
     * @effects conversions
     *
     * @return void 반환값 없음
     */
    public function test_guid_and_rdn_helpers(): void
    {
        $binary = (string) hex2bin(self::guidBinaryHex(self::PARK_GUID));
        $this->assertSame(16, strlen($binary));
        $this->assertSame(self::PARK_GUID, LdapDriver::guidToString($binary));
        $this->assertNull(LdapDriver::guidToString('short'));
        $this->assertNull(LdapDriver::guidToFilterValue('not-a-guid'));
        $this->assertSame('Sales, East', LdapDriver::firstRdnValue('CN=Sales\, East,OU=Groups,DC=x'));
        $this->assertSame('인사팀', LdapDriver::firstRdnValue('CN=\EC\9D\B8\EC\82\AC\ED\8C\80,OU=Groups'));
    }

    /**
     * 설정 검증 — 운영 환경 평문 거절, 필터 자리표시, 바인드 DN 비밀번호.
     *
     * @scenario validate_config=true
     *
     * @effects error_keys
     *
     * @return void 반환값 없음
     */
    public function test_validate_config(): void
    {
        $driver = $this->driver();
        $ok = ['host' => 'ldap.corp798.test', 'port' => 636, 'encryption' => 'ldaps', 'base_dn' => 'dc=x', 'user_filter' => '(uid={identifier})'];
        $this->assertSame([], $driver->validateConfig($ok, []));

        $errors = $driver->validateConfig(['host' => 'ldap://x', 'port' => 0, 'encryption' => 'bogus', 'base_dn' => '', 'user_filter' => 'uid=x', 'bind_dn' => 'cn=a', 'timeout' => 99], []);
        $this->assertEqualsCanonicalizing(['host', 'port', 'encryption', 'base_dn', 'user_filter', 'timeout', 'bind_password'], array_keys($errors));

        $this->app['env'] = 'production';
        try {
            $this->assertSame('sirsoft-sso::ldap.validation.plaintext_refused', $driver->validateConfig(array_merge($ok, ['encryption' => 'none']), [])['encryption'] ?? null);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    /**
     * 연결 시험 — 연결, 서비스 바인드, 기준 DN, 평문 경고와 「계정으로 시험」의 가린 결과.
     *
     * @scenario test_connection=true, test_account=true
     *
     * @effects checks_reported, values_masked
     *
     * @return void 반환값 없음
     */
    public function test_connection_and_account_checks(): void
    {
        $this->fakeDirectory();
        $idp = $this->ldapIdp();

        $result = $this->driver()->testConnection($idp);
        $this->assertSame(['connect', 'tls', 'bind', 'base_dn'], array_column($result->checks, 'key'));
        $this->assertFalse($result->ok, '평문 연결은 경고 항목으로 실패 표시');

        $account = $this->driver()->testAccount($idp, 'ldap.lee', self::FAKE_USER_PASSWORD);
        $this->assertTrue($account->ok);
        $this->assertStringNotContainsString(self::LEE_UUID, (string) $account->checks[0]['detail']);
        $this->assertStringNotContainsString('lee@corp798.test', (string) $account->checks[0]['detail']);
    }
}
