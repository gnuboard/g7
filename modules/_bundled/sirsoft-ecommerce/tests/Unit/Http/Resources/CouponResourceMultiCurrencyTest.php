<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Http\Resources\CouponResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 다통화 환산 테스트 (A1-④)
 *
 * CouponResource 에 HasMultiCurrencyPrices 트레이트 + 정액 환산 필드를 추가해
 * 정액(fixed) 쿠폰은 통화별 환산값, 정률(rate)은 null 을 노출하는지 검증한다.
 */
class CouponResourceMultiCurrencyTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HasMultiCurrencyPrices::clearCurrencySettingsCache();
    }

    private function makeCoupon(array $attributes): Coupon
    {
        return Coupon::make(array_merge([
            'name' => 'TEST',
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 5000,
            'discount_max_amount' => null,
            'min_order_amount' => 30000,
        ], $attributes));
    }

    private function toArray(Coupon $coupon): array
    {
        return (new CouponResource($coupon))->toArray(Request::create('/'));
    }

    public function test_fixed_coupon_exposes_multi_currency_discount_value(): void
    {
        $coupon = $this->makeCoupon([
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 5000,
        ]);

        $arr = $this->toArray($coupon);

        $this->assertArrayHasKey('multi_currency_discount_value', $arr);
        $this->assertIsArray($arr['multi_currency_discount_value']);
        // 기본통화(KRW) 환산 포함
        $this->assertArrayHasKey('KRW', $arr['multi_currency_discount_value']);
    }

    public function test_rate_coupon_multi_currency_discount_value_is_null(): void
    {
        $coupon = $this->makeCoupon([
            'discount_type' => CouponDiscountType::RATE,
            'discount_value' => 10, // 10%
        ]);

        $arr = $this->toArray($coupon);

        // 정률 할인은 통화 무관 → null
        $this->assertNull($arr['multi_currency_discount_value']);
    }

    public function test_min_order_amount_multi_currency(): void
    {
        $coupon = $this->makeCoupon([
            'min_order_amount' => 30000,
        ]);

        $arr = $this->toArray($coupon);

        $this->assertArrayHasKey('multi_currency_min_order_amount', $arr);
        $this->assertArrayHasKey('KRW', $arr['multi_currency_min_order_amount']);
    }

    public function test_discount_max_amount_multi_currency_when_set(): void
    {
        $coupon = $this->makeCoupon([
            'discount_type' => CouponDiscountType::RATE,
            'discount_value' => 10,
            'discount_max_amount' => 20000,
        ]);

        $arr = $this->toArray($coupon);

        $this->assertArrayHasKey('multi_currency_discount_max_amount', $arr);
        $this->assertArrayHasKey('KRW', $arr['multi_currency_discount_max_amount']);
    }

    public function test_multi_currency_min_order_amount_null_when_zero(): void
    {
        $coupon = $this->makeCoupon([
            'min_order_amount' => 0,
        ]);

        $arr = $this->toArray($coupon);

        $this->assertNull($arr['multi_currency_min_order_amount']);
    }
}
