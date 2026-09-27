<?php

namespace Tests\Feature\Analytics;

use App\Listeners\Analytics\AnalyticsAlertListener;
use App\Listeners\Analytics\AnalyticsNotificationDataListener;
use App\Models\Analytics\AnalyticsVisit;
use App\Models\NotificationDefinition;
use Database\Seeders\NotificationDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 방문자 통계 알림 — 본문의 자리가 숫자로 채워지는지.
 *
 * 이 리스너가 빠지면 메일 본문에 `{visits}` 가 그대로 적힌 채 발송된다. 발송 자체는
 * 성공하므로 아무도 눈치채지 못하고, 받는 사람만 이상한 메일을 본다.
 */
class NotificationDefinitionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 데이터 리스너가 구독하는 필터가 알림 체계의 이름과 맞는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=superadmin
     *
     * @effects notification_data_listener_subscribes_the_extract_filter
     */
    public function test_the_data_listener_subscribes_the_expected_filter(): void
    {
        $hooks = AnalyticsNotificationDataListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.analytics.notification.extract_data', $hooks);
        $this->assertSame('filter', $hooks['core.analytics.notification.extract_data']['type']);
    }

    /**
     * 집계 실패 알림의 자리가 값으로 채워지는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=superadmin
     *
     * @effects archive_failure_notification_carries_run_and_error
     */
    public function test_archive_failure_data_is_extracted(): void
    {
        $result = app(AnalyticsNotificationDataListener::class)->extractData(
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            'analytics_archive_failed',
            [['run_type' => 'day', 'period' => '2026-09-10', 'error' => '집계 중 예외']],
        );

        $this->assertSame(__('analytics.archive_run_type.day'), $result['data']['run_type'], '원시값(day)이 아니라 읽히는 이름이어야 한다');
        $this->assertSame('2026-09-10', $result['data']['period']);
        $this->assertSame('집계 중 예외', $result['data']['error']);
        $this->assertStringContainsString('/admin/analytics/diagnostics', $result['data']['diagnostics_url']);
        $this->assertSame('{recipient_name}', $result['data']['name']);
    }

    /**
     * 원장 한계 알림에 숫자와 설정 링크가 담기는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=superadmin
     *
     * @effects ledger_limit_notification_carries_counts_and_settings_link
     */
    public function test_ledger_limit_data_is_extracted(): void
    {
        $result = app(AnalyticsNotificationDataListener::class)->extractData(
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            'analytics_ledger_limit_exceeded',
            [['visits' => 600000, 'actions' => 2100000, 'threshold_visits' => 500000, 'threshold_actions' => 2000000, 'retention_days' => 90]],
        );

        $this->assertSame('600000', $result['data']['visits']);
        $this->assertSame('2100000', $result['data']['actions']);
        $this->assertSame('90', $result['data']['retention_days']);
        $this->assertStringContainsString('tab=retention', $result['data']['settings_url']);
    }

    /**
     * 리포트 알림의 상위 목록이 표로 바뀌고 증감률 없음이 줄표가 되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, role=superadmin
     *
     * @effects report_notification_renders_top_lists_and_handles_missing_change
     */
    public function test_report_data_renders_top_lists(): void
    {
        $result = app(AnalyticsNotificationDataListener::class)->extractData(
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            'analytics_weekly_report',
            [[
                'period_label' => '2026-09-07 ~ 2026-09-13',
                'visits' => 120,
                'visitors' => 90,
                'pageviews' => 340,
                'change_pct' => null,
                'top_pages' => [['label' => '/board/free', 'visits' => 50]],
                'top_referrers' => [],
            ]],
        );

        $this->assertSame('120', $result['data']['visits']);
        $this->assertSame('-', $result['data']['change_pct'], '직전 기간이 0 이면 비율이 아니라 줄표를 보인다');

        // 증감률은 부호와 단위를 달고 나간다 — 「12.5」만 보이면 늘었는지 줄었는지, 몇 배인지 알 수 없다.
        foreach ([[12.5, '+12.5%'], [-3.0, '-3.0%'], [0.0, '0.0%']] as [$raw, $shown]) {
            $formatted = app(AnalyticsNotificationDataListener::class)->extractData(
                ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
                'analytics_weekly_report',
                [['period_label' => '2026-09-07 ~ 2026-09-13', 'change_pct' => $raw]],
            );
            $this->assertSame($shown, $formatted['data']['change_pct']);
        }
        $this->assertStringContainsString('/board/free', $result['data']['top_pages']);
        $this->assertStringContainsString('<table>', $result['data']['top_pages']);
        $this->assertSame('', $result['data']['top_referrers'], '빈 목록은 빈 표가 아니라 아무것도 그리지 않는다');
    }

    /**
     * 모르는 알림 유형이 오면 기본값을 그대로 돌려주는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=superadmin
     *
     * @effects unknown_notification_types_pass_through
     */
    public function test_unknown_types_pass_through_untouched(): void
    {
        $default = ['notifiable' => null, 'notifiables' => null, 'data' => ['keep' => 'me'], 'context' => []];

        $result = app(AnalyticsNotificationDataListener::class)->extractData($default, 'some_other_type', [[]]);

        $this->assertSame($default, $result);
    }

    /**
     * 원장이 한계를 넘었을 때만 대시보드 알림이 붙는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=superadmin
     *
     * @effects dashboard_alert_appears_only_over_the_limit
     */
    public function test_dashboard_alert_is_added_only_when_over_the_limit(): void
    {
        config(['g7_settings.core.analytics.ledger_warning_visits' => 500000]);
        AnalyticsVisit::factory()->create();

        $listener = app(AnalyticsAlertListener::class);
        $this->assertSame([], $listener->addLedgerLimitAlert([]), '한계 안이면 알림을 붙이지 않는다');

        config(['g7_settings.core.analytics.ledger_warning_visits' => 1]);
        $alerts = $listener->addLedgerLimitAlert([]);

        $this->assertCount(1, $alerts);
        $this->assertSame('analytics_ledger_limit', $alerts[0]['id']);
        $this->assertSame('warning', $alerts[0]['type']);
        // 이동 버튼은 목적지를 열 수 있는 관리자에게만 붙는다 — 로그인 사용자가 없는 맥락에는 없다
        // (권한별 갈래는 LedgerAlertActionPermissionTest).
        $this->assertArrayNotHasKey('action_url', $alerts[0]);
        // 원클릭 복구는 성립하지 않는 알림이다(보존일을 고쳐야 한다) — 복구 배선이 붙으면 화면에
        // 누를 수 없는 「복구」 단추가 생긴다(대시보드 알림 카드 계약).
        $this->assertArrayNotHasKey('recover_endpoint', $alerts[0]);
        $this->assertStringNotContainsString('analytics.alerts', $alerts[0]['title'], '번역 키가 그대로 보이면 안 된다');
    }

    /**
     * 대시보드 알림 리스너가 알림 필터를 구독하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=superadmin
     *
     * @effects dashboard_alert_listener_subscribes_the_alerts_filter
     */
    public function test_the_alert_listener_subscribes_the_dashboard_filter(): void
    {
        $hooks = AnalyticsAlertListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.dashboard.alerts', $hooks);
        $this->assertSame('filter', $hooks['core.dashboard.alerts']['type']);
    }

    /**
     * 알림 정의 다섯 건이 채널·수신 대상과 함께 시딩되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=superadmin
     *
     * @effects analytics_notification_definitions_carry_channels_and_recipients
     */
    public function test_the_five_definitions_carry_their_channels_and_recipients(): void
    {
        $expected = [
            'analytics_archive_failed' => ['mail', 'database'],
            'analytics_ledger_limit_exceeded' => ['mail', 'database'],
            'analytics_visit_limit_exceeded' => ['database'],
            'analytics_weekly_report' => ['mail'],
            'analytics_monthly_report' => ['mail'],
        ];

        $this->seed(NotificationDefinitionSeeder::class);

        $this->assertSame(
            count($expected),
            NotificationDefinition::query()->where('type', 'like', 'analytics_%')->count(),
            '정의가 늘거나 줄면 이 목록과 함께 고친다',
        );

        foreach ($expected as $type => $channels) {
            $definition = NotificationDefinition::query()->where('type', $type)->first();

            $this->assertNotNull($definition, "{$type} 정의가 시딩되지 않았다");

            foreach ($channels as $channel) {
                $this->assertContains(
                    $channel,
                    (array) $definition->channels,
                    "{$type} 은 {$channel} 로도 나가야 한다",
                );
            }

            $this->assertTrue((bool) $definition->is_active, "{$type} 이 꺼진 채 시딩되면 아무것도 나가지 않는다");
        }

        // 수신 대상은 모델이 아니라 정의 설정이 소유한다 — 비면 발송 대상이 없어
        // 조용히 아무 일도 일어나지 않는다.
        $configured = collect(config('core.notification_definitions'))
            ->filter(fn (array $row, string $key): bool => str_starts_with(
                (string) ($row['type'] ?? $key),
                'analytics_',
            ));

        $this->assertCount(count($expected), $configured);

        // 수신 대상은 채널별 템플릿이 들고 있다.
        foreach ($configured as $key => $row) {
            $templates = (array) ($row['templates'] ?? []);

            $this->assertNotEmpty($templates, "{$key} 템플릿이 없으면 보낼 본문이 없다");

            foreach ($templates as $template) {
                $this->assertNotEmpty(
                    $template['recipients'] ?? [],
                    "{$key} 의 {$template['channel']} 채널 수신 대상이 비어 있다",
                );
            }
        }
    }
}
