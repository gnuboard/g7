<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\CouponRestoreListener;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * CouponRestoreListener 단위 테스트
 *
 * 주문 취소 시 쿠폰 복원 리스너의 동작을 검증합니다.
 */
class CouponRestoreListenerTest extends ModuleTestCase
{
    protected CouponRestoreListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = app(CouponRestoreListener::class);
    }

    /**
     * 테스트용 쿠폰과 발급 레코드를 생성합니다.
     *
     * @param  array  $issueOverrides  발급 레코드 오버라이드
     */
    protected function createCouponIssue(array $issueOverrides = []): CouponIssue
    {
        $coupon = Coupon::create([
            'name' => ['ko' => '테스트 쿠폰', 'en' => 'Test Coupon'],
            'description' => ['ko' => '테스트용', 'en' => 'Test'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 5000,
            'min_order_amount' => 0,
            'target_scope' => CouponTargetScope::ALL,
            'is_combinable' => true,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDays(30),
        ]);

        $user = User::factory()->create();

        return CouponIssue::create(array_merge([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'coupon_code' => 'TEST'.uniqid(),
            'status' => CouponIssueRecordStatus::USED,
            'issued_at' => now(),
            'expired_at' => now()->addDays(30),
            'used_at' => now()->subHour(),
        ], $issueOverrides));
    }

    /**
     * 테스트용 주문을 생성합니다.
     *
     * @param  array  $couponIssueIds  적용된 쿠폰 발급 ID 목록
     */
    protected function createOrderWithCoupons(array $couponIssueIds): Order
    {
        $user = User::factory()->create();

        // promotions_applied_snapshot 구성 — 실데이터 키 형식 (coupon_issue_ids 평탄 +
        // product_promotions.coupons). 종전 테스트는 실제로 존재하지 않는
        // product_coupons/shipping_coupons/order_coupons 키를 써서 false-green 이었다.
        $productCoupons = [];
        foreach ($couponIssueIds as $issueId) {
            $productCoupons[] = [
                'coupon_id' => 1,
                'coupon_issue_id' => $issueId,
                'name' => '테스트 쿠폰',
                'target_type' => 'product_amount',
                'discount_type' => 'fixed',
                'discount_value' => 5000,
                'total_discount' => 5000,
            ];
        }

        return Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-TEST-'.uniqid(),
            'order_status' => OrderStatusEnum::CANCELLED,
            'currency' => 'KRW',
            'item_count' => 1,
            'ordered_at' => now(),
            'subtotal_amount' => 50000,
            'total_amount' => 45000,
            'total_paid_amount' => 45000,
            'promotions_applied_snapshot' => [
                'coupon_issue_ids' => array_values($couponIssueIds),
                'item_coupons' => [],
                'discount_code' => null,
                'product_promotions' => ['coupons' => $productCoupons],
                'order_promotions' => ['coupons' => []],
            ],
        ]);
    }

    /**
     * 취소 시 사용된 쿠폰이 available 상태로 복원됩니다.
     */
    public function test_restores_used_coupon_to_available(): void
    {
        $couponIssue = $this->createCouponIssue();
        $order = $this->createOrderWithCoupons([$couponIssue->id]);

        $this->assertEquals(CouponIssueRecordStatus::USED, $couponIssue->status);

        // When
        $this->listener->restoreCoupons($order);

        // Then
        $couponIssue->refresh();
        $this->assertEquals(CouponIssueRecordStatus::AVAILABLE, $couponIssue->status);
        $this->assertNull($couponIssue->used_at);
    }

    /**
     * 만료된 쿠폰은 available 대신 expired 상태로 변경됩니다.
     */
    public function test_expired_coupon_becomes_expired_not_available(): void
    {
        $couponIssue = $this->createCouponIssue([
            'expired_at' => now()->subDays(1), // 어제 만료
        ]);
        $order = $this->createOrderWithCoupons([$couponIssue->id]);

        // When
        $this->listener->restoreCoupons($order);

        // Then
        $couponIssue->refresh();
        $this->assertEquals(CouponIssueRecordStatus::EXPIRED, $couponIssue->status);
        $this->assertNull($couponIssue->used_at);
    }

    /**
     * 이미 available 상태인 쿠폰은 변경되지 않습니다.
     */
    public function test_already_available_coupon_is_not_modified(): void
    {
        $couponIssue = $this->createCouponIssue([
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'used_at' => null,
        ]);
        $order = $this->createOrderWithCoupons([$couponIssue->id]);

        // When
        $this->listener->restoreCoupons($order);

        // Then: 상태 변경 없음
        $couponIssue->refresh();
        $this->assertEquals(CouponIssueRecordStatus::AVAILABLE, $couponIssue->status);
    }

    /**
     * 프로모션 스냅샷이 없는 주문은 에러 없이 건너뜁니다.
     */
    public function test_order_without_promotions_snapshot_is_skipped(): void
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-TEST-'.uniqid(),
            'order_status' => OrderStatusEnum::CANCELLED,
            'currency' => 'KRW',
            'item_count' => 1,
            'ordered_at' => now(),
            'subtotal_amount' => 50000,
            'total_amount' => 50000,
            'total_paid_amount' => 50000,
            'promotions_applied_snapshot' => null,
        ]);

        // When: 예외 없이 실행됨
        $this->listener->restoreCoupons($order);

        // Then: 아무 에러 없이 완료
        $this->assertTrue(true);
    }

    /**
     * 여러 쿠폰이 동시에 복원됩니다.
     */
    public function test_restores_multiple_coupons(): void
    {
        $couponIssue1 = $this->createCouponIssue();
        $couponIssue2 = $this->createCouponIssue();
        $order = $this->createOrderWithCoupons([$couponIssue1->id, $couponIssue2->id]);

        // When
        $this->listener->restoreCoupons($order);

        // Then
        $couponIssue1->refresh();
        $couponIssue2->refresh();
        $this->assertEquals(CouponIssueRecordStatus::AVAILABLE, $couponIssue1->status);
        $this->assertEquals(CouponIssueRecordStatus::AVAILABLE, $couponIssue2->status);
    }

    /**
     * getSubscribedHooks가 올바른 훅과 메서드를 반환합니다.
     */
    public function test_subscribes_to_after_cancel_hook(): void
    {
        $hooks = CouponRestoreListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_cancel', $hooks);
        $this->assertEquals('restoreCoupons', $hooks['sirsoft-ecommerce.order.after_cancel']['method']);
        $this->assertEquals(10, $hooks['sirsoft-ecommerce.order.after_cancel']['priority']);

        // 부분취소 명시 복원 훅
        $this->assertArrayHasKey('sirsoft-ecommerce.coupon.restore', $hooks);
        $this->assertEquals('restoreCouponsByIds', $hooks['sirsoft-ecommerce.coupon.restore']['method']);
    }

    /**
     * 부분취소(coupon.restore 훅): 전달된 ID 만 used→available 복원한다.
     */
    public function test_restore_coupons_by_ids_restores_only_passed_ids(): void
    {
        $dropped = $this->createCouponIssue();           // 탈락 → 복원 대상
        $kept = $this->createCouponIssue();              // 유지 → 복원 안 됨 (USED 유지)
        $order = $this->createOrderWithCoupons([$dropped->id, $kept->id]);

        // When: 탈락 쿠폰 ID 만 전달
        $this->listener->restoreCouponsByIds($order, [$dropped->id]);

        // Then
        $dropped->refresh();
        $kept->refresh();
        $this->assertEquals(CouponIssueRecordStatus::AVAILABLE, $dropped->status);
        $this->assertEquals(CouponIssueRecordStatus::USED, $kept->status, '유지 쿠폰은 USED 유지');
    }

    /**
     * after_cancel 경로가 신형식 스냅샷(coupon_issue_ids 평탄)에서 복원한다.
     *
     * 종전 listener 는 product_coupons/order_coupons 키만 봐 0/66 매칭 → 복원 미발동이었다.
     */
    public function test_restores_from_real_snapshot_coupon_issue_ids(): void
    {
        $couponIssue = $this->createCouponIssue();
        $order = $this->createOrderWithCoupons([$couponIssue->id]);

        // coupon_issue_ids 평탄 키가 채워졌는지 확인 (실데이터 형식)
        $this->assertNotEmpty($order->promotions_applied_snapshot['coupon_issue_ids']);

        $this->listener->restoreCoupons($order);

        $couponIssue->refresh();
        $this->assertEquals(CouponIssueRecordStatus::AVAILABLE, $couponIssue->status);
    }
}
