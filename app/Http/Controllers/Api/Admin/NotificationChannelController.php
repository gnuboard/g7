<?php

namespace App\Http\Controllers\Api\Admin;

use App\Contracts\Notifications\ChannelReadinessCheckerInterface;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Services\NotificationChannelService;
use Illuminate\Support\Facades\Log;

/**
 * 알림 채널 관리 컨트롤러
 *
 * 시스템에서 사용 가능한 알림 채널 목록을 제공합니다.
 * 플러그인이 Filter 훅으로 채널을 확장할 수 있습니다.
 */
class NotificationChannelController extends AdminBaseController
{
    /**
     * @param NotificationChannelService $channelService
     */
    public function __construct(
        private readonly NotificationChannelService $channelService,
        private readonly ChannelReadinessCheckerInterface $readinessChecker,
    ) {
        parent::__construct();
    }

    /**
     * 사용 가능한 알림 채널 목록을 반환합니다.
     *
     * 각 채널에 readiness (설정 완료 여부) 정보를 포함합니다.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        try {
            $channels = $this->channelService->getAvailableChannels();

            // 각 채널에 readiness 정보 추가
            $channels = array_map(function (array $channel) {
                $channelId = $channel['id'] ?? '';
                $channel['readiness'] = $this->readinessChecker->check($channelId);

                return $channel;
            }, $channels);

            return $this->success(
                __('notification.channels_success'),
                ['channels' => $channels]
            );
        } catch (\Exception $e) {
            Log::error('알림 채널 목록 조회 실패', ['error' => $e->getMessage()]);

            return $this->error(__('notification.channels_failed'), 500);
        }
    }
}
