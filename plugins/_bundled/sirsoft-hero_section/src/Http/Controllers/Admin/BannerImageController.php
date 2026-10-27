<?php

namespace Plugins\Sirsoft\HeroSection\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\HeroSection\Http\Requests\Admin\BannerImageUploadRequest;
use Plugins\Sirsoft\HeroSection\Http\Resources\BannerImageResource;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Services\BannerImageService;

/**
 * 관리자 배너 이미지 컨트롤러 — 임시 묶음 업로드(같은 기기 교체)·임시 이미지 삭제.
 *
 * 배너에 붙은 이미지는 여기서 바꾸거나 지우지 않는다 — 배너 등록·수정 저장(`BannerService`)만 바꾼다.
 */
class BannerImageController extends AdminBaseController
{
    /**
     * BannerImageController 생성자
     *
     * @param  BannerImageService  $images  이미지 서비스
     */
    public function __construct(
        private BannerImageService $images,
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

        $image = $this->images->upload(
            $request->file('file'),
            $data['device'],
            $data['temp_key'],
            $request->user()?->id,
        );

        return $this->successWithResource('sirsoft-hero_section::messages.image.uploaded', new BannerImageResource($image), 201);
    }

    /**
     * 아직 배너에 붙지 않은 임시 이미지를 지웁니다.
     *
     * @param  BannerImage  $image  대상 이미지 (라우트 모델 바인딩)
     * @return JsonResponse 결과 응답
     */
    public function destroy(BannerImage $image): JsonResponse
    {
        if ($image->banner_id !== null) {
            return $this->error('sirsoft-hero_section::messages.image.attached_not_deletable', 422);
        }

        if (! $this->images->deleteImage($image)) {
            return $this->error('sirsoft-hero_section::messages.image.delete_failed', 500);
        }

        return $this->success('sirsoft-hero_section::messages.image.deleted');
    }
}
