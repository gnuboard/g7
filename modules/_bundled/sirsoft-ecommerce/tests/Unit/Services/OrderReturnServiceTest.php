<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Mockery;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyClaimAddress;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\AssertsClaimExceptions;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimAdjustmentOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 반품 클레임 서비스 테스트 (S3)
 *
 * 검증 축:
 * - 신청 — 사유·귀책 스냅샷, 코스 상태 진입(전량/부분 분할), 재고 불변, 병합 금지,
 *   수량 잠금(D15), 청구 방식 규칙, 회수지 스냅샷, 신청 기간
 * - 전이 — ReturnStatusEnum 게이트 전 케이스, 시각 기록, 상품 상태 동기화, 훅 발화
 * - 복귀 — 반려·철회 시 상품옵션 단위로 신청 직전 상태 복원(확정 (a)), 재고 이중 차감 없음
 * - 완료 — D26: 건별 재고 복구 선택, 환불신청서 접수 여부, 설정이 동작을 바꾸지 않음
 */
class OrderReturnServiceTest extends ModuleTestCase
{
    use AssertsClaimExceptions;
    use BuildsClaimAdjustmentOrders;

    protected OrderReturnService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->service = app(OrderReturnService::class);
        $this->seedReturnReasons();
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    // ================================================================
    // 헬퍼
    // ================================================================

    /**
     * 반품 사유 카탈로그를 시드합니다.
     *
     * 코어 TestingSeeder 는 취소(refund) 사유만 시드하므로 반품 축은 테스트가 만든다.
     */
    protected function seedReturnReasons(): void
    {
        foreach ([
            ['changed_mind', ClaimReasonFaultTypeEnum::CUSTOMER, true],
            ['defective_product', ClaimReasonFaultTypeEnum::SELLER, true],
            ['retired_reason', ClaimReasonFaultTypeEnum::CUSTOMER, false],
        ] as [$code, $fault, $active]) {
            ClaimReason::firstOrCreate(
                ['type' => 'return', 'code' => $code],
                [
                    'name' => ['ko' => $code, 'en' => $code],
                    'fault_type' => $fault,
                    'is_user_selectable' => true,
                    'is_active' => $active,
                    'sort_order' => 0,
                ]
            );
        }
    }

    /**
     * 주문 상품을 배송완료 상태로 만듭니다.
     *
     * @param  Order  $order  주문
     * @return Order 갱신된 주문
     */
    protected function deliver(Order $order): Order
    {
        foreach ($order->options as $option) {
            $option->forceFill([
                'option_status' => OrderStatusEnum::DELIVERED,
                'delivered_at' => now()->subDay(),
                'is_stock_deducted' => true,
            ])->save();
        }

        $order->forceFill(['order_status' => OrderStatusEnum::DELIVERED])->save();

        return $order->fresh(['options', 'shippings', 'payment']);
    }

    /**
     * 배송완료된 단일 상품 주문을 만듭니다.
     *
     * @param  int  $price  판매가
     * @param  int  $quantity  수량
     * @param  ShippingPolicy|null  $policy  배송정책
     * @return Order 주문
     */
    protected function deliveredOrder(int $price = 29000, int $quantity = 1, ?ShippingPolicy $policy = null): Order
    {
        $policy ??= $this->createClaimShippingPolicy();

        return $this->deliver(
            $this->attachPolicyShippings($this->createSingleItemClaimOrder($price, $quantity, $policy), $policy)
        );
    }

    /**
     * 주문 옵션마다 배송정책이 붙은 배송 행을 만듭니다.
     *
     * 클레임 배송비 산정은 `order_shippings.shipping_policy_id` 로 정책을 찾는다 —
     * 옵션에 배송 행이 붙어 있지 않으면 정책을 해석하지 못해 언제나 0원이 된다.
     *
     * @param  Order  $order  주문
     * @param  ShippingPolicy  $policy  배송정책
     * @return Order 갱신된 주문
     */
    protected function attachPolicyShippings(Order $order, ShippingPolicy $policy): Order
    {
        $order->shippings()->delete();

        foreach ($order->options as $option) {
            OrderShipping::factory()->create([
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'shipping_policy_id' => $policy->id,
                'delivery_policy_snapshot' => ['country_code' => 'KR'],
                'base_shipping_amount' => 0,
                'extra_shipping_amount' => 0,
                'total_shipping_amount' => 0,
                'shipping_discount_amount' => 0,
            ]);
        }

        return $order->fresh(['options', 'shippings', 'payment']);
    }

    /**
     * 반품 고정 배송비를 설정합니다.
     *
     * @param  ShippingPolicy  $policy  배송정책
     * @param  float  $fee  편도 회수비
     */
    protected function setReturnFixedFee(ShippingPolicy $policy, float $fee): void
    {
        $policy->countrySettings()->where('country_code', 'KR')->update([
            'claim_fee_mode' => 'fixed',
            'return_fixed_fee' => $fee,
        ]);
    }

    /**
     * 신청 페이로드를 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  string  $reasonCode  사유 코드
     * @param  int|null  $quantity  신청 수량 (null 이면 전량)
     * @param  ClaimChargeMethodEnum|null  $chargeMethod  청구 방식
     * @return array 신청 페이로드
     */
    protected function payload(
        Order $order,
        string $reasonCode = 'changed_mind',
        ?int $quantity = null,
        ?ClaimChargeMethodEnum $chargeMethod = null,
    ): array {
        $items = [];

        foreach ($order->options as $option) {
            $items[] = [
                'order_option_id' => $option->id,
                'quantity' => $quantity ?? (int) $option->quantity,
            ];
        }

        return [
            'items' => $items,
            'reason_code' => $reasonCode,
            'charge_method' => $chargeMethod,
            'requested_by' => $order->user_id,
        ];
    }

    /**
     * 반품을 목표 상태까지 진행시킵니다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  ReturnStatusEnum  $target  목표 상태
     * @return OrderReturn 진행된 신청서
     */
    protected function advanceTo(OrderReturn $return, ReturnStatusEnum $target): OrderReturn
    {
        $path = [
            ReturnStatusEnum::APPROVED,
            ReturnStatusEnum::COLLECTION_READY,
            ReturnStatusEnum::COLLECTING,
            ReturnStatusEnum::COLLECTED,
        ];

        foreach ($path as $step) {
            $return = $this->service->transition($return, $step);

            if ($step === $target) {
                break;
            }
        }

        return $return;
    }

    /**
     * 완료 처리 옵션을 만듭니다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  bool  $restoreStock  재고 복구 여부 (전건 동일 적용)
     * @param  bool  $createRefundRequest  환불신청서 접수 여부
     * @return ClaimCompletionOptions 완료 옵션
     */
    protected function completionOptions(
        OrderReturn $return,
        bool $restoreStock,
        bool $createRefundRequest,
    ): ClaimCompletionOptions {
        $map = [];

        foreach ($return->returnOptions as $item) {
            $map[$item->id] = $restoreStock;
        }

        return new ClaimCompletionOptions($map, $createRefundRequest);
    }

