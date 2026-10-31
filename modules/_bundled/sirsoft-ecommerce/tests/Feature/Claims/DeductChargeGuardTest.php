<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 「환불금에서 차감」 은 환불액이 청구액을 덮을 때만 고를 수 있다 — 금액 수정·완료에서도 (S4)
 *
 * 신청 경로는 차감 가능 여부를 비교했지만, 관리자 금액 수정은 허용 목록만 보았다. 환불액 2,000원인 반품의 청구
 * 방식을 차감으로 바꾸고 회수비 6,000원으로 완료하면 환불은 0원이 되고 부족분 4,000원은 어디에도 청구되지 않았다.
 *
 * 시나리오 매니페스트: `tests/scenarios/claim-deduct-charge-guard.yaml`
 */
class DeductChargeGuardTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    private AdminClaimService $admin;

    private OrderReturnService $returns;

    /**
     * 통화·사유를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->admin = app(AdminClaimService::class);
        $this->returns = app(OrderReturnService::class);
    }

    /**
     * 테스트 통화 설정을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 반품을 접수합니다.
     *
     * @param  int  $price  판매가
     * @param  int  $quantity  수량
     * @param  int  $returnFee  회수비
     * @param  ClaimChargeMethodEnum|null  $method  청구 방식
     * @return OrderReturn 반품 신청서
     */
    private function returnOf(int $price, int $quantity, int $returnFee, ?ClaimChargeMethodEnum $method): OrderReturn
    {
        $order = $this->deliveredClaimOrder(price: $price, quantity: $quantity, returnFee: $returnFee);
        $payload = [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => $quantity]],
            'reason_code' => $returnFee > 0 ? 'changed_mind' : 'defective_product',
            'requested_by' => $order->user_id,
        ];
        if ($method !== null) {
            $payload['charge_method'] = $method;
        }

        return $this->returns->requestReturn($order, $payload);
    }

    /**
     * 금액 수정으로 차감을 지정할 때 환불액이 청구액보다 적으면 422 로 거절한다.
     *
     * @scenario claim_type=return, stage=amount_update
     *
     * @effects deduct_charge_rejected_when_refund_is_smaller
     *
     * @return void 반환값 없음
     */
    public function test_amount_update_to_deduct_beyond_refund_is_rejected(): void
    {
        $return = $this->returnOf(price: 2000, quantity: 1, returnFee: 0, method: null);

        try {
            $this->admin->updateAmounts($return, [
                'claim_shipping_fee' => 6000,
                'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND->value,
            ]);
            $this->fail('환불액보다 큰 차감 청구가 저장됐습니다.');
        } catch (OrderClaimException $e) {
            $this->assertSame(__('sirsoft-ecommerce::exceptions.charge_deduct_insufficient'), $e->getMessage());
        }

        $this->assertEquals(0, (float) $return->fresh()->claim_shipping_fee);
    }

    /**
     * 상품 반려로 완료 시점의 환불액이 차감 청구액보다 작아지면 완료를 422 로 막는다.
     *
     * @scenario claim_type=return, stage=completion
     *
     * @effects deduct_charge_rejected_when_refund_is_smaller
     *
     * @return void 반환값 없음
     */
    public function test_completion_with_refund_below_deduct_charge_is_rejected(): void
    {
        // 두 상품(각 4,000원) 주문 — 회수비 6,000원을 차감해도 접수 시 환불액 8,000원이 덮는다
        $policy = $this->createClaimShippingPolicy();
        $policy->countrySettings()->where('country_code', 'KR')->update(['claim_fee_mode' => 'fixed', 'return_fixed_fee' => 6000, 'exchange_fixed_fee' => 6000]);
        [$pA, $oA] = $this->createClaimProduct(4000, $policy);
        [$pB, $oB] = $this->createClaimProduct(4000, $policy);
        $order = $this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
        ]), ['currency_snapshot' => $this->krwCurrencySnapshot()]);
        $order = $this->moveOrderTo($this->attachPolicyShippings($order, $policy), OrderStatusEnum::DELIVERED);

        $return = $this->returns->requestReturn($order, [
            'items' => $order->options->map(fn ($o) => ['order_option_id' => $o->id, 'quantity' => 1])->values()->all(),
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'requested_by' => $order->user_id,
        ]);
        $this->assertEquals(6000, (float) $return->claim_shipping_fee);
        $return = $this->returns->transition($return->fresh(), ReturnStatusEnum::APPROVED);
        $return = $this->returns->transition($return->fresh(), ReturnStatusEnum::COLLECTION_READY);
        $return = $this->returns->transition($return->fresh(), ReturnStatusEnum::COLLECTING, $this->claimTrackingFor(ReturnStatusEnum::COLLECTING));
        $return = $this->returns->transition($return->fresh(), ReturnStatusEnum::COLLECTED);

        // 상품 하나를 반려 — 완료 대상이 4,000원으로 줄어 회수비 6,000원을 덮지 못한다
        $return->returnOptions->first()->forceFill(['option_status' => ReturnStatusEnum::REJECTED])->save();

        try {
            $this->admin->complete($return->fresh(), [], new ClaimCompletionOptions(restoreStockByItem: [], createRefundRequest: true));
            $this->fail('환불액보다 큰 차감 청구로 완료됐습니다.');
        } catch (OrderClaimException $e) {
            $this->assertSame(__('sirsoft-ecommerce::exceptions.charge_deduct_insufficient'), $e->getMessage());
        }

        $this->assertNotSame(ReturnStatusEnum::COMPLETED, $return->fresh()->return_status);
    }

    /**
     * 교환 금액 수정으로 왕복 배송비를 교환 차액 환불보다 크게 올리면 차감할 수 없어 422 로 거절한다.
     *
     * @scenario claim_type=exchange, stage=amount_update
     *
     * @effects deduct_charge_rejected_when_refund_is_smaller
     *
     * @return void 반환값 없음
     */
    public function test_exchange_amount_update_beyond_difference_refund_is_rejected(): void
    {
        [, $exchange] = $this->redeliveredLowerPricedExchange(-10000);

        try {
            $this->admin->updateAmounts($exchange, ['claim_shipping_fee' => 12000]);
            $this->fail('교환 차액 환불보다 큰 차감 청구가 저장됐습니다.');
        } catch (OrderClaimException $e) {
            $this->assertSame(__('sirsoft-ecommerce::exceptions.charge_deduct_insufficient'), $e->getMessage());
        }

        $this->assertNotEquals(12000, (float) $exchange->fresh()->claim_shipping_fee);
    }

    /**
     * 교환도 완료 시점의 차액 환불이 차감 청구액보다 작으면 완료를 422 로 막는다(05 S4-2 — 완료 처리에서도 같은 422).
     *
     * 상품 일부 반려 뒤 재계산 등으로 청구가 환불을 넘는 상태를 직접 만든다 — 금액 수정 경로는 이미 막으므로
     * 완료 시점의 판정만 잰다. 반품은 완료 때 막는데 교환은 그대로 통과했다(계획서 검증에서 발견).
     *
     * @scenario claim_type=exchange, stage=completion
     *
     * @effects deduct_charge_rejected_when_refund_is_smaller
     *
     * @return void 반환값 없음
     */
    public function test_exchange_completion_with_difference_refund_below_deduct_charge_is_rejected(): void
    {
        [, $exchange] = $this->redeliveredLowerPricedExchange(-10000);
        $exchange->forceFill([
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'claim_shipping_fee' => 12000,
            'total_charge_amount' => 12000,
        ])->save();

        try {
            // 환불신청서를 접수하지 않는 완료도 막아야 한다 — 접수를 끄면 차액이 청구에 묻혀 부족분이 어디에도 청구되지 않는다.
            $this->admin->complete($exchange->fresh(), [], new ClaimCompletionOptions(restoreStockByItem: [], createRefundRequest: false));
            $this->fail('교환 차액 환불보다 큰 차감 청구로 완료됐습니다.');
        } catch (OrderClaimException $e) {
            $this->assertSame(__('sirsoft-ecommerce::exceptions.charge_deduct_insufficient'), $e->getMessage());
        }

        $this->assertNotSame(ExchangeStatusEnum::COMPLETED, $exchange->fresh()->exchange_status);
    }
}
