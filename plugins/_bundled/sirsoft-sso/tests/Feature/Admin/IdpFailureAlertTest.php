<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Admin;

use App\Extension\HookManager;
use Plugins\Sirsoft\Sso\Listeners\Admin\IdpFailureMonitorListener;
use Plugins\Sirsoft\Sso\Models\SsoLoginAttempt;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * IdP 연속 실패 알림(W16) — 기준(5회 / 10분)에 닿으면 하루 한 번, 정책 거절(denied)은 세지 않는다.
 *
 * @since 1.0.0
 */
class IdpFailureAlertTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 실패 1건을 기록하고 훅을 냅니다 (P1 흐름의 기록 지점과 같은 순서).
     *
     * @param  \Plugins\Sirsoft\Sso\Models\IdentityProvider  $idp  대상 IdP
     * @param  string  $outcome  결과 (failed 실패, denied 정책 거절)
     * @return void 반환값 없음
     */
    private function recordFailure($idp, string $outcome = 'failed'): void
    {
        SsoLoginAttempt::query()->create(['idp_id' => $idp->id, 'intent' => 'login', 'guard' => 'user', 'outcome' => $outcome, 'reason_code' => 'token_error', 'created_at' => now()]);
        HookManager::doAction('sirsoft-sso.auth.login_failed', $idp, 'token_error', 'login', 'user', $outcome);
    }

    /**
     * 기준에 닿을 때 한 번만 알림 훅이 나는지 확인한다.
     *
     * @scenario failures=4|5|7, outcome=failed|denied
     *
     * @effects fires_at_threshold, once_per_day, denied_not_counted
     *
     * @return void 반환값 없음
     */
    public function test_threshold_once_per_day(): void
    {
        $idp = $this->makeIdp('corp');
        $fired = [];
        HookManager::addAction(IdpFailureMonitorListener::ACTION_FAILING, function ($failing, $count) use (&$fired) {
            $fired[] = $count;
        });

        for ($i = 0; $i < 6; $i++) {
            $this->recordFailure($idp, 'denied');
        }
        for ($i = 0; $i < 4; $i++) {
            $this->recordFailure($idp);
        }
        $this->assertSame([], $fired, '정책 거절과 기준 미만은 알리지 않는다');

        $this->recordFailure($idp);
        $this->recordFailure($idp);
        $this->recordFailure($idp);

        $this->assertSame([5], $fired);
    }
}
