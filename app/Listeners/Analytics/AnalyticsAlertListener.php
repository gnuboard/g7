<?php

namespace App\Listeners\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Contracts\Extension\HookListenerInterface;
use App\Services\Analytics\StorageStatsService;

/**
 * 방문자 통계 대시보드 알림 리스너
 *
 * 원장이 경고 한계를 넘은 사실을 관리자 대시보드에 띄웁니다. 메일 알림은 하루 한 번만
 * 가고 그 메일을 놓치면 끝이지만, 대시보드는 운영자가 매일 보는 자리입니다.
 *
 * 대시보드 레이아웃·컨트롤러·API 스키마는 건드리지 않습니다 — 기존
 * `core.dashboard.alerts` 필터에 항목을 얹기만 합니다.
 */
class AnalyticsAlertListener implements HookListenerInterface
{
    /**
     * 알림 서비스를 받습니다.
     *
     * @param  StorageStatsService  $storage  저장 용량 현황 서비스
     */
    public function __construct(
        private readonly StorageStatsService $storage,
    ) {}

    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array 훅 이름 → 메서드/우선순위/타입 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.dashboard.alerts' => [
                'method' => 'addLedgerLimitAlert',
                'priority' => 20,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 훅 이벤트를 처리합니다 (HookListenerInterface 필수 메서드).
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     * @return void 없음 — 이 리스너는 필터로만 동작한다
     */
    public function handle(...$args): void {}

    /**
     * 원장이 한계를 넘었으면 대시보드 알림을 더합니다.
     *
     * @param  array  $alerts  기존 알림 배열
     * @return array 알림이 더해진 배열
     */
    public function addLedgerLimitAlert(array $alerts): array
    {
        $config = AnalyticsConfig::fromSettings();
        $stats = $this->storage->stats($config);

        if (! $stats['over_limit']) {
            return $alerts;
        }

        $alerts[] = [
            'id' => 'analytics_ledger_limit',
            'type' => 'warning',
            'subtype' => 'analytics_ledger_limit',
            'icon' => 'exclamation-triangle',
            'title' => __('analytics.alerts.ledger_limit_title'),
            'message' => __('analytics.alerts.ledger_limit_message', [
                'visits' => number_format($stats['exact']['analytics_visits']),
                'actions' => number_format($stats['exact']['analytics_actions']),
                'days' => $config->retentionDays,
            ]),
            'read' => false,
            // 운영자가 결함을 처음 만나는 곳이 알림이므로, 보존일을 고치러 가는 길을
            // 그 자리에 둔다.
            'action_url' => rtrim((string) config('app.url'), '/').'/admin/analytics/settings?tab=retention',
            'action_label' => __('analytics.alerts.ledger_limit_action'),
        ];

        return $alerts;
    }
}
