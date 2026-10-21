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
    /**
     * 정액 5,000원 배송비 쿠폰을 만듭니다.
     *
     * @return Coupon 생성된 쿠폰
     */
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

    /**
     * 쿠폰을 회원에게 사용 가능 상태로 발급합니다.
     *
     * @param  Coupon  $coupon  발급할 쿠폰
     * @param  int  $userId  받을 회원 ID
     * @return CouponIssue 생성된 발급 내역
     */
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

    /**
     * 주문서 주문·배송비 쿠폰 목록이 통화별 혜택 문구 맵(기본 통화 + 환율 설정 외화)을 싣습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario endpoint=checkout
     *
     * @effects checkout_coupons_carry_multi_currency_benefit
     */
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

    /**
     * 외화 혜택 문구가 기본 통화 원값(5,000원)을 그대로 쓰지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario endpoint=checkout
     *
     * @effects checkout_coupons_carry_multi_currency_benefit
     */
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

    /**
     * 주문서 상품 줄의 상품 쿠폰 선택칸도 선택 통화 혜택 문구를 쓸 수 있게 통화별 맵을 싣는다.
     *
     * 종전에는 `benefit_formatted`(기본 통화)만 있어, 다른 통화로 보는 회원의 선택칸에 「1,000원 할인」 이 그대로 보였다.
     *
     * @return void 반환값 없음
     *
     * @scenario endpoint=checkout
     *
     * @effects product_coupons_carry_multi_currency_benefit
     */
    public function test_product_coupons_grouped_include_multi_currency_benefit(): void
    {
        $user = $this->createUser();
        $coupon = Coupon::create([
            'name' => ['ko' => '상품 쿠폰', 'en' => 'Product Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'issue_status' => CouponIssueStatus::ISSUING,
            'is_combinable' => true,
            'target_scope' => CouponTargetScope::ALL,
            'valid_from' => Carbon::now()->subDay(),
            'valid_to' => Carbon::now()->addMonth(),
        ]);
        $this->issueTo($coupon, $user->id);

        $result = app(UserCouponService::class)->getProductCouponsGrouped($user->id, [123], [123 => 50000]);

        $this->assertNotEmpty($result[123]);
        $row = $result[123][0];
        $this->assertArrayHasKey('multi_currency_benefit_formatted', $row);
        $this->assertArrayHasKey('KRW', $row['multi_currency_benefit_formatted']);
        $this->assertArrayHasKey('USD', $row['multi_currency_benefit_formatted']);
        $this->assertNotSame(
            $row['multi_currency_benefit_formatted']['KRW'],
            $row['multi_currency_benefit_formatted']['USD']
        );
    }
}
