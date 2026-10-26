<?php

namespace Plugins\Sirsoft\HeroSection\Http\Controllers\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\HeroSection\Services\BannerImageService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 공개 배너 이미지 컨트롤러 — 해시 주소로 배너에 연결된 이미지만 내보낸다(임시 업로드는 공개하지 않는다).
 */
class BannerImageController extends PublicBaseController
{
    /**
     * BannerImageController 생성자
     *
     * @param  BannerImageService  $images  이미지 서비스
     */
    public function __construct(private BannerImageService $images) {}

    /**
     * 이미지를 내보냅니다.
     *
     * @param  string  $hash  공개 해시
     * @return StreamedResponse|JsonResponse 이미지 또는 404
     */
    public function show(string $hash): StreamedResponse|JsonResponse
    {
        $response = $this->images->publicResponse($hash);

        return $response ?? $this->notFound('sirsoft-hero_section::messages.image.not_found');
    }
}
