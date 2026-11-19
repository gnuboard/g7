<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Monitors;

use App\Extension\HookManager;
use Plugins\Sirsoft\SocialLogin\Services\SignupSurgeMonitor;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 가입 급증 감시 — 시간당 가입 수가 기준에 닿으면 하루 한 번 훅(알림 정의가 구독), 기준 0 은 꺼짐.
 *
 * @since 1.0.0
 */
class SurgeMonitorTest extends PluginTestCase
{
    /**
     * 기준 3: 세 번째 가입에 훅 1회(가입 수·기준 인자), 그 뒤로는 같은 날 다시 쏘지 않는다. 기준 0 이면 세지 않는다.
     *
     * @scenario threshold=3|0
     *
     * @effects surge_fires_at_threshold, surge_cooldown_daily, surge_zero_disables
     *
     * @return void 반환값 없음
     */
    public function test_surge_threshold_and_cooldown(): void
    {
        $fired = [];
        HookManager::addAction(SignupSurgeMonitor::ACTION_SURGE, function (string $key, int $count, int $threshold) use (&$fired) {
            $fired[] = [$key, $count, $threshold];
        });
        $this->setPluginSettings(['signup_surge_threshold_per_hour' => 3]);
        $key = 'sg'.substr(uniqid(), -6);

        foreach (range(1, 5) as $i) {
            app(SignupSurgeMonitor::class)->record($key);
        }
        $this->assertSame([[$key, 3, 3]], $fired);

        $this->setPluginSettings(['signup_surge_threshold_per_hour' => 0]);
        foreach (range(1, 5) as $i) {
            app(SignupSurgeMonitor::class)->record($key.'z');
        }
        $this->assertCount(1, $fired);
    }
}
