<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Exceptions\ReviewNotWritableException;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\StoreReviewRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UserReviewListRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductReviewResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\UserProductReviewResource;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;

/**
 * 상품 리뷰 컨트롤러 (사용자)
 *
 * 사용자의 상품 리뷰 작성, 조회, 삭제 API를 제공합니다.
 */
class ProductReviewController extends AuthBaseController
{
    /**
     * 리뷰 서비스를 주입받습니다.
     *
     * @param  ProductReviewService  $reviewService  리뷰 서비스
     */
    public function __construct(
        private ProductReviewService $reviewService
    ) {}

    /**
     * 리뷰 작성 가능 여부 확인
     *
     * @param  int  $orderOptionId  주문 옵션 ID
     * @return JsonResponse 작성 가능 여부 JSON 응답
     */
    public function canWrite(int $orderOptionId): JsonResponse
    {
        try {
            $this->logApiUsage('review.can_write');
            $userId = Auth::id();
            $result = $this->reviewService->canWrite($userId, $orderOptionId);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.reviews.can_write_checked',
                $result
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.reviews.can_write_check_failed',
                500
            );
        }
    }

    /**
     * 리뷰 작성
     *
     * @param  StoreReviewRequest  $request  리뷰 작성 요청
     * @return JsonResponse 작성된 리뷰 JSON 응답
     */
    public function store(StoreReviewRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('review.store');
            $userId = Auth::id();
            $review = $this->reviewService->createReview($userId, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.reviews.created',
                new ProductReviewResource($review),
                201
            );
        } catch (ReviewNotWritableException $e) {
            // 작성 자격 미충족은 도메인 검증 실패 → 422 + 전용 메시지 (메시지 키 + 사유 파라미터).
            // 예외 메시지 원문을 키 자리에 넘기면 키 해석에 실패해 원문이 그대로 노출된다.
            // 사유는 원시 식별자(already_written)가 아니라 번역 라벨로 싣는다.
            return ResponseHelper::error(
                'messages.reviews.cannot_write',
                422,
                null,
                ['reason' => $e->getReasonLabel()],
                'sirsoft-ecommerce'
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.reviews.create_failed',
                500
            );
        }
    }

    /**
     * 내 리뷰 목록 (마이페이지 내 리뷰, #952)
     *
     * @param  UserReviewListRequest  $request  목록 요청
     * @return JsonResponse 리뷰 목록 (data + pagination)
     */
    public function index(UserReviewListRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('review.user_list');

            $perPage = (int) ($request->validated('per_page') ?? 10);
            $reviews = $this->reviewService->getUserReviews((int) Auth::id(), $perPage);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.reviews.user_list_fetched',
                [
                    'data' => UserProductReviewResource::collection($reviews->getCollection())->resolve($request),
                    'pagination' => [
                        'current_page' => $reviews->currentPage(),
                        'last_page' => $reviews->lastPage(),
                        'per_page' => $reviews->perPage(),
                        'total' => $reviews->total(),
                        'from' => $reviews->firstItem(),
                        'to' => $reviews->lastItem(),
                        'has_more_pages' => $reviews->hasMorePages(),
                    ],
                ]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.reviews.user_list_fetch_failed',
                500
            );
        }
    }

    /**
     * 내 리뷰 삭제
     *
     * @param  ProductReview  $review  삭제할 리뷰 모델
     * @return JsonResponse 삭제 결과 JSON 응답
     */
    public function destroy(ProductReview $review): JsonResponse
    {
        try {
            $userId = Auth::id();

            if ($review->user_id !== $userId) {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'messages.reviews.forbidden',
                    403
                );
            }

            $this->logApiUsage('review.destroy', ['review_id' => $review->id]);
            $review->load('images');
            $this->reviewService->deleteReview($review);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.reviews.deleted',
                ['deleted' => true]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.reviews.delete_failed',
                500
            );
        }
    }
}
