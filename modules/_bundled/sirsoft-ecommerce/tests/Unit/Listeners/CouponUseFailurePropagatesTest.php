<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\CouponUseListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 생성 때 쿠폰 사용 차감이 실패하면 주문 생성도 실패한다 (F3)
 *
 * 리스너가 `\Exception` 을 삼켜, 쿠폰 차감 쓰기가 실패해도 주문은 할인된 금액으로 만들어지고 쿠폰은 사용 가능으로 남았다 —
 * 같은 쿠폰을 다음 주문에 또 쓸 수 있었다. 이 리스너는 동기(`sync`)라 예외가 주문 생성 트랜잭션을 되돌린다
 * (선점 실패의 롤백은 OrderProcessingServiceTest 가 잠근다).
 *
 * 시나리오 매니페스트: `tests/scenarios/coupon-use-failure-propagates.yaml`
 */
class CouponUseFailurePropagatesTest extends ModuleTestCase
{
    /**
     * 쿠폰 차감 쓰기가 실패하면 예외가 호출부(주문 생성 트랜잭션)로 전파된다.
     *
     * @scenario failure=repository_error
     *
     * @effects coupon_use_failure_aborts_order_creation
     *
     * @return void 반환값 없음
     */
    public function test_repository_failure_is_not_swallowed(): void
    {
        $repository = $this->createMock(CouponIssueRepositoryInterface::class);
        $repository->method('updateIfStatus')->willThrowException(new \RuntimeException('db write failed'));
        $this->app->instance(CouponIssueRepositoryInterface::class, $repository);

        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-F3-'.uniqid(),
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
            'currency' => 'KRW',
            'item_count' => 1,
            'ordered_at' => now(),
            'subtotal_amount' => 50000,
            'total_amount' => 49000,
            'total_paid_amount' => 49000,
        ]);

        $this->expectException(\RuntimeException::class);

        app(CouponUseListener::class)->markCouponsUsed([12345], $order);
    }
}
