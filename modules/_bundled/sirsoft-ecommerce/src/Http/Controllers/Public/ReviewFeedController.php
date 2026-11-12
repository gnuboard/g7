<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\PublicReviewFeedRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\PublicReviewFeedResource;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;

/**
 * 전체 후기 공개 컨트롤러 (리뷰 모아보기)
 *
 * 전시 중인 모든 상품의 노출 후기를 최신순 등으로 모아 보여 주는 공개 API 를 제공합니다.
 */
class ReviewFeedController extends PublicBaseController
{
    /**
     * 상품 후기 서비스를 주입받습니다.
     *
     * @param  ProductReviewService  $reviewService  상품 후기 서비스
     */
    public function __construct(
        private ProductReviewService $reviewService
    ) {}

    /**
     * 전체 공개 후기 목록 조회
     *
     * @param  PublicReviewFeedRequest  $request  요청
     * @return JsonResponse 후기 목록 JSON 응답
     */
    public function index(PublicReviewFeedRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('review.feed');

            $reviews = $this->reviewService->getPublicFeed($request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.reviews.fetch_success',
                [
                    'reviews' => PublicReviewFeedResource::collection($reviews)->response()->getData(true),
                ]
            );
        } catch (Exception $e) {
            Log::error('review.feed failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.reviews.fetch_failed',
                500
            );
        }
    }
}
