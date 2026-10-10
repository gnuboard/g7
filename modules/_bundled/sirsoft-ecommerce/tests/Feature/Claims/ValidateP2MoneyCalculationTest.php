<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\CancellableAmount;
use Modules\Sirsoft\Ecommerce\DTO\CancellationAdjustment;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\DTO\ReturnAdjustment;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderOptionSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\CancellableAmountCalculator;
use Modules\Sirsoft\Ecommerce\Services\OrderAdjustmentService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Services\RefundExecutionService;
use Modules\Sirsoft\Ecommerce\Support\CalculationSnapshotPresenter;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\ReadsReturnRefundLink;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionMethod;

/**
 * 금액 산정 결함 수정 검증 — 회차 차분·기준선·후속 행 단가·정가 합계·치환 라인 (#599 validate P2-B)
 *
 * 검증 축:
 * - 반품 발 환불신청서의 취소 가능 한도가 완료 경로와 같은 회차 차분이다(#1).
 * - 재계산 기준선은 누적 환불액이 아니라 종결 행의 존재로 다시 구한다(#2).
 * - 교환 후속 행의 회계 단가가 엔진 단가와 같다(#3).
 * - 정가 합계가 교환완료 원 행과 후속 행을 두 번 세지 않는다(#5).
 * - 치환 라인의 쿠폰 상한 분배 합이 원 상한과 같고, 치환은 그 주문 행의 라인을 고른다(minor).
 * - 환율이 없는 결제 통화의 원값 폴백을 경고로 남긴다(minor).
 * - 환불 상품 행의 단가·소계·환불액이 한 단가로 맞는다(minor).
 * - 소수 자릿수 회수비를 정수로 반올림하지 않는다(minor).
 */
