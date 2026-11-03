<?php

// audit:allow api-doc-coverage 609 후속 — download 응답 판정을 코어 파일 서빙 단일 지점으로 옮겨 조건부 요청 304 와 ETag, Last-Modified, Cache-Control 헤더가 더해졌다(라우트, 요청은 그대로). 그 캐시 계약과 304 는 docs/api/product-image.md 가 이미 다룬다.

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Sirsoft\Ecommerce\Services\ProductImageService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 상품 이미지 서빙 컨트롤러
 *
 * 상품 이미지를 공개 API를 통해 서빙합니다.
 * 이미지는 hash를 통해 식별되며, 공개 API이므로 인증이 필요하지 않습니다.
 */
class ProductImageController extends PublicBaseController
{
    /**
     * 상품 이미지 서빙에 쓸 서비스를 주입받는다.
     *
     * @param  ProductImageService  $productImageService  상품 이미지 서비스
     */
    public function __construct(
        private ProductImageService $productImageService
    ) {}

    /**
     * 상품 이미지 다운로드 (서빙)
     *
     * @param  string  $hash  이미지 해시 (12자)
     * @return StreamedResponse|Response|JsonResponse 이미지 스트림, 304 또는 에러 응답
     */
    public function download(string $hash): StreamedResponse|Response|JsonResponse
    {
        $image = $this->productImageService->findByHash($hash);

        if (! $image) {
            return ResponseHelper::notFound(
                'messages.product_image.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        $info = $this->productImageService->getFileInfo($hash);

        if (! $info) {
            return ResponseHelper::notFound(
                'messages.product_image.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        // 캐시 계약(검증자 비교·환경별 Cache-Control·304 의 200 헤더 동반)과 메타 키 검증은
        // 코어 단일 지점(`streamedFileResponseFromMeta()`)이 소유한다 — 종전의 컨트롤러별 사본을
        // 접었다 (14차 → 16차 G3 라운드 E-7). `immutable` 은 `false` 고정(이 URL 에는 `?v=` 가 없다),
        // 압축은 선언으로 제외한다(사용자 업로드물 — 형제 `PublicStorageController` 와 같은 선언).
        // 만료 기간은 서비스 메타(`max_age`)가 정한다.
        return $this->streamedFileResponseFromMeta(
            $info,
            null,
            immutable: false,
            skipCompression: true,
            logContext: ['hash' => $hash]
        );
    }
}
