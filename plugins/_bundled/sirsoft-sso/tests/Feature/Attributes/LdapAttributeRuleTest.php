<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Attributes;

use App\Models\User;
use Plugins\Sirsoft\Sso\Drivers\Ldap\LdapDriver;
use Plugins\Sirsoft\Sso\Drivers\Oidc\OidcDriver;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Models\SsoUserAttribute;
use Plugins\Sirsoft\Sso\Services\AttributeSyncService;
use Plugins\Sirsoft\Sso\Services\ProtocolRegistry;
use Plugins\Sirsoft\Sso\Tests\Fixtures\Ldap\LdapFixtures;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * 속성 규칙의 출처 이름 — LDAP 은 디렉터리 표준 이름(대소문자 섞임)으로 적어도 값을 받고, 규칙에 적은 속성은 기본 목록 밖이어도 검색
 * 요청에 들어간다. 비밀번호류 속성은 규칙에 적어도 요청하지 않는다. OIDC 클레임은 대소문자를 그대로 가리고, 규칙에 적은 클레임은
 * 기본 목록 밖이어도 신원에 담긴다.
 *
 * @since 1.0.0
 */
class LdapAttributeRuleTest extends PluginTestCase
{
    use LdapFixtures;
    use ProvisioningTestHelpers {
        LdapFixtures::fakeDirectory insteadof ProvisioningTestHelpers;
    }

    /** 시험 사용자 DN */
    private const KIM_DN = 'uid=ldap.kim,ou=people,dc=corp798,dc=test';

    /** 시험 사용자 entryUUID */
    private const KIM_UUID = '7a2d3b4f-2222-4c4d-9a8b-0123456789cd';

    /**
     * 부서, 사번, 직급, 고용 형태(여러 값)를 가진 OpenLDAP 사용자를 더한 가짜 디렉터리.
     *
     * @param  array<string, string|array<int, string>>  $extra  더할 속성
     * @return void 반환값 없음
     */
    private function directoryWithKim(array $extra = []): void
    {
        $this->fakeDirectory()->add(self::KIM_DN, array_merge([
            'objectClass' => ['inetOrgPerson'],
            'uid' => 'ldap.kim',
            'mail' => 'kim@corp798.test',
            'displayName' => '김디렉',
            'cn' => '김디렉',
            'entryUUID' => self::KIM_UUID,
            'departmentNumber' => 'D-720',
            'employeeNumber' => 'E720001',
            'title' => 'Manager',
            'employeeType' => ['정규직', '관리자'],
            'userPassword' => '{SSHA}not-a-real-hash',
        ], $extra), self::FAKE_USER_PASSWORD);
    }

    /**
     * 속성 규칙을 둔 OpenLDAP IdP.
     *
     * @param  array<int, array{field: string, source: string, mode: string}>  $rules  속성 규칙
     * @return IdentityProvider 만든 IdP 행
     */
    private function ldapIdpWithRules(array $rules): IdentityProvider
    {
        return $this->ldapIdp(['attribute_map' => $rules]);
    }

    /**
     * 회원의 추가 속성 (키 → 값).
     *
     * @param  int  $userId  회원 id
     * @return array<string, string> 추가 속성
     */
    private function savedAttributes(int $userId): array
    {
        return SsoUserAttribute::query()->where('user_id', $userId)->orderBy('key')->pluck('value', 'key')->all();
    }

    /**
     * 사내 아이디로 로그인한 회원 id.
     *
     * @param  IdentityProvider  $idp  로그인할 IdP
     * @return int 로그인으로 정해진 회원 id
     */
    private function loginKim(IdentityProvider $idp): int
    {
        $this->withCredentials()->postJson('/api/auth/login', ['email' => 'ldap.kim', 'password' => self::FAKE_USER_PASSWORD])->assertStatus(200);

        return (int) SsoAccount::query()->where('idp_id', $idp->id)->where('subject', self::KIM_UUID)->value('user_id');
    }

