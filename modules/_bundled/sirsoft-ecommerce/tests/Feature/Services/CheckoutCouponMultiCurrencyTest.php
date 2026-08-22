<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Services;

use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Services\UserCouponService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 체크아웃 쿠폰 통화별 베네핏 테스트 (D5)
 *
 * 체크아웃 주문/배송비 쿠폰 옵션 텍스트가 선택 통화로 표시되도록
 * UserCouponService::getCheckoutCoupons 가 multi_currency_benefit_formatted 를 포함하는지 검증한다.
 * (배송비 쿠폰 할인액 KRW 고정 회귀 차단)
 */
class CheckoutCouponMultiCurrencyTest extends ModuleTestCase
{
    private function createShippingCoupon(): Coupon
    {
        return Coupon::create([
            'name' => ['ko' => '배송비 쿠폰', 'en' => 'Shipping Coupon'],
            'target_type' => CouponTargetType::SHIPPING_FEE,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 5000,
            'min_order_amount' => 0,
            'issue_status' => CouponIssueStatus::ISSUING,
            'is_combinable' => true,
            'target_scope' => CouponTargetScope::ALL,
            'valid_from' => Carbon::now()->subDay(),
            'valid_to' => Carbon::now()->addMonth(),
        ]);
    }

    private function issueTo(Coupon $coupon, int $userId): CouponIssue
    {
        return CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $userId,
            'coupon_code' => 'TEST-'.strtoupper(uniqid()),
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => Carbon::now(),
            'expired_at' => Carbon::now()->addMonth(),
        ]);
    }

    public function test_checkout_coupons_include_multi_currency_benefit(): void
    {
        $user = $this->createUser();
        $coupon = $this->createShippingCoupon();
        $this->issueTo($coupon, $user->id);

        $result = app(UserCouponService::class)->getCheckoutCoupons($user->id, [], 50000, 3000);

        $this->assertNotEmpty($result);
        $row = $result[0];
        $this->assertArrayHasKey('multi_currency_benefit_formatted', $row);
        $this->assertIsArray($row['multi_currency_benefit_formatted']);
        // 기본 통화(KRW) + 환율 설정 외화(USD) 포함
        $this->assertArrayHasKey('KRW', $row['multi_currency_benefit_formatted']);
        $this->assertArrayHasKey('USD', $row['multi_currency_benefit_formatted']);
    }

    public function test_checkout_shipping_coupon_usd_benefit_is_not_base_krw(): void
    {
        $user = $this->createUser();
        $coupon = $this->createShippingCoupon();
        $this->issueTo($coupon, $user->id);

        $result = app(UserCouponService::class)->getCheckoutCoupons($user->id, [], 50000, 3000);
        $row = $result[0];

        // USD 베네핏 문자열에 base KRW raw(5,000원)가 그대로 들어가면 KRW 고정 회귀
        $this->assertStringNotContainsString('5,000원', $row['multi_currency_benefit_formatted']['USD']);
        $this->assertNotSame(
            $row['multi_currency_benefit_formatted']['KRW'],
            $row['multi_currency_benefit_formatted']['USD']
        );
    }
}
