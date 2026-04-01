<?php

namespace Plugins\Sirsoft\Marketing\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Marketing\Http\Requests\ChannelUpdateRequest;
use Plugins\Sirsoft\Marketing\Services\MarketingConsentService;

/**
 * 마케팅 관리자 컨트롤러
 *
 * 관리자 전용 마케팅 동의 설정 API를 제공합니다.
 * 채널 목록 조회 및 저장을 담당합니다.
 */
class MarketingAdminController extends AdminBaseController
{
    /**
     * 플러그인 식별자
     */
    private const PLUGIN_ID = 'sirsoft-marketing';

    /**
     * MarketingAdminController 생성자
     *
     * @param PluginSettingsService $pluginSettings 플러그인 설정 서비스
     * @param MarketingConsentService $consentService 마케팅 동의 서비스
     */
    public function __construct(
        private PluginSettingsService $pluginSettings,
        private MarketingConsentService $consentService
    ) {
        parent::__construct();
    }

    /**
     * 채널 목록 전체 저장
     *
     * 제출된 채널 배열을 channels JSON으로 plugin_settings에 저장합니다.
     * ChannelUpdateRequest에서 key 중복, is_system 보호, 동의 존재 시 삭제 거부를 검증합니다.
     *
     * @param ChannelUpdateRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateChannels(ChannelUpdateRequest $request): \Illuminate\Http\JsonResponse
    {
        $channels = $request->input('channels');

        // system 채널은 제출 목록에 없어도 항상 유지 (저장 상태와 무관한 기본 정의 기준)
        $systemChannels = $this->consentService->getDefaultSystemChannels();

        $submittedKeys = array_column($channels, 'key');
        foreach ($systemChannels as $systemChannel) {
            if (! in_array($systemChannel['key'], $submittedKeys, true)) {
                array_unshift($channels, $systemChannel);
            }
        }

        $this->pluginSettings->save(self::PLUGIN_ID, ['channels' => json_encode($channels)]);

        return ResponseHelper::success('sirsoft-marketing::messages.channels_saved', [
            'channels' => $channels,
        ]);
    }
}
