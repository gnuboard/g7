<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderPaymentResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 입금 전 주문의 부분취소와 [입금확인] (E2 · Q17)
 *
 * 입금 전 주문을 일부만 취소하면 재계산된 남은 결제액이 받은 돈(total_paid_amount)에 들어가고 받을 돈
 * (total_due_amount)은 취소 전 금액으로 남았다. 결제행은 부분취소로 바뀌어 [입금확인] 버튼이 사라지고, API 로
 * 불러도 금액 검증(구성요소 합 = due)이 어떤 금액으로도 통과하지 못했다. 가상계좌는 PG 가 발급한 입금액이 고정
 * 이라 일부 취소 뒤에는 입금을 확인할 길이 없으므로 전액취소만 허용한다(Q17).
 *
 * 시나리오 매니페스트: `tests/scenarios/unpaid-partial-cancel-deposit.yaml`
 */
class UnpaidPartialCancelDepositTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $admin;

    /**
     * 통화·사유를 심고 주문 수정 권한을 가진 관리자를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read', 'sirsoft-ecommerce.orders.update']);
    }

    /**
     * 테스트가 심은 통화 설정을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 입금 전 주문을 만듭니다 (무료배송, 결제행 미결제).
     *
     * @param  array<int, int>  $prices  상품별 판매가 (각 1개)
     * @param  PaymentMethodEnum  $method  결제수단
     * @return Order 입금 대기 주문
     */
    protected function makeUnpaidOrder(array $prices = [20000, 10000], PaymentMethodEnum $method = PaymentMethodEnum::DBANK): Order
    {
        $policy = $this->createClaimShippingPolicy();
        $items = [];
        foreach ($prices as $price) {
            [$product, $option] = $this->createClaimProduct($price, $policy);
            $items[] = new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1);
        }
        $sum = array_sum($prices);
        $isVbank = $method === PaymentMethodEnum::VBANK;

        $order = $this->createClaimOrder(
            new CalculationInput(items: $items),
            [
                'order_status' => OrderStatusEnum::PENDING_PAYMENT,
                'total_paid_amount' => 0,
                'total_due_amount' => $sum,
                'total_amount' => $sum,
                'currency_snapshot' => $this->krwCurrencySnapshot(),
            ],
            [
                'payment_method' => $method->value,
                'payment_status' => $isVbank ? PaymentStatusEnum::WAITING_DEPOSIT : PaymentStatusEnum::READY,
                'paid_at' => null,
                'paid_amount_local' => 0,
                'paid_amount_base' => 0,
                'pg_provider' => $isVbank ? 'tosspayments' : '',
            ],
        );
        foreach ($order->options as $option) {
            $option->forceFill(['option_status' => OrderStatusEnum::PENDING_PAYMENT])->save();
        }

        return $order->fresh(['options', 'payment', 'shippings']);
    }

    /**
     * 상품 하나를 부분취소합니다(서비스 진입점 — 관리자 취소와 같은 접수 경로).
     *
     * @param  Order  $order  주문
     * @param  int  $index  취소할 상품 순서
     * @return Order 취소 뒤 주문
     */
    protected function cancelOne(Order $order, int $index): Order
    {
        $row = $order->options->values()[$index];
        app(OrderCancellationService::class)->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $row->id, 'cancel_quantity' => 1]],
            reason: 'changed_mind',
        );

        return $order->fresh(['options', 'payment', 'shippings']);
    }

    /**
     * 입금확인 API 를 부릅니다.
     *
     * @param  Order  $order  주문
     * @param  array<string, mixed>  $body  요청 본문
     * @return TestResponse 응답
     */
    protected function confirmDeposit(Order $order, array $body)
    {
        return $this->actingAs($this->admin)
            ->patchJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/confirm-deposit", $body);
    }

    /**
     * 입금 전 부분취소는 남은 금액을 받을 돈(due)으로 옮기고 받은 돈은 0, 결제행은 입금 대기로 둔다.
     *
     * @effects unpaid_partial_cancel_moves_remaining_total_to_due
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_of_an_unpaid_order_moves_the_remaining_total_to_due(): void
    {
        $order = $this->cancelOne($this->makeUnpaidOrder(), 1);

        $this->assertEquals(20000, (float) $order->total_due_amount);
        $this->assertEquals(0, (float) $order->total_paid_amount);
        $this->assertSame(PaymentStatusEnum::READY, $order->payment->payment_status);
        $this->assertSame(OrderStatusEnum::PENDING_PAYMENT, $order->order_status);
    }

    /**
     * 실주문처럼 상품 행이 「주문대기」인 무통장 주문을 입금 전에 부분취소해도 주문은 결제대기로 남는다.
     *
     * 주문서는 무통장 주문의 상품 행을 `pending_order` 로 만든다(헤더만 `pending_payment`). 부분취소 뒤 남은 행으로
     * 부모 상태를 다시 정하면 헤더가 `pending_order` 로 내려가 관리자 주문 목록(주문대기 기본 숨김)에서 사라졌다(#747 C12 V1).
     *
     * @effects unpaid_partial_cancel_moves_remaining_total_to_due
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_keeps_pending_payment_when_rows_are_pending_order(): void
    {
        $order = $this->makeUnpaidOrder();
        foreach ($order->options as $option) {
            $option->forceFill(['option_status' => OrderStatusEnum::PENDING_ORDER])->save();
        }

        $order = $this->cancelOne($order->fresh(['options', 'payment']), 1);

        $this->assertSame(OrderStatusEnum::PENDING_PAYMENT, $order->order_status);
        $this->assertEquals(20000, (float) $order->total_due_amount);
    }

    /**
     * 입금 전 부분취소 뒤 남은 금액으로 입금확인하면 주문이 결제완료가 된다.
     *
     * @effects deposit_confirm_after_unpaid_partial_cancel_completes_order
     *
     * @return void 반환값 없음
     */
    public function test_deposit_confirm_after_a_partial_cancel_completes_the_order(): void
    {
        $order = $this->cancelOne($this->makeUnpaidOrder(), 1);

        $this->confirmDeposit($order, ['amount' => 20000, 'mark_order_complete' => true])->assertOk();

        $order = $order->fresh(['options', 'payment']);
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertEquals(20000, (float) $order->total_paid_amount);
        $this->assertEquals(0, (float) $order->total_due_amount);
        $this->assertSame(PaymentStatusEnum::PAID, $order->payment->payment_status);
        $this->assertEquals(20000, (float) $order->payment->paid_amount_base);
        $this->assertSame(1, $order->options->where('option_status', OrderStatusEnum::PAYMENT_COMPLETE)->count());
        $this->assertSame(1, $order->options->where('option_status', OrderStatusEnum::CANCELLED)->count());
    }

    /**
     * 입금 전 부분취소 뒤 「입금 내역만 기록」도 받아들인다.
     *
     * @effects deposit_confirm_after_unpaid_partial_cancel_completes_order
     *
     * @return void 반환값 없음
     */
    public function test_deposit_record_only_after_a_partial_cancel_is_accepted(): void
    {
        $order = $this->cancelOne($this->makeUnpaidOrder(), 1);

        $this->confirmDeposit($order, ['amount' => 20000])->assertOk();

        $order = $order->fresh(['payment']);
        $this->assertSame(PaymentStatusEnum::PAID, $order->payment->payment_status);
        $this->assertSame(OrderStatusEnum::PENDING_PAYMENT, $order->order_status);
    }

    /**
     * 입금 전 부분취소 뒤 취소 전 금액으로 입금확인하면 금액 불일치로 거절한다.
     *
     * @effects deposit_of_original_amount_rejected_after_partial_cancel
     *
     * @return void 반환값 없음
     */
    public function test_deposit_of_the_original_amount_is_rejected_after_a_partial_cancel(): void
    {
        $order = $this->cancelOne($this->makeUnpaidOrder(), 1);

        $this->confirmDeposit($order, ['amount' => 30000, 'mark_order_complete' => true])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => __('sirsoft-ecommerce::messages.orders.deposit_amount_mismatch')]);
    }

    /**
     * 입금 전 부분취소를 두 번 해도 받은 돈은 0 이고 결제행 이력은 결제행에 정산되지 않은 것으로 남는다.
     *
     * @effects unpaid_partial_cancel_moves_remaining_total_to_due
     *
     * @return void 반환값 없음
     */
    public function test_two_partial_cancels_keep_paid_at_zero(): void
    {
        $order = $this->cancelOne($this->makeUnpaidOrder([20000, 10000, 5000]), 2);
        $order = $this->cancelOne($order, 1);

        $this->assertEquals(20000, (float) $order->total_due_amount);
        $this->assertEquals(0, (float) $order->total_paid_amount);
        $this->assertEquals(0, (float) $order->total_cancelled_amount);
        $history = $order->payment->cancel_history;
        $this->assertCount(2, $history);
        foreach ($history as $entry) {
            $this->assertFalse($entry['settled_on_payment']);
        }
    }

    /**
     * 입금 전 가상계좌 주문의 부분취소는 거절하고 아무것도 바꾸지 않는다(Q17).
     *
     * @effects vbank_awaiting_deposit_rejects_partial_cancel
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_of_a_vbank_awaiting_deposit_is_rejected(): void
    {
        $order = $this->makeUnpaidOrder([20000, 10000], PaymentMethodEnum::VBANK);
        $before = [(float) $order->total_due_amount, $order->payment->payment_status];

        try {
            $this->cancelOne($order, 1);
            $this->fail('입금 전 가상계좌 부분취소가 통과했습니다.');
        } catch (OrderCancellationException $e) {
            $this->assertSame(__('sirsoft-ecommerce::exceptions.partial_cancel_not_supported_vbank_awaiting_deposit'), $e->getMessage());
        }

        $order = $order->fresh(['options', 'payment']);
        $this->assertSame($before, [(float) $order->total_due_amount, $order->payment->payment_status]);
        $this->assertSame(0, $order->options->where('option_status', OrderStatusEnum::CANCELLED)->count());
        $this->assertSame(0, OrderCancel::query()->where('order_id', $order->id)->count());
    }

    /**
     * 입금 전 가상계좌 주문도 전액취소는 된다.
     *
     * @effects vbank_awaiting_deposit_allows_full_cancel
     *
     * @return void 반환값 없음
     */
    public function test_full_cancel_of_a_vbank_awaiting_deposit_is_allowed(): void
    {
        $order = $this->makeUnpaidOrder([20000, 10000], PaymentMethodEnum::VBANK);

        app(OrderCancellationService::class)->cancelOrderOptions(
            order: $order,
            cancelItems: $order->options->map(fn ($o) => ['order_option_id' => $o->id, 'cancel_quantity' => 1])->all(),
            reason: 'changed_mind',
        );

        $order = $order->fresh(['payment']);
        $this->assertSame(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertSame(PaymentStatusEnum::CANCELLED, $order->payment->payment_status);
        $this->assertEquals(0, (float) $order->total_due_amount);
    }

    /**
     * 결제 리소스가 입금 전 가상계좌의 부분취소 불가와 그 사유를 싣고, 결제 뒤에는 지운다.
     *
     * @effects payment_resource_reports_vbank_partial_cancel_block_reason
     *
     * @return void 반환값 없음
     */
    public function test_payment_resource_reports_the_vbank_block_reason(): void
    {
        $order = $this->makeUnpaidOrder([20000, 10000], PaymentMethodEnum::VBANK);

        $awaiting = (new OrderPaymentResource($order->payment))->toArray(Request::create('/'));
        $this->assertFalse($awaiting['supports_partial_cancel']);
        $this->assertSame('vbank_awaiting_deposit', $awaiting['partial_cancel_block_reason']);

        $order->payment->forceFill(['payment_status' => PaymentStatusEnum::PAID])->save();
        $paid = (new OrderPaymentResource($order->payment->fresh()))->toArray(Request::create('/'));
        $this->assertTrue($paid['supports_partial_cancel']);
        $this->assertNull($paid['partial_cancel_block_reason']);
    }
}
