<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Provisioning;

use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use App\Support\Auth\SystemOperation;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\Services\AttributeSyncService;
use Plugins\Sirsoft\Sso\Services\Provisioning\DeprovisionService;
use Plugins\Sirsoft\Sso\Services\Provisioning\ScimSchema;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * 시스템 작업 문맥(K19) — SCIM, 퇴사, N일 탈퇴, 속성 동기화가 모두 `SystemOperation::run()` 안에서 돈다(운영자가 건 사람용 본인인증
 * 정책이 대상 회원으로 판정돼 막히지 않게).
 *
 * 훅 정책 판정 자체는 코어 시험(`SystemOperationIdentityPolicyTest`)이 본다 — 여기서는 각 경로의 회원 변경 훅 시점에 문맥이 켜져 있는지 본다.
 *
 * @since 1.0.0
 */
class SystemOperationScopeTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /** @var array<int, array{0: string, 1: bool}> 훅 시점의 문맥 기록 */
    private array $seen = [];

    /**
     * 회원 변경 훅 3종에 문맥 기록기를 붙입니다.
     *
     * @return void 반환값 없음
     */
    private function watch(): void
    {
        foreach (['core.user.before_update', 'core.user.after_block', 'core.user.before_withdraw'] as $hook) {
            HookManager::addAction($hook, function () use ($hook) {
                $this->seen[] = [$hook, SystemOperation::active()];
            }, 1);
        }
    }

    /**
     * SCIM PATCH(이름), 퇴사, N일 뒤 탈퇴의 회원 변경이 시스템 작업 문맥에서 일어난다.
     *
     * @scenario path=scim_patch|deprovision|withdraw
     *
     * @effects system_operation_active
     *
     * @return void 반환값 없음
     */
    public function test_system_paths_run_inside_system_operation(): void
    {
        $idp = $this->makeIdp(['deprovision' => ['scim' => true, 'withdraw_after_days' => 1]]);
        $token = $this->issueScimToken($idp);
        $user = User::factory()->create(['name' => '전 이름']);
        $account = $this->link($user, $idp, 'sysop-1', ['linked_via' => 'scim']);
        $this->watch();

        $this->scim('PATCH', $idp, '/Users/'.$account->id, $token, [
            'schemas' => [ScimSchema::PATCH_OP],
            'Operations' => [['op' => 'replace', 'path' => 'displayName', 'value' => '새 이름']],
        ])->assertOk();
        app(DeprovisionService::class)->deprovision($account->fresh(), 'scim');
        app(DeprovisionService::class)->withdrawDue(now()->addDays(3));

        $this->assertSame(UserStatus::Withdrawn->value, $user->fresh()->status);
        $hooks = array_column($this->seen, 0);
        $this->assertContains('core.user.before_update', $hooks);
        $this->assertContains('core.user.after_block', $hooks);
        $this->assertContains('core.user.before_withdraw', $hooks);
        $this->assertNotContains(false, array_column($this->seen, 1), json_encode($this->seen));
        $this->assertFalse(SystemOperation::active());
    }

    /**
     * 로그인 중 속성 동기화의 회원 수정도 시스템 작업 문맥에서 일어난다.
     *
     * @scenario path=attribute_sync
     *
     * @effects system_operation_active
     *
     * @return void 반환값 없음
     */
    public function test_attribute_sync_runs_inside_system_operation(): void
    {
        $idp = $this->makeIdp(['attribute_map' => [['field' => 'name', 'source' => 'display_name', 'mode' => 'sync']]]);
        $user = User::factory()->create(['name' => '옛 이름']);
        $account = $this->link($user, $idp, 'sysop-2');
        $this->watch();

        app(AttributeSyncService::class)->sync($user, $account, $idp, new ExternalIdentity(subject: 'sysop-2', displayName: '새 이름'), false);

        $this->assertSame('새 이름', $user->fresh()->name);
        $this->assertSame([['core.user.before_update', true]], $this->seen);
    }
}
