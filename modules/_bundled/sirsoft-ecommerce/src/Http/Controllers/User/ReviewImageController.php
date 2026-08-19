<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Exceptions\ReviewImageUploadLimitException;
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
        } catch (ReviewImageUploadLimitException $e) {
            // 개수 상한 초과는 도메인 검증 실패 → 422 + 전용 메시지 (메시지 키 + 파라미터).
            // 예외 메시지 원문을 키 자리에 넘기면 키 해석에 실패해 원문이 그대로 노출된다.
            return ResponseHelper::error(
                'review.image_upload_limit_exceeded',
                422,
                null,
                ['max' => $e->maxImages],
                'sirsoft-ecommerce'
            );
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
     * 숨김(HIDDEN) 리뷰의 이미지는 서비스의 상태 게이트가 차단하되, 게이트를 통과한
     * 응답 직렬화(ProductReviewResource)가 발급한 한시 서명 URL 은 동등한 자격으로
     * 허용한다 — <img> 는 Authorization 헤더를 실을 수 없는 렌더 경로이기 때문이다.
     *
     * @param  Request  $request  HTTP 요청 객체 (한시 서명 검증용)
     * @param  string  $hash  이미지 해시 (12자)
     * @return StreamedResponse|JsonResponse 이미지 스트림 또는 404 응답
     */
    // audit:allow controller-base-request-injection reason: GET 이미지 서빙. 요청 URL 의 한시 서명 검증(hasValidSignature)만 수행. 검증할 body 없음(hash 는 라우트 파라미터)
    public function download(Request $request, string $hash): StreamedResponse|JsonResponse
    {
        $response = $this->imageService->download(
            $hash,
            signatureVerified: $request->hasValidSignature(absolute: false)
        );

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
