<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Helpers\TimezoneHelper;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;

/**
 * 마이페이지 쿠폰함 발급 건 리소스
 *
 * 관리자 발급 내역 리소스에 회원 화면 카드가 그리는 쿠폰 정보(`coupon` 블록)와 남은 일수를 더한다. 쿠폰이 삭제됐으면
 * `coupon` 은 null 이다. 적용 대상 상품·분류 이름은 3개까지만 싣고 나머지는 개수로 낸다(「외 N」).
 */
class UserCouponIssueResource extends CouponIssueResource
{
    /** 적용 대상 이름을 몇 개까지 실을지 */
    private const TARGET_NAME_LIMIT = 3;

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 쿠폰함 카드 값
     */
    public function toArray($request): array
    {
        $base = parent::toArray($request);

        return [
            ...$base,
            'expires_in_days' => $this->expiresInDays(),
            'coupon' => $this->couponBlock(),
        ];
    }

    /**
     * 만료일까지 남은 날 수를 사이트 날짜 기준으로 반환합니다. (오늘 만료면 0, 기한 없음이면 null)
     *
     * @return int|null 남은 날 수
     */
    private function expiresInDays(): ?int
    {
        if ($this->expired_at === null) {
            return null;
        }

        $timezone = TimezoneHelper::getSiteTimezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $expiresDay = CarbonImmutable::parse($this->expired_at)->setTimezone($timezone)->startOfDay();

        return (int) $today->diffInDays($expiresDay, false);
    }

    /**
     * 쿠폰 정의 블록을 만듭니다.
     *
     * @return array<string, mixed>|null 쿠폰 블록 (쿠폰이 삭제됐으면 null)
     */
    private function couponBlock(): ?array
    {
        $coupon = $this->resource->relationLoaded('coupon') ? $this->resource->coupon : null;
        if (! $coupon instanceof Coupon) {
            return null;
        }

        $converter = app(CurrencyConversionService::class);
        $products = $coupon->relationLoaded('includedProducts') ? $coupon->includedProducts : collect();
        $categories = $coupon->relationLoaded('includedCategories') ? $coupon->includedCategories : collect();

        return [
            'id' => $coupon->id,
            'localized_name' => $coupon->getLocalizedName(),
            'localized_description' => $coupon->getLocalizedDescription(),
            'target_type' => $coupon->target_type?->value,
            'target_type_label' => $coupon->target_type?->label(),
            'target_scope' => $coupon->target_scope?->value,
            'target_scope_label' => $coupon->target_scope?->label(),
            'discount_type' => $coupon->discount_type?->value,
            'discount_value' => $coupon->discount_value,
            'discount_max_amount' => $coupon->discount_max_amount,
            'benefit_formatted' => $coupon->benefit_formatted,
            'multi_currency_benefit_formatted' => $coupon->buildMultiCurrencyBenefitFormatted($converter),
            'multi_currency_discount_value' => $coupon->discount_type === CouponDiscountType::FIXED
                ? $converter->convertToMultiCurrency((int) ($coupon->discount_value ?? 0))
                : null,
            'min_order_amount' => $coupon->min_order_amount,
            'min_order_amount_formatted' => ecommerce_format_price($coupon->min_order_amount ?? 0),
            'multi_currency_min_order_amount' => ($coupon->min_order_amount ?? 0) > 0
                ? $converter->convertToMultiCurrency((int) $coupon->min_order_amount)
                : null,
            'is_combinable' => (bool) $coupon->is_combinable,
            'included_product_names' => $products->take(self::TARGET_NAME_LIMIT)->map(fn ($p) => $p->getLocalizedName())->values()->all(),
            'included_product_count' => $products->count(),
            'included_category_names' => $categories->take(self::TARGET_NAME_LIMIT)->map(fn ($c) => $c->getLocalizedName())->values()->all(),
            'included_category_count' => $categories->count(),
            'valid_period_formatted' => $coupon->valid_period_formatted,
        ];
    }
}
