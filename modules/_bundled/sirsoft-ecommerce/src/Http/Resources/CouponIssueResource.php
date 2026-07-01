<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;

/**
 * 쿠폰 발급 내역 API 리소스
 */
class CouponIssueResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 쿠폰 발급 내역 리소스 배열
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
            'order_number' => $this->whenLoaded('order', fn () => $this->order?->order_number),
            'discount_amount' => $this->roundToBaseCurrency($this->discount_amount),

            // 상태 확인
            'is_used' => $this->status?->value === 'used',
            'is_expired' => $this->isExpired(),
            'is_usable' => $this->isUsable(),
            'is_cancellable' => $this->status?->value === 'available' && ! $this->isExpired(),

            // 관계
            'user_name' => $this->whenLoaded('user', fn () => $this->user->name),
        ];
    }
}
