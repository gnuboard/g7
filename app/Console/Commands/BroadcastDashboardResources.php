<?php

namespace App\Console\Commands;

use App\Extension\HookManager;
use App\Services\DashboardService;
use Illuminate\Console\Command;

/**
 * 시스템 리소스 정보를 주기적으로 브로드캐스트하는 커맨드
 *
 * 스케줄러를 통해 주기적으로 실행되어 대시보드에 실시간 시스템 리소스 정보를 전달합니다.
 */
class BroadcastDashboardResources extends Command
{
    /**
     * 콘솔 명령어 시그니처
     *
     * @var string
     */
    protected $signature = 'dashboard:broadcast-resources';

    /**
     * 콘솔 명령어 설명
     *
     * @var string
     */
    protected $description = '시스템 리소스 정보를 브로드캐스트합니다';

    /**
     * 커맨드를 실행합니다.
     *
     * @param  DashboardService  $dashboardService  대시보드 서비스
     */
    public function handle(DashboardService $dashboardService): int
    {
        HookManager::broadcast('core.admin.dashboard', 'dashboard.resources.updated', [
            'type' => 'resources',
            'data' => $dashboardService->getSystemResources(),
        ]);

        $this->info('시스템 리소스 정보가 브로드캐스트되었습니다.');

        return Command::SUCCESS;
    }
}
