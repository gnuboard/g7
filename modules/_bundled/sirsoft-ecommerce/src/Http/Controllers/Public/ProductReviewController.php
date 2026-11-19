<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Concerns\EnsuresPublicProduct;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\PublicReviewListRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\PublicProductReviewResource;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;
use Modules\Sirsoft\Ecommerce\Support\MemberDisplayName;

/**
 * 공개 상품 리뷰 컨트롤러
 *
 * 비로그인 사용자도 접근할 수 있는 상품 리뷰 목록 및 통계 조회 API를 제공합니다.
 */
class ProductReviewController extends PublicBaseController
{
    use EnsuresPublicProduct;

    /**
     * 상품 후기 서비스를 주입받습니다.
     *
     * @param  ProductReviewService  $reviewService  상품 후기 서비스
     */
    public function __construct(
        private ProductReviewService $reviewService
    ) {}

    /**
     * 상품 공개 리뷰 목록 및 별점 통계 조회
     *
     * @param  PublicReviewListRequest  $request  요청
     * @param  Product  $product  라우트 바인딩된 상품 (product_code 또는 id)
     * @return JsonResponse 리뷰 목록 및 별점 통계 JSON 응답
     */
    public function index(PublicReviewListRequest $request, Product $product): JsonResponse
    {
        // 숨김·삭제 상품의 후기는 열지 않는다 (상품 상세와 같은 판정)
        $this->ensurePublicProduct($product);

        try {
            $this->logApiUsage('review.index');
            $validated = $request->validated();
            $filters = $validated;
            $rawOptionFilters = $filters['option_filters'] ?? '{}';
            $filters['option_filters'] = is_array($rawOptionFilters)
                ? $rawOptionFilters
                : (json_decode($rawOptionFilters, true) ?? []);
            $perPage = (int) ($filters['per_page'] ?? 10);

            $result = $this->reviewService->getProductReviews($product->id, $filters, $perPage);

            // 행마다 작성자의 표시 이름(상품 후기 영역)을 계산하기 전에 필요한 값을 한 번에 읽어 둔다.
            MemberDisplayName::preloadRelations($result['reviews'], ['user'], MemberDisplayName::AREA_REVIEW, $request);
            MemberDisplayName::preloadRelations($result['reviews'], ['replyAdmin'], MemberDisplayName::AREA_SITE, $request);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.reviews.fetch_success',
                [
                    'reviews' => PublicProductReviewResource::collection($result['reviews'])->response()->getData(true),
                    'rating_stats' => $result['rating_stats'],
                    'option_filters' => $result['option_filters'],
                    'total_count' => $result['total_count'],
                ]
            );
        } catch (Exception $e) {
            Log::error('review.index failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
                'product_id' => $product->id,
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.reviews.fetch_failed',
                500
            );
        }
    }
}
