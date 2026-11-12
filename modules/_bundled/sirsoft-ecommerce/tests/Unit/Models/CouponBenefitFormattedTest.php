<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Support\DecimalTrim;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 혜택 문구의 할인율 표기 테스트
 *
 * 할인값 칸이 소수 둘째 자리 형변환이라 정률 쿠폰 문구가 「10.00% 할인」으로 나오던 것을 「10% 할인」으로 맞춘다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/coupon-benefit-rate-format.yaml
 */
class CouponBenefitFormattedTest extends ModuleTestCase
{
    /**
     * 소수 끝 0 과 점을 떼어 낸다.
     *
     * @scenario surface=decimal_trim, value=boundary
     *
     * @effects trailing_zero_and_dot_removed
     *
     * @return void 반환값 없음
     */
    public function test_decimal_trim_removes_trailing_zeros(): void
    {
        $this->assertSame('10', DecimalTrim::trim('10.00'));
        $this->assertSame('12.5', DecimalTrim::trim('12.50'));
        $this->assertSame('12.55', DecimalTrim::trim(12.55));
        $this->assertSame('0', DecimalTrim::trim('0.00'));
        $this->assertSame('100', DecimalTrim::trim(100));
        $this->assertSame('0.5', DecimalTrim::trim('0.50'));
    }

    /**
     * 정률 쿠폰 문구에 소수 끝 0 이 붙지 않는다.
     *
     * @scenario surface=benefit_formatted, value=rate
     *
     * @effects rate_benefit_has_no_trailing_zero
     *
     * @return void 반환값 없음
     */
    public function test_rate_benefit_is_formatted_without_trailing_zeros(): void
    {
        $whole = $this->makeCoupon(CouponDiscountType::RATE, '10.00');
        $this->assertStringContainsString('10%', $whole->benefit_formatted);
        $this->assertStringNotContainsString('10.00', $whole->benefit_formatted);

        $half = $this->makeCoupon(CouponDiscountType::RATE, '12.50');
        $this->assertStringContainsString('12.5%', $half->benefit_formatted);
        $this->assertStringNotContainsString('12.50', $half->benefit_formatted);

        $capped = $this->makeCoupon(CouponDiscountType::RATE, '10.00', 5000);
        $this->assertStringContainsString('10%', $capped->benefit_formatted);
        $this->assertStringNotContainsString('10.00%', $capped->benefit_formatted);
    }

    /**
     * 통화별 혜택 문구도 같은 표기를 쓴다.
     *
     * @scenario surface=multi_currency_benefit, value=rate
     *
     * @effects multi_currency_rate_benefit_has_no_trailing_zero
     *
     * @return void 반환값 없음
     */
    public function test_multi_currency_rate_benefit_has_no_trailing_zeros(): void
    {
        $converter = app(CurrencyConversionService::class);

        foreach ([$this->makeCoupon(CouponDiscountType::RATE, '10.00'), $this->makeCoupon(CouponDiscountType::RATE, '10.00', 5000)] as $coupon) {
            $formatted = $coupon->buildMultiCurrencyBenefitFormatted($converter);
            $this->assertNotEmpty($formatted);
            foreach ($formatted as $text) {
                $this->assertStringContainsString('10%', $text);
                $this->assertStringNotContainsString('10.00%', $text);
            }
        }
    }

    /**
     * 정액 쿠폰 문구는 그대로다.
     *
     * @scenario surface=benefit_formatted, value=fixed
     *
     * @effects fixed_benefit_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_fixed_benefit_is_unchanged(): void
    {
        $coupon = $this->makeCoupon(CouponDiscountType::FIXED, '3000.00');

        $this->assertSame(
            __('sirsoft-ecommerce::messages.coupons.benefit_fixed_format', ['amount' => ecommerce_format_price($coupon->discount_value)]),
            $coupon->benefit_formatted
        );
    }

    /**
     * 저장하지 않은 쿠폰 모델을 만듭니다.
     *
     * @param  CouponDiscountType  $type  할인 방식
     * @param  string  $value  할인값
     * @param  int|null  $max  최대 할인 금액
     * @return Coupon 쿠폰 모델
     */
    private function makeCoupon(CouponDiscountType $type, string $value, ?int $max = null): Coupon
    {
        $coupon = new Coupon;
        $coupon->discount_type = $type;
        $coupon->discount_value = $value;
        $coupon->discount_max_amount = $max;

        return $coupon;
    }
}
