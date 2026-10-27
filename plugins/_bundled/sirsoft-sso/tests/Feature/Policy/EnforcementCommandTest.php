<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Policy;

use App\Models\ActivityLog;
use Plugins\Sirsoft\Sso\Services\Policy\PolicyService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 강제 해제 명령 `sirsoft-sso:enforcement` — --status, --disable(보관), --restore.
 *
 * @since 1.0.0
 */
class EnforcementCommandTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 해제가 모든 강제를 끄고 이전 값을 보관하며, 되돌리기가 그 값을 살리는지 확인한다.
     *
     * @scenario option=status|disable|restore
     *
     * @effects enforcement_off, backup_saved, activity_log_cli, enforcement_restored
     *
     * @return void 반환값 없음
     */
    public function test_disable_and_restore(): void
    {
        $idp = $this->makeIdp('corp', ['enforce_owned_domains' => true]);
        $emergency = $this->makeSuper();
        $this->setPolicy(['enforce_site' => true, 'enforce_roles' => ['manager'], 'enforce_linked' => true, 'emergency_user_ids' => [$emergency->id]]);

        $this->artisan('sirsoft-sso:enforcement', ['--status' => true])->assertExitCode(0);
        $this->artisan('sirsoft-sso:enforcement', ['--disable' => true])->assertExitCode(0);

        $policy = app(PolicyService::class)->current();
        $this->assertFalse($policy['enforce_site']);
        $this->assertSame([], $policy['enforce_roles']);
        $this->assertFalse($policy['enforce_linked']);
        $this->assertFalse($idp->fresh()->enforce_owned_domains);
        $this->assertSame([(int) $idp->id], $policy['enforcement_backup']['idp_ids']);
        $this->assertSame([$emergency->id], $policy['emergency_user_ids']);
        $this->assertSame(1, ActivityLog::query()->where('action', 'sso.enforcement_disabled_cli')->count());

        $this->artisan('sirsoft-sso:enforcement', ['--restore' => true])->assertExitCode(0);

        $policy = app(PolicyService::class)->current();
        $this->assertTrue($policy['enforce_site']);
        $this->assertSame(['manager'], $policy['enforce_roles']);
        $this->assertTrue($policy['enforce_linked']);
        $this->assertTrue($idp->fresh()->enforce_owned_domains);
        $this->assertNull($policy['enforcement_backup']);
    }

    /**
     * 보관 값 없이 되돌리면 실패 코드인지 확인한다.
     *
     * @scenario option=restore, backup=none
     *
     * @effects restore_fails
     *
     * @return void 반환값 없음
     */
    public function test_restore_without_backup_fails(): void
    {
        $this->artisan('sirsoft-sso:enforcement', ['--restore' => true])->assertExitCode(1);
    }
}
