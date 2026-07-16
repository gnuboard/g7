<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\DTO;

use Modules\Sirsoft\Ecommerce\DTO\AppliedPromotions;
use Modules\Sirsoft\Ecommerce\DTO\CouponApplication;
use Modules\Sirsoft\Ecommerce\DTO\DiscountCodeApplication;
use Modules\Sirsoft\Ecommerce\DTO\PromotionsSummary;
use PHPUnit\Framework\TestCase;

/**
 * PromotionsSummary 평탄 키 생산 표준 검증 (주문 스냅샷 표준화/MP06)
 *
 * 주문 생성 시 promotions_applied_snapshot 이 coupon_issue_ids / item_coupons / discount_code
 * 평탄 키를 항상 포함하도록 표준화한 것을 검증한다. 취소 복원/재계산이 이 평탄 키를 SSoT 로 읽는다.
 */
class PromotionsSummaryFlatKeysTest extends TestCase
{
    /**
     * 상품 쿠폰 + 주문 쿠폰을 가진 요약 DTO 를 구성합니다.
     */
    private function makeSummary(): PromotionsSummary
    {
        // 상품 쿠폰 (옵션 11, 12 에 적용)
        $productCoupon = new CouponApplication(
            couponId: 101,
            couponIssueId: 5001,
            name: '상품 쿠폰',
            targetType: 'product_amount',
            appliedItems: [
                ['product_option_id' => 11, 'discount_amount' => 1000],
                ['product_option_id' => 12, 'discount_amount' => 1000],
            ],
        );

        // 주문 쿠폰
        $orderCoupon = new CouponApplication(
            couponId: 122,
            couponIssueId: 6001,
            name: '주문 쿠폰',
            targetType: 'order_amount',
            appliedItems: [
                ['product_option_id' => 11, 'discount_amount' => 2000],
            ],
        );

        $product = new AppliedPromotions(coupons: [$productCoupon]);
        $order = new AppliedPromotions(
            coupons: [$orderCoupon],
            discountCodes: [new DiscountCodeApplication(codeId: 7, code: 'WELCOME10', name: '환영코드')],
        );

        return new PromotionsSummary(productPromotions: $product, orderPromotions: $order);
    }

    /**
     * toArray() 가 평탄 키 3종을 포함한다.
     */
    public function test_to_array_includes_flat_keys(): void
    {
        $arr = $this->makeSummary()->toArray();

        $this->assertArrayHasKey('coupon_issue_ids', $arr);
        $this->assertArrayHasKey('item_coupons', $arr);
        $this->assertArrayHasKey('discount_code', $arr);
        // 기존 구조도 유지
        $this->assertArrayHasKey('product_promotions', $arr);
        $this->assertArrayHasKey('order_promotions', $arr);
    }

    /**
     * coupon_issue_ids 는 상품+주문 전체 쿠폰 발급 ID (중복 제거).
     */
    public function test_coupon_issue_ids_covers_all_coupons(): void
    {
        $arr = $this->makeSummary()->toArray();

        $this->assertEqualsCanonicalizing([5001, 6001], $arr['coupon_issue_ids']);
    }

    /**
     * item_coupons 는 상품 쿠폰을 적용 옵션별로 매핑한다 (주문 쿠폰 제외).
     */
    public function test_item_coupons_maps_product_coupons_by_option(): void
    {
        $arr = $this->makeSummary()->toArray();

        $this->assertEquals([5001], $arr['item_coupons'][11] ?? null);
        $this->assertEquals([5001], $arr['item_coupons'][12] ?? null);
        // 주문 쿠폰(6001)은 item_coupons 에 들어가지 않는다
        foreach ($arr['item_coupons'] as $ids) {
            $this->assertNotContains(6001, $ids);
        }
    }

    /**
     * discount_code 는 적용된 할인코드 문자열.
     */
    public function test_discount_code_returns_code_string(): void
    {
        $arr = $this->makeSummary()->toArray();

        $this->assertEquals('WELCOME10', $arr['discount_code']);
    }

    /**
     * 쿠폰/코드가 없으면 평탄 키는 빈 값.
     */
    public function test_empty_promotions_produce_empty_flat_keys(): void
    {
        $arr = (new PromotionsSummary)->toArray();

        $this->assertSame([], $arr['coupon_issue_ids']);
        $this->assertSame([], $arr['item_coupons']);
        $this->assertNull($arr['discount_code']);
    }
}
