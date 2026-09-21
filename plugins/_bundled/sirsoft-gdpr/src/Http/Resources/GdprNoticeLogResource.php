<?php

namespace Plugins\Sirsoft\Gdpr\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/** 화면에 필요한 통지 이벤트만 반환하고 본문 스냅샷은 노출하지 않습니다. */
class GdprNoticeLogResource extends BaseApiResource
{
    /**
     * 관리자 화면의 통지 이력 필드를 반환합니다.
     *
     * @param  Request  $request  검증된 HTTP 요청
     * @return array 처리 결과
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'status' => $this->status->value,
            'status_label' => __('sirsoft-gdpr::messages.annual_notice.status.'.$this->status->value),
            'channels' => $this->channels,
            'channels_label' => implode(', ', $this->channels),
            'is_test' => $this->is_test,
            'sent_at' => $this->formatDateTimeStringForUser($this->sent_at),
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
        ];
    }
}
