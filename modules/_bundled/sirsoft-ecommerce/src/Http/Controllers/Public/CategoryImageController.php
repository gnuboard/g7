<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Services\CategoryImageService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 공개 카테고리 이미지 컨트롤러
 *
 * 카테고리 이미지 서빙 기능을 제공합니다.
 */
class CategoryImageController extends PublicBaseController
{
    public function __construct(
        private CategoryImageService $categoryImageService
    ) {}

    /**
     * 카테고리 이미지 다운로드
     *
     * @param string $hash 이미지 해시
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\JsonResponse
     */
    public function download(string $hash): StreamedResponse|JsonResponse
    {
        try {
            $response = $this->categoryImageService->download($hash);

            if (! $response) {
                return ResponseHelper::notFound(
                    'messages.category_images.not_found',
                    [],
                    'sirsoft-ecommerce'
                );
            }

            return $response;
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }
}
