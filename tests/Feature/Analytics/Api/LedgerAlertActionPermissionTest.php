<?php

namespace Tests\Feature\Analytics\Api;

use App\Listeners\Analytics\AnalyticsAlertListener;
use App\Models\Analytics\AnalyticsVisit;

/**
 * 대시보드 원장 경고의 이동 버튼 권한 테스트
 *
 * 경고는 대시보드를 보는 모든 관리자에게 뜨지만, 「보존 설정 열기」 버튼의 목적지(통계 설정
 * 화면)는 `core.analytics.update` 를 요구한다. 그 권한이 없는 관리자에게 버튼을 주면 누르는
 * 순간 권한 없음 화면이다 — 버튼만 빼고 경고는 그대로 둔다(#682 S3 라운드 13).
 */
class LedgerAlertActionPermissionTest extends AnalyticsApiTestCase
{
    /**
     * 원장을 경고 한계 위로 올려 둡니다.
     *
     * @return void 없음
     */
    private function overTheLimit(): void
    {
        config(['g7_settings.core.analytics.ledger_warning_visits' => 1]);
        AnalyticsVisit::factory()->count(2)->create();
    }

    /**
     * 통계 설정 권한이 있으면 경고에 설정 화면으로 가는 버튼이 붙는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=update
     *
     * @effects dashboard_alert_jump_is_gated_by_destination_permission
     */
    public function test_settings_managers_get_the_jump_button(): void
    {
        $this->overTheLimit();
        $this->actingAs($this->adminWith(['core.dashboard.read', 'core.analytics.update']));

        $alerts = app(AnalyticsAlertListener::class)->addLedgerLimitAlert([]);

        $this->assertCount(1, $alerts);
        $this->assertStringEndsWith('/admin/analytics/settings?tab=retention', $alerts[0]['action_url']);
        $this->assertNotSame('', $alerts[0]['action_label']);
    }

    /**
     * 대시보드만 볼 수 있는 관리자에게는 경고만 뜨고 버튼은 붙지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=read
     *
     * @effects dashboard_alert_jump_is_gated_by_destination_permission
     */
    public function test_dashboard_only_admins_get_the_warning_without_the_button(): void
    {
        $this->overTheLimit();
        $this->actingAs($this->adminWith(['core.dashboard.read', 'core.analytics.read']));

        $alerts = app(AnalyticsAlertListener::class)->addLedgerLimitAlert([]);

        $this->assertCount(1, $alerts, '경고 자체는 권한과 무관하게 뜬다');
        $this->assertSame('analytics_ledger_limit', $alerts[0]['id']);
        $this->assertArrayNotHasKey('action_url', $alerts[0]);
        $this->assertArrayNotHasKey('action_label', $alerts[0]);
    }
}
