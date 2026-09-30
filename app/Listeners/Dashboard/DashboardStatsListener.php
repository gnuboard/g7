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
            // 셀프 가입은 core.user.after_create 가 아니라 이 훅으로 발화한다 — 관리자가 만든 회원만 반영되던 것을 바로잡았다(#138 후속)
            'core.auth.after_register' => ['method' => 'handleStatsUpdate', 'priority' => 10],
            'core.user.after_update' => ['method' => 'handleStatsUpdate', 'priority' => 10],
            'core.user.after_delete' => ['method' => 'handleStatsUpdate', 'priority' => 10],
            // 복수형(core.plugins.*)이 실제 발화 이름이다 — 종전 단수형은 발화처가 없었다(#138).
            'core.plugins.after_activate' => ['method' => 'handleStatsUpdate', 'priority' => 10],
            'core.plugins.after_deactivate' => ['method' => 'handleStatsUpdate', 'priority' => 10],
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
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // 기본 핸들러는 사용하지 않음 - handleStatsUpdate 사용
    }

    /**
     * 대시보드 통계 업데이트를 브로드캐스트합니다.
     *
     * @param  mixed  ...$args  훅에서 전달된 인자
     * @return void 반환값 없음
     */
    public function handleStatsUpdate(...$args): void
    {
        HookManager::broadcast('core.admin.dashboard', 'dashboard.stats.updated', [
            'type' => 'stats',
            'data' => $this->dashboardService->getStats(),
        ]);
    }
}