    /**
     * 디렉터리 표준 이름(employeeNumber, departmentNumber)으로 적은 규칙이 로그인 때 값을 받아 추가 속성으로 저장된다. 규칙의 출처는
     * 사용자 검색의 요청 속성에 들어가고, 여러 값 속성은 쉼표로 이어 저장된다.
     *
     * @scenario directory=openldap, source_case=standard, source_list=outside_default
     *
     * @effects rule_sources_requested, attribute_saved_case_insensitive, multi_value_joined
     *
     * @return void 반환값 없음
     */
    public function test_standard_case_sources_are_requested_and_saved_on_login(): void
    {
        $this->directoryWithKim();
        $idp = $this->ldapIdpWithRules([
            ['field' => 'attr:department', 'source' => 'departmentNumber', 'mode' => 'sync'],
            ['field' => 'attr:employee_no', 'source' => 'employeeNumber', 'mode' => 'sync'],
            ['field' => 'attr:title', 'source' => 'title', 'mode' => 'sync'],
            ['field' => 'attr:employee_type', 'source' => 'employeeType', 'mode' => 'sync'],
        ]);

        $userId = $this->loginKim($idp);

        $this->assertSame([
            'department' => 'D-720',
            'employee_no' => 'E720001',
            'employee_type' => '정규직, 관리자',
            'title' => 'Manager',
        ], $this->savedAttributes($userId));

        $requested = array_map('mb_strtolower', $this->directory->requestedAttributes()[0]);
        $this->assertContains('departmentnumber', $requested, '규칙의 출처가 사용자 검색 요청 속성에 들어간다');
        $this->assertContains('employeetype', $requested);
    }

    /**
     * 출처를 소문자로 적은 규칙도 같은 값을 받는다(LDAP 속성 이름은 대소문자를 가리지 않는다).
     *
     * @scenario directory=openldap, source_case=lower
     *
     * @effects attribute_saved_case_insensitive
     *
     * @return void 반환값 없음
     */
    public function test_lowercase_sources_get_same_values(): void
    {
        $this->directoryWithKim();
        $idp = $this->ldapIdpWithRules([
            ['field' => 'attr:department', 'source' => 'departmentnumber', 'mode' => 'sync'],
            ['field' => 'attr:employee_no', 'source' => 'EMPLOYEENUMBER', 'mode' => 'sync'],
        ]);

        $userId = $this->loginKim($idp);

        $this->assertSame(['department' => 'D-720', 'employee_no' => 'E720001'], $this->savedAttributes($userId));
    }

    /**
     * AD 의 기본 목록 밖 속성(employeeID, physicalDeliveryOfficeName)도 규칙에 적으면 받는다.
     *
     * @scenario directory=ad, source_list=outside_default
     *
     * @effects rule_sources_requested, attribute_saved_case_insensitive
     *
     * @return void 반환값 없음
     */
    public function test_active_directory_attributes_outside_default_list(): void
    {
        $this->fakeDirectory()->add('CN=Choi AD,CN=Users,DC=corp798,DC=test', [
            'objectCategory' => 'person',
            'objectClass' => ['user'],
            'sAMAccountName' => 'ad.choi',
            'mail' => 'choi@corp798.test',
            'displayName' => '최에이디',
            'objectGUID' => (string) hex2bin(self::guidBinaryHex('2c3d4e5f-6071-4829-9bac-bbccddeeff00')),
            'userAccountControl' => '512',
            'employeeID' => 'AD-0042',
            'physicalDeliveryOfficeName' => '본사 7층',
        ], self::FAKE_USER_PASSWORD);
        $idp = $this->adIdp(['attribute_map' => [
            ['field' => 'attr:employee_no', 'source' => 'employeeID', 'mode' => 'sync'],
            ['field' => 'attr:office', 'source' => 'physicalDeliveryOfficeName', 'mode' => 'first_only'],
        ]]);

        $identity = app(ProtocolRegistry::class)->get(LdapDriver::KEY)->authenticate($idp, 'ad.choi', self::FAKE_USER_PASSWORD);
        $user = User::factory()->create(['email' => 'choi@corp798.test']);
        $account = $this->link($user, $idp, $identity->subject);

        app(AttributeSyncService::class)->sync($user, $account, $idp, $identity, true);

        $this->assertSame(['employee_no' => 'AD-0042', 'office' => '본사 7층'], $this->savedAttributes((int) $user->id));
    }

