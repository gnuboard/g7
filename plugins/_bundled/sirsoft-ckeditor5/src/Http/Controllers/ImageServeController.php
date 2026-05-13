<?php

namespace Plugins\Sirsoft\Ckeditor5\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Ckeditor5\Services\ImageServeService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CKEditor5 이미지 서빙 컨트롤러
 *
 * plugins 디스크에 저장된 이미지를 공개 API를 통해 서빙합니다.
 * 인증 없이 접근 가능한 공개 엔드포인트입니다.
 */
class ImageServeController extends PublicBaseController
{
    public function __construct(
        private ImageServeService $imageServeService
    ) {}

    /**
     * 이미지 서빙
     *
     * @param  string  $hash  이미지 해시 (12자)
     * @return StreamedResponse|JsonResponse 이미지 스트림 또는 에러 응답
     */
    public function serve(string $hash): StreamedResponse|JsonResponse
    {
        $image = $this->imageServeService->findByHash($hash);

        if (! $image) {
            return ResponseHelper::notFound('messages.image.not_found', [], 'sirsoft-ckeditor5');
        }

        $response = $this->imageServeService->serve($image);

        if (! $response) {
            return ResponseHelper::notFound('messages.image.not_found', [], 'sirsoft-ckeditor5');
        }

        return $response;
    }
}
