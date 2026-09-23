<?php

namespace Tests\Feature\Analytics;

use App\Listeners\ExtensionCompatibilityAlertListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 대시보드 알림 카드의 버튼 계약 — 복구냐 이동이냐를 가르는 두 필드 (이슈 #682 C1).
 *
 * 카드는 `recover_endpoint` 가 있으면 복구 버튼(POST), 없고 `action_url` 이 있으면 이동
 * 버튼(navigate)을 그린다. 종류(subtype)로 가르지 않는 것이 이 계약의 핵심이라, 리스너가
 * 어느 날 두 필드를 함께 실으면 같은 알림에 버튼이 둘 생기거나 복구가 이동으로 바뀐다.
 * 통계 리스너에는 이동 주소가, 기존 두 리스너에는 복구 주소만 있어야 한다.
 */
class DashboardAlertContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 확장 호환 알림이 복구 주소만 싣는지 확인합니다.
     *
     * 짝인 정적 게시 리스너에는 같은 단언이 있는데 이쪽에는 없어, 이 리스너만 조용히
     * 바뀔 수 있었다(계획 대조에서 드러남).
     *
     * @return void 없음
     *
     * @scenario screen=dashboard_widget, state=normal, viewport=1440, locale=ko
     *
     * @effects alert_with_recover_endpoint_keeps_recover_button
     */
    public function test_compatibility_alerts_carry_recover_wiring_without_navigation_url(): void
    {
        $listener = new class extends ExtensionCompatibilityAlertListener
        {
            /**
             * 자동 비활성 기록을 합성합니다.
             *
             * @return array<string, array<int, array<string, mixed>>> 유형별 기록
             */
            protected function resolveAutoDeactivated(): array
            {
                return ['plugins' => [[
                    'identifier' => 'sirsoft-demo',
                    'incompatible_required_version' => '7.2.0',
                    'deactivated_at' => null,
                ]]];
            }

            /**
             * 재호환 기록을 합성합니다.
             *
             * @return array<string, array<int, array<string, mixed>>> 유형별 기록
             */
            protected function resolveRecovered(): array
            {
                return ['plugins' => [[
                    'identifier' => 'sirsoft-demo',
                    'previously_required' => '7.2.0',
                    'deactivated_at' => null,
                ]]];
            }
        };

        $alerts = $listener->addCompatibilityAlerts([]);

        $this->assertNotEmpty($alerts, '합성 기록이 있으면 알림이 나와야 한다');

        $recovery = collect($alerts)->firstWhere('subtype', 'recovery_available');

        $this->assertNotNull($recovery, '재호환 알림이 있어야 한다');
        $this->assertSame('/api/admin/extensions/plugin/sirsoft-demo/recover', $recovery['recover_endpoint']);

        foreach ($alerts as $alert) {
            $this->assertArrayNotHasKey(
                'action_url',
                $alert,
                '이 리스너는 이동 주소를 싣지 않는다 — 실으면 카드가 복구 대신 이동 버튼을 그린다',
            );
        }
    }
}
