<?php

namespace Plugins\Sirsoft\Sso\Tests\Fixtures\Ldap;

use Plugins\Sirsoft\Sso\Drivers\Ldap\LdapDriver;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Services\Ldap\DirectoryClientInterface;
use Plugins\Sirsoft\Sso\Services\Ldap\LdapExtension;
use Plugins\Sirsoft\Sso\Services\ProtocolRegistry;

/**
 * LDAP 시험 도우미 — 가짜 디렉터리(OpenLDAP 모양, AD 모양)와 LDAP IdP 행.
 *
 * 비밀번호는 시험용 고정 문자열이다(실제 디렉터리 계정 값이 아니다). `PluginTestCase` 를 상속한 시험 클래스에서 쓴다.
 *
 * @since 1.0.0
 */
trait LdapFixtures
{
    /** 가짜 디렉터리 서비스 계정 비밀번호 */
    protected const FAKE_SERVICE_PASSWORD = 'fake-service-secret';

    /** 가짜 디렉터리 사용자 비밀번호 */
    protected const FAKE_USER_PASSWORD = 'fake-user-secret';

    /** OpenLDAP 모양 사용자의 entryUUID */
    protected const LEE_UUID = '6f1c2a3e-1111-4c4d-9a8b-0123456789ab';

    /** AD 모양 사용자의 objectGUID 문자열 */
    protected const PARK_GUID = '1b2c3d4e-5f60-4718-8a9b-aabbccddeeff';

    /** @var FakeDirectoryClient|null 이 시험의 가짜 디렉터리 */
    protected ?FakeDirectoryClient $directory = null;

    /**
     * 가짜 디렉터리를 컨테이너에 넣고 기본 항목(서비스 계정, ldap.lee, ad.park, 그룹)을 채웁니다.
     *
     * @return FakeDirectoryClient 가짜 디렉터리
     */
    protected function fakeDirectory(): FakeDirectoryClient
    {
        $directory = new FakeDirectoryClient;
        $directory
            ->add('cn=admin,dc=corp798,dc=test', ['cn' => 'admin'], self::FAKE_SERVICE_PASSWORD)
            ->add('uid=ldap.lee,ou=people,dc=corp798,dc=test', [
                'objectClass' => ['inetOrgPerson'],
                'uid' => 'ldap.lee',
                'mail' => 'lee@corp798.test',
                'displayName' => '이엘답',
                'cn' => '이엘답',
                'entryUUID' => self::LEE_UUID,
                'department' => '인사부',
            ], self::FAKE_USER_PASSWORD)
            ->add('cn=인사팀,ou=groups,dc=corp798,dc=test', [
                'objectClass' => ['groupOfNames'],
                'cn' => '인사팀',
                'member' => ['uid=ldap.lee,ou=people,dc=corp798,dc=test'],
            ])
            ->add('CN=Park AD,CN=Users,DC=corp798,DC=test', [
                'objectCategory' => 'person',
                'objectClass' => ['user'],
                'sAMAccountName' => 'ad.park',
                'userPrincipalName' => 'ad.park@corp798.test',
                'mail' => 'park@corp798.test',
                'displayName' => '박에이디',
                'objectGUID' => (string) hex2bin(self::guidBinaryHex(self::PARK_GUID)),
                'userAccountControl' => '512',
                'memberOf' => ['CN=인사팀,CN=Users,DC=corp798,DC=test', 'CN=Domain Users,CN=Users,DC=corp798,DC=test'],
            ], self::FAKE_USER_PASSWORD);

        app()->instance(DirectoryClientInterface::class, $directory);

        return $this->directory = $directory;
    }

    /**
     * GUID 문자열의 AD 바이너리 순서 16진.
     *
     * @param  string  $guid  GUID 문자열
     * @return string 16진 32자
     */
    protected static function guidBinaryHex(string $guid): string
    {
        return str_replace('\\', '', (string) LdapDriver::guidToFilterValue($guid));
    }

    /**
     * OpenLDAP 모양 LDAP IdP 행을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  더하거나 바꿀 속성 (`config` 는 병합)
     * @param  string  $key  IdP 키
     * @return IdentityProvider 만든 IdP
     */
    protected function ldapIdp(array $attributes = [], string $key = 'ldap_open'): IdentityProvider
    {
        $config = array_merge([
            'directory_type' => 'openldap',
            'host' => '127.0.0.1',
            'port' => 1389,
            'encryption' => 'none',
            'base_dn' => 'dc=corp798,dc=test',
            'bind_dn' => 'cn=admin,dc=corp798,dc=test',
            'user_filter' => LdapDriver::PRESETS['openldap']['user_filter'],
            'subject_attribute' => 'entryUUID',
            'group_filter' => '(&(objectClass=groupOfNames)(member={dn}))',
            'accept_username' => true,
            'disabled_rule' => 'not_found',
            'timeout' => 5,
        ], $attributes['config'] ?? []);
        unset($attributes['config']);

        return IdentityProvider::query()->create(array_merge([
            'key' => $key,
            'protocol' => LdapDriver::KEY,
            'enabled' => true,
            'label' => ['ko' => '사내 디렉터리', 'en' => 'Company directory'],
            'config' => $config,
            'secrets' => ['bind_password' => self::FAKE_SERVICE_PASSWORD],
            'owned_domains' => ['corp798.test'],
            'provisioning_mode' => 'jit',
            'require_consent' => false,
        ], $attributes));
    }

    /**
     * AD 모양 LDAP IdP 행을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  더하거나 바꿀 속성
     * @return IdentityProvider 만든 IdP
     */
    protected function adIdp(array $attributes = []): IdentityProvider
    {
        $config = array_merge([
            'directory_type' => 'ad',
            'base_dn' => 'DC=corp798,DC=test',
            'bind_dn' => 'cn=admin,dc=corp798,dc=test',
            'user_filter' => LdapDriver::PRESETS['ad']['user_filter'],
            'subject_attribute' => 'objectGUID',
            'group_filter' => '',
            'disabled_rule' => 'ad_uac',
        ], $attributes['config'] ?? []);
        unset($attributes['config']);

        return $this->ldapIdp(array_merge(['config' => $config], $attributes), 'ldap_ad');
    }

    /**
     * ext-ldap 이 없는 서버를 흉내 냅니다.
     *
     * @return void 반환값 없음
     */
    protected function withoutLdapExtension(): void
    {
        app()->instance(LdapExtension::class, new class extends LdapExtension
        {
            /**
             * 확장이 없다고 답합니다.
             *
             * @return bool 항상 false
             */
            public function loaded(): bool
            {
                return false;
            }
        });
        app(ProtocolRegistry::class)->flush();
    }
}
