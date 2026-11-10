<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use App\Thumbnails\ThumbnailQuery;
use App\Thumbnails\ThumbnailResponder;
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
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * 리뷰 이미지 컨트롤러 (사용자)
 *
 * 사용자의 리뷰 이미지 업로드, 삭제, 다운로드 API를 제공합니다.
 */
class ReviewImageController extends AuthBaseController
{
    /**
     * ReviewImageController 생성자
     *
     * @param  ProductReviewImageService  $imageService  리뷰 이미지 서비스
     * @param  ThumbnailResponder  $thumbnails  썸네일 헬퍼 (게이트 뒤 `?size=`·`?w=` 응답)
     */
    public function __construct(
        private ProductReviewImageService $imageService,
        private ThumbnailResponder $thumbnails
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
                ['max' => $e->maxImages, 'count' => $e->maxImages],
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
     * `?size={규격}`·`?w={숫자}` 가 붙으면 게이트를 지난 래스터 이미지는 썸네일로 낸다. 리뷰 이미지는 게이트 원본이라
     * 원본이 공개 자산 디스크에 있어도 썸네일은 비공개 디스크·`private` 캐시다(D25). 서명은 그 두 파라미터를 빼고
     * 검증한다 — 관리자 화면이 숨김 리뷰의 서명 주소 뒤에 `size` 를 덧붙여도 서명이 깨지지 않게.
     *
     * @param  Request  $request  HTTP 요청 객체 (한시 서명 검증·`size`·`w`)
     * @param  string  $hash  이미지 해시 (12자)
     * @return SymfonyResponse|JsonResponse 이미지·썸네일 응답(200·304) 또는 404 응답
     */
    // audit:allow controller-base-request-injection reason: GET 이미지 서빙. 요청 URL 의 한시 서명 검증과 썸네일 파라미터(size·w) 원문 판정만 수행. 검증할 body 없음(hash 는 라우트 파라미터)
    public function download(Request $request, string $hash): SymfonyResponse|JsonResponse
    {
        $info = $this->imageService->getFileInfo(
            $hash,
            signatureVerified: $request->hasValidRelativeSignatureWhileIgnoring(ThumbnailQuery::ignoreClosure())
        );

        if (! $info) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.reviews.image_not_found',
                404
            );
        }

        // 게이트(숨김 상태·한시 서명 — 서비스 `getFileInfo()`)를 지난 뒤, 원본 응답 바로 앞
        if ($thumbnail = $this->thumbnails->respond($request, $info['thumbnail_source'] ?? null)) {
            return $thumbnail;
        }

        // 캐시 계약(검증자 비교·환경별 Cache-Control·304 의 200 헤더 동반)과 메타 키 검증은
        // 코어 단일 지점(`streamedFileResponseFromMeta()`)이 소유한다 — 종전의 컨트롤러별 사본을
        // 접었다 (14차 → 16차 G3 라운드 E-7). `immutable` 은 `false` 고정(이 URL 에는 `?v=` 가 없다),
        // 압축은 선언으로 제외한다(사용자 업로드물 — 형제 `PublicStorageController` 와 같은 선언).
        // 만료 기간은 서비스 메타(`max_age`)가 정한다. 공유 캐시 저장 여부도 서비스 메타(`private`)가 정한다.
        $response = $this->streamedFileResponseFromMeta(
            $info,
            null,
            immutable: false,
            skipCompression: true,
            logContext: ['hash' => $hash],
            private: (bool) ($info['private'] ?? false)
        );

        return $response instanceof JsonResponse ? $response : ThumbnailResponder::markBypass($response, $request);
    }
}
