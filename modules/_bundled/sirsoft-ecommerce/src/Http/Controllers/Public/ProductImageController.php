<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
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
    public function __construct(
        private ProductImageService $productImageService
    ) {}

    /**
     * 상품 이미지 다운로드 (서빙)
     *
     * @param string $hash 이미지 해시 (12자)
     * @return StreamedResponse|JsonResponse 이미지 스트림 또는 에러 응답
     */
    public function download(string $hash): StreamedResponse|JsonResponse
    {
        $image = $this->productImageService->findByHash($hash);

        if (! $image) {
            return ResponseHelper::notFound(
                'messages.product_image.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        $response = $this->productImageService->download($hash);

        if (! $response) {
            return ResponseHelper::notFound(
                'messages.product_image.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        return $response;
    }
}
