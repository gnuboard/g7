<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Board\Http\Requests\Admin\DashboardRangeRequest;
use Modules\Sirsoft\Board\Http\Resources\PendingReportResource;
use Modules\Sirsoft\Board\Http\Resources\RecentPostResource;
use Modules\Sirsoft\Board\Services\BoardDashboardService;

/**
 * 게시판 대시보드 컨트롤러
 *
 * 관리자 대시보드의 게시판 영역(오늘 현황/추세 그래프/최신글/미처리 신고) API를 제공합니다.
 */
class DashboardController extends AdminBaseController
{
    /**
     * 모듈 식별자
     */
    private const MODULE = 'sirsoft-board';

    /**
     * 최신글/신고 카드 기본 표시 건수
     */
    private const DEFAULT_RECENT_LIMIT = 5;

    /**
     * 추세 그래프 기본 표시 일수
     */
    private const DEFAULT_GRAPH_DAYS = 7;

    /**
     * @param  BoardDashboardService  $dashboardService  대시보드 서비스
     */
    public function __construct(
        private readonly BoardDashboardService $dashboardService
    ) {}

    /**
     * 오늘 새 글/새 댓글 현황을 조회합니다.
     *
     * @return JsonResponse 오늘 집계
     */
    public function overview(): JsonResponse
    {
        return ResponseHelper::moduleSuccess(
            self::MODULE,
            'messages.dashboard.fetch_success',
            $this->dashboardService->getOverview(),
        );
    }

    /**
     * 7일 추세 그래프(막대 + 합계 + 변화율)를 조회합니다.
     *
     * @return JsonResponse 그래프 데이터
     */
    public function postGraph(): JsonResponse
    {
        return ResponseHelper::moduleSuccess(
            self::MODULE,
            'messages.dashboard.fetch_success',
            $this->dashboardService->getPostGraph(self::DEFAULT_GRAPH_DAYS),
        );
    }

    /**
     * 전체 게시판의 최신 게시글을 조회합니다.
     *
     * @param  DashboardRangeRequest  $request  조회 파라미터
     * @return JsonResponse 최신 게시글 목록
     */
    public function recentPosts(DashboardRangeRequest $request): JsonResponse
    {
        $limit = (int) ($request->validated('limit') ?? self::DEFAULT_RECENT_LIMIT);

        $posts = $this->dashboardService->getRecentPosts($limit);

        return ResponseHelper::moduleSuccess(
            self::MODULE,
            'messages.dashboard.fetch_success',
            RecentPostResource::collection($posts),
        );
    }

    /**
     * 전체 게시판의 미처리 신고 목록과 총 건수를 조회합니다.
     *
     * @param  DashboardRangeRequest  $request  조회 파라미터
     * @return JsonResponse 미처리 신고 목록 + 총 건수
     */
    public function pendingReports(DashboardRangeRequest $request): JsonResponse
    {
        $limit = (int) ($request->validated('limit') ?? self::DEFAULT_RECENT_LIMIT);

        $result = $this->dashboardService->getPendingReports($limit);

        return ResponseHelper::moduleSuccess(
            self::MODULE,
            'messages.dashboard.fetch_success',
            [
                'items' => PendingReportResource::collection($result['items']),
                'total' => $result['total'],
            ],
        );
    }
}
