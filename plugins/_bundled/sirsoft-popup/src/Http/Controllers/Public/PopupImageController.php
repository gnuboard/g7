<?php

namespace Plugins\Sirsoft\Popup\Http\Controllers\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Popup\Services\PopupImageService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 공개 팝업 이미지 컨트롤러 — 해시 주소로 이미지를 내보낸다(저장 전 미리보기를 위해 임시 업로드도 64자 해시로 내보낸다).
 */
class PopupImageController extends PublicBaseController
{
    /**
     * PopupImageController 생성자
     *
     * @param  PopupImageService  $images  이미지 서비스
     */
    public function __construct(private PopupImageService $images) {}

    /**
     * 이미지를 내보냅니다.
     *
     * @param  string  $hash  공개 해시
     * @return StreamedResponse|JsonResponse 이미지 또는 404
     */
    public function show(string $hash): StreamedResponse|JsonResponse
    {
        $response = $this->images->publicResponse($hash);

        return $response ?? $this->notFound('sirsoft-popup::messages.image.not_found');
    }
}
