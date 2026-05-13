<?php

namespace App\Services;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Repositories\ActivityLogRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Helpers\TimezoneHelper;
use App\Models\ActivityLog;

/**
 * 대시보드 서비스
 *
 * 관리자 대시보드에 표시되는 통계, 리소스, 활동 등의 데이터를 제공합니다.
 */
class DashboardService
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private ActivityLogRepositoryInterface $activityLogRepository,
        private ModuleManagerInterface $moduleManager,
        private PluginManagerInterface $pluginManager
    ) {}

    /**
     * 대시보드 통계를 조회합니다.
     *
     * @return array 통계 데이터 배열
     */
    public function getStats(): array
    {
        // 훅: 대시보드 통계 조회 전 (IDV 정책 가드 지점)
        HookManager::doAction('core.dashboard.before_stats');

        $stats = [
            'total_users' => $this->getUserStats(),
            'installed_modules' => $this->getModuleStats(),
            'active_plugins' => $this->getPluginStats(),
            'system_status' => $this->getSystemStatus(),
        ];

        // 훅을 통한 통계 확장 지원
        return HookManager::applyFilters('core.dashboard.stats', $stats);
    }

    /**
     * 사용자 통계를 조회합니다.
     *
     * @return array 사용자 통계 배열
     */
    private function getUserStats(): array
    {
        $statistics = $this->userRepository->getStatistics();

        $totalUsers = $statistics['total_users'] ?? 0;
        $usersThisMonth = $statistics['users_this_month'] ?? 0;

        // 전월 사용자 수 추정 (전체 - 이번달 가입자)
        $previousMonthUsers = max(0, $totalUsers - $usersThisMonth);

        // 변화율 계산
        $changePercent = 0;
        if ($previousMonthUsers > 0) {
            $changePercent = round(($usersThisMonth / $previousMonthUsers) * 100, 1);
        }

        $trend = $usersThisMonth > 0 ? 'up' : ($usersThisMonth < 0 ? 'down' : 'up');
        $changeDisplay = "+{$usersThisMonth}";

        return [
            'count' => $totalUsers,
            'change_percent' => abs($changePercent),
            'change_display' => $changeDisplay,
            'trend' => $trend,
        ];
    }

    /**
     * 모듈 통계를 조회합니다.
     *
     * @return array 모듈 통계 배열
     */
    private function getModuleStats(): array
    {
        $allModules = $this->moduleManager->getAllModules();
        $activeModules = $this->moduleManager->getActiveModules();

        return [
            'total' => count($allModules),
            'active' => count($activeModules),
        ];
    }

    /**
     * 플러그인 통계를 조회합니다.
     *
     * @return array 플러그인 통계 배열
     */
    private function getPluginStats(): array
    {
        $allPlugins = $this->pluginManager->getAllPlugins();
        $activePlugins = $this->pluginManager->getActivePlugins();

        return [
            'total' => count($allPlugins),
            'active' => count($activePlugins),
        ];
    }

    /**
     * 시스템 상태를 조회합니다.
     *
     * @return array 시스템 상태 배열
     */
    private function getSystemStatus(): array
    {
        // 기본 시스템 상태 확인
        $allServicesRunning = $this->checkAllServicesRunning();

        $status = $allServicesRunning ? 'normal' : 'warning';
        $label = $allServicesRunning
            ? __('dashboard.stats.status_normal')
            : __('dashboard.stats.status_warning');

        return [
            'status' => $status,
            'label' => $label,
            'all_services_running' => $allServicesRunning,
        ];
    }

    /**
     * 모든 서비스가 정상 동작 중인지 확인합니다.
     *
     * @return bool 모든 서비스 정상 여부
     */
    private function checkAllServicesRunning(): bool
    {
        // TODO: 실제 서비스 상태 체크 로직 구현
        // 현재는 기본적으로 true 반환
        return true;
    }

    /**
     * 시스템 리소스 사용량을 조회합니다.
     *
     * @return array 리소스 사용량 배열
     */
    public function getSystemResources(): array
    {
        // IDV 정책 가드 지점 (계획서 #297 — 민감 관리자 조회 훅 커버리지)
        HookManager::doAction('core.dashboard.before_resources');

        $resources = [
            'cpu' => $this->getCpuUsage(),
            'memory' => $this->getMemoryUsage(),
            'disk' => $this->getDiskUsage(),
        ];

        // 훅을 통한 리소스 정보 확장 지원
        return HookManager::applyFilters('core.dashboard.resources', $resources);
    }

    /**
     * CPU 사용률을 조회합니다.
     *
     * @return array CPU 사용률 정보
     */
    protected function getCpuUsage(): array
    {
        $percentage = 0;

        if (PHP_OS_FAMILY === 'Windows') {
            $percentage = $this->getWindowsCpuUsage();
        } else {
            // Linux/Unix: /proc/stat에서 CPU 사용률 계산
            if (function_exists('sys_getloadavg')) {
                $load = sys_getloadavg();
                // 1분 평균 로드를 CPU 코어 수로 나눠서 백분율로 변환
                $cores = (int) shell_exec('nproc 2>/dev/null') ?: 1;
                $percentage = min(100, round(($load[0] / $cores) * 100));
            }
        }

        return [
            'percentage' => $percentage,
            'color' => $this->getResourceColor($percentage),
        ];
    }

    /**
     * Windows에서 CPU 사용률을 조회합니다.
     *
     * PowerShell Get-CimInstance를 우선 사용하고, 실패 시 wmic을 fallback으로 사용합니다.
     *
     * @return int CPU 사용률 (0-100)
     */
    private function getWindowsCpuUsage(): int
    {
        // PowerShell Get-CimInstance 시도 (Windows 10/11 권장)
        $output = shell_exec('powershell -NoProfile -Command "Get-CimInstance -ClassName Win32_Processor | Select-Object -ExpandProperty LoadPercentage" 2>&1');
        if ($output !== null) {
            $value = (int) trim($output);
            if ($value >= 0 && $value <= 100) {
                return $value;
            }
        }

        // wmic fallback (이전 Windows 버전)
        $output = shell_exec('wmic cpu get loadpercentage 2>&1');
        if ($output) {
            $lines = explode("\n", trim($output));
            if (isset($lines[1])) {
                return (int) trim($lines[1]);
            }
        }

        return 0;
    }

    /**
     * 메모리 사용량을 조회합니다.
     *
     * @return array 메모리 사용량 정보
     */
    protected function getMemoryUsage(): array
    {
        $used = 0;
        $total = 0;
        $percentage = 0;

        if (PHP_OS_FAMILY === 'Windows') {
            [$total, $used] = $this->getWindowsMemoryUsage();
        } else {
            // Linux: /proc/meminfo에서 메모리 정보 조회
            if (file_exists('/proc/meminfo')) {
                $memInfo = file_get_contents('/proc/meminfo');
                if (preg_match('/MemTotal:\s+(\d+)/', $memInfo, $matches)) {
                    $total = $matches[1] * 1024;
                }
                if (preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $matches)) {
                    $available = $matches[1] * 1024;
                    $used = $total - $available;
                }
            }
        }

        if ($total > 0) {
            $percentage = round(($used / $total) * 100);
        }

        return [
            'percentage' => $percentage,
            'used' => $this->formatBytes($used),
            'total' => $this->formatBytes($total),
            'color' => $this->getResourceColor($percentage),
        ];
    }

    /**
     * Windows에서 메모리 사용량을 조회합니다.
     *
     * PowerShell Get-CimInstance를 우선 사용하고, 실패 시 wmic을 fallback으로 사용합니다.
     *
     * @return array [total, used] 바이트 단위
     */
    private function getWindowsMemoryUsage(): array
    {
        $total = 0;
        $used = 0;

        // PowerShell Get-CimInstance 시도 (Windows 10/11 권장)
        $totalOutput = shell_exec('powershell -NoProfile -Command "(Get-CimInstance -ClassName Win32_ComputerSystem).TotalPhysicalMemory" 2>&1');
        $freeOutput = shell_exec('powershell -NoProfile -Command "(Get-CimInstance -ClassName Win32_OperatingSystem).FreePhysicalMemory" 2>&1');

        if ($totalOutput !== null && $freeOutput !== null) {
            $totalValue = (int) trim($totalOutput);
            $freeKb = (int) trim($freeOutput);

            if ($totalValue > 0) {
                $total = $totalValue;
                $free = $freeKb * 1024;
                $used = $total - $free;

                return [$total, $used];
            }
        }

        // wmic fallback (이전 Windows 버전)
        $totalOutput = shell_exec('wmic computersystem get totalphysicalmemory 2>&1');
        $freeOutput = shell_exec('wmic os get freephysicalmemory 2>&1');

        if ($totalOutput && $freeOutput) {
            $lines = explode("\n", trim($totalOutput));
            if (isset($lines[1])) {
                $total = (int) trim($lines[1]);
            }

            $lines = explode("\n", trim($freeOutput));
            if (isset($lines[1])) {
                $freeKb = (int) trim($lines[1]);
                $free = $freeKb * 1024;
                $used = $total - $free;
            }
        }

        return [$total, $used];
    }

    /**
     * 디스크 사용량을 조회합니다.
     *
     * @return array 디스크 사용량 정보
     */
    protected function getDiskUsage(): array
    {
        $path = PHP_OS_FAMILY === 'Windows' ? 'C:' : '/';

        $total = disk_total_space($path) ?: 0;
        $free = disk_free_space($path) ?: 0;
        $used = $total - $free;

        $percentage = $total > 0 ? round(($used / $total) * 100) : 0;

        return [
            'percentage' => $percentage,
            'used' => $this->formatBytes($used),
            'total' => $this->formatBytes($total),
            'color' => $this->getResourceColor($percentage),
        ];
    }

    /**
     * 리소스 사용률에 따른 색상을 반환합니다.
     *
     * @param  int  $percentage  사용률 (0-100)
     * @return string 색상명
     */
    private function getResourceColor(int $percentage): string
    {
        if ($percentage >= 90) {
            return 'red';
        } elseif ($percentage >= 70) {
            return 'yellow';
        } elseif ($percentage >= 50) {
            return 'blue';
        }

        return 'green';
    }

    /**
     * 바이트 단위를 읽기 쉬운 형태로 변환합니다.
     *
     * @param  int|float  $bytes  변환할 바이트 수
     * @return string 형식화된 문자열
     */
    private function formatBytes(int|float $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 1).' '.$units[$i];
    }

    /**
     * 최근 활동 내역을 조회합니다.
     *
     * ActivityLog 테이블에서 최근 활동을 조회하여
     * 대시보드 표시 형식으로 변환합니다.
     *
     * @param  int  $limit  조회할 활동 수
     * @return array 활동 내역 배열
     */
    public function getRecentActivities(int $limit = 5): array
    {
        $logs = $this->activityLogRepository->getRecent('core.dashboard.activities', $limit);

        $activities = $logs->map(function (ActivityLog $log) {
            return [
                'type' => $log->log_type->value,
                'icon' => $this->getActivityIcon($log->action),
                'icon_color' => $log->log_type->variant(),
                'title' => $log->localized_description,
                'description' => $log->actor_name,
                'time' => TimezoneHelper::toUserCarbon($log->created_at)?->diffForHumans(),
                'timestamp' => TimezoneHelper::toUserTimezone($log->created_at),
            ];
        })->toArray();

        // 훅을 통한 활동 내역 확장 지원
        $activities = HookManager::applyFilters('core.dashboard.activities', $activities, $limit);

        return array_slice($activities, 0, $limit);
    }

    /**
     * 활동 액션에 따른 아이콘을 반환합니다.
     *
     * @param string|null $action 액션 문자열
     * @return string 아이콘명
     */
    private function getActivityIcon(?string $action): string
    {
        if ($action === null) {
            return 'circle-info';
        }

        $lastSegment = last(explode('.', $action));

        return match ($lastSegment) {
            'create' => 'plus',
            'update' => 'pen-to-square',
            'delete' => 'trash',
            'login' => 'right-to-bracket',
            'logout' => 'right-from-bracket',
            'activate' => 'toggle-on',
            'deactivate' => 'toggle-off',
            'install' => 'download',
            'uninstall' => 'trash-can',
            default => 'circle-info',
        };
    }

    /**
     * 시스템 알림을 조회합니다.
     *
     * @return array 시스템 알림 배열
     */
    public function getSystemAlerts(): array
    {
        return HookManager::applyFilters('core.dashboard.alerts', []);
    }
}
