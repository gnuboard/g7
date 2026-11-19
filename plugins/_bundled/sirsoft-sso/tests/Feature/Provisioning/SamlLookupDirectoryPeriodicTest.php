<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Provisioning;

use App\Enums\UserStatus;
use App\Models\User;
use Plugins\Sirsoft\Sso\DTO\DirectoryEntry;
use Plugins\Sirsoft\Sso\Services\Provisioning\PeriodicCheckService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * SAML 주기 점검의 보조 조회 디렉터리(P11) — `lookup_directory_idp_id` 의 LDAP IdP 로 `lookup_match`(email / subject) 값을 찾는다.
 *
 * @since 1.0.0
 */
class SamlLookupDirectoryPeriodicTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * 이메일로 찾기(기본) — 디렉터리에 없으면 퇴사, 있으면 그대로.
     *
     * @scenario lookup_match=email
     *
     * @effects deprovisioned_by_directory
     *
     * @return void 반환값 없음
     */
    public function test_directory_lookup_by_email(): void
    {
        $directory = $this->fakeDirectory();
        $ldap = $this->makeIdp(['protocol' => 'ldap']);
        $saml = $this->makeIdp(['protocol' => 'saml', 'config' => ['lookup_directory_idp_id' => $ldap->id], 'deprovision' => ['periodic' => true]]);
        $stay = User::factory()->create();
        $leave = User::factory()->create();
        $this->link($stay, $saml, 'nameid-stay', ['email' => 'stay@corp798.test']);
        $this->link($leave, $saml, 'nameid-leave', ['email' => 'leave@corp798.test']);
        $directory->entries['stay@corp798.test'] = new DirectoryEntry('guid-stay', true);

        $summary = app(PeriodicCheckService::class)->run(now());

        $this->assertSame(1, $summary['deprovisioned']);
        $this->assertSame(UserStatus::Active->value, $stay->fresh()->status);
        $this->assertSame(UserStatus::Blocked->value, $leave->fresh()->status);
        $this->assertEqualsCanonicalizing(['stay@corp798.test', 'leave@corp798.test'], $directory->lookups);
    }

    /**
     * subject 로 찾기 — NameID 가 사내 아이디일 때.
     *
     * @scenario lookup_match=subject
     *
     * @effects lookup_by_subject
     *
     * @return void 반환값 없음
     */
    public function test_directory_lookup_by_subject(): void
    {
        $directory = $this->fakeDirectory();
        $ldap = $this->makeIdp(['protocol' => 'ldap']);
        $saml = $this->makeIdp(['protocol' => 'saml', 'config' => ['lookup_directory_idp_id' => $ldap->id, 'lookup_match' => 'subject'], 'deprovision' => ['periodic' => true]]);
        $this->link(User::factory()->create(), $saml, 'ad.park');
        $directory->entries['ad.park'] = new DirectoryEntry('guid-park', true);

        app(PeriodicCheckService::class)->run(now());

        $this->assertSame(['ad.park'], $directory->lookups);
    }

    /**
     * 고른 디렉터리가 꺼져 있거나 LDAP 이 아니면 점검하지 않는다.
     *
     * @scenario lookup_directory=disabled|not_ldap
     *
     * @effects not_checked
     *
     * @return void 반환값 없음
     */
    public function test_disabled_or_wrong_directory_skips(): void
    {
        $directory = $this->fakeDirectory();
        $off = $this->makeIdp(['protocol' => 'ldap', 'enabled' => false]);
        $oidc = $this->makeIdp(['protocol' => 'oidc']);
        foreach ([$off, $oidc] as $target) {
            $saml = $this->makeIdp(['protocol' => 'saml', 'config' => ['lookup_directory_idp_id' => $target->id], 'deprovision' => ['periodic' => true]]);
            $this->link(User::factory()->create(), $saml, 'skip-'.$target->id, ['email' => 'skip'.$target->id.'@corp798.test']);
        }

        $summary = app(PeriodicCheckService::class)->run(now());

        $this->assertSame(0, $summary['idps']);
        $this->assertSame([], $directory->lookups);
    }
}
