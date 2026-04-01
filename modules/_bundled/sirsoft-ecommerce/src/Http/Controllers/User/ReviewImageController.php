<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UploadReviewImageRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductReviewImageResource;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewImageService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 리뷰 이미지 컨트롤러 (사용자)
 *
 * 사용자의 리뷰 이미지 업로드, 삭제, 다운로드 API를 제공합니다.
 */
class ReviewImageController extends AuthBaseController
{
    public function __construct(
        private ProductReviewImageService $imageService
    ) {}

    /**
     * 리뷰 이미지 업로드
     *
     * @param  UploadReviewImageRequest  $request  요청 (image 파일 포함)
     * @param  ProductReview  $review  대상 리뷰 모델
     * @return JsonResponse 업로드된 이미지 JSON 응답
     */
    public function store(UploadReviewImageRequest $request, ProductReview $review): JsonResponse
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

            $this->logApiUsage('review_image.store', ['review_id' => $review->id]);
            $image = $this->imageService->upload($request->file('image'), $review);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.reviews.image_uploaded',
                new ProductReviewImageResource($image),
                201
            );
        } catch (\RuntimeException $e) {
            return ResponseHelper::error($e->getMessage(), 422);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.reviews.image_upload_failed',
                500
            );
        }
    }

    /**
     * 리뷰 이미지 삭제
     *
     * @param  ProductReview  $review  대상 리뷰 모델
     * @param  ProductReviewImage  $image  삭제할 이미지 모델
     * @return JsonResponse 삭제 결과 JSON 응답
     */
    public function destroy(ProductReview $review, ProductReviewImage $image): JsonResponse
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

            if ($image->review_id !== $review->id) {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'messages.reviews.image_not_found',
                    404
                );
            }

            $this->logApiUsage('review_image.destroy', ['review_id' => $review->id, 'image_id' => $image->id]);
            $this->imageService->delete($image);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.reviews.image_deleted',
                ['deleted' => true]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.reviews.image_delete_failed',
                500
            );
        }
    }

    /**
     * 리뷰 이미지 다운로드 (해시 기반 공개 서빙)
     *
     * @param  string  $hash  이미지 해시 (12자)
     * @return StreamedResponse|JsonResponse 이미지 스트림 또는 404 응답
     */
    public function download(string $hash): StreamedResponse|JsonResponse
    {
        $response = $this->imageService->download($hash);

        if (! $response) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.reviews.image_not_found',
                404
            );
        }

        return $response;
    }
}
