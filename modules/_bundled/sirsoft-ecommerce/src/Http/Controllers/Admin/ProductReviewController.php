<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\AdminReviewListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkReviewRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreReviewReplyRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateReviewStatusRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductReviewResource;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;

/**
 * 상품 리뷰 관리 컨트롤러 (관리자)
 *
 * 관리자가 상품 리뷰를 관리할 수 있는 기능을 제공합니다.
 */
class ProductReviewController extends AdminBaseController
{
    public function __construct(
        private ProductReviewService $reviewService
    ) {}

    /**
     * 리뷰 목록 조회
     *
     * @param  AdminReviewListRequest  $request  목록 조회 요청
     * @return JsonResponse 리뷰 목록 JSON 응답
     */
    public function index(AdminReviewListRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $perPage = (int) ($filters['per_page'] ?? 20);
            $reviews = $this->reviewService->getAdminList($filters, $perPage);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.reviews.fetch_success',
                ProductReviewResource::collection($reviews)->response()->getData(true)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.reviews.fetch_failed',
                500
            );
        }
    }

    /**
     * 리뷰 상세 조회
     *
     * @param  ProductReview  $review  조회할 리뷰 모델
     * @return JsonResponse 리뷰 상세 JSON 응답
     */
    public function show(ProductReview $review): JsonResponse
    {
        try {
            $review->load(['user', 'product', 'images', 'replyAdmin', 'orderOption.order']);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.reviews.fetch_success',
                new ProductReviewResource($review)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.reviews.fetch_failed',
                500
            );
        }
    }

    /**
     * 리뷰 상태 변경
     *
     * @param  UpdateReviewStatusRequest  $request  상태 변경 요청
     * @param  ProductReview  $review  대상 리뷰 모델
     * @return JsonResponse 상태 변경 결과 JSON 응답
     */
    public function updateStatus(UpdateReviewStatusRequest $request, ProductReview $review): JsonResponse
    {
        try {
            $status = $request->validated('status');
            $updated = $this->reviewService->updateStatus($review, $status);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.reviews.updated',
                new ProductReviewResource($updated)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.reviews.update_failed',
                500
            );
        }
    }

    /**
     * 판매자 답변 등록/수정
     *
     * @param  StoreReviewReplyRequest  $request  답변 작성 요청
     * @param  ProductReview  $review  대상 리뷰 모델
     * @return JsonResponse 답변 저장 결과 JSON 응답
     */
    public function storeReply(StoreReviewReplyRequest $request, ProductReview $review): JsonResponse
    {
        try {
            $adminId = Auth::id();
            $updated = $this->reviewService->saveReply($review, $adminId, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.reviews.reply_saved',
                new ProductReviewResource($updated)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.reviews.reply_save_failed',
                500
            );
        }
    }

    /**
     * 판매자 답변 삭제
     *
     * @param  ProductReview  $review  대상 리뷰 모델
     * @return JsonResponse 답변 삭제 결과 JSON 응답
     */
    public function destroyReply(ProductReview $review): JsonResponse
    {
        try {
            $updated = $this->reviewService->deleteReply($review);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.reviews.reply_deleted',
                new ProductReviewResource($updated)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.reviews.reply_delete_failed',
                500
            );
        }
    }

    /**
     * 리뷰 삭제
     *
     * @param  ProductReview  $review  삭제할 리뷰 모델
     * @return JsonResponse 삭제 결과 JSON 응답
     */
    public function destroy(ProductReview $review): JsonResponse
    {
        try {
            $reviewId = $review->id;
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

    /**
     * 리뷰 일괄 처리 (삭제/상태변경)
     *
     * @param  BulkReviewRequest  $request  일괄 처리 요청
     * @return JsonResponse 일괄 처리 결과 JSON 응답
     */
    public function bulk(BulkReviewRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $ids = $validated['ids'];
            $action = $validated['action'];

            if ($action === 'delete') {
                $count = $this->reviewService->bulkDelete($ids);

                return ResponseHelper::moduleSuccess(
                    'sirsoft-ecommerce',
                    'messages.reviews.bulk_deleted',
                    ['deleted_count' => $count]
                );
            }

            // change_status
            $count = $this->reviewService->bulkUpdateStatus($ids, $validated['status']);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.reviews.bulk_updated',
                ['updated_count' => $count]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.reviews.bulk_failed',
                500
            );
        }
    }
}
