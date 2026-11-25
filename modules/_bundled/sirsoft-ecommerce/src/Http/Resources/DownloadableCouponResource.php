<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 공개 쿠폰존 카드 리소스 (#952)
 *
 * 허용 목록 방식이다 — 화면 카드가 그리는 값만 싣고, 발급 수·회원당 한도·알림 설정 같은 운영 값은 싣지 않는다.
 * 비로그인 방문자가 보므로 받은 여부(`is_downloaded`)도 없다. 입력은 `UserCouponService::decorateDownloadableCoupon()` 이
 * 표시값을 채운 쿠폰 모델이다.
 */
class DownloadableCouponResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 쿠폰 카드 값
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'coupon_id' => $this->id,
            'localized_name' => $this->getLocalizedName(),
            'localized_description' => $this->getLocalizedDescription(),
            'target_type' => $this->target_type?->value,
            'target_type_short_label' => $this->target_type?->shortLabel(),
            'target_scope' => $this->target_scope?->value,
            'discount_type' => $this->discount_type?->value,
            'discount_value' => $this->discount_value,
            'discount_max_amount' => $this->discount_max_amount,
            'min_order_amount' => $this->min_order_amount,
            'min_order_amount_formatted' => $this->min_order_amount_formatted,
            'benefit_formatted' => $this->benefit_formatted,
            'multi_currency_benefit_formatted' => $this->multi_currency_benefit_formatted,
            'multi_currency_discount_value' => $this->multi_currency_discount_value,
            'multi_currency_min_order_amount' => $this->multi_currency_min_order_amount,
            'multi_currency_discount_max_amount' => $this->multi_currency_discount_max_amount,
            'is_combinable' => (bool) $this->is_combinable,
            'valid_type' => $this->valid_type,
            'valid_days' => $this->valid_days,
            'valid_to' => $this->valid_to?->toIso8601String(),
            'valid_period_formatted' => $this->valid_period_formatted,
            'remaining_quantity' => $this->remaining_quantity,
            // 받은 여부는 로그인 회원 응답에만 싣는다 — 비로그인 응답에 false 를 실으면 「안 받음」 으로 잘못 읽힌다
            ...($request->user() !== null ? [
                'is_downloaded' => (bool) $this->is_downloaded,
                'user_issued_count' => (int) ($this->user_issued_count ?? 0),
            ] : []),
        ];
    }
}
