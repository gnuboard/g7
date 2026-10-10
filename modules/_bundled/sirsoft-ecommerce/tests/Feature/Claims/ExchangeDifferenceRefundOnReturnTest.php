<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderOptionSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimResource;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상위 옵션 교환 차액은 교환품을 반품할 때 낸 수단 그대로 돌려준다 (S2 · Q9)
 *
 * 24,000원 상품을 29,000원 옵션으로 교환하며 차액 5,000원을 추가결제한 뒤 그 교환품을 반품하면, 주문 재계산은
 * 원 행 단가만 돌려주고 차액은 클레임 추가결제 장부에 남아 돌아오지 않았다. 교환 완료 상태는 저장소에 직접
 * 만들고(교환 흐름 자체는 다른 테스트가 소유), 반품·환불은 실제 서비스 경로로 한다.
 *
 * 시나리오 매니페스트: `tests/scenarios/exchange-difference-refund.yaml`
 */
class ExchangeDifferenceRefundOnReturnTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 통화·사유를 심습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
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
     * 교환을 마친 주문을 만듭니다 — 원 행은 교환완료, 후속 행은 배송완료, 교환 차액은 결제 건으로 수납됨.
     *
     * @param  int  $quantity  교환 수량
     * @param  int  $difference  교환 차액 합계 (상품 몫)
     * @param  string  $method  차액을 낸 수단 (card · point)
     * @return array{0: Order, 1: OrderOption, 2: ClaimPayment} [주문, 후속 행, 차액 결제 건]
     */
    protected function exchangedOrder(int $quantity = 1, int $difference = 5000, string $method = 'card'): array
    {
        $order = $this->deliveredClaimOrder(price: 24000, quantity: $quantity, returnFee: 0);
        $original = $order->options->first();

        $successor = $original->replicate(['parent_option_id']);
        $successor->source_type = OrderOptionSourceTypeEnum::EXCHANGE;
        $successor->source_option_id = $original->id;
        $successor->option_status = OrderStatusEnum::DELIVERED;
        $successor->delivered_at = now()->subDay();
        $successor->save();
        $original->forceFill(['option_status' => OrderStatusEnum::EXCHANGED])->save();

        $exchange = OrderExchange::factory()->create([
            'order_id' => $order->id,
            'exchange_status' => ExchangeStatusEnum::COMPLETED,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_APPROVAL,
            'additional_product_amount' => $difference,
            'total_charge_amount' => $difference,
            'requested_by' => $order->user_id,
        ]);
        OrderExchangeOption::factory()->create([
            'order_exchange_id' => $exchange->id,
            'order_id' => $order->id,
            'order_option_id' => $original->id,
            'new_order_option_id' => $successor->id,
            'exchange_quantity' => $quantity,
            'price_difference' => $difference,
        ]);

        $isPoint = $method === 'point';
        $payment = ClaimPayment::factory()->create([
            'payable_type' => ClaimTypeEnum::EXCHANGE->value,
            'payable_id' => $exchange->id,
            'order_id' => $order->id,
            'payment_method' => $isPoint ? PaymentMethodEnum::POINT->value : PaymentMethodEnum::CARD->value,
            'pg_provider' => $isPoint ? null : 'tosspayments',
            'payment_status' => ClaimPaymentStatusEnum::PAID,
            'amount' => $isPoint ? 0 : $difference,
            'points_amount' => $isPoint ? $difference : 0,
            'transaction_id' => $isPoint ? null : 'CLAIM-TXN-1',
            'paid_at' => now(),
        ]);

        return [$order->fresh(['options', 'payment', 'shippings']), $successor->fresh(), $payment];
    }

    /**
     * 후속 행을 반품 완료하고 환불신청서를 받습니다.
     *
     * @param  Order  $order  주문
     * @param  OrderOption  $successor  후속 행
     * @param  int  $quantity  반품 수량
     * @return OrderRefund 반품 발 환불신청서
     */
    protected function returnSuccessor(Order $order, OrderOption $successor, int $quantity = 1): OrderRefund
    {
        $service = app(OrderReturnService::class);
        $return = $service->requestReturn($order, [
            'items' => [['order_option_id' => $successor->id, 'quantity' => $quantity]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);
        $return = $service->transition($return, ReturnStatusEnum::APPROVED);
        $return = $service->transition($return->fresh(), ReturnStatusEnum::COLLECTION_READY);
        $return = $service->transition($return->fresh(), ReturnStatusEnum::COLLECTING, $this->claimTrackingFor(ReturnStatusEnum::COLLECTING));
        $return = $service->transition($return->fresh(), ReturnStatusEnum::COLLECTED);
        app(AdminClaimService::class)->complete($return->fresh(), [], new ClaimCompletionOptions(restoreStockByItem: [], createRefundRequest: true));

        return OrderRefund::query()
            ->where('refund_source_id', $return->id)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * 환불신청서를 승인하고 실행합니다.
     *
     * @param  OrderRefund  $refund  환불신청서
     * @return OrderRefund 실행된 환불신청서
     */
    protected function execute(OrderRefund $refund): OrderRefund
    {
        $service = app(OrderRefundRequestService::class);
        $refund = $service->transition($refund->fresh(), RefundStatusEnum::APPROVED);

        return $service->executeRefund($refund->fresh());
    }

    /**
     * 카드로 낸 교환 차액은 반품 환불을 실행할 때 클레임 결제 건의 카드 취소로 돌려준다.
     *
     * @effects exchange_difference_is_refunded_by_the_method_it_was_paid
     *
     * @return void 반환값 없음
     */
    public function test_card_difference_is_refunded_with_the_return(): void
    {
        [$order, $successor, $payment] = $this->exchangedOrder();
        $orderPgCalls = [];
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', function (array $default, $o, $p, $amount) use (&$orderPgCalls) {
            $orderPgCalls[] = $amount;

            return array_merge($default, ['success' => true, 'transaction_id' => 'ORDER-RTXN']);
        });
        $this->fakeClaimPaymentRefund(true, $claimCalls);

        $refund = $this->returnSuccessor($order, $successor);
        $this->assertSame([['claim_payment_id' => $payment->id, 'amount' => 5000.0, 'points_amount' => 0.0]], $this->normalized($refund->exchange_difference_refunds));

        $this->execute($refund);

        $this->assertCount(1, $orderPgCalls);
        $this->assertCount(1, $claimCalls);
        $this->assertEquals(5000, $claimCalls[0]['amount']);
        $this->assertEquals(5000, (float) $payment->fresh()->refunded_amount);
    }

    /**
     * 마일리지로 낸 교환 차액은 마일리지로 돌려준다(claim_payment_id 가 붙은 환불 반환 거래).
     *
     * @effects exchange_difference_is_refunded_by_the_method_it_was_paid
     *
     * @return void 반환값 없음
     */
    public function test_points_difference_is_restored_as_mileage(): void
    {
        app(EcommerceSettingsService::class)->setSetting('mileage.enabled', true);
        [$order, $successor, $payment] = $this->exchangedOrder(method: 'point');
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn (array $default) => array_merge($default, ['success' => true, 'transaction_id' => 'ORDER-RTXN']));

        $refund = $this->returnSuccessor($order, $successor);
        $this->execute($refund);

        $restored = (float) MileageTransaction::query()
            ->where('claim_payment_id', $payment->id)
            ->where('type', MileageTransactionTypeEnum::REFUND_RESTORE->value)
            ->sum('amount');
        $this->assertEquals(5000, $restored);
    }

    /**
     * 교환품 일부만 반품하면 차액도 반품 수량 비율만큼만 돌려준다.
     *
     * @effects exchange_difference_share_follows_returned_quantity
     *
     * @return void 반환값 없음
     */
    public function test_partial_return_refunds_proportional_difference(): void
    {
        [$order, $successor, $payment] = $this->exchangedOrder(quantity: 2, difference: 10000);

        $refund = $this->returnSuccessor($order, $successor, 1);

        $this->assertSame([['claim_payment_id' => $payment->id, 'amount' => 5000.0, 'points_amount' => 0.0]], $this->normalized($refund->exchange_difference_refunds));
    }

    /**
     * 환불 상세 응답의 교환 차액 환급 표기는 합계와 함께 낸 수단(카드·마일리지)을 말한다.
     *
     * 계획서 05 S2-3 「교환 차액 환급 X원(카드/마일리지)」. 합계만 보이면 운영자·고객은 그 돈이 카드 취소로
     * 오는지 마일리지로 오는지 알 수 없다(계획서 검증에서 발견).
     *
     * @effects exchange_difference_refund_is_shown_separately
     *
     * @return void 반환값 없음
     */
    public function test_detail_shows_the_method_the_difference_returns_by(): void
    {
        [$order, $successor] = $this->exchangedOrder();
        $card = (new ClaimResource($this->returnSuccessor($order, $successor)))->toArray(request());
        $this->assertSame('5,000원 (신용카드)', $card['exchange_difference_refunds_formatted']);

        app(EcommerceSettingsService::class)->setSetting('mileage.enabled', true);
        [$order2, $successor2] = $this->exchangedOrder(method: 'point');
        $points = (new ClaimResource($this->returnSuccessor($order2, $successor2)))->toArray(request());
        // 금액 표기는 그 주문의 통화 스냅샷을 따른다 — 같은 응답의 합계 표기에 수단만 붙는지를 잰다.
        $this->assertSame($points['exchange_difference_refund_total_formatted'].' (마일리지)', $points['exchange_difference_refunds_formatted']);
    }

    /**
     * 클레임 결제 건의 카드 취소가 거절되면 환불 전체를 되돌린다(D2).
     *
     * @effects exchange_difference_refund_failure_aborts_the_refund
     *
     * @return void 반환값 없음
     */
    public function test_claim_payment_pg_failure_rolls_back_the_refund(): void
    {
        [$order, $successor, $payment] = $this->exchangedOrder();
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn (array $default) => array_merge($default, ['success' => true, 'transaction_id' => 'ORDER-RTXN']));
        $this->fakeClaimPaymentRefund(false);

        $refund = $this->returnSuccessor($order, $successor);

        try {
            $this->execute($refund);
            $this->fail('클레임 결제 환급이 거절됐는데 환불이 완료됐습니다.');
        } catch (\Throwable) {
            // 기대한 중단
        }

        $this->assertNotSame('completed', $refund->fresh()->refund_status->value);
        $this->assertEquals(0, (float) $payment->fresh()->refunded_amount);
    }

    /**
     * 원 주문 PG 는 승인됐는데 클레임 결제 카드 취소가 실패해 롤백된 환불을 다시 실행하면, 원 주문 PG 는
     * 다시 부르지 않고 교환 차액은 돌려준다.
     *
     * 첫 실행의 롤백이 남긴 PG 기집행 표식으로 재실행이 「장부만 확정」 분기를 탈 때, 교환 차액 환급까지 같은
     * 조건으로 건너뛰어 차액이 영영 돌아가지 않았다(계획서 검증에서 발견).
     *
     * @effects exchange_difference_refund_failure_aborts_the_refund
     *
     * @return void 반환값 없음
     */
    public function test_retry_after_claim_payment_failure_refunds_the_difference_without_recalling_order_pg(): void
    {
        [$order, $successor, $payment] = $this->exchangedOrder();
        $orderPgCalls = [];
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', function (array $default, $o, $p, $amount) use (&$orderPgCalls) {
            $orderPgCalls[] = $amount;

            return array_merge($default, ['success' => true, 'transaction_id' => 'ORDER-RTXN']);
        });
        $claimSucceeds = false;
        $claimCalls = [];
        HookManager::addFilter('sirsoft-ecommerce.claim_payment.refund', function (array $default, $p, float $amount) use (&$claimSucceeds, &$claimCalls) {
            $claimCalls[] = $amount;

            return array_merge($default, [
                'handled' => true,
                'success' => $claimSucceeds,
                'transaction_id' => $claimSucceeds ? 'CLAIM-RTXN' : null,
                'error_code' => $claimSucceeds ? null : 'REFUND_DECLINED',
                'error_message' => $claimSucceeds ? null : 'refund declined',
            ]);
        });

        $refund = $this->returnSuccessor($order, $successor);

        try {
            $this->execute($refund);
            $this->fail('클레임 결제 환급이 거절됐는데 환불이 완료됐습니다.');
        } catch (\Throwable) {
            // 기대한 중단 — 원 주문 PG 는 승인됐으므로 기집행 표식이 남는다
        }
        $this->assertCount(1, $orderPgCalls);
        $this->assertSame('approved', $refund->fresh()->refund_status->value);

        $claimSucceeds = true;
        app(OrderRefundRequestService::class)->executeRefund($refund->fresh());

        $this->assertCount(1, $orderPgCalls, '원 주문 PG 를 다시 불렀습니다(이중 환불).');
        $this->assertSame('completed', $refund->fresh()->refund_status->value);
        $this->assertEquals(5000, (float) $payment->fresh()->refunded_amount, '교환 차액이 돌아오지 않았습니다.');
    }

    /**
     * 저장된 몫을 비교하기 좋게 정리합니다.
     *
     * @param  array|null  $entries  저장된 몫
     * @return array<int, array{claim_payment_id: int, amount: float, points_amount: float}> 정리된 몫
     */
    private function normalized(?array $entries): array
    {
        return array_map(fn (array $e) => [
            'claim_payment_id' => (int) $e['claim_payment_id'],
            'amount' => (float) $e['amount'],
            'points_amount' => (float) $e['points_amount'],
        ], $entries ?? []);
    }
}
