<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

/**
 * 관리자 대시보드 컨트롤러
 *
 * 대시보드에 표시되는 통계, 시스템 리소스, 최근 활동, 알림 등의 데이터를 제공합니다.
 */
class DashboardController extends AdminBaseController
{
    public function __construct(
        private DashboardService $dashboardService
    ) {
        parent::__construct();
    }

    /**
     * 대시보드 통계를 조회합니다.
     *
     * 총 사용자 수, 설치된 모듈 수, 활성 플러그인 수, 시스템 상태를 반환합니다.
     *
     * @return JsonResponse 통계 데이터를 포함한 JSON 응답
     */
    public function stats(): JsonResponse
    {
        try {
            $stats = $this->dashboardService->getStats();

            return $this->success('dashboard.stats_loaded', $stats);
        } catch (\Exception $e) {
            return $this->error('dashboard.stats_failed', 500, $e->getMessage());
        }
    }

    /**
     * 시스템 리소스 사용량을 조회합니다.
     *
     * CPU, 메모리, 디스크 사용량 정보를 반환합니다.
     *
     * @return JsonResponse 리소스 사용량을 포함한 JSON 응답
     */
    public function resources(): JsonResponse
    {
        try {
            $resources = $this->dashboardService->getSystemResources();

            return $this->success('dashboard.resources_loaded', $resources);
        } catch (\Exception $e) {
            return $this->error('dashboard.resources_failed', 500, $e->getMessage());
        }
    }

    /**
     * 최근 활동 내역을 조회합니다.
     *
     * 최근 사용자 등록, 모듈 활성화 등의 활동 내역을 반환합니다.
     *
     * @return JsonResponse 활동 내역을 포함한 JSON 응답
     */
    public function activities(): JsonResponse
    {
        try {
            $activities = $this->dashboardService->getRecentActivities();

            return $this->success('dashboard.activities_loaded', $activities);
        } catch (\Exception $e) {
            return $this->error('dashboard.activities_failed', 500, $e->getMessage());
        }
    }

    /**
     * 시스템 알림을 조회합니다.
     *
     * 시스템 업데이트, 경고 등의 알림을 반환합니다.
     *
     * @return JsonResponse 알림 목록을 포함한 JSON 응답
     */
    public function alerts(): JsonResponse
    {
        try {
            $alerts = $this->dashboardService->getSystemAlerts();

            return $this->success('dashboard.alerts_loaded', $alerts);
        } catch (\Exception $e) {
            return $this->error('dashboard.alerts_failed', 500, $e->getMessage());
        }
    }
}
