<?php

namespace Plugins\Sirsoft\Gdpr\Http\Resources;

use App\Http\Resources\BaseApiResource;
use App\Services\NotificationChannelService;
use Illuminate\Http\Request;

/** 화면에 필요한 통지 이벤트만 반환하고 본문 스냅샷은 노출하지 않습니다. */
class GdprNoticeLogResource extends BaseApiResource
{
    /** 한 응답 안에서 채널 이름표를 한 번만 만들도록 요청 속성에 담아 두는 키 */
    private const CHANNEL_NAMES_ATTRIBUTE = 'sirsoft-gdpr.notice_log.channel_names';

    /**
     * 관리자 화면의 통지 이력 필드를 반환합니다.
     *
     * @param  Request  $request  검증된 HTTP 요청
     * @return array 처리 결과
     */
    public function toArray(Request $request): array
    {
        $names = $this->channelNames($request);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'status' => $this->status->value,
            'status_label' => __('sirsoft-gdpr::messages.annual_notice.status.'.$this->status->value),
            'channels' => $this->channels,
            // 화면 칸은 채널 이름을 보인다 — 식별자(database 등)를 그대로 내보내면 운영자가 어느 수단인지
            // 알아보기 어렵다. 지금 없는 채널(제공 확장을 끈 경우)은 식별자로 남긴다.
            'channels_label' => implode(', ', array_map(
                fn (string $channel) => $names[$channel] ?? $channel,
                $this->channels ?? []
            )),
            'is_test' => $this->is_test,
            'sent_at' => $this->formatDateTimeStringForUser($this->sent_at),
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
        ];
    }

    /**
     * 채널 식별자 → 현재 언어 이름표를 반환합니다.
     *
     * 채널 목록은 필터 훅으로 모이므로 행마다 다시 모으지 않고 요청 하나에 한 번만 만든다.
     *
     * @param  Request  $request  현재 요청
     * @return array<string, string> 채널 식별자별 이름
     */
    private function channelNames(Request $request): array
    {
        $cached = $request->attributes->get(self::CHANNEL_NAMES_ATTRIBUTE);
        if (is_array($cached)) {
            return $cached;
        }

        $names = [];
        foreach (app(NotificationChannelService::class)->getAvailableChannels() as $channel) {
            $id = (string) ($channel['id'] ?? '');
            $name = $channel['name'] ?? null;
            if ($id !== '' && is_string($name) && $name !== '') {
                $names[$id] = $name;
            }
        }

        $request->attributes->set(self::CHANNEL_NAMES_ATTRIBUTE, $names);

        return $names;
    }
}
