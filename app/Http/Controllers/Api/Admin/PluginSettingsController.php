<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\UpdatePluginSettingsRequest;
use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;

/**
 * 플러그인 설정 API 컨트롤러
 *
 * 플러그인의 설정 조회, 수정, 레이아웃 조회 기능을 제공합니다.
 */
class PluginSettingsController extends AdminBaseController
{
    /**
     * PluginSettingsController 생성자
     *
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 서비스
     */
    public function __construct(
        private PluginSettingsService $pluginSettingsService
    ) {
        parent::__construct();
    }

    /**
     * 플러그인 설정을 조회합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @return JsonResponse 설정 데이터
     */
    public function show(string $identifier): JsonResponse
    {
        $settings = $this->pluginSettingsService->get($identifier);

        if ($settings === null) {
            return $this->notFound('plugins.not_found');
        }

        return $this->success('messages.success', $settings);
    }

    /**
     * 플러그인 설정을 업데이트합니다.
     *
     * @param  UpdatePluginSettingsRequest  $request  검증된 요청
     * @param  string  $identifier  플러그인 식별자
     * @return JsonResponse 업데이트 결과
     */
    public function update(UpdatePluginSettingsRequest $request, string $identifier): JsonResponse
    {
        // validated()가 빈 배열이면 all()에서 설정값을 가져옴
        // (PluginManager에 등록되지 않은 플러그인의 경우)
        $settings = $request->validated();
        if (empty($settings)) {
            $settings = $request->all();
        }

        $result = $this->pluginSettingsService->save($identifier, $settings);

        if (! $result) {
            return $this->error('plugins.settings.update_failed', 500);
        }

        return $this->success(
            'plugins.settings.updated',
            $this->pluginSettingsService->get($identifier)
        );
    }

    /**
     * 플러그인 설정 레이아웃을 조회합니다.
     *
     * 설정 페이지 UI 구성과 설정 스키마를 반환합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @return JsonResponse 레이아웃 데이터
     */
    public function layout(string $identifier): JsonResponse
    {
        $layout = $this->pluginSettingsService->getLayout($identifier);

        if ($layout === null) {
            return $this->notFound('plugins.not_found');
        }

        return $this->success('messages.success', $layout);
    }
}