    /**
     * 비밀번호류 속성은 규칙에 적어도 검색 요청에 넣지 않고 저장하지 않는다. LDAP 속성 이름이 될 수 없는 출처도 요청하지 않는다.
     *
     * @scenario directory=openldap, source=secret|invalid_name
     *
     * @effects secret_attribute_not_requested, attribute_not_saved
     *
     * @return void 반환값 없음
     */
    public function test_secret_and_invalid_sources_are_not_requested(): void
    {
        $this->directoryWithKim();
        $idp = $this->ldapIdpWithRules([
            ['field' => 'attr:pw', 'source' => 'userPassword', 'mode' => 'sync'],
            ['field' => 'attr:bad', 'source' => 'dept)(uid=*', 'mode' => 'sync'],
            ['field' => 'attr:title', 'source' => 'title', 'mode' => 'sync'],
        ]);

        $userId = $this->loginKim($idp);

        $this->assertSame(['title' => 'Manager'], $this->savedAttributes($userId));
        $requested = array_map('mb_strtolower', $this->directory->requestedAttributes()[0]);
        $this->assertNotContains('userpassword', $requested);
        $this->assertNotContains('dept)(uid=*', $requested);
    }

    /**
     * 「계정으로 시험」은 받은 속성 이름과, 규칙에 적었는데 디렉터리가 주지 않은 출처를 보여 준다(값은 보이지 않는다).
     *
     * @scenario surface=account_test, source=missing
     *
     * @effects received_names_listed, missing_source_listed
     *
     * @return void 반환값 없음
     */
    public function test_account_check_lists_missing_rule_sources(): void
    {
        $this->directoryWithKim();
        $idp = $this->ldapIdpWithRules([
            ['field' => 'attr:employee_no', 'source' => 'employeeNumber', 'mode' => 'sync'],
            ['field' => 'attr:cost_center', 'source' => 'costCenter', 'mode' => 'sync'],
        ]);

        $result = app(ProtocolRegistry::class)->get(LdapDriver::KEY)->testAccount($idp, 'ldap.kim', self::FAKE_USER_PASSWORD);
        $detail = (string) $result->checks[0]['detail'];

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('employeenumber', $detail);
        $this->assertStringContainsString('missing=costCenter', $detail);
        $this->assertStringNotContainsString('E720001', $detail, '값은 싣지 않는다');
    }

    /**
     * OIDC 클레임 이름은 대소문자를 그대로 가린다 — 규칙의 출처가 대소문자만 다르면 값을 받지 않는다.
     *
     * @scenario protocol=oidc, source_case=lower|standard
     *
     * @effects oidc_case_sensitive
     *
     * @return void 반환값 없음
     */
    public function test_oidc_sources_stay_case_sensitive(): void
    {
        $idp = $this->makeIdp(['attribute_map' => [
            ['field' => 'attr:employee_no', 'source' => 'employeenumber', 'mode' => 'sync'],
            ['field' => 'attr:title', 'source' => 'title', 'mode' => 'sync'],
        ]]);
        $user = User::factory()->create();
        $account = $this->link($user, $idp, 'oidc-case-1');
        $identity = new ExternalIdentity(subject: 'oidc-case-1', attributes: ['employeeNumber' => 'E1', 'title' => '대리']);

        app(AttributeSyncService::class)->sync($user, $account, $idp, $identity, false);

        $this->assertSame(['title' => '대리'], $this->savedAttributes((int) $user->id));
    }

    /**
     * OIDC 규칙에 적은 클레임은 기본 목록 밖이어도 신원에 담긴다(이름은 대소문자 그대로).
     *
     * @scenario protocol=oidc, source_list=outside_default
     *
     * @effects rule_claim_kept, oidc_case_sensitive
     *
     * @return void 반환값 없음
     */
    public function test_oidc_rule_claims_outside_default_list(): void
    {
        $idp = new IdentityProvider(['key' => 'corp_oidc', 'protocol' => 'oidc', 'config' => [], 'attribute_map' => [
            ['field' => 'attr:employee_no', 'source' => 'employee_id', 'mode' => 'sync'],
            ['field' => 'attr:cost_center', 'source' => 'CostCenter', 'mode' => 'sync'],
            ['field' => 'attr:org', 'source' => 'org_path', 'mode' => 'sync'],
        ]]);

        /** @var OidcDriver $driver */
        $driver = app(ProtocolRegistry::class)->get('oidc');
        $identity = $driver->identityFromClaims($idp, ['sub' => 's-1', 'employee_id' => 'E-777', 'costcenter' => 'CC-1', 'org_path' => ['본사', '인사']]);

        $this->assertSame('E-777', $identity->attributes['employee_id'] ?? null);
        $this->assertSame(['본사', '인사'], $identity->attributes['org_path'] ?? null);
        $this->assertArrayNotHasKey('costcenter', $identity->attributes, '대소문자가 다른 클레임은 담지 않는다');
        $this->assertArrayNotHasKey('CostCenter', $identity->attributes);
    }
}
