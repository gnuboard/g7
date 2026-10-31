<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Provisioning;

use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Services\Provisioning\DeprovisionService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * 퇴사 N일 뒤 탈퇴(D21) — 기간이 지났고 이 연결이 건 차단이 그대로인 회원만 익명화. 관리자는 건너뛰고 한 번 알림. 운영 환경 `--as-of` 거절.
 *
 * @since 1.0.0
 */
class WithdrawAfterDaysTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * 기간 전에는 그대로, 기간이 지나면 탈퇴 + 연결 정리.
     *
     * @scenario days=1, as_of=before|after
     *
     * @effects member_withdrawn, link_removed
     *
     * @return void 반환값 없음
     */
    public function test_withdraws_after_days(): void
    {
        $idp = $this->makeIdp(['deprovision' => ['withdraw_after_days' => 1]]);
        $user = User::factory()->create();
        $account = $this->link($user, $idp, 'wd-1');
        app(DeprovisionService::class)->deprovision($account, 'scim');

        $this->artisan('sirsoft-sso:withdraw-deprovisioned', ['--as-of' => now()->addHours(12)->toIso8601String()])->assertSuccessful();
        $this->assertSame(UserStatus::Blocked->value, $user->fresh()->status);

        $this->artisan('sirsoft-sso:withdraw-deprovisioned', ['--as-of' => now()->addDays(2)->toIso8601String()])->assertSuccessful();
        $this->assertSame(UserStatus::Withdrawn->value, $user->fresh()->status);
        $this->assertSame(0, SsoAccount::query()->where('user_id', $user->id)->count());
    }

    /**
     * 재활성된 회원, 관리자가 다시 차단한 회원, 탈퇴를 끈 IdP 는 건너뛴다.
     *
     * @scenario state=reactivated|reblocked|disabled_idp
     *
     * @effects member_kept
     *
     * @return void 반환값 없음
     */
    public function test_skips_reactivated_reblocked_and_disabled(): void
    {
        $idp = $this->makeIdp(['deprovision' => ['withdraw_after_days' => 1]]);
        $off = $this->makeIdp(['deprovision' => ['withdraw_after_days' => null]]);

        $reactivated = User::factory()->create();
        $a1 = $this->link($reactivated, $idp, 'wd-re');
        app(DeprovisionService::class)->deprovision($a1, 'scim');
        app(DeprovisionService::class)->reactivate($a1->fresh(), 'scim');

        $reblocked = User::factory()->create();
        $a2 = $this->link($reblocked, $idp, 'wd-block');
        app(DeprovisionService::class)->deprovision($a2, 'scim');
        $reblocked->forceFill(['blocked_at' => now()->addMinute()])->save();

        $offUser = User::factory()->create();
        app(DeprovisionService::class)->deprovision($this->link($offUser, $off, 'wd-off'), 'scim');

        $summary = app(DeprovisionService::class)->withdrawDue(now()->addDays(3));

        $this->assertSame(0, $summary['withdrawn']);
        $this->assertSame(UserStatus::Active->value, $reactivated->fresh()->status);
        $this->assertSame(UserStatus::Blocked->value, $reblocked->fresh()->status);
        $this->assertSame(UserStatus::Blocked->value, $offUser->fresh()->status);
    }

    /**
     * 관리자 계정은 탈퇴시키지 않고 탈퇴 건너뜀 알림을 한 번만 보낸다(최고관리자 퇴사 알림은 부르지 않는다).
     *
     * @scenario target=admin
     *
     * @effects admin_skipped, notice_once, no_super_admin_notice
     *
     * @return void 반환값 없음
     */
    public function test_admin_is_skipped_and_notified_once(): void
    {
        $idp = $this->makeIdp(['deprovision' => ['withdraw_after_days' => 1]]);
        $admin = User::factory()->create();
        $admin->roles()->attach($this->role('p3_admin_role', admin: true)->id);
        $account = $this->link($admin, $idp, 'wd-admin');
        app(DeprovisionService::class)->deprovision($account, 'scim');
        $notices = [];
        HookManager::addAction(DeprovisionService::ACTION_ADMIN_WITHDRAW_SKIPPED, function ($user, $link) use (&$notices) {
            $notices[] = [$user->id, $link->id];
        });
        $superNotices = 0;
        HookManager::addAction(DeprovisionService::ACTION_SUPER_ADMIN_DEPROVISIONED, function () use (&$superNotices) {
            $superNotices++;
        });

        $first = app(DeprovisionService::class)->withdrawDue(now()->addDays(3));
        app(DeprovisionService::class)->withdrawDue(now()->addDays(4));

        $this->assertSame(1, $first['skipped_admin']);
        $this->assertSame(UserStatus::Blocked->value, $admin->fresh()->status);
        $this->assertSame([[$admin->id, $account->id]], $notices);
        $this->assertSame(0, $superNotices);
    }

    /**
     * 운영 환경에서는 `--as-of` 를 거절한다.
     *
     * @scenario env=production, option=as_of
     *
     * @effects command_refused
     *
     * @return void 반환값 없음
     */
    public function test_as_of_refused_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('sirsoft-sso:withdraw-deprovisioned', ['--as-of' => now()->toIso8601String()])->assertFailed();
    }
}
