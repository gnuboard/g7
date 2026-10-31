<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Provisioning;

use App\Enums\UserStatus;
use App\Models\User;
use Plugins\Sirsoft\Sso\DTO\DirectoryEntry;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Services\Provisioning\PeriodicCheckService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * 주기 점검(D7 ②, W10) — LDAP 디렉터리, OIDC 갱신 토큰(`invalid_grant`, 회전), SAML 비대상, 한 회차 상한, 디렉터리 장애 오판 금지.
 *
 * @since 1.0.0
 */
class PeriodicCheckTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * LDAP — 없는 계정, 비활성 계정은 퇴사, 활성은 그대로. IdP 의 마지막 점검 시각이 남는다.
     *
     * @scenario protocol=ldap, entry=missing|disabled|active
     *
     * @effects deprovisioned_periodic, kept_active, idp_checked_at
     *
     * @return void 반환값 없음
     */
    public function test_ldap_lookup_deprovisions_missing_and_disabled(): void
    {
        $directory = $this->fakeDirectory();
        $idp = $this->makeIdp(['protocol' => 'ldap', 'deprovision' => ['periodic' => true, 'periodic_minutes' => 60]]);
        $gone = User::factory()->create();
        $disabled = User::factory()->create();
        $active = User::factory()->create();
        $this->link($gone, $idp, 'guid-gone');
        $this->link($disabled, $idp, 'guid-disabled');
        $this->link($active, $idp, 'guid-active');
        $directory->entries = [
            'guid-gone' => null,
            'guid-disabled' => new DirectoryEntry('guid-disabled', false),
            'guid-active' => new DirectoryEntry('guid-active', true),
        ];

        $summary = app(PeriodicCheckService::class)->run(now());

        $this->assertSame(['idps' => 1, 'checked' => 3, 'deprovisioned' => 2, 'reactivated' => 0, 'failed' => 0], $summary);
        $this->assertSame(UserStatus::Blocked->value, $gone->fresh()->status);
        $this->assertSame(UserStatus::Blocked->value, $disabled->fresh()->status);
        $this->assertSame(UserStatus::Active->value, $active->fresh()->status);
        $this->assertNotNull($idp->fresh()->periodic_checked_at);

        $again = app(PeriodicCheckService::class)->run(now()->addMinutes(10));
        $this->assertSame(0, $again['checked'], '간격(60분)이 지나지 않은 연결은 다시 보지 않는다');
    }

    /**
     * 디렉터리 장애면 퇴사로 오판하지 않고 그 회차를 멈춘다.
     *
     * @scenario protocol=ldap, directory=unavailable
     *
     * @effects no_deprovision, failed_counted
     *
     * @return void 반환값 없음
     */
    public function test_directory_outage_is_not_deprovision(): void
    {
        $directory = $this->fakeDirectory();
        $idp = $this->makeIdp(['protocol' => 'ldap', 'deprovision' => ['periodic' => true]]);
        $user = User::factory()->create();
        $this->link($user, $idp, 'guid-down');
        $directory->entries['guid-down'] = false;

        $summary = app(PeriodicCheckService::class)->run(now());

        $this->assertSame(1, $summary['failed']);
        $this->assertSame(0, $summary['deprovisioned']);
        $this->assertSame(UserStatus::Active->value, $user->fresh()->status);
    }

    /**
     * 한 회차 상한 — 남은 연결은 다음 회차가 이어서 본다.
     *
     * @scenario batch=2, accounts=3
     *
     * @effects split_over_runs
     *
     * @return void 반환값 없음
     */
    public function test_batch_limit_continues_next_run(): void
    {
        $directory = $this->fakeDirectory();
        $idp = $this->makeIdp(['protocol' => 'ldap', 'deprovision' => ['periodic' => true, 'periodic_minutes' => 60]]);
        foreach (['b1', 'b2', 'b3'] as $subject) {
            $this->link(User::factory()->create(), $idp, $subject);
            $directory->entries[$subject] = new DirectoryEntry($subject, true);
        }

        $first = app(PeriodicCheckService::class)->run(now(), 2);
        $second = app(PeriodicCheckService::class)->run(now()->addMinute(), 2);

        $this->assertSame(2, $first['checked']);
        $this->assertSame(1, $second['checked']);
        $this->assertSame(['b1', 'b2', 'b3'], $directory->lookups);
    }

    /**
     * OIDC — 로그인 때 보관한 갱신 토큰으로 확인: 정상은 새 토큰으로 교체(회전), `refresh_invalid` 는 `invalid_grant` → 퇴사.
     *
     * @scenario protocol=oidc, refresh=ok|invalid_grant
     *
     * @effects refresh_rotated, deprovisioned_periodic
     *
     * @return void 반환값 없음
     */
    public function test_oidc_refresh_rotation_and_invalid_grant(): void
    {
        $idp = $this->mockOidcIdp(['deprovision' => ['periodic' => true, 'periodic_minutes' => 60]]);
        $ok = User::factory()->create();
        $bad = User::factory()->create();
        $okAccount = $this->link($ok, $idp, 'oidc-ok');
        $badAccount = $this->link($bad, $idp, 'oidc-bad');

        $this->roundTrip($idp, 'full', ['sub' => 'oidc-ok']);
        $this->switchBrowser();
        $this->roundTrip($idp, 'refresh_invalid', ['sub' => 'oidc-bad']);
        $before = $okAccount->fresh()->refresh_token;
        $this->assertIsString($before);
        $this->assertIsString($badAccount->fresh()->refresh_token);

        $summary = app(PeriodicCheckService::class)->run(now()->addHours(2));

        $this->assertSame(2, $summary['checked']);
        $this->assertSame(1, $summary['deprovisioned']);
        $this->assertNotSame($before, $okAccount->fresh()->refresh_token);
        $this->assertSame(UserStatus::Active->value, $ok->fresh()->status);
        $this->assertSame(UserStatus::Blocked->value, $bad->fresh()->status);
        $this->assertNull($badAccount->fresh()->refresh_token);
    }

    /**
     * 주기 점검을 끈 IdP 는 보관하지 않고, 갱신 토큰이 없는 연결은 판정하지 않는다.
     *
     * @scenario protocol=oidc, periodic=off|no_refresh_token
     *
     * @effects no_token_stored, not_judged
     *
     * @return void 반환값 없음
     */
    public function test_oidc_without_periodic_or_token(): void
    {
        $idp = $this->mockOidcIdp(['deprovision' => ['periodic' => false]]);
        $user = User::factory()->create();
        $account = $this->link($user, $idp, 'oidc-off');
        $this->roundTrip($idp, 'full', ['sub' => 'oidc-off']);
        $this->assertNull($account->fresh()->refresh_token);

        $idp->forceFill(['deprovision' => ['periodic' => true]])->save();
        $summary = app(PeriodicCheckService::class)->run(now()->addHours(2));

        $this->assertSame(0, $summary['deprovisioned']);
        $this->assertSame(UserStatus::Active->value, $user->fresh()->status);
    }

    /**
     * SAML — 재직 조회 디렉터리를 고르지 않았으면 점검하지 않는다.
     *
     * @scenario protocol=saml, lookup_directory=none
     *
     * @effects not_checked
     *
     * @return void 반환값 없음
     */
    public function test_saml_without_directory_is_not_checked(): void
    {
        $idp = $this->makeIdp(['protocol' => 'saml', 'deprovision' => ['periodic' => true]]);
        $user = User::factory()->create();
        $this->link($user, $idp, 'saml-1');

        $summary = app(PeriodicCheckService::class)->run(now());

        $this->assertSame(0, $summary['idps']);
        $this->assertNull(SsoAccount::query()->where('subject', 'saml-1')->value('periodic_checked_at'));
    }
}