    /**
     * 배송완료된 2상품 주문으로 반품 신청서를 만듭니다.
     *
     * 상품 단위 전이·복귀 축은 한 신청서 안에서 상품마다 결과가 갈리는지를 봐야 하므로
     * 단일 상품 주문으로는 잴 수 없다. 신청 상품 행은 원 상품옵션으로 되짚어 가른다 —
     * 행 순서에 기대면 정렬이 바뀌는 순간 두 단언이 서로 자리를 바꿔 통과해 버린다.
     *
     * @return array{0: OrderReturn, 1: int, 2: int} [신청서, 첫 상품 행 ID, 둘째 상품 행 ID]
     */
    protected function twoItemReturn(): array
    {
        $policy = $this->createClaimShippingPolicy();
        [$productA, $optionA] = $this->createClaimProduct(10000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(20000, $policy);

        $order = $this->deliver($this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem($productA->id, $optionA->id, 1),
            new CalculationItem($productB->id, $optionB->id, 1),
        ])));

        $return = $this->service->requestReturn($order, $this->payload($order));

        $itemIdFor = function (int $productOptionId) use ($order, $return): int {
            $orderOptionIds = $order->options
                ->where('product_option_id', $productOptionId)
                ->pluck('id')
                ->all();

            return (int) $return->returnOptions
                ->firstWhere(fn ($item) => in_array((int) $item->order_option_id, $orderOptionIds, true))
                ->id;
        };

        return [$return, $itemIdFor((int) $optionA->id), $itemIdFor((int) $optionB->id)];
    }

    // ================================================================
    // 신청
    // ================================================================

    public function test_request_creates_return_with_number_and_requested_status(): void
    {
        $order = $this->deliveredOrder();

        $return = $this->service->requestReturn($order, $this->payload($order));

        $this->assertStringStartsWith('RT', $return->return_number);
        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->return_status);
        $this->assertSame($order->id, $return->order_id);
        $this->assertCount(1, $return->returnOptions);
    }

    public function test_request_snapshots_reason_and_fault_type(): void
    {
        $order = $this->deliveredOrder();

        $return = $this->service->requestReturn($order, $this->payload($order, 'defective_product'));

        $this->assertSame('defective_product', $return->reason_code);
        $this->assertSame(ClaimReasonFaultTypeEnum::SELLER, $return->fault_type);
    }

    public function test_request_moves_order_option_into_return_in_progress(): void
    {
        $order = $this->deliveredOrder();

        $this->service->requestReturn($order, $this->payload($order));

        $this->assertSame(
            OrderStatusEnum::RETURN_IN_PROGRESS,
            $order->options->first()->fresh()->option_status
        );
    }

    /**
     * @effects return_request_records_the_original_option_status_for_revert
     */
    public function test_request_records_original_option_status_for_revert(): void
    {
        $order = $this->deliveredOrder();

        $return = $this->service->requestReturn($order, $this->payload($order));

        $this->assertSame(
            OrderStatusEnum::DELIVERED,
            $return->returnOptions->first()->original_option_status
        );
    }

    /**
     * @effects return_request_splits_the_order_option_for_a_partial_quantity
     */
    public function test_request_splits_order_option_for_partial_quantity(): void
    {
        $order = $this->deliveredOrder(29000, 3);

        $return = $this->service->requestReturn($order, $this->payload($order, quantity: 1));

        $order = $order->fresh(['options']);

        $remaining = $order->options->firstWhere('option_status', OrderStatusEnum::DELIVERED);
        $inProgress = $order->options->firstWhere('option_status', OrderStatusEnum::RETURN_IN_PROGRESS);

        $this->assertNotNull($remaining);
        $this->assertNotNull($inProgress);
        $this->assertSame(2, (int) $remaining->quantity);
        $this->assertSame(1, (int) $inProgress->quantity);

        // 신청 상품 행은 코스 상태를 실제로 가진 행을 가리켜야 한다 —
        // 잔여 행을 가리키면 이후 완료·복귀가 엉뚱한 수량에 적용된다.
        $this->assertSame($inProgress->id, $return->returnOptions->first()->order_option_id);
    }

    /**
     * @effects return_request_keeps_the_original_quantity_for_audit
     */
    public function test_request_keeps_original_quantity_for_audit(): void
    {
        $order = $this->deliveredOrder(29000, 3);

        $return = $this->service->requestReturn($order, $this->payload($order, quantity: 1));

        $this->assertSame(3, (int) $return->returnOptions->first()->original_quantity);
        $this->assertSame(1, (int) $return->returnOptions->first()->return_quantity);
    }

    public function test_request_does_not_restore_stock_on_acceptance(): void
    {
        $order = $this->deliveredOrder();
        $productOptionId = $order->options->first()->product_option_id;
        $before = (int) ProductOption::find($productOptionId)->stock_quantity;

        $this->service->requestReturn($order, $this->payload($order));

        $this->assertSame($before, (int) ProductOption::find($productOptionId)->stock_quantity);
        $this->assertTrue((bool) $order->options->first()->fresh()->is_stock_deducted);
    }

    public function test_request_does_not_merge_course_rows_of_two_returns(): void
    {
        $order = $this->deliveredOrder(29000, 3);

        $first = $this->service->requestReturn($order, $this->payload($order, quantity: 1));
        $second = $this->service->requestReturn($order->fresh(['options', 'shippings', 'payment']), [
            'items' => [[
                'order_option_id' => $order->fresh(['options'])->options
                    ->firstWhere('option_status', OrderStatusEnum::DELIVERED)->id,
                'quantity' => 1,
            ]],
            'reason_code' => 'changed_mind',
            'charge_method' => null,
            'requested_by' => $order->user_id,
        ]);

        // 병합이 일어나면 피흡수 행이 삭제되고 그 행을 참조하는 신청 상품 행이
        // cascade 로 함께 사라진다 — 두 신청서가 각각 자기 행을 보유해야 한다.
        $this->assertNotNull($first->returnOptions->first()->fresh());
        $this->assertNotNull($second->returnOptions->first()->fresh());
        $this->assertNotSame(
            $first->returnOptions->first()->order_option_id,
            $second->returnOptions->first()->order_option_id
        );
        $this->assertSame(
            2,
            $order->fresh(['options'])->options
                ->where('option_status', OrderStatusEnum::RETURN_IN_PROGRESS)->count()
        );
    }

    public function test_request_rejects_option_not_in_requestable_status(): void
    {
        $order = $this->createSingleItemClaimOrder(); // payment_complete 상태

        $this->assertClaimException('claim_option_not_claimable', fn () => $this->service->requestReturn($order, $this->payload($order)));
    }

    public function test_request_rejects_quantity_over_order_quantity(): void
    {
        $order = $this->deliveredOrder(29000, 2);

        $this->assertClaimException('claim_quantity_invalid', fn () => $this->service->requestReturn($order, $this->payload($order, quantity: 3)));
    }

    public function test_request_rejects_zero_quantity(): void
    {
        $order = $this->deliveredOrder();

        $this->assertClaimException('claim_quantity_invalid', fn () => $this->service->requestReturn($order, $this->payload($order, quantity: 0)));
    }

    public function test_request_rejects_empty_items(): void
    {
        $order = $this->deliveredOrder();

        $this->assertClaimException('claim_items_required', fn () => $this->service->requestReturn($order, [
            'items' => [],
            'reason_code' => 'changed_mind',
        ]));
    }

    public function test_request_rejects_duplicate_option_lines(): void
    {
        $order = $this->deliveredOrder(29000, 2);
        $optionId = $order->options->first()->id;

        $this->assertClaimException('claim_quantity_invalid', fn () => $this->service->requestReturn($order, [
            'items' => [
                ['order_option_id' => $optionId, 'quantity' => 1],
                ['order_option_id' => $optionId, 'quantity' => 1],
            ],
            'reason_code' => 'changed_mind',
        ]));
    }

    public function test_request_rejects_unknown_option(): void
    {
        $order = $this->deliveredOrder();

        $this->assertClaimException('claim_option_not_found', fn () => $this->service->requestReturn($order, [
            'items' => [['order_option_id' => 999999, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
        ]));
    }

    public function test_request_rejects_inactive_reason(): void
    {
        $order = $this->deliveredOrder();

        $this->assertClaimException('claim_reason_unavailable', fn () => $this->service->requestReturn($order, $this->payload($order, 'retired_reason')));
    }

    public function test_request_rejects_unknown_reason(): void
    {
        $order = $this->deliveredOrder();

        $this->assertClaimException('claim_reason_unavailable', fn () => $this->service->requestReturn($order, $this->payload($order, 'no_such_reason')));
    }

    /**
     * 신청 경로에서 잔여 수량을 넘기면 **수량 검증**이 먼저 잡는다.
     *
     * 이름을 `..._locked_by_active_claim` 으로 두면 사실과 다르다 — 신청 접수가 코스 상태로
     * 행을 분할하므로 남은 행의 `quantity` 자체가 이미 줄어 있고, 그래서
     * `ClaimQuantityGuard` 의 잠금 분기(`claim_quantity_locked`)는 이 경로에서 실행되지
     * 않는다. 잠금 분기는 분할이 없는 **취소 경로**와 가드 단위 테스트가 덮는다
     * (`ClaimQuantityGuardTest::test_resolve_rejects_a_locked_quantity`).
     */
    public function test_request_rejects_quantity_over_the_remaining_row_after_a_split(): void
    {
        $order = $this->deliveredOrder(29000, 2);

        $this->service->requestReturn($order, $this->payload($order, quantity: 1));

        // 잔여 1개짜리 행에 2개를 신청하면 수량 검증에서 걸린다.
        $remaining = $order->fresh(['options'])->options
            ->firstWhere('option_status', OrderStatusEnum::DELIVERED);

        $this->assertClaimException('claim_quantity_invalid', fn () => $this->service->requestReturn($order->fresh(['options', 'shippings', 'payment']), [
            'items' => [['order_option_id' => $remaining->id, 'quantity' => 2]],
            'reason_code' => 'changed_mind',
        ]));
    }

    /**
     * @effects return_pay_on_approval_does_not_ask_for_payment_at_request_time
     */
    public function test_request_accepts_pay_on_approval_charge_method(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setReturnFixedFee($policy, 3000);

        $return = $this->service->requestReturn(
            $order,
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_APPROVAL)
        );

        $this->assertSame(ClaimChargeMethodEnum::PAY_ON_APPROVAL, $return->charge_method);
        $this->assertSame(3000, (int) round((float) $return->total_charge_amount));

        // 승인 후 결제는 접수 시점에 결제를 요구하지 않는다 — 상태는 신청 그대로다.
        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->return_status);
    }

    /**
     * @effects return_multi_currency_amounts_come_from_the_order_snapshot
     */
    public function test_request_records_multi_currency_amounts_from_the_order_snapshot(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setReturnFixedFee($policy, 3000);

        $order->forceFill(['currency_snapshot' => [
            'base_currency' => 'KRW',
            'order_currency' => 'KRW',
            'exchange_rate' => 1.0,
            'base_unit' => 1000,
            'exchange_rates' => [
                'KRW' => ['rate' => 1.0, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'base_unit' => 1000],
                'USD' => ['rate' => 0.75, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2, 'base_unit' => 1000],
            ],
            'snapshot_at' => '2026-08-21T00:00:00+09:00',
        ]])->save();

        $return = $this->service->requestReturn(
            $order->fresh(['options', 'shippings', 'payment']),
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST)
        );

        // 통화 단위는 설정이 정한다 — 기준 통화는 주문 스냅샷에서 동결한다.
        $this->assertSame('KRW', $return->currency_code);
        $this->assertSame(['KRW', 'USD'], array_keys($return->mc_claim_shipping_fee));
        $this->assertSame(3000, $return->mc_claim_shipping_fee['KRW']);
        $this->assertSame(2.25, $return->mc_claim_shipping_fee['USD']);
        $this->assertSame($return->mc_claim_shipping_fee, $return->mc_total_charge_amount);
    }

    /**
     * @effects return_shipping_fee_and_charge_amount_are_frozen
     */
    public function test_request_freezes_shipping_fee_and_charge_amount(): void
    {
        $policy = $this->createClaimShippingPolicy(ChargePolicyEnum::FREE, 0);
        $this->setReturnFixedFee($policy, 3000);
        $order = $this->deliveredOrder(29000, 1, $policy);

        $return = $this->service->requestReturn(
            $order,
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST)
        );

        $this->assertSame(3000.0, (float) $return->claim_shipping_fee);
        $this->assertSame(3000.0, (float) $return->total_charge_amount);
        $this->assertNotEmpty($return->shipping_fee_snapshot);
        $this->assertTrue($return->requiresAdditionalPayment());
    }

    /**
     * @effects return_seller_fault_charges_nothing
     */
    public function test_request_charges_nothing_for_seller_fault(): void
    {
        $policy = $this->createClaimShippingPolicy(ChargePolicyEnum::FREE, 0);
        $this->setReturnFixedFee($policy, 3000);
        $order = $this->deliveredOrder(29000, 1, $policy);

        $return = $this->service->requestReturn($order, $this->payload($order, 'defective_product'));

        $this->assertSame(0.0, (float) $return->claim_shipping_fee);
        $this->assertNull($return->charge_method);
    }

    /**
     * @effects return_charge_method_is_required_when_a_fee_remains
     */
    public function test_request_requires_charge_method_when_fee_is_positive(): void
    {
        $policy = $this->createClaimShippingPolicy(ChargePolicyEnum::FREE, 0);
        $this->setReturnFixedFee($policy, 3000);
        $order = $this->deliveredOrder(29000, 1, $policy);

        $this->assertClaimException('claim_charge_method_required', fn () => $this->service->requestReturn($order, $this->payload($order)));
    }

    /**
     * @effects return_charge_method_is_cleared_when_the_fee_is_zero
     */
    public function test_request_nulls_charge_method_when_fee_is_zero(): void
    {
        $order = $this->deliveredOrder();

        $return = $this->service->requestReturn(
            $order,
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST)
        );

        $this->assertNull($return->charge_method);
        $this->assertFalse($return->requiresAdditionalPayment());
    }

    /**
     * @effects return_charge_method_outside_the_allowed_list_is_rejected
     */
    public function test_request_rejects_charge_method_not_allowed_by_settings(): void
    {
        app(EcommerceSettingsService::class)->setSetting(
            'claim.allowed_charge_methods',
            [ClaimChargeMethodEnum::PAY_ON_APPROVAL->value]
        );

        $policy = $this->createClaimShippingPolicy(ChargePolicyEnum::FREE, 0);
        $this->setReturnFixedFee($policy, 3000);
        $order = $this->deliveredOrder(29000, 1, $policy);

        $this->assertClaimException('claim_charge_method_not_allowed', fn () => $this->service->requestReturn(
            $order,
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST)
        ));
    }

    /**
     * @effects return_deduct_from_refund_requires_a_covering_refund
     */
    public function test_request_rejects_deduct_from_refund_when_refund_cannot_cover_fee(): void
    {
        $policy = $this->createClaimShippingPolicy(ChargePolicyEnum::FREE, 0);
        $this->setReturnFixedFee($policy, 30000);
        $order = $this->deliveredOrder(1000, 1, $policy);

        $this->assertClaimException('claim_charge_method_not_allowed', fn () => $this->service->requestReturn(
            $order,
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND)
        ));
    }

    /**
     * @effects return_deduct_from_refund_requires_a_covering_refund
     */
    public function test_request_accepts_deduct_from_refund_when_refund_covers_fee(): void
    {
        $policy = $this->createClaimShippingPolicy(ChargePolicyEnum::FREE, 0);
        $this->setReturnFixedFee($policy, 3000);
        $order = $this->deliveredOrder(29000, 1, $policy);

        $return = $this->service->requestReturn(
            $order,
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND)
        );

        $this->assertSame(ClaimChargeMethodEnum::DEDUCT_FROM_REFUND, $return->charge_method);
    }

    /**
     * @effects return_request_splits_the_order_option_for_a_partial_quantity
     */
    public function test_request_can_charge_a_partial_quantity_separately(): void
    {
        // 부분 수량은 회수 대상 행을 나누는 축이고 청구 방식은 그 위에서 정해진 금액을 받는
        // 방법이다 — 나뉜 행이 청구를 그대로 지고 가는지 본다.
        $policy = $this->createClaimShippingPolicy(ChargePolicyEnum::FREE, 0);
        $this->setReturnFixedFee($policy, 3000);
        $order = $this->deliveredOrder(29000, 3, $policy);

        $return = $this->service->requestReturn(
            $order,
            $this->payload($order, quantity: 1, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST)
        );

        $order = $order->fresh(['options']);
        $inProgress = $order->options->firstWhere('option_status', OrderStatusEnum::RETURN_IN_PROGRESS);

        $this->assertSame(1, (int) $inProgress->quantity);
        $this->assertSame($inProgress->id, $return->returnOptions->first()->order_option_id);
        $this->assertSame(ClaimChargeMethodEnum::PAY_ON_REQUEST, $return->charge_method);
        $this->assertSame(3000.0, (float) $return->total_charge_amount, '고정액 회수비는 신청 수량으로 나뉘지 않는다.');
        $this->assertTrue($return->requiresAdditionalPayment());
    }

    /**
     * @effects return_address_comes_from_the_shipping_policy
     */
    public function test_request_snapshots_return_address_from_policy(): void
    {
        $policy = $this->createClaimShippingPolicy();
        ShippingPolicyClaimAddress::create([
            'shipping_policy_id' => $policy->id,
            'claim_type' => 'return',
            'recipient_name' => '회수담당',
            'phone' => '02-000-0000',
            'zipcode' => '06236',
            'address1' => '서울시 강남구',
            'address2' => '1층',
            'country_code' => 'KR',
        ]);

        $order = $this->deliveredOrder(29000, 1, $policy);

        $return = $this->service->requestReturn($order, $this->payload($order));

        $this->assertSame('회수담당', $return->return_address_snapshot['recipient_name']);
        $this->assertSame('06236', $return->return_address_snapshot['zipcode']);
    }

    public function test_request_leaves_return_address_null_when_policy_has_none(): void
    {
        $order = $this->deliveredOrder();

        $return = $this->service->requestReturn($order, $this->payload($order));

        $this->assertNull($return->return_address_snapshot);
    }

    /**
     * @effects return_request_period_is_measured_from_delivery
     */
    public function test_request_rejects_after_request_period(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.request_period_days', 1);

        $order = $this->deliveredOrder();
        $order->options->first()->forceFill(['delivered_at' => now()->subDays(5)])->save();

        $this->assertClaimException('claim_request_period_expired', fn () => $this->service->requestReturn($order->fresh(['options', 'shippings', 'payment']), $this->payload($order)));
    }

    public function test_request_falls_back_to_confirmed_at_when_delivered_at_is_missing(): void
    {
        // `배송중 → 구매확정` 직접 전이가 허용되므로 `delivered_at` 이 영영 null 인 옵션이 실재한다.
        // 그것을 "판정 불가" 로 넘기면 기한 게이트가 그 옵션에 한해 영구히 열린 채가 된다.
        app(EcommerceSettingsService::class)->setSetting('claim.request_period_days', 1);

        $order = $this->deliveredOrder();
        $order->options->first()->forceFill([
            'delivered_at' => null,
            'confirmed_at' => now()->subDays(5),
        ])->save();

        $this->assertClaimException(
            'claim_request_period_expired',
            fn () => $this->service->requestReturn($order->fresh(['options', 'shippings', 'payment']), $this->payload($order)),
        );
    }

    public function test_request_is_allowed_when_no_timestamp_is_stale(): void
    {
        // 두 시각이 모두 없는 구형 주문(컬럼이 생기기 전)은 마지막 변경 시각으로 내려간다 —
        // 창은 닫히되 정상 신청은 막지 않는다.
        app(EcommerceSettingsService::class)->setSetting('claim.request_period_days', 1);

        $order = $this->deliveredOrder();
        $order->options->first()->forceFill(['delivered_at' => null, 'confirmed_at' => null])->save();

        $return = $this->service->requestReturn(
            $order->fresh(['options', 'shippings', 'payment']),
            $this->payload($order)
        );

        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->return_status);
    }

    public function test_request_does_not_change_order_status(): void
    {
        $order = $this->deliveredOrder();

        $this->service->requestReturn($order, $this->payload($order));

        // 전량 반품 신청이라도 주문 상태는 그대로다 — 코스 상태는 주문 상태 동기화에서
        // 제외되며, 동기화를 부르면 활성 옵션 0건으로 판정돼 주문이 취소로 뒤집힌다.
        $this->assertSame(OrderStatusEnum::DELIVERED, $order->fresh()->order_status);
    }

    public function test_request_fires_requested_hook(): void
    {
        $fired = 0;
        HookManager::addAction('sirsoft-ecommerce.claim.return.requested', function () use (&$fired) {
            $fired++;
        });

        $order = $this->deliveredOrder();
        $this->service->requestReturn($order, $this->payload($order));

        $this->assertSame(1, $fired);
    }

    public function test_request_stays_requested_when_auto_approve_is_off(): void
    {
        // 기본값이 false 이므로 설정을 켜지 않은 신청은 승인 대기로 남는다 — 아래 두 케이스의 대조군.
        $order = $this->deliveredOrder();

        $return = $this->service->requestReturn($order, $this->payload($order));

        $this->assertSame(0.0, (float) $return->total_charge_amount);
        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->return_status);
        $this->assertNull($return->approved_at);
    }

    /**
     * @effects return_auto_approval_needs_a_zero_charge
     */
    public function test_request_auto_approves_when_charge_is_zero_and_setting_is_on(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.auto_approve_zero_charge', true);

        $order = $this->deliveredOrder();

        $return = $this->service->requestReturn($order, $this->payload($order));

        $this->assertSame(0.0, (float) $return->total_charge_amount);
        $this->assertSame(ReturnStatusEnum::APPROVED, $return->return_status);
        $this->assertNotNull($return->approved_at);
    }

    /**
     * @effects return_auto_approval_needs_a_zero_charge
     */
    public function test_request_does_not_auto_approve_when_a_charge_remains(): void
    {
        // 받을 돈이 남아 있으면 결제가 선행되어야 하므로 설정이 켜져 있어도 승인하지 않는다.
        app(EcommerceSettingsService::class)->setSetting('claim.auto_approve_zero_charge', true);

        $policy = $this->createClaimShippingPolicy(ChargePolicyEnum::FREE, 0);
        $this->setReturnFixedFee($policy, 3000);
        $order = $this->deliveredOrder(29000, 1, $policy);

        $return = $this->service->requestReturn(
            $order,
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST)
        );

        $this->assertSame(3000.0, (float) $return->total_charge_amount);
        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->return_status);
    }

    public function test_auto_approved_request_fires_both_requested_and_approved_hooks(): void
    {
        $sequence = [];
        HookManager::addAction('sirsoft-ecommerce.claim.return.requested', function () use (&$sequence) {
            $sequence[] = 'requested';
        });
        HookManager::addAction('sirsoft-ecommerce.claim.return.approved', function () use (&$sequence) {
            $sequence[] = 'approved';
        });

        app(EcommerceSettingsService::class)->setSetting('claim.auto_approve_zero_charge', true);

        $order = $this->deliveredOrder();
        $this->service->requestReturn($order, $this->payload($order));

        // 알림·로그 리스너가 두 사건을 순서대로 받아야 한다 — 승인만 오면 접수 이력이 비고,
        // 순서가 뒤집히면 '승인된 뒤에 신청됨' 으로 기록된다.
        $this->assertSame(['requested', 'approved'], $sequence);
    }

    /**
     * @effects return_guest_request_has_no_requester
     */
    public function test_guest_request_has_no_requester(): void
    {
        $order = $this->deliveredOrder();

        $return = $this->service->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'requested_by' => null,
        ]);

        $this->assertTrue($return->isGuestRequest());
    }

    // ================================================================
    // 미리보기
    // ================================================================

    /**
     * @effects return_preview_matches_the_requested_amounts
     */
    public function test_preview_matches_requested_amounts(): void
    {
        $policy = $this->createClaimShippingPolicy(ChargePolicyEnum::FREE, 0);
        $this->setReturnFixedFee($policy, 3000);
        $order = $this->deliveredOrder(29000, 1, $policy);

        $preview = $this->service->previewReturn(
            $order,
            [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'changed_mind',
            ClaimChargeMethodEnum::PAY_ON_REQUEST,
        );

        $return = $this->service->requestReturn(
            $order,
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST)
        );

        $this->assertSame((float) $return->claim_shipping_fee, (float) $preview['shipping_fee']->fee);
        $this->assertSame((float) $return->total_charge_amount, $preview['total_charge_amount']);
    }

    public function test_preview_does_not_write_any_record(): void
    {
        $order = $this->deliveredOrder();

        $this->service->previewReturn(
            $order,
            [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'changed_mind',
        );

        $this->assertSame(0, OrderReturn::where('order_id', $order->id)->count());
        $this->assertSame(OrderStatusEnum::DELIVERED, $order->options->first()->fresh()->option_status);
    }

    public function test_preview_seller_fault_has_zero_fee(): void
    {
        $policy = $this->createClaimShippingPolicy(ChargePolicyEnum::FREE, 0);
        $this->setReturnFixedFee($policy, 3000);
        $order = $this->deliveredOrder(29000, 1, $policy);

        $preview = $this->service->previewReturn(
            $order,
            [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'defective_product',
        );

        $this->assertSame(0, $preview['shipping_fee']->fee);
        $this->assertFalse($preview['requires_payment']);
    }

    public function test_preview_deduct_from_refund_reduces_refund_by_fee(): void
    {
        $policy = $this->createClaimShippingPolicy(ChargePolicyEnum::FREE, 0);
        $this->setReturnFixedFee($policy, 3000);
        $order = $this->deliveredOrder(29000, 1, $policy);
        $items = [['order_option_id' => $order->options->first()->id, 'quantity' => 1]];

        $withoutDeduct = $this->service->previewReturn($order, $items, 'changed_mind', ClaimChargeMethodEnum::PAY_ON_REQUEST);
        $withDeduct = $this->service->previewReturn($order, $items, 'changed_mind', ClaimChargeMethodEnum::DEDUCT_FROM_REFUND);

        $this->assertSame(
            $withoutDeduct['adjustment']->getTotalRefundAmount() - 3000,
            $withDeduct['adjustment']->getTotalRefundAmount()
        );
    }

    // ================================================================
    // 철회 / 반려 / 복귀
    // ================================================================

    public function test_withdraw_from_requested(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->service->requestReturn($order, $this->payload($order));

        $withdrawn = $this->service->withdraw($return);

        $this->assertSame(ReturnStatusEnum::WITHDRAWN, $withdrawn->return_status);
        $this->assertNotNull($withdrawn->withdrawn_at);

        // 철회자 식별은 이 표가 아니라 활동 로그(S7)가 맡는다 — 신청자는 requested_by 다.
        $this->assertSame($order->user_id, $withdrawn->requested_by);
    }

    /**
     * @effects return_withdraw_is_blocked_after_approval
     */
    public function test_withdraw_is_blocked_after_approval(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::APPROVED
        );

        $this->assertClaimException('claim_not_withdrawable', fn () => $this->service->withdraw($return));
    }

    /**
     * @effects return_withdraw_reverts_the_order_option
     */
    public function test_withdraw_reverts_order_option_to_original_status(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->service->requestReturn($order, $this->payload($order));

        $this->service->withdraw($return);

        $this->assertSame(OrderStatusEnum::DELIVERED, $order->options->first()->fresh()->option_status);
    }

    public function test_reject_reverts_order_option_and_records_reason(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->service->requestReturn($order, $this->payload($order));

        $rejected = $this->service->transition($return, ReturnStatusEnum::REJECTED, [
            'reject_reason' => '회수 불가 상품',
        ]);

        $this->assertSame('회수 불가 상품', $rejected->reject_reason);
        $this->assertNotNull($rejected->rejected_at);
        $this->assertSame(OrderStatusEnum::DELIVERED, $order->options->first()->fresh()->option_status);
    }

    /**
     * @effects return_reject_reverts_each_item_to_its_own_original_status
     */
    public function test_revert_restores_each_item_to_its_own_original_status(): void
    {
        $policy = $this->createClaimShippingPolicy();
        [$productA, $optionA] = $this->createClaimProduct(10000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(20000, $policy);

        $order = $this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem($productA->id, $optionA->id, 1),
            new CalculationItem($productB->id, $optionB->id, 1),
        ]));

        // 상품마다 신청 직전 상태가 다르다 — 신청서 단위 단일 스냅샷으로는 복원할 수 없는 형태.
        $order->options[0]->forceFill([
            'option_status' => OrderStatusEnum::SHIPPING,
            'is_stock_deducted' => true,
        ])->save();
        $order->options[1]->forceFill([
            'option_status' => OrderStatusEnum::DELIVERED,
            'delivered_at' => now()->subDay(),
            'is_stock_deducted' => true,
        ])->save();

        app(EcommerceSettingsService::class)->setSetting('claim.return_requestable_statuses', [
            OrderStatusEnum::SHIPPING->value,
            OrderStatusEnum::DELIVERED->value,
        ]);

        $order = $order->fresh(['options', 'shippings', 'payment']);
        $return = $this->service->requestReturn($order, $this->payload($order));

        $this->service->withdraw($return);

        $statuses = $order->fresh(['options'])->options
            ->pluck('option_status')
            ->map(fn (OrderStatusEnum $status) => $status->value)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            [OrderStatusEnum::DELIVERED->value, OrderStatusEnum::SHIPPING->value],
            $statuses
        );
    }

    public function test_revert_does_not_deduct_stock_again(): void
    {
        $order = $this->deliveredOrder();
        $productOptionId = $order->options->first()->product_option_id;
        $before = (int) ProductOption::find($productOptionId)->stock_quantity;

        $return = $this->service->requestReturn($order, $this->payload($order));
        $this->service->withdraw($return);

        // 신청 접수 시 복원한 적이 없으므로 되돌릴 재고도 없다 —
        // 재차감이 발화하면 재고가 한 번 더 줄어든다.
        $this->assertSame($before, (int) ProductOption::find($productOptionId)->stock_quantity);
    }

    public function test_revert_of_partial_quantity_keeps_rows_separate(): void
    {
        $order = $this->deliveredOrder(29000, 3);
        $return = $this->service->requestReturn($order, $this->payload($order, quantity: 1));

        $this->service->withdraw($return);

        $options = $order->fresh(['options'])->options;

        $this->assertSame(2, $options->count());
        $this->assertSame(3, (int) $options->sum('quantity'));
        $this->assertTrue($options->every(
            fn (OrderOption $option) => $option->option_status === OrderStatusEnum::DELIVERED
        ));
        // 병합으로 행이 사라지면 신청 이력(신청 상품 행)이 cascade 로 함께 지워진다.
        $this->assertNotNull($return->returnOptions->first()->fresh());
    }

    // ================================================================
    // 상태 전이
    // ================================================================

    /**
     * @effects return_transition_follows_the_status_gate
     */
    public function test_transition_follows_the_gate_forward(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->service->requestReturn($order, $this->payload($order));

        foreach ([
            ReturnStatusEnum::APPROVED,
            ReturnStatusEnum::COLLECTION_READY,
            ReturnStatusEnum::COLLECTING,
            ReturnStatusEnum::COLLECTED,
        ] as $target) {
            $return = $this->service->transition($return, $target);
            $this->assertSame($target, $return->return_status);
        }
    }

    public function test_transition_blocks_a_target_outside_the_gate(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->service->requestReturn($order, $this->payload($order));

        $this->assertClaimException('claim_transition_not_allowed', fn () => $this->service->transition($return, ReturnStatusEnum::COLLECTED));
    }

    /**
     * @effects return_completion_is_not_reachable_by_a_plain_transition
     */
    public function test_transition_to_completed_is_blocked(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        $this->assertClaimException('claim_completion_flow_required', fn () => $this->service->transition($return, ReturnStatusEnum::COMPLETED));
    }

    /**
     * @effects return_on_hold_and_approval_times_are_stamped
     */
    public function test_transition_stamps_approved_and_on_hold_times(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->service->requestReturn($order, $this->payload($order));

        $onHold = $this->service->transition($return, ReturnStatusEnum::ON_HOLD);
        $this->assertNotNull($onHold->on_hold_at);

        $approved = $this->service->transition($onHold, ReturnStatusEnum::APPROVED);
        $this->assertNotNull($approved->approved_at);
    }

    public function test_transition_records_collection_completed_at(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        $this->assertNotNull($return->collection_completed_at);
    }

    public function test_transition_syncs_item_statuses(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->service->requestReturn($order, $this->payload($order));

        $approved = $this->service->transition($return, ReturnStatusEnum::APPROVED);

        $this->assertSame(
            ReturnStatusEnum::APPROVED,
            $approved->returnOptions->first()->option_status
        );
    }

    public function test_transition_fires_status_changed_and_specific_hooks(): void
    {
        $changed = [];
        $approved = 0;
        HookManager::addAction(
            'sirsoft-ecommerce.claim.return.status_changed',
            function ($return, $from, $to) use (&$changed) {
                $changed[] = $from.'>'.$to;
            }
        );
        HookManager::addAction('sirsoft-ecommerce.claim.return.approved', function () use (&$approved) {
            $approved++;
        });

        $order = $this->deliveredOrder();
        $return = $this->service->requestReturn($order, $this->payload($order));
        $this->service->transition($return, ReturnStatusEnum::APPROVED);

        $this->assertContains('requested>approved', $changed);
        $this->assertSame(1, $approved);
    }

    public function test_bulk_transition_reports_skipped_entries(): void
    {
        $orderA = $this->deliveredOrder();
        $orderB = $this->deliveredOrder();

        $ok = $this->service->requestReturn($orderA, $this->payload($orderA));
        $blocked = $this->advanceTo(
            $this->service->requestReturn($orderB, $this->payload($orderB)),
            ReturnStatusEnum::COLLECTED
        );

        $result = $this->service->bulkTransition([$ok->id, $blocked->id], ReturnStatusEnum::APPROVED);

        $this->assertSame([$ok->id], $result['succeeded']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame($blocked->id, $result['skipped'][0]['id']);
        // 사유까지 단언한다 — 건수만 세면 어떤 문구가 실려도 통과한다 (6차 ⑩).
        $this->assertSame(
            __('sirsoft-ecommerce::exceptions.claim_transition_not_allowed', [
                'from' => ReturnStatusEnum::COLLECTED->label(),
                'to' => ReturnStatusEnum::APPROVED->label(),
            ]),
            $result['skipped'][0]['message']
        );
    }

    public function test_bulk_transition_reports_missing_records(): void
    {
        $result = $this->service->bulkTransition([999999], ReturnStatusEnum::APPROVED);

        $this->assertSame([], $result['succeeded']);
        $this->assertCount(1, $result['skipped']);

        // 사유까지 단언한다 — 건수만 세면 '완료할 상품이 없습니다' 처럼 행위·원인이 모두
        // 어긋난 안내가 실려도 통과한다. 운영자는 승인을 눌렀는데 완료 얘기를 듣게 된다.
        $this->assertSame(
            __('sirsoft-ecommerce::exceptions.claim_not_found'),
            $result['skipped'][0]['message']
        );
    }

    // ================================================================
    // 상품 단위 전이 (D25)
    // ================================================================

    public function test_transition_option_moves_only_selected_items(): void
    {
        // 신청 상품이 둘이어야 "선택한 것만" 을 잴 수 있다 — 1행이면 인자를 무시하고 전부
        // 옮겨도 통과한다.
        [$return, $itemA, $itemB] = $this->twoItemReturn();
        $return = $this->service->transition($return, ReturnStatusEnum::APPROVED);

        $result = $this->service->transitionOption($return, [$itemA], ReturnStatusEnum::COLLECTION_READY);

        $this->assertSame([$itemA], $result['succeeded']);
        $this->assertSame(ReturnStatusEnum::COLLECTION_READY, OrderReturnOption::find($itemA)->option_status);
        $this->assertSame(ReturnStatusEnum::APPROVED, OrderReturnOption::find($itemB)->option_status, '선택하지 않은 상품이 함께 움직였습니다.');
        $this->assertSame(ReturnStatusEnum::APPROVED, $return->fresh()->return_status);
    }

    public function test_transition_option_reports_skip_for_invalid_target(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->service->requestReturn($order, $this->payload($order));

        $result = $this->service->transitionOption($return, [], ReturnStatusEnum::COLLECTED);

        $this->assertSame([], $result['succeeded']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame(
            __('sirsoft-ecommerce::exceptions.claim_transition_not_allowed', [
                'from' => ReturnStatusEnum::REQUESTED->label(),
                'to' => ReturnStatusEnum::COLLECTED->label(),
            ]),
            $result['skipped'][0]['message']
        );
    }

    public function test_transition_option_blocks_completed_target(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->service->requestReturn($order, $this->payload($order));

        $this->assertClaimException('claim_completion_flow_required', fn () => $this->service->transitionOption($return, [], ReturnStatusEnum::COMPLETED));
    }

    /**
     * @effects return_item_level_transition_moves_the_header_only_when_items_agree
     */
    public function test_transition_option_moves_header_when_all_items_agree(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->service->requestReturn($order, $this->payload($order));

        $this->service->transitionOption($return, [], ReturnStatusEnum::APPROVED);

        $this->assertSame(ReturnStatusEnum::APPROVED, $return->fresh()->return_status);
    }

    public function test_transition_option_moves_header_ignoring_rejected_items(): void
    {
        [$return, $itemA, $itemB] = $this->twoItemReturn();

        // A 만 반려한다 — 끝난 상품은 판정에서 빠지므로 남은 B 가 도달한 단계를
        // 신청서가 따라가야 한다. 포함해서 세면 상태 집합이 영원히 2종이라 신청서가
        // 신청 상태에 묶이고, 운영자가 헤더를 매번 손으로 옮겨야 한다.
        $this->service->transitionOption($return, [$itemA], ReturnStatusEnum::REJECTED);
        $this->service->transitionOption(
            $return->fresh(['returnOptions']),
            [$itemB],
            ReturnStatusEnum::APPROVED
        );

        $this->assertSame(ReturnStatusEnum::APPROVED, $return->fresh()->return_status);
    }

    public function test_transition_option_moves_header_when_every_item_is_rejected(): void
    {
        [$return, $itemA, $itemB] = $this->twoItemReturn();

        // 대조군 — 끝난 상품을 빼고 나면 셀 것이 없는 경우다. 이때는 끝난 상태들이
        // 하나로 모이면 신청서가 그것을 따라간다(전량 반려된 신청서가 신청 상태로
        // 남지 않게 한다).
        $this->service->transitionOption($return, [$itemA, $itemB], ReturnStatusEnum::REJECTED);

        $this->assertSame(ReturnStatusEnum::REJECTED, $return->fresh()->return_status);
    }

    public function test_transition_option_rejecting_every_item_reverts_order_options(): void
    {
        [$return, $itemA, $itemB] = $this->twoItemReturn();

        $this->service->transitionOption($return, [$itemA, $itemB], ReturnStatusEnum::REJECTED);

        foreach ($return->fresh(['returnOptions.orderOption'])->returnOptions as $item) {
            $this->assertSame(
                OrderStatusEnum::DELIVERED,
                $item->orderOption->option_status,
                '신청서가 전량 반려로 종결됐는데 주문옵션이 반품 코스에 남아 있습니다.'
            );
        }
    }

    // ================================================================
    // 회수 송장
    // ================================================================

    public function test_register_tracking_moves_to_collecting(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTION_READY
        );

        $updated = $this->service->registerCollectionTracking($return, null, null);
        $this->assertSame(ReturnStatusEnum::COLLECTION_READY, $updated->return_status);

        $carrier = ShippingCarrier::first()
            ?? ShippingCarrier::create([
                'code' => 'test-carrier',
                'name' => ['ko' => '테스트택배', 'en' => 'Test Carrier'],
                'is_active' => true,
            ]);

        $tracked = $this->service->registerCollectionTracking($updated, $carrier->id, '1234567890');

        $this->assertSame('1234567890', $tracked->collection_tracking_number);
        $this->assertSame(ReturnStatusEnum::COLLECTING, $tracked->return_status);
    }

    public function test_register_tracking_requires_both_fields(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->service->requestReturn($order, $this->payload($order));

        $this->assertClaimException('claim_tracking_incomplete', fn () => $this->service->registerCollectionTracking($return, null, '1234567890'));
    }

    // ================================================================
    // 금액 수정
    // ================================================================

    /**
     * @effects return_update_amounts_moves_the_total_charge_with_the_fee
     */
    public function test_update_amounts_moves_total_charge_with_the_fee(): void
    {
        $policy = $this->createClaimShippingPolicy(ChargePolicyEnum::FREE, 0);
        $this->setReturnFixedFee($policy, 3000);
        $order = $this->deliveredOrder(29000, 1, $policy);
        $return = $this->service->requestReturn(
            $order,
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST)
        );

        $updated = $this->service->updateAmounts($return, ['claim_shipping_fee' => 1500]);

        $this->assertSame(1500.0, (float) $updated->claim_shipping_fee);
        $this->assertSame(1500.0, (float) $updated->total_charge_amount);
        $this->assertTrue($updated->shipping_fee_snapshot['admin_adjusted']);
    }

    public function test_update_amounts_rejects_negative_fee(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->service->requestReturn($order, $this->payload($order));

        $this->assertClaimException('claim_amount_invalid', fn () => $this->service->updateAmounts($return, ['claim_shipping_fee' => -1]));
    }

    public function test_update_amounts_clears_charge_method_when_fee_becomes_zero(): void
    {
        $policy = $this->createClaimShippingPolicy(ChargePolicyEnum::FREE, 0);
        $this->setReturnFixedFee($policy, 3000);
        $order = $this->deliveredOrder(29000, 1, $policy);
        $return = $this->service->requestReturn(
            $order,
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST)
        );

        $updated = $this->service->updateAmounts($return, ['claim_shipping_fee' => 0]);

        $this->assertNull($updated->charge_method);
        $this->assertFalse($updated->requiresAdditionalPayment());
    }

    /**
     * 청구 방식이 없는 신청서(접수 시 청구 0원)의 청구액을 양수로 올리면 거절한다 — 결제할 수 없는
     * 청구가 남는다. 청구 방식을 함께 정하는 금액 수정 API 가 그 경로다(교환과 같은 규칙).
     */
    public function test_update_amounts_refuses_to_raise_a_charge_without_a_charge_method(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->service->requestReturn($order, $this->payload($order));
        $this->assertNull($return->charge_method);

        $this->assertClaimException('claim_charge_method_required', fn () => $this->service->updateAmounts($return, ['claim_shipping_fee' => 3000]));
        $this->assertSame(0.0, (float) $return->fresh()->total_charge_amount);
        $this->assertSame(0.0, (float) $return->fresh()->claim_shipping_fee);
    }

    // ================================================================
    // 완료 처리 (D26)
    // ================================================================

    public function test_complete_marks_item_and_order_option_returned(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        $completed = $this->service->completeReturn(
            $return,
            [],
            $this->completionOptions($return, false, false)
        );

        $this->assertSame(ReturnStatusEnum::COMPLETED, $completed->return_status);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame(ReturnStatusEnum::COMPLETED, $completed->returnOptions->first()->option_status);
        $this->assertSame(OrderStatusEnum::RETURNED, $order->options->first()->fresh()->option_status);
    }

    /**
     * 완료 시 반품 상품의 적립예정 마일리지를 회수한다 — 상품이 주문에서 빠졌으므로 그 적립도
     * 남으면 안 된다. (교환 축 `OrderExchangeServiceTest` 의 같은 이름 단언과 한 벌)
     *
     * @effects return_completion_cancels_the_pending_earn
     */
    public function test_complete_cancels_the_pending_earn_of_the_returned_option(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );
        $orderOptionId = (int) $return->returnOptions->first()->order_option_id;

        MileageTransaction::create([
            'user_id' => $order->user_id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 500,
            'remaining_amount' => 500,
            'balance_after' => 500,
            'order_id' => $order->id,
            'order_option_id' => $orderOptionId,
        ]);

        $this->service->completeReturn($return, [], $this->completionOptions($return, false, false));

        $this->assertSame(
            1,
            MileageTransaction::where('order_option_id', $orderOptionId)
                ->where('type', MileageTransactionTypeEnum::EARN_CANCEL->value)
                ->count(),
            '반품 상품의 적립 lot 이 회수되어야 한다.'
        );
    }

    /**
     * @scenario quantity=full, charge_method=none, refund_chain=none
     *
     * @effects return_completion_refund_is_filed_only_when_asked
     */
    public function test_complete_without_refund_request_creates_no_refund(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        $completed = $this->service->completeReturn(
            $return,
            [],
            $this->completionOptions($return, false, false)
        );

        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());
        $this->assertNull($completed->refund_id);
    }

    /**
     * @scenario quantity=full, charge_method=none, refund_chain=at_completion
     *
     * @effects return_completion_refund_is_filed_exactly_once
     */
    public function test_complete_with_refund_request_creates_exactly_one_refund(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        $completed = $this->service->completeReturn(
            $return,
            [],
            $this->completionOptions($return, false, true)
        );

        $this->assertSame(1, OrderRefund::where('order_id', $order->id)->count());
        $this->assertNotNull($completed->refund_id);
    }

    public function test_complete_refund_records_return_as_its_source(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        $completed = $this->service->completeReturn(
            $return,
            [],
            $this->completionOptions($return, false, true)
        );

        $refund = OrderRefund::find($completed->refund_id);

        $this->assertSame(RefundSourceTypeEnum::RETURN, $refund->refund_source_type);
        $this->assertSame($return->id, $refund->refund_source_id);
        $this->assertSame(RefundStatusEnum::REQUESTED, $refund->refund_status);
        $this->assertNull($refund->order_cancel_id);
        $this->assertSame($return->reason_code, $refund->reason_code);
    }

    public function test_complete_refund_creates_option_rows(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        $completed = $this->service->completeReturn(
            $return,
            [],
            $this->completionOptions($return, false, true)
        );

        $this->assertSame(1, OrderRefundOption::where('order_refund_id', $completed->refund_id)->count());
    }

    public function test_complete_refund_amount_covers_the_returned_item(): void
    {
        $order = $this->deliveredOrder(29000, 1);
        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        $completed = $this->service->completeReturn(
            $return,
            [],
            $this->completionOptions($return, false, true)
        );

        $refund = OrderRefund::find($completed->refund_id);

        $this->assertSame(29000.0, (float) $refund->refund_amount);
    }

    /**
     * @effects return_completion_restores_stock_only_for_checked_items
     */
    public function test_complete_restores_stock_only_for_checked_items(): void
    {
        $order = $this->deliveredOrder();
        $productOptionId = $order->options->first()->product_option_id;
        $before = (int) ProductOption::find($productOptionId)->stock_quantity;

        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        $completed = $this->service->completeReturn(
            $return,
            [],
            $this->completionOptions($return, true, false)
        );

        $this->assertSame($before + 1, (int) ProductOption::find($productOptionId)->stock_quantity);
        $this->assertTrue((bool) $completed->is_stock_restored);
    }

    /**
     * 반품 재고 복구가 **다른 취소 건**의 차감 표시까지 지우지 않는다.
     *
     * 같은 상품옵션에 "재고는 복구하지 않음" 으로 취소된 행이 남아 있을 수 있다. 그 행은
     * 재고를 여전히 점유하므로 `is_stock_deducted` 가 참이어야 하고, 나중에 그 취소를
     * 되살릴 때 재차감 대상이 되면 안 된다. 반품 복구가 그 표시를 함께 내리면
     * 되돌린 적 없는 재고가 다시 차감돼 재고가 조용히 줄어든다.
     */
    public function test_complete_stock_restore_keeps_flags_of_unrestored_cancelled_rows(): void
    {
        $order = $this->deliveredOrder();
        $option = $order->options->first();
        $productOptionId = $option->product_option_id;
        $before = (int) ProductOption::find($productOptionId)->stock_quantity;

        // 재고를 되돌리지 않고 취소된 형제 행 (취소 모달의 '재고 복구 안 함' 선택 결과)
        $cancelledSibling = OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_id' => $option->product_id,
            'product_option_id' => $productOptionId,
            'quantity' => 1,
            'option_status' => OrderStatusEnum::CANCELLED,
            'is_stock_deducted' => true,
        ]);

        $return = $this->advanceTo(
            $this->service->requestReturn($order->fresh(['options', 'shippings', 'payment']), [
                'items' => [['order_option_id' => $option->id, 'quantity' => 1]],
                'reason_code' => 'changed_mind',
            ]),
            ReturnStatusEnum::COLLECTED
        );

        $this->service->completeReturn($return, [], $this->completionOptions($return, true, false));

        // 반품 몫은 복구됐다 (대조군 — 복구 자체가 일어나지 않았다면 아래 단언은 무의미하다).
        $this->assertSame($before + 1, (int) ProductOption::find($productOptionId)->stock_quantity);

        // 그런데 취소 행의 차감 표시는 그대로여야 한다.
        $this->assertTrue(
            (bool) $cancelledSibling->fresh()->is_stock_deducted,
            '반품 재고 복구가 미복원 취소 행의 차감 표시까지 내렸습니다.'
        );
    }

    public function test_complete_leaves_stock_untouched_when_not_checked(): void
    {
        $order = $this->deliveredOrder();
        $productOptionId = $order->options->first()->product_option_id;
        $before = (int) ProductOption::find($productOptionId)->stock_quantity;

        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        $completed = $this->service->completeReturn(
            $return,
            [],
            $this->completionOptions($return, false, false)
        );

        $this->assertSame($before, (int) ProductOption::find($productOptionId)->stock_quantity);
        $this->assertFalse((bool) $completed->is_stock_restored);
    }

    /**
     * D26 의 핵심은 재고 복구를 **신청 상품 행마다** 따로 고른다는 것이다.
     *
     * 위 두 케이스는 전 아이템에 같은 값을 넣어 "복구한다/안 한다" 만 재므로, 선택이 상품마다
     * 갈릴 때 실제로 갈리는지는 고정되지 않는다 — `shouldRestoreStock()` 이 인자를 무시하고
     * 아무 값이나 돌려줘도 둘 다 통과한다. 한 라운드에서 A 만 복구하고 B 는 두는 형태로
     * 두 상품의 재고를 각각 단언해 그 사각을 닫는다. 헤더 `is_stock_restored` 가
     * "하나라도 복구되면 true" 임도 같은 케이스가 고정한다.
     *
     * @effects return_completion_stock_choice_can_differ_per_item
     */
    public function test_complete_restores_stock_per_item_when_the_choice_differs(): void
    {
        $policy = $this->createClaimShippingPolicy();
        [$productA, $optionA] = $this->createClaimProduct(10000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(20000, $policy);

        $order = $this->deliver($this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem($productA->id, $optionA->id, 1),
            new CalculationItem($productB->id, $optionB->id, 1),
        ])));

        $beforeA = (int) ProductOption::find($optionA->id)->stock_quantity;
        $beforeB = (int) ProductOption::find($optionB->id)->stock_quantity;

        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        // 신청 상품 행을 원 상품옵션으로 되짚어 A/B 를 가른다 — 행 순서에 기대면
        // 정렬이 바뀌는 순간 두 단언이 서로 자리를 바꿔 통과해 버린다.
        $itemIdFor = function (int $productOptionId) use ($order, $return): int {
            $orderOptionIds = $order->options
                ->where('product_option_id', $productOptionId)
                ->pluck('id')
                ->all();

            return (int) $return->returnOptions
                ->firstWhere(fn ($item) => in_array((int) $item->order_option_id, $orderOptionIds, true))
                ->id;
        };

        $completed = $this->service->completeReturn(
            $return,
            [],
            new ClaimCompletionOptions(
                [$itemIdFor((int) $optionA->id) => true, $itemIdFor((int) $optionB->id) => false],
                false,
            )
        );

        $this->assertSame($beforeA + 1, (int) ProductOption::find($optionA->id)->stock_quantity);
        $this->assertSame($beforeB, (int) ProductOption::find($optionB->id)->stock_quantity);
        $this->assertTrue((bool) $completed->is_stock_restored);
    }

    /**
     * @effects return_completion_ignores_the_settings_default
     */
    public function test_complete_ignores_settings_defaults(): void
    {
        // 설정은 모달의 초기 선택값일 뿐이다 — 같은 요청은 설정과 무관하게 같은 결과여야 한다.
        app(EcommerceSettingsService::class)->setSetting('claim.complete_defaults', [
            'create_refund_request' => true,
            'restore_stock' => true,
        ]);

        $order = $this->deliveredOrder();
        $productOptionId = $order->options->first()->product_option_id;
        $before = (int) ProductOption::find($productOptionId)->stock_quantity;

        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        $this->service->completeReturn($return, [], new ClaimCompletionOptions([], false));

        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());
        $this->assertSame($before, (int) ProductOption::find($productOptionId)->stock_quantity);
    }

    public function test_complete_of_some_items_keeps_the_header_open(): void
    {
        $policy = $this->createClaimShippingPolicy();
        [$productA, $optionA] = $this->createClaimProduct(10000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(20000, $policy);

        $order = $this->deliver($this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem($productA->id, $optionA->id, 1),
            new CalculationItem($productB->id, $optionB->id, 1),
        ])));

        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        $firstItemId = $return->returnOptions->first()->id;

        $partial = $this->service->completeReturn(
            $return,
            [$firstItemId],
            new ClaimCompletionOptions([$firstItemId => false], false)
        );

        $this->assertSame(ReturnStatusEnum::COLLECTED, $partial->return_status);
        $this->assertSame(1, $partial->returnOptions->where('option_status', ReturnStatusEnum::COMPLETED)->count());
    }

    /**
     * @effects return_later_round_refund_covers_every_completed_item
     */
    public function test_refund_created_in_a_later_round_covers_every_completed_item(): void
    {
        $policy = $this->createClaimShippingPolicy();
        [$productA, $optionA] = $this->createClaimProduct(10000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(20000, $policy);

        $order = $this->deliver($this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem($productA->id, $optionA->id, 1),
            new CalculationItem($productB->id, $optionB->id, 1),
        ])));

        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        $ids = $return->returnOptions->pluck('id')->all();

        $this->service->completeReturn($return, [$ids[0]], new ClaimCompletionOptions([], false));

        $completed = $this->service->completeReturn(
            $return->fresh(['returnOptions']),
            [$ids[1]],
            new ClaimCompletionOptions([], true)
        );

        // 환불신청서는 신청서당 한 건이므로, 마지막 라운드에서 만든 건이 앞 라운드 상품까지
        // 담아야 한다 — 이번 라운드 몫만 담으면 먼저 완료한 상품이 환불 대상에서 사라진다.
        $this->assertSame(2, OrderRefundOption::where('order_refund_id', $completed->refund_id)->count());
        $this->assertSame(30000.0, (float) OrderRefund::find($completed->refund_id)->refund_amount);
    }

    public function test_complete_rejects_items_that_are_not_collected(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->service->requestReturn($order, $this->payload($order));

        $this->assertClaimException('claim_completion_items_invalid', fn () => $this->service->completeReturn($return, [], $this->completionOptions($return, false, false)));
    }

    /**
     * @effects return_second_refund_request_is_rejected
     */
    public function test_complete_rejects_a_second_refund_request(): void
    {
        $policy = $this->createClaimShippingPolicy();
        [$productA, $optionA] = $this->createClaimProduct(10000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(20000, $policy);

        $order = $this->deliver($this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem($productA->id, $optionA->id, 1),
            new CalculationItem($productB->id, $optionB->id, 1),
        ])));

        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        $ids = $return->returnOptions->pluck('id')->all();

        $this->service->completeReturn($return, [$ids[0]], new ClaimCompletionOptions([], true));

        $this->assertClaimException('claim_refund_request_exists', fn () => $this->service->completeReturn($return->fresh(['returnOptions']), [$ids[1]], new ClaimCompletionOptions([], true)));
    }

    /**
     * 두 처리자가 같은 반품을 거의 동시에 완료해도 환불신청서는 한 건만 만들어진다.
     *
     * 접수 여부를 호출부가 들고 온 모델로만 판정하면, 두 요청이 각자 **접수 전** 상태를
     * 읽은 뒤 차례로 저장돼 두 건이 생긴다. 헤더 `refund_id` 는 나중 것만 남으므로 앞 건은
     * 취소 가능 금액의 선점액 집계에서 빠지고, 그만큼 한도가 실제보다 넓게 계산된다 —
     * 예외도 로그도 남지 않는다.
     */
    public function test_complete_rejects_a_refund_request_created_by_a_concurrent_round(): void
    {
        $order = $this->deliveredOrder();

        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        // 다른 처리자가 아직 접수 전 상태를 손에 쥔 채로 대기한다.
        $stale = OrderReturn::with('returnOptions')->find($return->id);

        $this->service->completeReturn(
            $return->fresh(['returnOptions']),
            [],
            $this->completionOptions($return, false, true)
        );

        $this->assertClaimException(
            'claim_refund_request_exists',
            fn () => $this->service->completeReturn($stale, [], $this->completionOptions($stale, false, true))
        );

        $this->assertSame(1, OrderRefund::where('order_id', $order->id)->count());
    }

    /**
     * @scenario quantity=full, charge_method=none, refund_chain=after_completion
     *
     * @effects return_refund_can_be_filed_after_completion
     */
    public function test_refund_request_can_be_created_after_completion(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        $completed = $this->service->completeReturn(
            $return,
            [],
            $this->completionOptions($return, false, false)
        );

        $refund = $this->service->createRefundRequestForCompleted($completed);

        $this->assertSame(RefundSourceTypeEnum::RETURN, $refund->refund_source_type);
        $this->assertSame($refund->id, $completed->fresh()->refund_id);
    }

    /**
     * @scenario quantity=full, charge_method=deduct_from_refund, refund_chain=none
     *
     * @effects return_deducted_fee_is_carried_into_a_later_refund_request
     */
    public function test_deducted_collection_fee_survives_a_completion_without_a_refund_request(): void
    {
        // '환불금 차감' 은 결제 행을 만들지 않는다 — 회수비는 오직 환불액에서 빠지는 형태로만
        // 걷힌다. 그래서 완료 처리에서 '접수하지 않음' 을 고르면 그 시점에는 걷히는 곳이 없고,
        // 회수비가 사라졌는지 미뤄졌는지는 **나중 접수**를 이어 봐야 갈린다(D26).
        $policy = $this->createClaimShippingPolicy(ChargePolicyEnum::FREE, 0);
        $this->setReturnFixedFee($policy, 3000);
        $order = $this->deliveredOrder(29000, 1, $policy);

        $return = $this->advanceTo(
            $this->service->requestReturn(
                $order,
                $this->payload($order, chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND)
            ),
            ReturnStatusEnum::COLLECTED
        );

        $completed = $this->service->completeReturn($return, [], $this->completionOptions($return, false, false));

        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());
        $this->assertSame(3000.0, (float) $completed->claim_shipping_fee, '동결된 회수비는 남아 있다.');

        // 나중 접수가 그 회수비를 실제로 뺀다 — 미뤄진 것이지 사라진 것이 아니다.
        // 반품의 회수비는 배송비 재판정 차(`refund_shipping_amount`)가 아니라 환불 원금에서
        // 빠진다(`OrderAdjustmentService` 의 `additionalCharges`) — 두 값을 헷갈리면 회수비가
        // 두 번 빠지거나 한 번도 안 빠진다.
        $refund = $this->service->createRefundRequestForCompleted($completed);

        $this->assertSame(26000.0, (float) $refund->refund_amount, '29,000 − 회수비 3,000.');
        $this->assertSame(0.0, (float) $refund->refund_shipping_amount, '주문 배송비가 무료라 재판정 차는 없다.');
    }

    /**
     * 회수비 3,000 원이 청구되는 반품을 접수 → 회수도착 → 완료까지 진행합니다.
     *
     * 환불 접수 시점(`refund_chain`) 축은 신청 단계만으로는 잴 수 없다 — 접수 여부는 완료
     * 처리에서 정해지고, '완료 뒤 접수' 는 그보다 한 단계 더 뒤다. 그래서 이 축의 칸은
     * 전부 신청부터 완료까지 관통한 뒤에 판정한다.
     *
     * @param  ClaimChargeMethodEnum|null  $method  청구 방식 (null 이면 판매자 귀책이라 청구가 0)
     * @param  bool  $createRefundRequest  완료 시 환불 접수 여부
     * @param  int|null  $quantity  신청 수량 (null 이면 전량)
     * @return array{0: Order, 1: OrderReturn} [주문, 완료된 반품]
     */
    private function completedReturn(
        ?ClaimChargeMethodEnum $method,
        bool $createRefundRequest,
        ?int $quantity = null,
    ): array {
        $policy = $this->createClaimShippingPolicy(ChargePolicyEnum::FREE, 0);
        $this->setReturnFixedFee($policy, 3000);
        $order = $this->deliveredOrder(29000, $quantity === null ? 1 : 3, $policy);

        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload(
                $order,
                $method === null ? 'defective_product' : 'changed_mind',
                quantity: $quantity,
                chargeMethod: $method,
            )),
            ReturnStatusEnum::COLLECTED
        );

        return [
            $order,
            $this->service->completeReturn($return, [], $this->completionOptions($return, false, $createRefundRequest)),
        ];
    }

    /**
     * @scenario quantity=full, charge_method=pay_on_request, refund_chain=none
     *
     * @effects return_completion_refund_is_filed_only_when_asked
     */
    public function test_full_return_charged_on_request_can_complete_without_filing_a_refund(): void
    {
        [$order, $completed] = $this->completedReturn(ClaimChargeMethodEnum::PAY_ON_REQUEST, false);

        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());
        $this->assertNull($completed->refund_id);
    }

    /**
     * @scenario quantity=full, charge_method=pay_on_request, refund_chain=at_completion
     *
     * @effects return_completion_refund_is_filed_exactly_once
     */
    public function test_full_return_charged_on_request_files_the_refund_at_completion(): void
    {
        [$order, $completed] = $this->completedReturn(ClaimChargeMethodEnum::PAY_ON_REQUEST, true);

        $this->assertSame(1, OrderRefund::where('order_id', $order->id)->count());
        $this->assertNotNull($completed->refund_id);
    }

    /**
     * @scenario quantity=full, charge_method=pay_on_request, refund_chain=after_completion
     *
     * @effects return_refund_can_be_filed_after_completion
     */
    public function test_full_return_charged_on_request_files_the_refund_after_completion(): void
    {
        [$order, $completed] = $this->completedReturn(ClaimChargeMethodEnum::PAY_ON_REQUEST, false);

        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());

        $refund = $this->service->createRefundRequestForCompleted($completed);

        $this->assertSame($refund->id, $completed->fresh()->refund_id);
        $this->assertSame(1, OrderRefund::where('order_id', $order->id)->count());
    }

    /**
     * @scenario quantity=full, charge_method=pay_on_approval, refund_chain=none
     *
     * @effects return_completion_refund_is_filed_only_when_asked
     */
    public function test_full_return_charged_on_approval_can_complete_without_filing_a_refund(): void
    {
        [$order, $completed] = $this->completedReturn(ClaimChargeMethodEnum::PAY_ON_APPROVAL, false);

        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());
        $this->assertNull($completed->refund_id);
    }

    /**
     * @scenario quantity=full, charge_method=pay_on_approval, refund_chain=at_completion
     *
     * @effects return_completion_refund_is_filed_exactly_once
     */
    public function test_full_return_charged_on_approval_files_the_refund_at_completion(): void
    {
        [$order, $completed] = $this->completedReturn(ClaimChargeMethodEnum::PAY_ON_APPROVAL, true);

        $this->assertSame(1, OrderRefund::where('order_id', $order->id)->count());
        $this->assertNotNull($completed->refund_id);
    }

    /**
     * @scenario quantity=full, charge_method=pay_on_approval, refund_chain=after_completion
     *
     * @effects return_refund_can_be_filed_after_completion
     */
    public function test_full_return_charged_on_approval_files_the_refund_after_completion(): void
    {
        [$order, $completed] = $this->completedReturn(ClaimChargeMethodEnum::PAY_ON_APPROVAL, false);

        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());

        $refund = $this->service->createRefundRequestForCompleted($completed);

        $this->assertSame($refund->id, $completed->fresh()->refund_id);
        $this->assertSame(1, OrderRefund::where('order_id', $order->id)->count());
    }

    /**
     * @scenario quantity=full, charge_method=deduct_from_refund, refund_chain=at_completion
     *
     * @effects return_deducted_fee_is_carried_into_a_later_refund_request
     */
    public function test_full_return_deducting_the_fee_files_the_refund_at_completion(): void
    {
        [$order, $completed] = $this->completedReturn(ClaimChargeMethodEnum::DEDUCT_FROM_REFUND, true);

        $refund = OrderRefund::findOrFail($completed->refund_id);

        $this->assertSame(26000.0, (float) $refund->refund_amount, '29,000 − 회수비 3,000.');
    }

    /**
     * @scenario quantity=full, charge_method=deduct_from_refund, refund_chain=after_completion
     *
     * @effects return_deducted_fee_is_carried_into_a_later_refund_request
     */
    public function test_full_return_deducting_the_fee_files_the_refund_after_completion(): void
    {
        [$order, $completed] = $this->completedReturn(ClaimChargeMethodEnum::DEDUCT_FROM_REFUND, false);

        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());

        $refund = $this->service->createRefundRequestForCompleted($completed);

        $this->assertSame(26000.0, (float) $refund->refund_amount, '미룬 접수도 회수비를 뺀다.');
    }

    /**
     * @scenario quantity=partial, charge_method=pay_on_request, refund_chain=none
     *
     * @effects return_completion_refund_is_filed_only_when_asked
     */
    public function test_partial_return_charged_on_request_can_complete_without_filing_a_refund(): void
    {
        [$order, $completed] = $this->completedReturn(ClaimChargeMethodEnum::PAY_ON_REQUEST, false, 1);

        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());
        $this->assertNull($completed->refund_id);
    }

    /**
     * @scenario quantity=partial, charge_method=pay_on_request, refund_chain=at_completion
     *
     * @effects return_completion_refund_is_filed_exactly_once
     */
    public function test_partial_return_charged_on_request_files_a_refund_for_the_returned_quantity(): void
    {
        [$order, $completed] = $this->completedReturn(ClaimChargeMethodEnum::PAY_ON_REQUEST, true, 1);

        $this->assertSame(1, OrderRefund::where('order_id', $order->id)->count());

        $refund = OrderRefund::findOrFail($completed->refund_id);

        $this->assertCount(1, $refund->refundOptions, '나뉜 행 하나만 환불에 실린다.');
    }

    /**
     * @scenario quantity=partial, charge_method=pay_on_request, refund_chain=after_completion
     *
     * @effects return_refund_can_be_filed_after_completion
     */
    public function test_partial_return_charged_on_request_files_the_refund_after_completion(): void
    {
        [$order, $completed] = $this->completedReturn(ClaimChargeMethodEnum::PAY_ON_REQUEST, false, 1);

        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());

        $refund = $this->service->createRefundRequestForCompleted($completed);

        $this->assertCount(1, $refund->refundOptions, '나뉜 행 하나만 환불에 실린다.');
    }

    /**
     * @scenario quantity=partial, charge_method=none, refund_chain=none
     *
     * @effects return_completion_refund_is_filed_only_when_asked
     */
    public function test_partial_return_without_a_charge_can_complete_without_filing_a_refund(): void
    {
        [$order, $completed] = $this->completedReturn(null, false, 1);

        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());
        $this->assertNull($completed->refund_id);
    }

    /**
     * @scenario quantity=partial, charge_method=none, refund_chain=at_completion
     *
     * @effects return_completion_refund_is_filed_exactly_once
     */
    public function test_partial_return_without_a_charge_files_a_refund_for_the_returned_quantity(): void
    {
        [$order, $completed] = $this->completedReturn(null, true, 1);

        $this->assertSame(1, OrderRefund::where('order_id', $order->id)->count());

        $refund = OrderRefund::findOrFail($completed->refund_id);

        $this->assertCount(1, $refund->refundOptions, '나뉜 행 하나만 환불에 실린다.');
    }

    /**
     * @scenario quantity=partial, charge_method=none, refund_chain=after_completion
     *
     * @effects return_refund_can_be_filed_after_completion
     */
    public function test_partial_return_without_a_charge_files_the_refund_after_completion(): void
    {
        [$order, $completed] = $this->completedReturn(null, false, 1);

        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());

        $refund = $this->service->createRefundRequestForCompleted($completed);

        $this->assertCount(1, $refund->refundOptions, '나뉜 행 하나만 환불에 실린다.');
    }

    public function test_late_refund_request_is_blocked_when_one_exists(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        $completed = $this->service->completeReturn(
            $return,
            [],
            $this->completionOptions($return, false, true)
        );

        $this->assertClaimException('claim_refund_request_exists', fn () => $this->service->createRefundRequestForCompleted($completed));
    }

    public function test_late_refund_request_requires_completed_items(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->service->requestReturn($order, $this->payload($order));

        $this->assertClaimException('claim_completion_items_invalid', fn () => $this->service->createRefundRequestForCompleted($return));
    }

    public function test_complete_fires_completed_hook_once(): void
    {
        $fired = 0;
        HookManager::addAction('sirsoft-ecommerce.claim.return.completed', function () use (&$fired) {
            $fired++;
        });

        $order = $this->deliveredOrder();
        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTED
        );

        $this->service->completeReturn($return, [], $this->completionOptions($return, false, false));

        $this->assertSame(1, $fired);
    }

    public function test_completed_return_is_excluded_from_active_claim_quantity(): void
    {
        $order = $this->deliveredOrder(29000, 2);

        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order, quantity: 1)),
            ReturnStatusEnum::COLLECTED
        );

        $this->service->completeReturn($return, [], $this->completionOptions($return, false, false));

        // 완료된 반품은 더 이상 수량을 잡지 않는다 — 잔여 1개는 다시 신청할 수 있어야 한다.
        $remaining = $order->fresh(['options'])->options
            ->firstWhere('option_status', OrderStatusEnum::DELIVERED);

        $second = $this->service->requestReturn($order->fresh(['options', 'shippings', 'payment']), [
            'items' => [['order_option_id' => $remaining->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
        ]);

        $this->assertSame(ReturnStatusEnum::REQUESTED, $second->return_status);
    }

    // ================================================================
    // 헤더 자동추종 · 다통화 금액 수정 · 처리자 보존 · 송장 1TX 회귀
    // ================================================================

    /**
     * 상품 단위 전이가 서로 한 단계 이상 벌어진 뒤 같은 단계에서 만나면, 헤더는 건너뛴 중간
     * 단계를 차례로 거쳐 그 단계까지 따라간다 (확정 2026-08-22 6차 ①).
     *
     * 한 단계만 추종하면 A 가 회수중까지 간 뒤 B 가 뒤따라 회수중이 되는 순간 헤더는
     * 승인 → 회수중 게이트에 막혀 영구히 승인에 남고, 회수 3단계 알림이 영영 나가지 않는다.
     */
    public function test_header_follows_items_through_intermediate_steps_after_staggered_progress(): void
    {
        $changed = [];
        HookManager::addAction(
            'sirsoft-ecommerce.claim.return.status_changed',
            function ($return, $from, $to) use (&$changed) {
                $changed[] = $from.'>'.$to;
            }
        );

        [$return, $itemA, $itemB] = $this->twoItemReturn();
        $return = $this->service->transition($return, ReturnStatusEnum::APPROVED);
        $fresh = fn () => $return->fresh(['returnOptions']);

        $this->service->transitionOption($fresh(), [$itemA], ReturnStatusEnum::COLLECTION_READY);
        $this->service->transitionOption($fresh(), [$itemA], ReturnStatusEnum::COLLECTING);
        $this->service->transitionOption($fresh(), [$itemB], ReturnStatusEnum::COLLECTION_READY);

        // B 가 아직 회수준비중이라 살아 있는 상품이 두 상태 — 헤더는 움직이지 않는다.
        $this->assertSame(ReturnStatusEnum::APPROVED, $return->fresh()->return_status);

        $this->service->transitionOption($fresh(), [$itemB], ReturnStatusEnum::COLLECTING);

        $this->assertSame(
            ReturnStatusEnum::COLLECTING,
            $return->fresh()->return_status,
            '살아 있는 상품 전부가 회수중인데 헤더가 따라가지 못했습니다.'
        );
        // 건너뛴 회수준비중 단계의 훅도 순서대로 발화한다 — 알림 리스너가 단계를 잃지 않는다.
        $this->assertSame(
            ['requested>approved', 'approved>collection_ready', 'collection_ready>collecting'],
            $changed
        );
        $this->assertNotNull($return->fresh()->collection_ready_at ?? $return->fresh()->approved_at);
    }

    /**
     * 관리자 금액 수정은 다통화 표기도 함께 움직인다 (확정 2026-08-22 6차 ②).
     *
     * 환율은 현재 설정이 아니라 주문 `currency_snapshot` 의 동결 환율이다 — 접수 시점과 같은 규칙.
     */
    public function test_update_amounts_moves_multi_currency_columns_with_the_order_snapshot(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setReturnFixedFee($policy, 3000);

        $order->forceFill(['currency_snapshot' => [
            'base_currency' => 'KRW',
            'order_currency' => 'KRW',
            'exchange_rate' => 1.0,
            'base_unit' => 1000,
            'exchange_rates' => [
                'KRW' => ['rate' => 1.0, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'base_unit' => 1000],
                'USD' => ['rate' => 0.75, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2, 'base_unit' => 1000],
            ],
            'snapshot_at' => '2026-08-21T00:00:00+09:00',
        ]])->save();

        $return = $this->service->requestReturn(
            $order->fresh(['options', 'shippings', 'payment']),
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST)
        );
        $this->assertSame(2.25, $return->mc_claim_shipping_fee['USD']);

        $updated = $this->service->updateAmounts($return, ['claim_shipping_fee' => 2000]);

        // 2000 × 0.75 / 1000 = 1.5 — 이진 부동소수로 정확히 표현되는 값을 골라 변환기의
        // 반올림 잔차(1.1300000000000001 류)가 아니라 "수정값을 따랐는가" 만 잰다.
        $this->assertSame(2000.0, (float) $updated->total_charge_amount);
        $this->assertSame(['KRW', 'USD'], array_keys($updated->mc_claim_shipping_fee));
        $this->assertSame(2000, $updated->mc_claim_shipping_fee['KRW']);
        $this->assertSame(1.5, $updated->mc_claim_shipping_fee['USD']);
        $this->assertSame($updated->mc_claim_shipping_fee, $updated->mc_total_charge_amount);
    }

    /**
     * 미리보기도 보조통화 총액을 낸다 — 화면이 그 줄을 세우는 재료다 (§5-4).
     *
     * 반품의 청구는 회수 배송비 하나뿐이라 접수 시 박제되는 값과 같은 출처(`ClaimShippingFee::mcFee`)를
     * 쓴다. 이 단언이 없으면 그 필드를 지워도 어떤 테스트도 red 가 되지 않고, 화면에서는 그 줄이
     * 오류 없이 사라진다 (2026-08-31 3차 검증이 남긴 구멍).
     */
    public function test_preview_reports_multi_currency_charge_matching_the_request(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setReturnFixedFee($policy, 3000);

        $order->forceFill(['currency_snapshot' => [
            'base_currency' => 'KRW',
            'order_currency' => 'KRW',
            'exchange_rate' => 1.0,
            'base_unit' => 1000,
            'exchange_rates' => [
                'KRW' => ['rate' => 1.0, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'base_unit' => 1000],
                'USD' => ['rate' => 0.75, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2, 'base_unit' => 1000],
            ],
            'snapshot_at' => '2026-08-21T00:00:00+09:00',
        ]])->save();

        $order = $order->fresh(['options', 'shippings', 'payment']);
        $payload = $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST);

        $preview = $this->service->previewReturn(
            $order,
            $payload['items'],
            'changed_mind',
            ClaimChargeMethodEnum::PAY_ON_REQUEST
        );
        $return = $this->service->requestReturn($order, $payload);

        $this->assertSame(3000, $preview['mc_total_charge_amount']['KRW']);
        $this->assertSame(2.25, $preview['mc_total_charge_amount']['USD']);
        // 예고와 기록이 같은 값이어야 한다 — 갈리면 화면이 예고한 금액과 청구가 어긋난다.
        $this->assertSame($return->mc_total_charge_amount, $preview['mc_total_charge_amount']);
    }

    /**
     * 환불금 차감 방식의 미리보기는 차감 전 금액과 차감액을 함께 낸다. (§6-3 반품 요약 3줄)
     *
     * `adjustment.refund_total` 은 회수비를 이미 뺀 뒤의 금액이라, 화면이 그것만 받으면 §6-3 이
     * 요구하는 첫 줄(환불 예정액)을 세울 근거가 없다. 화면이 되더하면 예고와 청구의 근거가 갈린다.
     *
     * @scenario claim_type=return, charge_method=deduct_from_refund, refund_branch=none
     *
     * @effects claim_return_preview_reports_refund_before_deduction, return_preview_reports_the_refund_before_the_fee_is_deducted
     */
    public function test_preview_reports_the_refund_before_the_collection_fee_is_deducted(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setReturnFixedFee($policy, 3000);
        $order = $order->fresh(['options', 'shippings', 'payment']);
        $payload = $this->payload($order);

        $preview = $this->service->previewReturn(
            $order,
            $payload['items'],
            'changed_mind',
            ClaimChargeMethodEnum::DEDUCT_FROM_REFUND
        );

        $this->assertSame(3000.0, $preview['deducted_shipping_fee']);
        $this->assertSame(
            $preview['refund_before_deduction'] - 3000.0,
            $preview['adjustment']->getTotalRefundAmount(),
            '차감 전 금액 − 차감액 = 최종 환불액 이어야 세 줄이 서로를 설명한다',
        );
    }

    /**
     * 회수비가 환불 대상 금액 전부를 먹어도 차감 전 금액은 **실제 환불 가능액**이다.
     *
     * 확정 환불액에 회수비를 되더하는 방식은 이 조합에서 실제보다 큰 금액을 내놓는다 —
     * 환불액이 0 으로 바닥쳐 있어 `0 + 회수비` 가 되기 때문이다. 세 줄의 뺄셈은 그래도
     * 맞아떨어지므로 화면만 보고는 첫 줄이 부풀었다는 것을 알 수 없다.
     *
     * @scenario claim_type=return, charge_method=deduct_from_refund, refund_branch=none
     *
     * @effects claim_return_preview_reports_refund_before_deduction
     */
    public function test_preview_reports_the_real_gross_refund_when_the_fee_exceeds_it(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(2000, 1, $policy);
        $this->setReturnFixedFee($policy, 5000);
        $order = $order->fresh(['options', 'shippings', 'payment']);
        $payload = $this->payload($order);

        $preview = $this->service->previewReturn(
            $order,
            $payload['items'],
            'changed_mind',
            ClaimChargeMethodEnum::DEDUCT_FROM_REFUND
        );

        $this->assertSame(5000.0, $preview['deducted_shipping_fee']);
        $this->assertSame(0.0, (float) $preview['adjustment']->getTotalRefundAmount());
        // 되더하는 방식이면 5,000 이 된다 — 이 주문에서 돌려받을 수 있는 돈은 2,000 뿐이다.
        $this->assertSame(
            2000.0,
            $preview['refund_before_deduction'],
            '차감 전 금액은 회수비를 되더한 값이 아니라 회수비 없이 계산한 실제 환불 가능액이다',
        );
    }

    /**
     * 별도 결제 방식은 회수비를 환불금에서 빼지 않으므로 차감액이 0 이고 두 금액이 같다. (대조군)
     *
     * 이 방식에서 차감액이 0 이 아니면 화면이 "환불 예정액"과 "최종"을 다르게 그려, 실제로는
     * 전액 환불되는데 구매자는 배송비만큼 덜 받는 것으로 읽는다.
     *
     * @scenario claim_type=return, charge_method=pay_on_request, refund_branch=none
     *
     * @effects claim_return_preview_reports_no_deduction_when_charged_separately
     */
    public function test_preview_reports_no_deduction_when_the_fee_is_charged_separately(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setReturnFixedFee($policy, 3000);
        $order = $order->fresh(['options', 'shippings', 'payment']);
        $payload = $this->payload($order);

        $preview = $this->service->previewReturn(
            $order,
            $payload['items'],
            'changed_mind',
            ClaimChargeMethodEnum::PAY_ON_REQUEST
        );

        $this->assertSame(0.0, $preview['deducted_shipping_fee']);
        $this->assertSame($preview['adjustment']->getTotalRefundAmount(), $preview['refund_before_deduction']);
    }

    /**
     * 차감 전 금액의 보조통화도 주문 스냅샷의 동결 환율로 낸다. (§5-4)
     *
     * 화면이 스스로 환산하면 예고와 청구의 근거가 갈린다.
     *
     * @scenario claim_type=return, charge_method=deduct_from_refund, refund_branch=none
     *
     * @effects claim_return_preview_multi_currency_for_refund_before_deduction
     */
    public function test_preview_reports_multi_currency_for_the_refund_before_deduction(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setReturnFixedFee($policy, 3000);

        $order->forceFill(['currency_snapshot' => [
            'base_currency' => 'KRW',
            'order_currency' => 'KRW',
            'exchange_rate' => 1.0,
            'base_unit' => 1000,
            'exchange_rates' => [
                'KRW' => ['rate' => 1.0, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'base_unit' => 1000],
                'USD' => ['rate' => 0.75, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2, 'base_unit' => 1000],
            ],
            'snapshot_at' => '2026-08-21T00:00:00+09:00',
        ]])->save();

        $order = $order->fresh(['options', 'shippings', 'payment']);
        $payload = $this->payload($order);

        $preview = $this->service->previewReturn(
            $order,
            $payload['items'],
            'changed_mind',
            ClaimChargeMethodEnum::DEDUCT_FROM_REFUND
        );

        $this->assertNotNull($preview['mc_refund_before_deduction']);
        $this->assertSame(
            $preview['refund_before_deduction'],
            (float) $preview['mc_refund_before_deduction']['KRW'],
        );
        // 환산은 스냅샷의 base_unit(1000) 을 분모로 쓴다 — 형제 줄(`mc_total_charge_amount`)과 같은 변환기다.
        $this->assertEqualsWithDelta(
            $preview['refund_before_deduction'] / 1000 * 0.75,
            (float) $preview['mc_refund_before_deduction']['USD'],
            0.01,
        );

        // 차감액 줄도 같은 규약이다(S17 트랙 A F-1). 이 값이 없으면 반품 요약 3줄 중 가운데
        // '회수 배송비 차감' 만 기준 통화 한 줄로 남아, 위아래 두 줄과 표기 규약이 갈린다.
        $this->assertNotNull($preview['mc_deducted_shipping_fee']);
        $this->assertSame(
            $preview['deducted_shipping_fee'],
            (float) $preview['mc_deducted_shipping_fee']['KRW'],
        );
        $this->assertEqualsWithDelta(
            $preview['deducted_shipping_fee'] / 1000 * 0.75,
            (float) $preview['mc_deducted_shipping_fee']['USD'],
            0.01,
        );
    }

    /** 스냅샷이 없으면 차감 전 금액의 보조통화도 null 이다 (형제 줄과 같은 규약). */
    public function test_preview_reports_null_multi_currency_for_refund_before_deduction_without_a_snapshot(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $this->setReturnFixedFee($policy, 3000);
        $order = $this->deliveredOrder(29000, 1, $policy);
        $order->forceFill(['currency_snapshot' => null])->save();
        $payload = $this->payload($order);

        $preview = $this->service->previewReturn(
            $order->fresh(['options', 'shippings', 'payment']),
            $payload['items'],
            'changed_mind',
            ClaimChargeMethodEnum::DEDUCT_FROM_REFUND
        );

        $this->assertNull($preview['mc_refund_before_deduction']);
    }

    /** 통화 스냅샷이 없는 주문은 보조통화를 낼 근거가 없으므로 null 이다 (화면이 그 줄을 세우지 않는다). */
    public function test_preview_reports_null_multi_currency_when_the_order_has_no_snapshot(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $this->setReturnFixedFee($policy, 3000);
        $order = $this->deliveredOrder(29000, 1, $policy);
        $order->forceFill(['currency_snapshot' => null])->save();
        $payload = $this->payload($order);

        $preview = $this->service->previewReturn(
            $order->fresh(['options', 'shippings', 'payment']),
            $payload['items'],
            'changed_mind'
        );

        $this->assertNull($preview['mc_total_charge_amount']);
    }

    /**
     * 다통화 스냅샷이 없는 주문은 금액 수정 후에도 mc 컬럼이 비어 있다 (대조군).
     */
    public function test_update_amounts_leaves_multi_currency_empty_without_a_snapshot(): void
    {
        $policy = $this->createClaimShippingPolicy(ChargePolicyEnum::FREE, 0);
        $this->setReturnFixedFee($policy, 3000);
        $order = $this->deliveredOrder(29000, 1, $policy);
        $order->forceFill(['currency_snapshot' => null])->save();
        $return = $this->service->requestReturn(
            $order->fresh(['options', 'shippings', 'payment']),
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST)
        );
        $this->assertNull($return->mc_claim_shipping_fee);

        $updated = $this->service->updateAmounts($return, ['claim_shipping_fee' => 1500]);

        $this->assertSame(1500.0, (float) $updated->claim_shipping_fee);
        $this->assertNull($updated->mc_claim_shipping_fee);
        $this->assertNull($updated->mc_total_charge_amount);
    }

    /**
     * 상품 단위 전이에 처리자가 없으면 신청 상품 행의 기존 처리자를 지우지 않는다 (6차 ④).
     */
    public function test_transition_option_keeps_the_existing_processor_when_none_is_given(): void
    {
        [$return, $itemA] = $this->twoItemReturn();
        $processor = (int) $return->order->user_id;

        $this->service->transitionOption($return, [$itemA], ReturnStatusEnum::APPROVED, ['processed_by' => $processor]);
        $this->assertSame($processor, OrderReturnOption::find($itemA)->processed_by);

        $this->service->transitionOption($return->fresh(['returnOptions']), [$itemA], ReturnStatusEnum::COLLECTION_READY);

        $this->assertSame($processor, OrderReturnOption::find($itemA)->processed_by, '처리자 없는 전이가 기존 처리자를 지웠습니다.');
    }

    /**
     * 완료 처리에 처리자가 없으면 신청 상품 행의 기존 처리자를 지우지 않는다 (6차 ④).
     */
    public function test_complete_keeps_the_existing_processor_when_none_is_given(): void
    {
        $order = $this->deliveredOrder();
        $processor = (int) $order->user_id;
        $return = $this->service->requestReturn($order, $this->payload($order));

        foreach ([ReturnStatusEnum::APPROVED, ReturnStatusEnum::COLLECTION_READY, ReturnStatusEnum::COLLECTING, ReturnStatusEnum::COLLECTED] as $step) {
            $return = $this->service->transition($return, $step, ['processed_by' => $processor]);
        }
        $this->assertSame($processor, $return->returnOptions->first()->processed_by);

        $completed = $this->service->completeReturn($return, [], $this->completionOptions($return, false, false));

        $this->assertSame(ReturnStatusEnum::COMPLETED, $completed->return_status);
        $this->assertSame($processor, $completed->returnOptions->first()->processed_by, '처리자 없는 완료가 기존 처리자를 지웠습니다.');
    }

    /**
     * 송장 저장과 회수중 전환은 한 묶음이다 — 전환이 실패하면 송장도 남지 않는다 (6차 ⑤).
     */
    public function test_register_tracking_does_not_persist_tracking_when_the_transition_fails(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->advanceTo(
            $this->service->requestReturn($order, $this->payload($order)),
            ReturnStatusEnum::COLLECTION_READY
        );
        $carrier = ShippingCarrier::first()
            ?? ShippingCarrier::create([
                'code' => 'test-carrier',
                'name' => ['ko' => '테스트택배', 'en' => 'Test Carrier'],
                'is_active' => true,
            ]);

        // 첫 update(송장)는 통과시키고, 두 번째 update(회수중 전이)에서 실패를 주입한다.
        $repository = Mockery::mock(app(OrderReturnRepositoryInterface::class))->makePartial();
        $repository->shouldReceive('update')->once()->ordered()->passthru();
        $repository->shouldReceive('update')->once()->ordered()->andThrow(new \RuntimeException('transition failed'));
        app()->instance(OrderReturnRepositoryInterface::class, $repository);
        $service = app(OrderReturnService::class);

        try {
            $service->registerCollectionTracking($return, $carrier->id, '1234567890');
            $this->fail('전이 실패가 전파되지 않았습니다.');
        } catch (\RuntimeException $e) {
            $this->assertSame('transition failed', $e->getMessage());
        }

        $fresh = $return->fresh();
        $this->assertNull($fresh->collection_tracking_number, '전이가 실패했는데 송장만 저장됐습니다.');
        $this->assertSame(ReturnStatusEnum::COLLECTION_READY, $fresh->return_status);
    }

    /**
     * 상품 하나만 반려하면 그 상품만 원 주문옵션이 복귀하고 나머지는 코스에 남는다 (6차 ⑧).
     */
    public function test_transition_option_rejecting_one_item_reverts_only_that_item(): void
    {
        [$return, $itemA, $itemB] = $this->twoItemReturn();

        $this->service->transitionOption($return, [$itemA], ReturnStatusEnum::REJECTED);

        $optionA = OrderReturnOption::find($itemA)->orderOption;
        $optionB = OrderReturnOption::find($itemB)->orderOption;
        $this->assertSame(OrderStatusEnum::DELIVERED, $optionA->option_status, '반려된 상품이 코스에 남아 있습니다.');
        $this->assertSame(OrderStatusEnum::RETURN_IN_PROGRESS, $optionB->option_status, '반려하지 않은 상품까지 복귀했습니다.');
        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
    }

    /**
     * 상품 일부가 헤더보다 앞서 있으면 신청서 단위 반려·철회는 거부된다 — 상품 단위로만 처리한다.
     *
     * 거부하지 않으면 `syncOptionStatuses` 는 앞선 상품을 건너뛰는데 `revertOrderOptions` 는 전 상품을
     * 되돌려, 상품 행은 승인·회수중인데 주문옵션은 코스를 벗어난 어긋남이 생긴다(교환과 같은 규칙).
     */
    public function test_header_rejection_is_refused_while_an_item_has_moved_past_the_gate(): void
    {
        [$return, $itemA, $itemB] = $this->twoItemReturn();
        $this->service->transitionOption($return, [$itemA], ReturnStatusEnum::APPROVED);

        $this->assertClaimException('claim_transition_not_allowed', fn () => $this->service->transition($return->fresh(['returnOptions']), ReturnStatusEnum::REJECTED));
        $this->assertClaimException('claim_transition_not_allowed', fn () => $this->service->withdraw($return->fresh(['returnOptions'])));
        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
        $this->assertSame(ReturnStatusEnum::APPROVED, OrderReturnOption::find($itemA)->option_status);
        $this->assertSame(OrderStatusEnum::RETURN_IN_PROGRESS, OrderReturnOption::find($itemA)->orderOption->option_status, '거부된 반려가 앞서 있는 상품의 주문옵션을 되돌렸습니다.');
        $this->assertSame(OrderStatusEnum::RETURN_IN_PROGRESS, OrderReturnOption::find($itemB)->orderOption->option_status);
    }

    /**
     * 끝난 상품(반려·철회·완료)에 같은 상태를 다시 지시하면 건너뛰고 보고한다 — 자기 전이는 게이트가
     * 언제나 허용하므로 막지 않으면 처리자·상태가 다시 쓰인다(교환과 같은 규칙).
     */
    public function test_transition_option_skips_an_item_that_is_already_finished(): void
    {
        [$return, $itemA] = $this->twoItemReturn();

        $this->service->transitionOption($return, [$itemA], ReturnStatusEnum::REJECTED);
        $rejected = OrderReturnOption::find($itemA);

        $result = $this->service->transitionOption($return->fresh(['returnOptions']), [$itemA], ReturnStatusEnum::REJECTED, ['processed_by' => 99]);

        $this->assertSame([], $result['succeeded']);
        $this->assertSame($itemA, $result['skipped'][0]['id']);
        $this->assertNull(OrderReturnOption::find($itemA)->processed_by, '건너뛴 상품에는 처리자가 덮어써지지 않는다.');
        $this->assertEquals($rejected->updated_at, OrderReturnOption::find($itemA)->updated_at, '종결 상품은 다시 쓰이지 않는다.');
    }

    /**
     * 상품 하나만 철회해도 같은 규칙이다 — 철회된 상품만 복귀 (6차 ⑧).
     */
    public function test_transition_option_withdrawing_one_item_reverts_only_that_item(): void
    {
        [$return, $itemA, $itemB] = $this->twoItemReturn();

        $result = $this->service->transitionOption($return, [$itemA], ReturnStatusEnum::WITHDRAWN);

        $this->assertSame([$itemA], $result['succeeded']);
        $this->assertSame(ReturnStatusEnum::WITHDRAWN, OrderReturnOption::find($itemA)->option_status);
        $this->assertSame(OrderStatusEnum::DELIVERED, OrderReturnOption::find($itemA)->orderOption->option_status);
        $this->assertSame(OrderStatusEnum::RETURN_IN_PROGRESS, OrderReturnOption::find($itemB)->orderOption->option_status);
        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
    }

    // ================================================================
    // 미리보기 자격 가드 (S5) / 승인 전 수정 (D13)
    // ================================================================

    public function test_preview_rejects_an_item_that_is_not_in_a_requestable_status(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 1, $this->createClaimShippingPolicy());

        $this->assertClaimException('claim_option_not_claimable', fn () => $this->service->previewReturn(
            $order,
            [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'changed_mind',
        ));
    }

    /**
     * @effects return_request_rejects_a_quantity_locked_by_another_claim
     */
    public function test_preview_rejects_a_quantity_locked_by_a_live_refund_request(): void
    {
        $order = $this->deliveredOrder(29000, 2);
        $option = $order->options->first();
        // 직접 환불신청은 원 주문옵션을 판매 상태 그대로 두므로 상태 가드가 아닌 수량 잠금이 막는다.
        $this->makeDirectRefund($order, $option, 2);

        $this->assertClaimException('claim_quantity_locked', fn () => $this->service->previewReturn(
            $order->fresh(['options']),
            [['order_option_id' => $option->id, 'quantity' => 1]],
            'changed_mind',
        ));
    }

    public function test_preview_excludes_the_return_being_edited_from_the_lock(): void
    {
        $order = $this->deliveredOrder(29000, 2);
        $return = $this->service->requestReturn($order, $this->payload($order, quantity: 2));

        $preview = $this->service->previewReturn(
            $order->fresh(['options']),
            [['order_option_id' => $order->options->first()->id, 'quantity' => 2]],
            'changed_mind',
            excludeReturnId: $return->id,
        );

        $this->assertSame(0.0, (float) $preview['total_charge_amount']);
    }

    public function test_preview_still_accepts_cancel_quantity_as_the_quantity_key(): void
    {
        $order = $this->deliveredOrder();

        $preview = $this->service->previewReturn(
            $order,
            [['order_option_id' => $order->options->first()->id, 'cancel_quantity' => 1]],
            'changed_mind',
        );

        $this->assertSame(29000.0, (float) $preview['adjustment']->getTotalRefundAmount());
    }

    /**
     * @effects return_update_request_recalculates_the_fee_when_the_reason_changes
     */
    public function test_update_request_recalculates_the_fee_when_the_reason_changes(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(policy: $policy);
        $this->setReturnFixedFee($policy, 3000);
        $return = $this->service->requestReturn($order, $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST));
        $this->assertSame(3000.0, (float) $return->total_charge_amount);

        $updated = $this->service->updateRequest($return, ['reason_code' => 'defective_product']);

        $this->assertSame(ClaimReasonFaultTypeEnum::SELLER, $updated->fault_type);
        $this->assertSame(0.0, (float) $updated->total_charge_amount);
        $this->assertNull($updated->charge_method);
    }

    public function test_update_request_requires_a_charge_method_when_the_charge_becomes_positive(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(policy: $policy);
        $this->setReturnFixedFee($policy, 3000);
        $return = $this->service->requestReturn($order, $this->payload($order, 'defective_product'));
        $this->assertNull($return->charge_method);

        $this->assertClaimException('claim_charge_method_required', fn () => $this->service->updateRequest($return, ['reason_code' => 'changed_mind']));

        $updated = $this->service->updateRequest($return->fresh(), ['reason_code' => 'changed_mind', 'charge_method' => 'pay_on_approval']);
        $this->assertSame(3000.0, (float) $updated->total_charge_amount);
        $this->assertSame(ClaimChargeMethodEnum::PAY_ON_APPROVAL, $updated->charge_method);
    }

    /**
     * @effects return_update_request_is_blocked_after_approval
     */
    public function test_update_request_is_blocked_after_approval(): void
    {
        $order = $this->deliveredOrder();
        $return = $this->service->requestReturn($order, $this->payload($order));
        $approved = $this->service->transition($return, ReturnStatusEnum::APPROVED);

        $this->assertClaimException('claim_not_editable', fn () => $this->service->updateRequest($approved, ['reason_detail' => 'x']));
    }
}
