<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\PublicReviewListRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductReviewResource;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;

/**
 * 공개 상품 리뷰 컨트롤러
 *
 * 비로그인 사용자도 접근할 수 있는 상품 리뷰 목록 및 통계 조회 API를 제공합니다.
 */
class ProductReviewController extends PublicBaseController
{
    public function __construct(
        private ProductReviewService $reviewService
    ) {}

    /**
     * 상품 공개 리뷰 목록 및 별점 통계 조회
     *
     * @param  Request  $request  요청
     * @param  int  $productId  상품 ID
     * @return JsonResponse 리뷰 목록 및 별점 통계 JSON 응답
     */
    public function index(PublicReviewListRequest $request, int $productId): JsonResponse
    {
        try {
            $this->logApiUsage('review.index');
            $validated = $request->validated();
            $filters = $validated;
            $rawOptionFilters = $filters['option_filters'] ?? '{}';
            $filters['option_filters'] = is_array($rawOptionFilters)
                ? $rawOptionFilters
                : (json_decode($rawOptionFilters, true) ?? []);
            $perPage = (int) ($filters['per_page'] ?? 10);

            $result = $this->reviewService->getProductReviews($productId, $filters, $perPage);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.reviews.fetch_success',
                [
                    'reviews' => ProductReviewResource::collection($result['reviews'])->response()->getData(true),
                    'rating_stats' => $result['rating_stats'],
                    'option_filters' => $result['option_filters'],
                    'total_count' => $result['total_count'],
                ]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.reviews.fetch_failed',
                500
            );
        }
    }
}
