<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\DashboardRangeRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\PendingInquiryResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\RecentReviewResource;
use Modules\Sirsoft\Ecommerce\Services\EcommerceDashboardService;

/**
 * 이커머스 대시보드 컨트롤러
 *
 * 관리자 대시보드의 이커머스 영역(오늘 주문 현황/판매 추세 그래프/최신 리뷰/미답변 문의) API를 제공합니다.
 */
class DashboardController extends AdminBaseController
{
    /**
     * 모듈 식별자
     */
    private const MODULE = 'sirsoft-ecommerce';

    /**
     * 최신 리뷰/미답변 문의 카드 기본 표시 건수
     */
    private const DEFAULT_RECENT_LIMIT = 5;

    /**
     * 추세 그래프 기본 표시 일수
     */
    private const DEFAULT_GRAPH_DAYS = 7;

    /**
     * @param  EcommerceDashboardService  $dashboardService  대시보드 서비스
     */
    public function __construct(
        private readonly EcommerceDashboardService $dashboardService
    ) {
        parent::__construct();
    }

    /**
     * 오늘 주문 상태별 판매 수량(배지)을 조회합니다.
     *
     * @return JsonResponse 오늘 상태별 집계
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
     * 7일 판매 추세 그래프(막대 + 합계 + 변화율)를 조회합니다.
     *
     * @return JsonResponse 그래프 데이터
     */
    public function salesGraph(): JsonResponse
    {
        return ResponseHelper::moduleSuccess(
            self::MODULE,
            'messages.dashboard.fetch_success',
            $this->dashboardService->getSalesGraph(
                (int) g7_module_settings(self::MODULE, 'dashboard.graph_days', self::DEFAULT_GRAPH_DAYS)
            ),
        );
    }

    /**
     * 전체 상품의 최신 노출 리뷰를 조회합니다.
     *
     * @param  DashboardRangeRequest  $request  조회 파라미터
     * @return JsonResponse 최신 리뷰 목록
     */
    public function recentReviews(DashboardRangeRequest $request): JsonResponse
    {
        $reviews = $this->dashboardService->getRecentReviews($this->resolveLimit($request));

        return ResponseHelper::moduleSuccess(
            self::MODULE,
            'messages.dashboard.fetch_success',
            RecentReviewResource::collection($reviews),
        );
    }

    /**
     * 전체 상품의 미답변 문의 목록과 총 건수를 조회합니다.
     *
     * @param  DashboardRangeRequest  $request  조회 파라미터
     * @return JsonResponse 미답변 문의 목록 + 총 건수
     */
    public function pendingInquiries(DashboardRangeRequest $request): JsonResponse
    {
        $result = $this->dashboardService->getPendingInquiries($this->resolveLimit($request));

        return ResponseHelper::moduleSuccess(
            self::MODULE,
            'messages.dashboard.fetch_success',
            [
                'items' => PendingInquiryResource::collection($result['items']),
                'total' => $result['total'],
                'board_slug' => $result['board_slug'],
            ],
        );
    }

    /**
     * 요청의 limit 파라미터를 해석합니다 (미지정 시 설정값 또는 기본값).
     *
     * @param  DashboardRangeRequest  $request  조회 파라미터
     * @return int 표시 건수
     */
    private function resolveLimit(DashboardRangeRequest $request): int
    {
        return (int) ($request->validated('limit')
            ?? g7_module_settings(self::MODULE, 'dashboard.recent_limit', self::DEFAULT_RECENT_LIMIT));
    }
}
