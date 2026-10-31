<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Policy;

use App\Extension\HookManager;
use App\Models\ActivityLog;
use Plugins\Sirsoft\Sso\Services\Policy\EmergencyAccess;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * 비상 계정 로그인 기록(D12, W4) — 강제 중 비밀번호 로그인이면 활동 로그 + 알림 훅, 강제가 없거나 회사 계정 로그인이면 없음.
 *
 * @since 1.0.0
 */
class EmergencyAccessTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 강제 중 비상 계정의 비밀번호 로그인을 기록하는지 확인한다.
     *
     * @scenario enforcement=on, login_method=password|sso
     *
     * @effects activity_log_emergency_login, emergency_hook_once
     *
     * @return void 반환값 없음
     */
    public function test_records_emergency_password_login_while_enforced(): void
    {
        $this->makeIdp('corp', ['enforce_owned_domains' => true]);
        $emergency = $this->makeSuper();
        $this->setPolicy(['emergency_user_ids' => [$emergency->id]]);
        $fired = [];
        HookManager::addAction(EmergencyAccess::ACTION_EMERGENCY_LOGIN, function ($user) use (&$fired) {
            $fired[] = $user->id;
        });

        HookManager::doAction('core.auth.after_login', $emergency, ['login_method' => 'password']);
        HookManager::doAction('core.auth.after_login', $emergency, ['login_method' => 'sso:corp']);

        $this->assertSame([$emergency->id], $fired);
        $this->assertSame(1, ActivityLog::query()->where('action', 'sso.emergency_login')->where('loggable_id', $emergency->id)->count());
    }

    /**
     * 강제가 꺼져 있거나 비상 계정이 아니면 기록하지 않는지 확인한다.
     *
     * @scenario enforcement=off|on, emergency=no
     *
     * @effects nothing_recorded
     *
     * @return void 반환값 없음
     */
    public function test_no_record_without_enforcement_or_for_others(): void
    {
        $emergency = $this->makeSuper();
        $member = $this->makeMember();
        $this->setPolicy(['emergency_user_ids' => [$emergency->id]]);

        HookManager::doAction('core.auth.after_login', $emergency, ['login_method' => 'password']);

        $this->setPolicy(['enforce_linked' => true]);
        HookManager::doAction('core.auth.after_login', $member, ['login_method' => 'password']);

        $this->assertSame(0, ActivityLog::query()->where('action', 'sso.emergency_login')->count());
    }
}
