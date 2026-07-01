<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 통화별 베네핏 포맷 테스트 (D4)
 *
 * 상품상세 쿠폰 칩이 선택 통화로 베네핏을 표시하도록 Coupon::buildMultiCurrencyBenefitFormatted 가
 * 정액(fixed) 할인액을 통화별 환산 문자열로, 정률(rate)은 % 유지(최대금액만 환산)로 만드는지 검증한다.
 * (KRW 고정 결함 회귀 차단)
 */
class CouponMultiCurrencyBenefitTest extends ModuleTestCase
{
    private function converter(): CurrencyConversionService
    {
        $service = app(CurrencyConversionService::class);
        $service->clearCache();

        return $service;
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

    public function test_fixed_coupon_benefit_includes_base_and_foreign_currencies(): void
    {
        $coupon = $this->makeCoupon([
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 5000,
        ]);

        $map = $coupon->buildMultiCurrencyBenefitFormatted($this->converter());

        // 기본 통화(KRW) 포함
        $this->assertArrayHasKey('KRW', $map);
        // 환율 설정된 외화(USD) 포함 — 선택 통화 환산 문자열
        $this->assertArrayHasKey('USD', $map);
        // KRW 문자열에는 base 금액(5,000), USD 문자열에는 USD 기호가 들어간다(서로 다른 값)
        $this->assertNotSame($map['KRW'], $map['USD']);
        $this->assertStringContainsString('5,000', $map['KRW']);
    }

    public function test_fixed_coupon_usd_benefit_is_not_base_krw_amount(): void
    {
        // USD 베네핏 문자열에 base KRW raw 숫자(5,000)가 그대로 들어가면 KRW 고정 회귀
        $coupon = $this->makeCoupon([
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 5000,
        ]);

        $map = $coupon->buildMultiCurrencyBenefitFormatted($this->converter());

        $this->assertArrayHasKey('USD', $map);
        $this->assertStringNotContainsString('5,000원', $map['USD']);
    }

    public function test_rate_coupon_benefit_keeps_percent_across_currencies(): void
    {
        $coupon = $this->makeCoupon([
            'discount_type' => CouponDiscountType::RATE,
            'discount_value' => 10,
            'discount_max_amount' => null,
        ]);

        $map = $coupon->buildMultiCurrencyBenefitFormatted($this->converter());

        // 정률(최대금액 없음): 기본 통화 키 1건, % 문자열
        $this->assertNotEmpty($map);
        foreach ($map as $text) {
            $this->assertStringContainsString('10', $text);
        }
    }

    public function test_rate_coupon_with_max_converts_max_amount_per_currency(): void
    {
        $coupon = $this->makeCoupon([
            'discount_type' => CouponDiscountType::RATE,
            'discount_value' => 10,
            'discount_max_amount' => 20000,
        ]);

        $map = $coupon->buildMultiCurrencyBenefitFormatted($this->converter());

        $this->assertArrayHasKey('KRW', $map);
        $this->assertArrayHasKey('USD', $map);
        // 최대 할인 금액이 통화별로 환산되어 KRW/USD 문자열이 다르다
        $this->assertNotSame($map['KRW'], $map['USD']);
    }
}
