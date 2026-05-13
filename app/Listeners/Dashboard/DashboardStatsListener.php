<?php

namespace App\Listeners\Dashboard;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use App\Services\DashboardService;

/**
 * 대시보드 통계 리스너
 *
 * 사용자/플러그인 변경 시 대시보드 통계를 브로드캐스트합니다.
 */
class DashboardStatsListener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.after_create' => ['method' => 'handleStatsUpdate', 'priority' => 10],
            'core.user.after_update' => ['method' => 'handleStatsUpdate', 'priority' => 10],
            'core.user.after_delete' => ['method' => 'handleStatsUpdate', 'priority' => 10],
            'core.plugin.after_activate' => ['method' => 'handleStatsUpdate', 'priority' => 10],
            'core.plugin.after_deactivate' => ['method' => 'handleStatsUpdate', 'priority' => 10],
        ];
    }

    /**
     * 리스너 인스턴스를 생성합니다.
     *
     * @param  DashboardService  $dashboardService  대시보드 서비스
     */
    public function __construct(
        private DashboardService $dashboardService
    ) {}

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     */
    public function handle(...$args): void
    {
        // 기본 핸들러는 사용하지 않음 - handleStatsUpdate 사용
    }

    /**
     * 대시보드 통계 업데이트를 브로드캐스트합니다.
     *
     * @param  mixed  ...$args  훅에서 전달된 인자
     */
    public function handleStatsUpdate(...$args): void
    {
        HookManager::broadcast('core.admin.dashboard', 'dashboard.stats.updated', [
            'type' => 'stats',
            'data' => $this->dashboardService->getStats(),
        ]);
    }
}
