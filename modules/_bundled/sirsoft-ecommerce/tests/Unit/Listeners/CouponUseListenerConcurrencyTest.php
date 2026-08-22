<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\CouponAlreadyUsedException;
use Modules\Sirsoft\Ecommerce\Listeners\CouponUseListener;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 사용 차감 동시성 회귀 테스트 (KVE-2026-1886)
 *
 * 무락 스냅샷 read → 상태 확인 → 무조건 update 순서는 두 주문이 동시에 AVAILABLE 을 읽어
 * 각자 USED 로 덮어쓰는 lost update 를 허용한다. 1회 제한 쿠폰이 여러 주문에 사용되며,
 * 두 주문 모두 이미 할인 금액이 확정된 상태라 검출만으로는 부족하고 경쟁에서 진 주문의
 * 트랜잭션이 롤백되어야 한다.
 */
class CouponUseListenerConcurrencyTest extends ModuleTestCase
{
    protected CouponUseListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = app(CouponUseListener::class);
    }

    /**
     * 조건부 차감은 기대 상태일 때만 성공하고, 두 번째 시도는 0 행을 반환해야 합니다.
     *
     * @return void
     */
    public function test_update_if_status_is_atomic_compare_and_set(): void
    {
        $repo = app(CouponIssueRepositoryInterface::class);
        $issue = $this->createAvailableIssue();
        $order = $this->createOrder();

        $first = $repo->updateIfStatus($issue->id, CouponIssueRecordStatus::AVAILABLE, [
            'status' => CouponIssueRecordStatus::USED,
            'used_at' => now(),
            'order_id' => $order->id,
        ]);

        $second = $repo->updateIfStatus($issue->id, CouponIssueRecordStatus::AVAILABLE, [
            'status' => CouponIssueRecordStatus::USED,
            'used_at' => now(),
            'order_id' => $order->id,
        ]);

        $this->assertSame(1, $first, '기대 상태였던 첫 시도는 1 행을 갱신해야 합니다.');
        $this->assertSame(0, $second, '이미 상태가 바뀐 뒤의 시도는 0 행이어야 합니다.');
    }

    /**
     * 다른 주문이 이미 선점한 쿠폰은 예외로 차단되어야 합니다 (경쟁에서 진 주문 롤백).
     *
     * @return void
     */
    public function test_coupon_taken_by_another_order_throws(): void
    {
        $issue = $this->createAvailableIssue();
        $firstOrder = $this->createOrder();
        $secondOrder = $this->createOrder();

        $this->listener->markCouponsUsed([$issue->id], $firstOrder);

        $this->expectException(CouponAlreadyUsedException::class);
        $this->listener->markCouponsUsed([$issue->id], $secondOrder);
    }

    /**
     * 선점당한 쿠폰의 소유(order_id)는 선행 주문 그대로여야 합니다.
     *
     * @return void
     */
    public function test_losing_order_does_not_overwrite_coupon_owner(): void
    {
        $issue = $this->createAvailableIssue();
        $firstOrder = $this->createOrder();
        $secondOrder = $this->createOrder();

        $this->listener->markCouponsUsed([$issue->id], $firstOrder);

        try {
            $this->listener->markCouponsUsed([$issue->id], $secondOrder);
        } catch (CouponAlreadyUsedException) {
            // 기대된 차단
        }

        $issue->refresh();
        $this->assertEquals(CouponIssueRecordStatus::USED, $issue->status);
        $this->assertEquals($firstOrder->id, $issue->order_id, '쿠폰 소유는 선행 주문이어야 합니다.');
    }

    /**
     * 같은 주문의 재발화는 멱등이어야 합니다 (예외 없이 skip).
     *
     * @return void
     */
    public function test_same_order_refire_is_idempotent(): void
    {
        $issue = $this->createAvailableIssue();
        $order = $this->createOrder();

        $this->listener->markCouponsUsed([$issue->id], $order);
        $firstUsedAt = $issue->refresh()->used_at;

        $this->listener->markCouponsUsed([$issue->id], $order);

        $issue->refresh();
        $this->assertEquals(CouponIssueRecordStatus::USED, $issue->status);
        $this->assertEquals($firstUsedAt->toIso8601String(), $issue->used_at->toIso8601String());
        $this->assertEquals($order->id, $issue->order_id);
    }

    /**
     * 사용 불가 상태(취소됨)의 쿠폰도 차단되어야 합니다.
     *
     * @return void
     */
    public function test_non_available_coupon_is_blocked(): void
    {
        $issue = $this->createAvailableIssue(['status' => CouponIssueRecordStatus::CANCELLED]);
        $order = $this->createOrder();

        $this->expectException(CouponAlreadyUsedException::class);
        $this->listener->markCouponsUsed([$issue->id], $order);
    }

    /**
     * 존재하지 않는 발급 ID 는 예외 없이 skip 되어야 합니다.
     *
     * @return void
     */
    public function test_missing_issue_is_skipped(): void
    {
        $order = $this->createOrder();

        $this->listener->markCouponsUsed([999999], $order);

        $this->assertTrue(true);
    }

    /**
     * 사용 가능 상태의 쿠폰 발급 레코드를 생성합니다.
     *
     * @param  array  $overrides  오버라이드
     * @return CouponIssue 생성된 발급 레코드
     */
    protected function createAvailableIssue(array $overrides = []): CouponIssue
    {
        $coupon = Coupon::create([
            'name' => ['ko' => '테스트 쿠폰', 'en' => 'Test Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 1000,
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
            'coupon_code' => 'RACE'.uniqid(),
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expired_at' => now()->addDays(30),
        ], $overrides));
    }

    /**
     * 테스트용 주문을 생성합니다.
     *
     * @return Order 생성된 주문
     */
    protected function createOrder(): Order
    {
        $user = User::factory()->create();

        return Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-RACE-'.uniqid(),
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
            'currency' => 'KRW',
            'item_count' => 1,
            'ordered_at' => now(),
            'subtotal_amount' => 50000,
            'total_amount' => 49000,
            'total_paid_amount' => 49000,
        ]);
    }
}
