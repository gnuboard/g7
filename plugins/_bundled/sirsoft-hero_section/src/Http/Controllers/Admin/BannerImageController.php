<?php

namespace Plugins\Sirsoft\HeroSection\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\HeroSection\Enums\BannerDevice;
use Plugins\Sirsoft\HeroSection\Http\Requests\Admin\BannerImageUploadRequest;
use Plugins\Sirsoft\HeroSection\Http\Resources\BannerImageResource;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Services\BannerImageService;
use Plugins\Sirsoft\HeroSection\Services\BannerService;

/**
 * 관리자 배너 이미지 컨트롤러 — 업로드(같은 기기 교체)·모바일 이미지 삭제.
 */
class BannerImageController extends AdminBaseController
{
    /**
     * BannerImageController 생성자
     *
     * @param  BannerImageService  $images  이미지 서비스
     * @param  BannerService  $banners  배너 서비스
     */
    public function __construct(
        private BannerImageService $images,
        private BannerService $banners,
    ) {
        parent::__construct();
    }

    /**
     * 이미지를 올립니다.
     *
     * @param  BannerImageUploadRequest  $request  업로드 요청
     * @return JsonResponse 올린 이미지 응답
     */
    public function store(BannerImageUploadRequest $request): JsonResponse
    {
        $data = $request->validated();
        $banner = isset($data['banner_id']) ? $this->banners->find((int) $data['banner_id']) : null;

        $image = $this->images->upload(
            $request->file('file'),
            $data['device'],
            $data['temp_key'] ?? null,
            $banner,
            $request->user()?->id,
        );

        return $this->successWithResource('sirsoft-hero_section::messages.image.uploaded', new BannerImageResource($image), 201);
    }

    /**
     * 이미지를 지웁니다 — PC 이미지는 필수라 지울 수 없고 교체만 합니다.
     *
     * @param  BannerImage  $image  대상 이미지 (라우트 모델 바인딩)
     * @return JsonResponse 결과 응답
     */
    public function destroy(BannerImage $image): JsonResponse
    {
        if ($image->device === BannerDevice::Pc->value) {
            return $this->error('sirsoft-hero_section::messages.image.pc_not_deletable', 422);
        }

        if (! $this->images->deleteImage($image)) {
            return $this->error('sirsoft-hero_section::messages.image.delete_failed', 500);
        }

        return $this->success('sirsoft-hero_section::messages.image.deleted');
    }
}
