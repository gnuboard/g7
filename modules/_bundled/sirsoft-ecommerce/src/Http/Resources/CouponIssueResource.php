<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;

/**
 * 쿠폰 발급 내역 API 리소스
 */
class CouponIssueResource extends BaseApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'coupon_id' => $this->coupon_id,
            'user_id' => $this->user?->uuid,
            'coupon_code' => $this->coupon_code,

            // 상태
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'status_badge_color' => $this->status?->badgeColor(),

            // 날짜
            'issued_at' => $this->formatDateTimeStringForUser($this->issued_at),
            'expired_at' => $this->formatDateTimeStringForUser($this->expired_at),
            'used_at' => $this->formatDateTimeStringForUser($this->used_at),

            // 사용 정보
            'order_id' => $this->order_id,
            'discount_amount' => $this->discount_amount,

            // 상태 확인
            'is_expired' => $this->isExpired(),
            'is_usable' => $this->isUsable(),

            // 관계
            'user_name' => $this->whenLoaded('user', fn () => $this->user->name),
        ];
    }
}