class ValidateP2MoneyCalculationTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;
    use ReadsReturnRefundLink;

    /**
     * 테스트 통화·사유를 준비합니다.
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
     * 테스트 통화 설정을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    // ── 헬퍼 ──

    /**
     * 가격별 단일 수량 상품으로 여러 상품 주문을 만듭니다. (무료배송 정책)
     *
     * @param  array<int, int>  $prices  상품별 판매가
     * @return Order 결제완료 주문 (options·shippings·payment 로드)
     */
    private function multiItemOrder(array $prices): Order
    {
        $policy = $this->createClaimShippingPolicy();
        $items = [];

        foreach ($prices as $price) {
            [$product, $option] = $this->createClaimProduct($price, $policy);
            $items[] = new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1);
        }

        $order = $this->createClaimOrder(
            new CalculationInput(items: $items),
            ['currency_snapshot' => $this->krwCurrencySnapshot()],
        );

        return $order->fresh(['options', 'shippings', 'payment', 'payments']);
    }

    /**
     * 반품 1건을 접수해 완료까지 보내고 환불신청서를 만듭니다. (판매자 귀책 — 회수비 0)
     *
     * @param  Order  $order  주문
     * @param  int  $optionId  주문 옵션 ID
     * @param  int  $quantity  반품 수량
     * @return OrderReturn 완료된 신청서
     */
    private function completeReturnOf(Order $order, int $optionId, int $quantity): OrderReturn
    {
        $service = app(OrderReturnService::class);

        $return = $service->requestReturn($order, [
            'items' => [['order_option_id' => $optionId, 'quantity' => $quantity]],
            'reason_code' => 'defective_product',
        ]);

        foreach ([
            ReturnStatusEnum::APPROVED,
            ReturnStatusEnum::COLLECTION_READY,
            ReturnStatusEnum::COLLECTING,
            ReturnStatusEnum::COLLECTED,
        ] as $target) {
            $return = $service->transition($return, $target, $this->claimTrackingFor($target));
        }

        $return->load('returnOptions');
        $ids = $return->returnOptions->pluck('id')->all();

        return $service->completeReturn(
            $return,
            $ids,
            new ClaimCompletionOptions(
                restoreStockByItem: array_fill_keys($ids, false),
                createRefundRequest: true,
            ),
        );
    }

    /**
     * 교환이 끝난 주문을 흉내 냅니다 — 원 행은 교환완료, 후속 행이 회계 주체, 저가 차액 환불신청서 접수.
     *
     * 후속 행은 원 행의 금액·스냅샷을 승계한다(설계). 교환 신청 시점의 상품가는 30,000 원으로
     * 올라 있어 신규 단가(`new_unit_price`)는 20,000 원인데, 신규 옵션 조정액은 -10,000 원이다 —
     * 원 주문의 상품 스냅샷(29,000)에 조정액을 그대로 더하면 19,000 원이 된다.
     *
     * @param  Order  $order  주문 (첫 옵션이 교환 대상)
     * @return OrderOption 후속 행
     */
    private function completeLowerPricedExchangeOfFirstOption(Order $order): OrderOption
    {
        $original = $order->options->first();

        $successor = $original->replicate();
        $successor->forceFill([
            'source_type' => OrderOptionSourceTypeEnum::EXCHANGE,
            'source_option_id' => $original->id,
            'option_status' => OrderStatusEnum::DELIVERED,
        ])->save();

        $original->forceFill(['option_status' => OrderStatusEnum::EXCHANGED])->save();

        $exchange = OrderExchange::factory()->create([
            'order_id' => $order->id,
            'exchange_status' => ExchangeStatusEnum::COMPLETED,
            'refund_product_amount' => 9000,
        ]);

        OrderExchangeOption::factory()->create([
            'order_exchange_id' => $exchange->id,
            'order_id' => $order->id,
            'order_option_id' => $original->id,
            'option_status' => ExchangeStatusEnum::COMPLETED,
            'unit_price' => 29000,
            'new_unit_price' => 20000,
            'new_option_snapshot' => ['price_adjustment' => -10000],
            'price_difference' => -9000,
            'new_order_option_id' => $successor->id,
        ]);

        OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::EXCHANGE,
            'refund_source_id' => $exchange->id,
            'refund_number' => 'RF-P2B-'.uniqid(),
            'refund_status' => RefundStatusEnum::COMPLETED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 9000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
        ]);

        return $successor;
    }

    // ── #1 반품 2회차 D19 상한 ──

    /**
     * 반품 2회차 환불신청서의 취소 가능 한도가 그 회차 몫(완료 경로의 차분)과 같은지 검증합니다.
     *
     * 수정 전에는 누적 재계산 결과를 그대로 한도로 써서, 1회차 7,000 원이 이미 환불된 뒤 2회차
     * 한도가 14,000 원으로 열렸다(과다 환불 허용).
     *
     * @scenario defect=return_round_cap
     *
     * @effects second_return_round_cap_equals_its_own_delta
     *
     * @return void 반환값 없음
     */
    public function test_반품_2회차_환불_한도는_그_회차_몫이다(): void
    {
        $order = $this->deliveredClaimOrder(price: 7000, quantity: 3);
        $optionId = $order->options->first()->id;

        $first = $this->completeReturnOf($order, $optionId, 1);
        $firstRefund = OrderRefund::findOrFail($this->returnRefundId($first));

        // 1회차를 실행 완료로 만든다 — 이미 나간 돈이 있어야 누적 한도의 이중 계상이 드러난다.
        DB::table('ecommerce_orders')->where('id', $order->id)->update([
            'total_refunded_amount' => $firstRefund->refund_amount,
            'total_refunded_points_amount' => $firstRefund->refund_points_amount,
        ]);

        $second = $this->completeReturnOf($order->fresh(['options']), $optionId, 1);
        $secondRefund = OrderRefund::findOrFail($this->returnRefundId($second));
        $filed = (float) $secondRefund->refund_amount + (float) $secondRefund->refund_points_amount;

        $cancellable = app(CancellableAmountCalculator::class)->calculateForRefund(
            $order->fresh(['options', 'shippings', 'payments']),
            $secondRefund,
        );

        $this->assertEqualsWithDelta(7000.0, $filed, 0.01, '2회차 환불신청서가 한 개 몫이 아닙니다 — 측정 전제가 깨졌습니다.');
        $this->assertEqualsWithDelta(
            $filed,
            $cancellable->domainRefundable,
            0.01,
            '반품 2회차의 ① 축이 완료 경로의 차분과 다릅니다 — 앞 회차 환불분이 한도에 이중 계상되었습니다.'
        );
    }

    // ── #2 기준선 판정 ──

    /**
     * 환불액 0 원으로 닫힌 행이 있는 주문의 부분취소가 그 행 값을 다시 돌려주지 않는지 검증합니다.
     *
     * 수정 전에는 누적 환불액이 0 이면 원 결제금액을 기준선으로 삼아, 이미 닫힌 행(10,000원)까지
     * 이번 환불에 더해 20,000 원을 돌려줬다.
     *
     * @scenario defect=baseline_closed_rows
     *
     * @effects partial_cancel_after_zero_refund_closure_refunds_only_its_item
     *
     * @return void 반환값 없음
     */
    public function test_0원_종결_행_뒤_부분취소는_그_상품값만_돌려준다(): void
    {
        $order = $this->multiItemOrder([10000, 10000, 10000]);
        [$closed, $target] = [$order->options[0], $order->options[1]];

        // 환불액 0 원으로 확정된 부분 환불 — 행은 닫혔고 누적 환불액·결제금액은 그대로다.
        $closed->forceFill(['option_status' => OrderStatusEnum::CANCELLED])->save();

        $result = app(OrderAdjustmentService::class)->calculate(
            $order->fresh(['options', 'shippings', 'payment']),
            CancellationAdjustment::fromArray([['order_option_id' => $target->id, 'cancel_quantity' => 1]]),
        );

        $this->assertEqualsWithDelta(10000.0, $result->getTotalRefundAmount(), 0.01);
    }

    // ── #3 후속 행 회계 단가 ──

    /**
     * 상품가가 바뀐 뒤의 저가 교환 후속 행을 취소하면 신규 단가만큼 돌려주는지 검증합니다.
     *
     * 수정 전에는 신규 옵션 조정액(-10,000)을 원 주문 상품 스냅샷(29,000)에 그대로 더해 엔진 단가가
     * 19,000 원이 되었다 — 신규 단가 20,000 원보다 1,000 원 덜 환불된다.
     *
     * @scenario defect=successor_unit_price
     *
     * @effects successor_cancel_refunds_the_new_unit_price
     *
     * @return void 반환값 없음
     */
    public function test_교환_후속_행_취소는_신규_단가를_돌려준다(): void
    {
        $order = $this->multiItemOrder([29000, 10000]);
        $successor = $this->completeLowerPricedExchangeOfFirstOption($order);

        // 교환 차액 환불(9,000)이 실행된 상태.
        DB::table('ecommerce_orders')->where('id', $order->id)->update(['total_refunded_amount' => 9000]);

        $result = app(OrderAdjustmentService::class)->calculate(
            $order->fresh(['options', 'shippings', 'payment']),
            CancellationAdjustment::fromArray([['order_option_id' => $successor->id, 'cancel_quantity' => 1]]),
        );

        $this->assertEqualsWithDelta(20000.0, $result->getTotalRefundAmount(), 0.01, '후속 행 엔진 단가가 신규 단가와 다릅니다.');
        $this->assertEqualsWithDelta(20000.0, (float) $result->adjustedItems[0]['cancel_amount'], 0.01, '취소 장부와 재계산 단가가 갈렸습니다.');
    }

    // ── #5 정가 합계 이중 집계 ──

    /**
     * 교환이 끝난 주문의 정가 합계가 원 행과 후속 행을 한 번만 세는지 검증합니다.
     *
     * 수정 전에는 취소 행만 빼서 교환완료 원 행(29,000)과 후속 행(29,000)이 모두 더해졌다.
     *
     * @scenario defect=list_price_double_count
     *
     * @effects list_price_total_counts_an_exchanged_item_once
     *
     * @return void 반환값 없음
     */
    public function test_교환_완료_주문의_정가_합계는_상품을_한_번만_센다(): void
    {
        $order = $this->multiItemOrder([29000, 10000]);
        $this->completeLowerPricedExchangeOfFirstOption($order);

        $total = app(CalculationSnapshotPresenter::class)->listPriceTotal($order->fresh(['options']));

        $this->assertEqualsWithDelta(39000.0, $total, 0.01);
    }

    // ── minor: 치환 라인 ──

    /**
     * 치환 라인의 정률 쿠폰 상한 분배 합이 원 상한을 넘지 않는지 검증합니다.
     *
     * 수정 전에는 두 라인을 각자 반올림해 상한 1,001 원이 501 + 501 = 1,002 원으로 나뉘었다.
     *
     * @scenario defect=substitution_coupon_cap
     *
     * @effects substitution_coupon_cap_split_sums_to_the_original_cap
     *
     * @return void 반환값 없음
     */
    public function test_치환_라인_쿠폰_상한_분배_합은_원_상한이다(): void
    {
        $order = $this->deliveredClaimOrder(price: 10000, quantity: 2);
        $option = $order->options->first();
        $key = (int) $option->product_option_id;

        $input = new CalculationInput(
            items: [new CalculationItem(
                productId: (int) $option->product_id,
                productOptionId: $key,
                quantity: 2,
                productSnapshot: $option->product_snapshot,
                optionSnapshot: $option->option_snapshot,
            )],
            itemCoupons: [$key => [77]],
            metadata: ['coupon_snapshots' => [77 => [
                'discount_type' => CouponDiscountType::RATE->value,
                'max_discount_amount' => 1001,
                'applied_items' => [],
            ]]],
        );

        $out = (new ReflectionMethod(OrderAdjustmentService::class, 'applySubstitutions'))->invoke(
            app(OrderAdjustmentService::class),
            $input,
            $order,
            [$option->id => ['quantity' => 1, 'unit_price' => 10000, 'option_snapshot' => $option->option_snapshot]],
        );

        $byLine = $out->metadata['coupon_snapshots'][77]['max_discount_amount_by_line'] ?? [];

        $this->assertCount(2, $byLine, '라인이 갈렸는데 상한 분배가 없습니다 — 측정 전제가 깨졌습니다.');
        $this->assertSame(1001, array_sum($byLine));
    }

    /**
     * 같은 상품옵션을 가진 행이 여럿일 때 치환이 그 주문 행의 라인을 줄이는지 검증합니다.
     *
     * 수정 전에는 상품옵션 키로 첫 라인을 잡아, 다른 주문 행(수량 2)이 1 로 줄고 대상 행은 남았다.
     *
     * @scenario defect=substitution_line_match
     *
     * @effects substitution_targets_the_line_of_its_own_order_option
     *
     * @return void 반환값 없음
     */
    public function test_치환은_그_주문_행의_라인을_줄인다(): void
    {
        $order = $this->deliveredClaimOrder(price: 10000, quantity: 2);
        $first = $order->options->first();
        $key = (int) $first->product_option_id;

        // 수량 분할로 생긴 형제 행 — 같은 상품옵션, 수량 1.
        $sibling = $first->replicate();
        $sibling->forceFill(['quantity' => 1, 'source_type' => OrderOptionSourceTypeEnum::SPLIT, 'parent_option_id' => $first->id])->save();
        $order = $order->fresh(['options', 'shippings', 'payment']);

        $service = app(OrderAdjustmentService::class);
        $base = (new ReflectionMethod(OrderAdjustmentService::class, 'buildRecalcInput'))->invoke($service, $order, [], []);
        $out = (new ReflectionMethod(OrderAdjustmentService::class, 'applySubstitutions'))->invoke(
            $service,
            $base,
            $order,
            [$sibling->id => ['quantity' => 1, 'unit_price' => 10000, 'option_snapshot' => $sibling->option_snapshot]],
        );

        $originalLines = array_values(array_filter(
            $out->items,
            static fn (CalculationItem $item): bool => $item->productOptionId === $key,
        ));

        $this->assertCount(1, $originalLines, '치환 대상 행의 라인이 남고 다른 행의 라인이 줄었습니다.');
        $this->assertSame(2, $originalLines[0]->quantity);
    }

    // ── minor: 환율 없음 폴백 ──

    /**
     * 스냅샷에 결제 통화 환율이 없을 때 원값 폴백을 경고로 남기는지 검증합니다.
     *
     * 수정 전에는 환산 없이 원값(USD 50)을 50 원 한도로 쓰면서 아무 흔적도 남기지 않았다. 원값을 쓰는
     * 보수적 폴백은 기존 계약대로 유지하고, 운영자가 원인을 찾을 수 있게 경고를 남긴다.
     *
     * @scenario defect=missing_rate_fallback
     *
     * @effects missing_rate_fallback_is_logged
     *
     * @return void 반환값 없음
     */
    public function test_환율이_없으면_원값_폴백을_경고로_남긴다(): void
    {
        $order = $this->foreignPaidClaimOrder(rate: 0.001, paidLocal: 50);
        $snapshot = $order->currency_snapshot;
        unset($snapshot['exchange_rates']['USD']);
        $order->forceFill(['currency_snapshot' => $snapshot])->save();

        Log::spy();

        $result = app(CancellableAmountCalculator::class)->calculate(
            $order->fresh(['options', 'shippings', 'payments']),
            [['order_option_id' => $order->options->first()->id, 'cancel_quantity' => 1]],
            ClaimTypeEnum::RETURN,
        );

        $method = collect($result->methods)->firstWhere('type', CancellableAmount::METHOD_PAYMENT);

        $this->assertNotNull($method);
        $this->assertEqualsWithDelta(50.0, (float) $method['remaining_base'], 0.01);
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $context = []): bool => str_contains($message, '[claim.cancellable]')
                && ($context['currency'] ?? null) === 'USD'
        )->once();
    }

    // ── minor: 환불 상품 행 단가 ──

    /**
     * 환불 상품 행이 재계산 회계 단가 하나로 단가·소계·환불액을 적는지 검증합니다.
     *
     * 수정 전에는 소계는 저장 단가(12,000)로, 환불액은 재계산이 본 전체 수량의 취소액(20,000)으로
     * 따로 적혀 수량 1 행의 환불액이 20,000 원이 되었다.
     *
     * @scenario defect=refund_row_unit_price
     *
     * @effects refund_option_row_uses_one_unit_price
     *
     * @return void 반환값 없음
     */
    public function test_환불_상품_행은_한_단가로_적힌다(): void
    {
        $order = $this->deliveredClaimOrder(price: 12000, quantity: 2);
        $option = $order->options->first();

        $rows = app(RefundExecutionService::class)->buildRefundOptionRows(
            $order,
            [['order_option_id' => $option->id, 'cancel_quantity' => 1]],
            new AdjustmentResult(adjustedItems: [[
                'order_option_id' => $option->id,
                'cancel_quantity' => 2,
                'cancel_amount' => 20000,
            ]]),
        );

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(10000.0, (float) $rows[0]['unit_price'], 0.01);
        $this->assertEqualsWithDelta(10000.0, (float) $rows[0]['subtotal_amount'], 0.01);
        $this->assertEqualsWithDelta(10000.0, (float) $rows[0]['refund_amount'], 0.01);
    }

    // ── 적립 마일리지 표기 ──

    /**
     * 신청 미리보기 비교 스냅샷이 적립 마일리지를 포인트 표기로 싣는지 검증합니다.
     *
     * 수정 전에는 스냅샷의 `formatted` 맵(기준 통화 표기)뿐이라 화면이 적립 줄을 「710원」으로 그렸다.
     *
     * @scenario defect=earned_points_label
     *
     * @effects comparison_snapshot_carries_the_earned_points_label
     *
     * @return void 반환값 없음
     */
    public function test_비교_스냅샷은_적립_마일리지를_포인트로_표기한다(): void
    {
        $order = $this->multiItemOrder([10000, 10000]);
        DB::table('ecommerce_orders')->where('id', $order->id)->update(['total_earned_points_amount' => 1710]);

        $result = app(OrderAdjustmentService::class)->calculate(
            $order->fresh(['options', 'shippings', 'payment']),
            CancellationAdjustment::fromArray([['order_option_id' => $order->options[0]->id, 'cancel_quantity' => 1]]),
        );

        $this->assertSame('1,710P', $result->originalSnapshot['total_earned_points_amount_formatted'] ?? null);
        $this->assertStringEndsWith('P', (string) ($result->recalculatedSnapshot['total_earned_points_amount_formatted'] ?? ''));
    }

    // ── minor: 소수 회수비 ──

    /**
     * 소수 자릿수 회수비가 정수로 반올림되지 않고 차감되는지 검증합니다.
     *
     * 수정 전에는 산정기와 변경 DTO 가 정수만 받아 3.5 가 4(또는 3)로 바뀌었다.
     *
     * @scenario defect=fractional_collection_fee
     *
     * @effects fractional_collection_fee_is_deducted_as_is
     *
     * @return void 반환값 없음
     */
    public function test_소수_회수비는_그대로_차감된다(): void
    {
        $return = new OrderReturn;
        $return->forceFill([
            'claim_shipping_fee' => 3.5,
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);

        $fee = (new ReflectionMethod(CancellableAmountCalculator::class, 'deductedCollectionFeeOf'))
            ->invoke(app(CancellableAmountCalculator::class), $return);

        $this->assertEqualsWithDelta(3.5, (float) $fee, 0.0001);
        $this->assertEqualsWithDelta(
            3.5,
            (float) (new ReturnAdjustment(collectionFee: 3.5, chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND))->getAdditionalCharges(),
            0.0001,
        );
    }
}
