<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * S2 신규 action 훅 발화 테스트
 *
 * - order.after_deposit_recorded : 관리자 "입금만 기록" 경로 (기존에 훅이 0개였다)
 * - order.after_purchase_confirmed : 구매확정 시점 (기존 order.after_confirm 은 결제완료 훅이라 이름만 비슷하다)
 */
class CashReceiptHookFiringTest extends ModuleTestCase
{
    /** @var array<int, array> 발화된 훅 인자 기록 */
    private array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fired = [];
    }

    /**
     * 훅 발화를 기록합니다.
     */
    private function capture(string $hook): void
    {
        HookManager::addAction($hook, function (...$args) {
            $this->fired[] = $args;
        });
    }

    /**
     * 무통장 미결제 주문을 생성합니다.
     */
    private function makeUnpaidDbankOrder(int $due = 11000): Order
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => $due,
            'total_due_amount' => $due,
            'subtotal_amount' => $due,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'total_points_used_amount' => 0,
        ]);

        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::DBANK,
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
        ]);

        return $order->fresh();
    }

    // ─────────────────────────────────────────────
    // order.after_deposit_recorded
    // ─────────────────────────────────────────────

    #[Test]
    public function 입금만_기록_경로에서_after_deposit_recorded_가_발화한다(): void
    {
        $this->capture('sirsoft-ecommerce.order.after_deposit_recorded');

        $order = $this->makeUnpaidDbankOrder(11000);

        // markOrderComplete = false → completePayment 를 타지 않고 recordDepositPayment 만 수행
        app(OrderProcessingService::class)->confirmManualDeposit($order, 11000.0, '홍길동', false);

        $this->assertCount(1, $this->fired, 'after_deposit_recorded 가 1회 발화해야 한다');
        $this->assertInstanceOf(Order::class, $this->fired[0][0]);
        $this->assertSame($order->id, $this->fired[0][0]->id);
        $this->assertSame(11000.0, $this->fired[0][1]);

        // 결제 레코드는 PAID 로 정합화된다
        $this->assertSame(PaymentStatusEnum::PAID, $order->fresh()->payment->payment_status);
    }

    #[Test]
    public function 결제완료_전이_경로에서는_after_deposit_recorded_가_발화하지_않는다(): void
    {
        // markOrderComplete=true 이고 주문이 아직 결제완료가 아니면 completePayment 에 위임되고,
        // 그 경로는 after_payment_complete 를 발화한다 (입금 기록 훅은 발화하지 않는다).
        $this->capture('sirsoft-ecommerce.order.after_deposit_recorded');
        $this->capture('sirsoft-ecommerce.order.after_payment_complete');

        $order = $this->makeUnpaidDbankOrder(11000);
        $order->update(['order_status' => OrderStatusEnum::PENDING_PAYMENT]);

        app(OrderProcessingService::class)->confirmManualDeposit($order->fresh(), 11000.0, null, true);

        // capture 는 두 훅을 같은 배열에 모으므로, 발화한 것이 결제완료 훅뿐인지 확인한다.
        $this->assertCount(1, $this->fired, 'after_payment_complete 만 1회 발화해야 한다');
    }

    // ─────────────────────────────────────────────
    // order.after_purchase_confirmed
    // ─────────────────────────────────────────────

    /**
     * 확정 가능한 옵션 1개짜리 주문을 만듭니다.
     */
    private function makeConfirmableOrder(): array
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatusEnum::DELIVERED,
            'confirmed_at' => null,
        ]);

        $option = OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::DELIVERED,
            'confirmed_at' => null,
        ]);

        return [$order->fresh(), $option->fresh()];
    }

    #[Test]
    public function 유저_셀프_구매확정시_after_purchase_confirmed_가_발화한다(): void
    {
        $this->capture('sirsoft-ecommerce.order.after_purchase_confirmed');

        [$order, $option] = $this->makeConfirmableOrder();

        app(OrderService::class)->confirmOption($order, $option);

        $this->assertCount(1, $this->fired, '구매확정 훅이 1회 발화해야 한다');
        $this->assertSame($order->id, $this->fired[0][0]->id);
        $this->assertNotNull($order->fresh()->confirmed_at);
    }

    #[Test]
    public function 관리자_옵션_일괄확정시에도_after_purchase_confirmed_가_발화한다(): void
    {
        // OrderOptionService::transitionOrderStatus 경유 경로.
        // 유저 셀프 확정(OrderService::confirmOption)과 별개의 발화 지점이므로 따로 검증한다.
        $this->capture('sirsoft-ecommerce.order.after_purchase_confirmed');

        [$order, $option] = $this->makeConfirmableOrder();

        // 옵션을 확정 상태로 만든 뒤 부모 주문 상태를 파생 동기화한다.
        $option->update(['option_status' => OrderStatusEnum::CONFIRMED, 'confirmed_at' => now()]);
        app(OrderOptionService::class)->syncParentOrderStatus($order->id);

        $this->assertCount(1, $this->fired, '옵션 일괄확정 경로에서도 구매확정 훅이 1회 발화해야 한다');
        $this->assertSame($order->id, $this->fired[0][0]->id);
        $this->assertSame(OrderStatusEnum::CONFIRMED, $order->fresh()->order_status);
        $this->assertNotNull($order->fresh()->confirmed_at);
    }

    #[Test]
    public function 옵션_일괄확정_경로도_confirmed_at_이_있으면_재발화하지_않는다(): void
    {
        // 멱등: 두 발화 지점 모두 최초 확정 시점을 보존해야 한다.
        $this->capture('sirsoft-ecommerce.order.after_purchase_confirmed');

        [$order, $option] = $this->makeConfirmableOrder();
        $confirmedAt = now()->subDay();
        $order->update(['confirmed_at' => $confirmedAt]);

        $option->update(['option_status' => OrderStatusEnum::CONFIRMED, 'confirmed_at' => now()]);
        app(OrderOptionService::class)->syncParentOrderStatus($order->id);

        $this->assertCount(0, $this->fired, '이미 확정된 주문은 훅을 재발화하지 않는다');
        $this->assertSame(
            $confirmedAt->toDateTimeString(),
            $order->fresh()->confirmed_at->toDateTimeString(),
            '최초 확정 시점이 보존되어야 한다',
        );
    }

    #[Test]
    public function confirmed_at_이_이미_있으면_구매확정_훅이_재발화하지_않는다(): void
    {
        // 멱등: 최초 확정 시점을 보존하고 훅도 1회만 발화한다.
        $this->capture('sirsoft-ecommerce.order.after_purchase_confirmed');

        [$order, $option] = $this->makeConfirmableOrder();
        $confirmedAt = now()->subDay();
        $order->update(['confirmed_at' => $confirmedAt]);

        app(OrderService::class)->confirmOption($order->fresh(), $option);

        $this->assertCount(0, $this->fired, '이미 확정된 주문은 훅을 재발화하지 않는다');
        $this->assertSame(
            $confirmedAt->toDateTimeString(),
            $order->fresh()->confirmed_at->toDateTimeString(),
            '최초 확정 시점이 보존되어야 한다',
        );
    }
}
