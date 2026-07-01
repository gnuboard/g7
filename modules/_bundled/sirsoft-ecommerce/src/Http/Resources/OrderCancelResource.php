<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 주문 취소 이력 리소스
 *
 * 주문상세 화면(관리자/유저)에서 취소 사유와 취소 일시를 표시하기 위한 리소스입니다.
 */
class OrderCancelResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 직렬화된 취소 이력 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cancel_number' => $this->cancel_number,
            'cancel_type' => $this->cancel_type,
            'cancel_type_label' => $this->cancel_type ? $this->cancel_type->label() : null,
            'cancel_status' => $this->cancel_status,
            'cancel_status_label' => $this->cancel_status ? $this->cancel_status->label() : null,
            // 사유 코드 → ClaimReason 다국어 라벨 (없으면 코드 원문 폴백)
            'cancel_reason_type' => $this->cancel_reason_type,
            'cancel_reason_label' => $this->getRefundReasonLabel(),
            // "기타" 선택 시 입력한 상세 사유 텍스트 (없으면 null)
            'cancel_reason_detail' => $this->cancel_reason,
            // 취소 일시 — raw ISO 와 사용자 타임존 변환된 *_formatted 함께 제공
            'cancelled_at' => $this->cancelled_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'cancelled_at_formatted' => $this->formatDateTimeStringForUser($this->cancelled_at),
        ];
    }
}
