<?php

namespace Plugins\Sirsoft\HeroSection\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\HeroSection\Http\Requests\Admin\UpdatePositionSettingRequest;
use Plugins\Sirsoft\HeroSection\Services\PositionSettingService;

/**
 * 관리자 위치 설정 컨트롤러 — 위치 목록·위치별 슬라이더 설정·업로드 한도.
 */
class PositionSettingController extends AdminBaseController
{
    /**
     * PositionSettingController 생성자
     *
     * @param  PositionSettingService  $settings  위치 설정 서비스
     */
    public function __construct(private PositionSettingService $settings)
    {
        parent::__construct();
    }

    /**
     * 위치 목록과 설정, 업로드 한도를 반환합니다.
     *
     * @return JsonResponse 위치 설정 응답
     */
    public function index(): JsonResponse
    {
        return $this->success('sirsoft-hero_section::messages.position.fetched', $this->settings->overview());
    }

    /**
     * 위치 슬라이더 설정을 저장합니다.
     *
     * @param  UpdatePositionSettingRequest  $request  설정 요청
     * @param  string  $position  위치 키
     * @return JsonResponse 저장한 설정 응답
     */
    public function update(UpdatePositionSettingRequest $request, string $position): JsonResponse
    {
        if (! $this->settings->isRegistered($position)) {
            return $this->notFound('sirsoft-hero_section::messages.position.not_found');
        }

        $setting = $this->settings->update($position, $request->validated(), $request->user()?->id);

        return $this->success('sirsoft-hero_section::messages.position.updated', [
            'position' => $position,
            'settings' => $setting->toSettings(),
        ]);
    }
}
