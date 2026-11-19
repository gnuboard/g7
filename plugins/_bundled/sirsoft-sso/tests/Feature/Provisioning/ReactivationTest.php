<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Provisioning;

use App\Enums\UserStatus;
use App\Models\User;
use Plugins\Sirsoft\Sso\DTO\DirectoryEntry;
use Plugins\Sirsoft\Sso\Services\Provisioning\DeprovisionService;
use Plugins\Sirsoft\Sso\Services\Provisioning\PeriodicCheckService;
use Plugins\Sirsoft\Sso\Services\Provisioning\ScimSchema;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * 재활성(W30) — SCIM `active=true`, 주기 점검 활성 확인, IdP 로그인 세 경로. 차단 시각이 이 연결의 기록과 다르면(그 뒤 관리자가 다시
 * 차단) 풀지 않는다.
 *
 * @since 1.0.0
 */
class ReactivationTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * SCIM `active=true` — 이 연결이 차단한 회원은 풀린다.
     *
     * @scenario path=scim, blocked_by=this_link
     *
     * @effects member_unblocked, account_active
     *
     * @return void 반환값 없음
     */
    public function test_scim_active_true_unblocks_member_blocked_by_link(): void
    {
        $idp = $this->makeIdp();
        $token = $this->issueScimToken($idp);
        $user = User::factory()->create();
        $account = $this->link($user, $idp, 're-scim', ['linked_via' => 'scim']);
        app(DeprovisionService::class)->deprovision($account, 'scim');
        $this->assertSame(UserStatus::Blocked->value, $user->fresh()->status);

        $this->scim('PATCH', $idp, '/Users/'.$account->id, $token, [
            'schemas' => [ScimSchema::PATCH_OP],
            'Operations' => [['op' => 'replace', 'value' => ['active' => true]]],
        ])->assertOk()->assertJsonPath('active', true);

        $this->assertSame(UserStatus::Active->value, $user->fresh()->status);
        $this->assertTrue($account->fresh()->active);
        $this->assertNull($account->fresh()->blocked_user_at);
    }

    /**
     * SCIM `active=true` 는 SCIM 이 끈 연결만 다시 켠다 — 주기 점검이 끈 연결은 그대로 두고(주기 점검 활성 확인·IdP 로그인이 푼다), 회원 차단도 유지된다.
     *
     * @scenario path=scim, deprovision_reason=periodic
     *
     * @effects member_still_blocked, account_inactive
     *
     * @return void 반환값 없음
     */
    public function test_scim_active_true_keeps_periodic_deprovision(): void
    {
        $idp = $this->makeIdp();
        $token = $this->issueScimToken($idp);
        $user = User::factory()->create();
        $account = $this->link($user, $idp, 're-scim-periodic', ['linked_via' => 'scim']);
        app(DeprovisionService::class)->deprovision($account, 'periodic');
        $this->assertSame(UserStatus::Blocked->value, $user->fresh()->status);

        $this->scim('PATCH', $idp, '/Users/'.$account->id, $token, [
            'schemas' => [ScimSchema::PATCH_OP],
            'Operations' => [['op' => 'replace', 'value' => ['active' => true]]],
        ])->assertOk()->assertJsonPath('active', false);

        $this->assertSame(UserStatus::Blocked->value, $user->fresh()->status);
        $this->assertFalse($account->fresh()->active);
        $this->assertSame('periodic', $account->fresh()->deprovision_reason);
    }

    /**
     * 퇴사 뒤 관리자가 다시 차단했으면(차단 시각이 다르면) SCIM 재활성도 회원 차단을 풀지 않는다.
     *
     * @scenario path=scim, blocked_by=admin_after
     *
     * @effects member_still_blocked, account_active
     *
     * @return void 반환값 없음
     */
    public function test_reblocked_by_admin_is_kept(): void
    {
        $idp = $this->makeIdp();
        $user = User::factory()->create();
        $account = $this->link($user, $idp, 're-admin');
        app(DeprovisionService::class)->deprovision($account, 'scim');
        $user->forceFill(['blocked_at' => now()->addMinute()])->save();

        app(DeprovisionService::class)->reactivate($account->fresh(), 'scim');

        $this->assertSame(UserStatus::Blocked->value, $user->fresh()->status);
        $this->assertTrue($account->fresh()->active);
    }

    /**
     * 주기 점검이 끈 연결은 다음 점검에서 활성으로 확인되면 다시 켜진다.
     *
     * @scenario path=periodic
     *
     * @effects member_unblocked
     *
     * @return void 반환값 없음
     */
    public function test_periodic_check_reactivates_periodic_deprovision(): void
    {
        $directory = $this->fakeDirectory();
        $idp = $this->makeIdp(['protocol' => 'ldap', 'deprovision' => ['periodic' => true, 'periodic_minutes' => 5]]);
        $user = User::factory()->create();
        $account = $this->link($user, $idp, 'guid-re-1');

        $directory->entries['guid-re-1'] = null;
        app(PeriodicCheckService::class)->run(now());
        $this->assertSame(UserStatus::Blocked->value, $user->fresh()->status);
        $this->assertSame('periodic', $account->fresh()->deprovision_reason);

        $directory->entries['guid-re-1'] = new DirectoryEntry('guid-re-1', true);
        $summary = app(PeriodicCheckService::class)->run(now()->addMinutes(10));

        $this->assertSame(1, $summary['reactivated']);
        $this->assertSame(UserStatus::Active->value, $user->fresh()->status);
        $this->assertTrue($account->fresh()->active);
    }

    /**
     * IdP 로그인 성공(주기 점검이 끈 연결) — 로그인이 연결을 다시 켜고 이 연결이 걸었던 차단을 푼다.
     *
     * @scenario path=login
     *
     * @effects member_unblocked, handoff_issued
     *
     * @return void 반환값 없음
     */
    public function test_login_reactivates_periodic_deprovision_and_unblocks(): void
    {
        $idp = $this->mockOidcIdp();
        $user = User::factory()->create();
        $account = $this->link($user, $idp, 're-login');
        app(DeprovisionService::class)->deprovision($account, 'periodic');
        $this->assertSame(UserStatus::Blocked->value, $user->fresh()->status);

        $response = $this->roundTrip($idp, 'full', ['sub' => 're-login']);

        $this->assertArrayHasKey('login_handoff', $this->redirectQuery($response));
        $this->assertSame(UserStatus::Active->value, $user->fresh()->status);
        $this->assertTrue($account->fresh()->active);
    }
}
