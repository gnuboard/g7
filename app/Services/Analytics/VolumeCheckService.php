<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Analytics\NotificationCooldown;
use App\Contracts\Repositories\Analytics\ActionRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Extension\HookManager;

/**
 * 원장 용량 점검
 *
 * 방문·액션 원장이 운영자가 정한 한계를 넘었는지 보고, 넘었으면 알림을 밀어 올립니다.
 * 방문자 통계는 조용히 자라는 표라, 디스크가 찰 때까지 아무도 모르는 것이 기본값입니다.
 *
 * 같은 경고를 하루에 한 번만 보냅니다 — 매시 배치가 같은 메일을 스무 통 보내면
 * 운영자는 그 알림을 통째로 무시하게 됩니다.
 */
class VolumeCheckService
{
    /**
     * 점검기를 생성합니다.
     *
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소
     * @param  ActionRepositoryInterface  $actions  액션 원장 저장소
     * @param  NotificationCooldown  $cooldown  알림 쿨다운
     */
    public function __construct(
        private readonly VisitRepositoryInterface $visits,
        private readonly ActionRepositoryInterface $actions,
        private readonly NotificationCooldown $cooldown,
    ) {}

    /**
     * 원장 크기를 재고 한계를 넘었으면 알립니다.
     *
     * @param  AnalyticsConfig  $config  설정
     * @return array{visits: int, actions: int, exceeded: bool, notified: bool} 점검 결과
     */
    public function check(AnalyticsConfig $config): array
    {
        $visits = $this->visits->countAll();
        $actions = $this->actions->countAll();

        $exceeded = $visits >= $config->ledgerWarningVisits || $actions >= $config->ledgerWarningActions;
        $notified = false;

        if ($exceeded && $this->cooldown->shouldNotify('ledger_limit_exceeded')) {
            HookManager::doAction('core.analytics.ledger_limit_exceeded', [
                'visits' => $visits,
                'actions' => $actions,
                'threshold_visits' => $config->ledgerWarningVisits,
                'threshold_actions' => $config->ledgerWarningActions,
                'retention_days' => $config->retentionDays,
            ]);

            $notified = true;
        }

        return [
            'visits' => $visits,
            'actions' => $actions,
            'exceeded' => $exceeded,
            'notified' => $notified,
        ];
    }
}
