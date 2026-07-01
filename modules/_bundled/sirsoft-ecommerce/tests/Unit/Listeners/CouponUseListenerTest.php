<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\CouponUseListener;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * CouponUseListener 단위 테스트 (공개#57/MP06)
 *
 * 주문 생성 시 쿠폰 사용 차감(available → used) 동작을 검증합니다.
 */
class CouponUseListenerTest extends ModuleTestCase
{
    protected CouponUseListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = app(CouponUseListener::class);
    }

    /**
     * 사용 가능 상태의 쿠폰 발급 레코드를 생성합니다.
     *
     * @param  array  $overrides  오버라이드
     * @return CouponIssue
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
            'coupon_code' => 'USE'.uniqid(),
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expired_at' => now()->addDays(30),
        ], $overrides));
    }

    /**
     * 테스트용 주문을 생성합니다.
     */
    protected function createOrder(): Order
    {
        $user = User::factory()->create();

        return Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-USE-'.uniqid(),
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
            'currency' => 'KRW',
            'item_count' => 1,
            'ordered_at' => now(),
            'subtotal_amount' => 50000,
            'total_amount' => 49000,
            'total_paid_amount' => 49000,
        ]);
    }

    /**
     * coupon.use 발화 → status=used, used_at, order_id 세팅.
     */
    public function test_marks_coupon_used(): void
    {
        $issue = $this->createAvailableIssue();
        $order = $this->createOrder();

        $this->listener->markCouponsUsed([$issue->id], $order);

        $issue->refresh();
        $this->assertEquals(CouponIssueRecordStatus::USED, $issue->status);
        $this->assertNotNull($issue->used_at);
        $this->assertEquals($order->id, $issue->order_id);
    }

    /**
     * 멱등성: 재발화해도 안전 (이미 used 면 skip).
     */
    public function test_idempotent_on_already_used(): void
    {
        $issue = $this->createAvailableIssue();
        $order = $this->createOrder();

        $this->listener->markCouponsUsed([$issue->id], $order);
        $firstUsedAt = $issue->refresh()->used_at;

        // 재발화
        $this->listener->markCouponsUsed([$issue->id], $order);

        $issue->refresh();
        $this->assertEquals(CouponIssueRecordStatus::USED, $issue->status);
        $this->assertEquals($firstUsedAt->toIso8601String(), $issue->used_at->toIso8601String());
    }

    /**
     * 존재하지 않는 ID 는 에러 없이 skip.
     */
    public function test_skips_missing_id(): void
    {
        $order = $this->createOrder();

        // 예외 없이 완료
        $this->listener->markCouponsUsed([999999], $order);
        $this->assertTrue(true);
    }

    /**
     * 재사용 차단(공개#57 직접 재현): 사용 처리되면 available 필터에서 제외된다.
     *
     * 차감 전: getUserAvailableCouponsForCheckout 류 available 조회에 포함 →
     * 차감 후: USED 라 제외 → 같은 쿠폰을 둘째 주문에 다시 적용 불가.
     */
    public function test_used_coupon_excluded_from_available_after_use(): void
    {
        $issue = $this->createAvailableIssue();
        $order = $this->createOrder();
        $repo = app(CouponIssueRepositoryInterface::class);

        // 차감 전: available
        $this->assertEquals(CouponIssueRecordStatus::AVAILABLE, $issue->status);

        $this->listener->markCouponsUsed([$issue->id], $order);

        // 차감 후: used → 재조회 시 available 아님
        $reloaded = $repo->findById($issue->id);
        $this->assertEquals(CouponIssueRecordStatus::USED, $reloaded->status);
    }

    /**
     * getSubscribedHooks 가 coupon.use 훅에 연결된다.
     */
    public function test_subscribes_to_coupon_use_hook(): void
    {
        $hooks = CouponUseListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.coupon.use', $hooks);
        $this->assertEquals('markCouponsUsed', $hooks['sirsoft-ecommerce.coupon.use']['method']);
    }
}
