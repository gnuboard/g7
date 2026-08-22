<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\CancellationAdjustment;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderOptionSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyClaimAddress;
use Modules\Sirsoft\Ecommerce\Services\ClaimQuantityGuard;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderAdjustmentService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\AssertsClaimExceptions;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimAdjustmentOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 교환 클레임 서비스 테스트 (S4)
 *
 * 검증 축:
 * - 신청 — 동일상품/옵션변경, 저가·동가·고가 3분기의 차액·청구액, 신규 옵션 유효성·재고, 코스 상태
 *   진입(전량/부분 분할), 수량 잠금(D15), 청구 방식 규칙, 회수지·재발송지 스냅샷(D14 3축)
 * - 전이 — ExchangeStatusEnum 13종 게이트 전 케이스, 승인 시 신규 재고 차감, 시각 기록, 상품 단위 전이
 * - 재발송 — 후속 주문옵션 생성(금액 승계·정체 교체), 배송 행 복제, 도착 처리
 * - 복귀 — 반려·철회 시 상품옵션 단위 복귀, 재고 불변
 * - 완료 — D26: 건별 원 옵션 재고 복구, 저가 교환 차액 환불신청서, 설정이 동작을 바꾸지 않음
 * - 재계산 정합 — 교환 뒤 다른 상품의 부분취소 환불액이 차액에 오염되지 않음
 */
class OrderExchangeServiceTest extends ModuleTestCase
{
    use AssertsClaimExceptions;
    use BuildsClaimAdjustmentOrders;

    protected OrderExchangeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->service = app(OrderExchangeService::class);
        $this->seedExchangeReasons();
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
     * 교환 사유 카탈로그를 시드합니다.
     */
    protected function seedExchangeReasons(): void
    {
        foreach ([
            ['size_mismatch', ClaimReasonFaultTypeEnum::CUSTOMER, true],
            ['defective_product', ClaimReasonFaultTypeEnum::SELLER, true],
            ['retired_reason', ClaimReasonFaultTypeEnum::CUSTOMER, false],
        ] as [$code, $fault, $active]) {
            ClaimReason::firstOrCreate(
                ['type' => 'exchange', 'code' => $code],
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
     * 주문 상품을 배송완료 상태로 만들고 배송지(KR)를 붙입니다.
     *
     * @param  Order  $order  주문
     * @param  string  $countryCode  배송지 국가
     * @return Order 갱신된 주문
     */
    protected function deliver(Order $order, string $countryCode = 'KR'): Order
    {
        foreach ($order->options as $option) {
            $option->forceFill([
                'option_status' => OrderStatusEnum::DELIVERED,
                'delivered_at' => now()->subDay(),
                'is_stock_deducted' => true,
            ])->save();
        }

        $order->forceFill(['order_status' => OrderStatusEnum::DELIVERED])->save();

        if (! $order->addresses()->where('address_type', 'shipping')->exists()) {
            OrderAddress::factory()->create([
                'order_id' => $order->id,
                'address_type' => 'shipping',
                'recipient_name' => '홍길동',
                'recipient_phone' => '010-1234-5678',
                'recipient_country_code' => $countryCode,
                'zipcode' => '06236',
                'address' => '서울 강남구 테헤란로 1',
                'address_detail' => '101호',
            ]);
        }

        return $order->fresh(['options', 'shippings', 'payment', 'shippingAddress']);
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
     * 결제 구성(PG 결제액 / 마일리지 사용액)을 지정한 배송완료 주문을 만듭니다.
     *
     * @param  float  $pgPaid  PG 로 낸 금액
     * @param  float  $pointsUsed  마일리지로 낸 금액
     * @return Order 배송완료 주문
     */
    protected function deliveredOrderPaidWith(float $pgPaid, float $pointsUsed): Order
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->createSingleItemClaimOrder(
            29000,
            1,
            $policy,
            orderOverrides: ['total_paid_amount' => $pgPaid, 'total_points_used_amount' => $pointsUsed],
            paymentOverrides: [
                'payment_method' => $pgPaid > 0 ? PaymentMethodEnum::CARD->value : PaymentMethodEnum::POINT->value,
                'paid_amount_local' => $pgPaid,
                'paid_amount_base' => $pgPaid,
            ],
        );

        return $this->deliver($this->attachPolicyShippings($order, $policy));
    }

    /**
     * 주문 옵션마다 배송정책이 붙은 배송 행을 만듭니다.
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
     * 교환 고정 배송비(왕복 총액)를 설정합니다.
     *
     * @param  ShippingPolicy  $policy  배송정책
     * @param  float  $fee  왕복 교환비
     */
    protected function setExchangeFixedFee(ShippingPolicy $policy, float $fee): void
    {
        $policy->countrySettings()->where('country_code', 'KR')->update([
            'claim_fee_mode' => 'fixed',
            'exchange_fixed_fee' => $fee,
        ]);
    }

    /**
     * 주문 상품과 같은 상품에 다른 가격의 옵션을 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  float  $priceAdjustment  옵션 조정액 (양수 = 더 비싼 옵션)
     * @param  int  $stock  재고
     * @return ProductOption 신규 옵션
     */
    protected function siblingOption(Order $order, float $priceAdjustment, int $stock = 100): ProductOption
    {
        $product = $order->options->first()->product;

        return ProductOptionFactory::new()->forProduct($product)->create([
            'price_adjustment' => $priceAdjustment,
            'stock_quantity' => $stock,
            'is_default' => false,
            'is_active' => true,
        ]);
    }

    /**
     * 신청 페이로드를 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  string  $reasonCode  사유 코드
     * @param  int|null  $quantity  신청 수량 (null 이면 전량)
     * @param  ClaimChargeMethodEnum|null  $chargeMethod  청구 방식
     * @param  int|null  $newProductOptionId  교환 대상 옵션 (null = 동일상품)
     * @param  array|null  $redeliveryAddress  재발송지
     * @return array 신청 페이로드
     */
    protected function payload(
        Order $order,
        string $reasonCode = 'size_mismatch',
        ?int $quantity = null,
        ?ClaimChargeMethodEnum $chargeMethod = null,
        ?int $newProductOptionId = null,
        ?array $redeliveryAddress = null,
    ): array {
        $items = [];

        foreach ($order->options as $option) {
            $items[] = [
                'order_option_id' => $option->id,
                'quantity' => $quantity ?? (int) $option->quantity,
                'new_product_option_id' => $newProductOptionId,
            ];
        }

        return [
            'items' => $items,
            'reason_code' => $reasonCode,
            'charge_method' => $chargeMethod,
            'requested_by' => $order->user_id,
            'redelivery_address' => $redeliveryAddress,
        ];
    }

    /**
     * 교환을 목표 상태까지 진행시킵니다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  ExchangeStatusEnum  $target  목표 상태
     * @return OrderExchange 진행된 신청서
     */
    protected function advanceTo(OrderExchange $exchange, ExchangeStatusEnum $target): OrderExchange
    {
        $path = [
            ExchangeStatusEnum::APPROVED,
            ExchangeStatusEnum::COLLECTION_READY,
            ExchangeStatusEnum::COLLECTING,
            ExchangeStatusEnum::COLLECTED,
            ExchangeStatusEnum::REDELIVERY_READY,
            ExchangeStatusEnum::REDELIVERING,
            ExchangeStatusEnum::REDELIVERED,
        ];

        foreach ($path as $step) {
            if ($exchange->exchange_status === $step) {
                continue;
            }

            $exchange = $this->service->transition($exchange, $step);

            if ($step === $target) {
                break;
            }
        }

        return $exchange;
    }

    /**
     * 송장 테스트용 택배사를 돌려줍니다.
     *
     * @return ShippingCarrier 택배사
     */
    protected function carrier(): ShippingCarrier
    {
        return ShippingCarrier::first()
            ?? ShippingCarrier::create([
                'code' => 'test-carrier',
                'name' => ['ko' => '테스트택배', 'en' => 'Test Carrier'],
                'is_active' => true,
            ]);
    }

    /**
     * 완료 처리 옵션을 만듭니다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  bool  $restoreStock  원 옵션 재고 복구 여부 (전건 동일 적용)
     * @param  bool  $createRefundRequest  환불신청서 접수 여부
     * @return ClaimCompletionOptions 완료 옵션
     */
    protected function completionOptions(
        OrderExchange $exchange,
        bool $restoreStock,
        bool $createRefundRequest,
    ): ClaimCompletionOptions {
        $map = [];

        foreach ($exchange->exchangeOptions as $item) {
            $map[$item->id] = $restoreStock;
        }

        return new ClaimCompletionOptions($map, $createRefundRequest);
    }

    /**
     * 배송완료된 2상품 주문으로 교환 신청서를 만듭니다.
     *
     * @return array{0: OrderExchange, 1: int, 2: int, 3: Order} [신청서, 첫 상품 행 ID, 둘째 상품 행 ID, 주문]
     */
    protected function twoItemExchange(): array
    {
        $policy = $this->createClaimShippingPolicy();
        [$productA, $optionA] = $this->createClaimProduct(10000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(20000, $policy);

        $order = $this->deliver($this->attachPolicyShippings($this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem($productA->id, $optionA->id, 1),
            new CalculationItem($productB->id, $optionB->id, 1),
        ])), $policy));

        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $itemIdFor = function (int $productOptionId) use ($order, $exchange): int {
            $orderOptionIds = $order->options
                ->where('product_option_id', $productOptionId)
                ->pluck('id')
                ->all();

            return (int) $exchange->exchangeOptions
                ->firstWhere(fn ($item) => in_array((int) $item->order_option_id, $orderOptionIds, true))
                ->id;
        };

        return [$exchange, $itemIdFor((int) $optionA->id), $itemIdFor((int) $optionB->id), $order];
    }

    /**
     * 두 상품 모두 옵션을 바꾼 교환 신청서를 재발송도착까지 진행시킵니다.
     *
     * @param  float  $adjustmentA  첫 상품(30,000) 신규 옵션 조정액
     * @param  float  $adjustmentB  둘째 상품(20,000) 신규 옵션 조정액
     * @param  ClaimChargeMethodEnum|null  $chargeMethod  청구 방식
     * @param  float  $fee  왕복 교환비 (고정, 0 이면 미설정)
     * @return array{0: OrderExchange, 1: int, 2: int, 3: Order} [신청서, 첫 상품 행 ID, 둘째 상품 행 ID, 주문]
     */
    protected function twoItemOptionChangeExchange(
        float $adjustmentA,
        float $adjustmentB,
        ?ClaimChargeMethodEnum $chargeMethod = null,
        float $fee = 0,
    ): array {
        $policy = $this->createClaimShippingPolicy();
        [$productA, $optionA] = $this->createClaimProduct(30000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(20000, $policy);

        if ($fee > 0) {
            $this->setExchangeFixedFee($policy, $fee);
        }

        $order = $this->deliver($this->attachPolicyShippings($this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem($productA->id, $optionA->id, 1),
            new CalculationItem($productB->id, $optionB->id, 1),
        ])), $policy));

        $newOptionFor = fn ($product, float $adjustment): ProductOption => ProductOptionFactory::new()->forProduct($product)->create([
            'price_adjustment' => $adjustment,
            'stock_quantity' => 100,
            'is_default' => false,
            'is_active' => true,
        ]);
        $newA = $newOptionFor($productA, $adjustmentA);
        $newB = $newOptionFor($productB, $adjustmentB);

        $items = [];

        foreach ($order->options as $option) {
            $items[] = [
                'order_option_id' => $option->id,
                'quantity' => (int) $option->quantity,
                'new_product_option_id' => (int) $option->product_option_id === (int) $optionA->id ? $newA->id : $newB->id,
            ];
        }

        $exchange = $this->advanceTo($this->service->requestExchange($order, [
            'items' => $items,
            'reason_code' => 'size_mismatch',
            'charge_method' => $chargeMethod,
            'requested_by' => $order->user_id,
        ]), ExchangeStatusEnum::REDELIVERED);

        $itemIdFor = function (int $productOptionId) use ($order, $exchange): int {
            $orderOptionIds = $order->options
                ->where('product_option_id', $productOptionId)
                ->pluck('id')
                ->all();

            return (int) $exchange->exchangeOptions
                ->firstWhere(fn ($item) => in_array((int) $item->order_option_id, $orderOptionIds, true))
                ->id;
        };

        return [$exchange, $itemIdFor((int) $optionA->id), $itemIdFor((int) $optionB->id), $order];
    }

    /**
     * 상품 3건을 옵션 변경으로 신청해 재발송도착까지 진행시킵니다. (부호 혼합 안분 검증용)
     *
     * @param  float  $adjustmentA  1번 상품의 신규 옵션 조정액
     * @param  float  $adjustmentB  2번 상품의 신규 옵션 조정액
     * @param  float  $adjustmentC  3번 상품의 신규 옵션 조정액
     * @return array{0: OrderExchange, 1: Order} [신청서, 주문]
     */
    protected function threeItemMixedExchange(float $adjustmentA, float $adjustmentB, float $adjustmentC): array
    {
        $policy = $this->createClaimShippingPolicy();
        $products = [];
        $items = [];

        foreach ([30000, 20000, 25000] as $price) {
            $products[] = $this->createClaimProduct($price, $policy);
        }

        $order = $this->deliver($this->attachPolicyShippings($this->createClaimOrder(new CalculationInput(items: array_map(
            fn (array $entry) => new CalculationItem($entry[0]->id, $entry[1]->id, 1),
            $products,
        ))), $policy));

        $newByOriginal = [];

        foreach ($products as $index => [$product, $option]) {
            $newByOriginal[(int) $option->id] = ProductOptionFactory::new()->forProduct($product)->create([
                'price_adjustment' => [$adjustmentA, $adjustmentB, $adjustmentC][$index],
                'stock_quantity' => 100,
                'is_default' => false,
                'is_active' => true,
            ])->id;
        }

        foreach ($order->options as $option) {
            $items[] = [
                'order_option_id' => $option->id,
                'quantity' => (int) $option->quantity,
                'new_product_option_id' => $newByOriginal[(int) $option->product_option_id],
            ];
        }

        $exchange = $this->advanceTo($this->service->requestExchange($order, [
            'items' => $items,
            'reason_code' => 'size_mismatch',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'requested_by' => $order->user_id,
        ]), ExchangeStatusEnum::REDELIVERED);

        return [$exchange, $order];
    }

    /**
     * 재발송이 끝난(재발송도착) 교환과 그 후속 주문옵션을 돌려줍니다.
     *
     * @param  float  $priceAdjustment  신규 옵션 조정액
     * @return array{0: OrderExchange, 1: Order, 2: OrderOption} [신청서, 주문, 후속 주문옵션]
     */
    protected function redeliveredExchange(float $priceAdjustment = 0): array
    {
        $order = $this->deliveredOrder();
        $newOption = $priceAdjustment !== 0.0 ? $this->siblingOption($order, $priceAdjustment) : null;

        $exchange = $this->advanceTo(
            $this->service->requestExchange($order, $this->payload(
                $order,
                chargeMethod: $priceAdjustment > 0 ? ClaimChargeMethodEnum::PAY_ON_REQUEST : null,
                newProductOptionId: $newOption?->id,
            )),
            ExchangeStatusEnum::REDELIVERED
        );

        $successor = OrderOption::find($exchange->exchangeOptions->first()->new_order_option_id);

        return [$exchange, $order, $successor];
    }

    // ================================================================
    // 신청 — 기본
    // ================================================================

    public function test_request_creates_exchange_with_number_and_requested_status(): void
    {
        $order = $this->deliveredOrder();

        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $this->assertStringStartsWith('EX', $exchange->exchange_number);
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->exchange_status);
        $this->assertSame($order->id, $exchange->order_id);
        $this->assertCount(1, $exchange->exchangeOptions);
    }

    public function test_request_snapshots_reason_and_fault_type(): void
    {
        $order = $this->deliveredOrder();

        $exchange = $this->service->requestExchange($order, $this->payload($order, 'defective_product'));

        $this->assertSame('defective_product', $exchange->reason_code);
        $this->assertSame(ClaimReasonFaultTypeEnum::SELLER, $exchange->fault_type);
    }

    public function test_request_moves_order_option_into_exchange_in_progress(): void
    {
        $order = $this->deliveredOrder();

        $this->service->requestExchange($order, $this->payload($order));

        $this->assertSame(OrderStatusEnum::EXCHANGE_IN_PROGRESS, $order->options->first()->fresh()->option_status);
    }

    public function test_request_records_original_option_status_for_revert(): void
    {
        $order = $this->deliveredOrder();

        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $this->assertSame(OrderStatusEnum::DELIVERED, $exchange->exchangeOptions->first()->original_option_status);
    }

    public function test_request_splits_order_option_for_partial_quantity(): void
    {
        $order = $this->deliveredOrder(29000, 3);

        $exchange = $this->service->requestExchange($order, $this->payload($order, quantity: 1));

        $order = $order->fresh(['options']);
        $remaining = $order->options->firstWhere('option_status', OrderStatusEnum::DELIVERED);
        $inProgress = $order->options->firstWhere('option_status', OrderStatusEnum::EXCHANGE_IN_PROGRESS);

        $this->assertSame(2, (int) $remaining->quantity);
        $this->assertSame(1, (int) $inProgress->quantity);
        // 신청 상품 행은 코스 상태를 실제로 가진 행을 가리켜야 한다.
        $this->assertSame($inProgress->id, $exchange->exchangeOptions->first()->order_option_id);
        $this->assertSame(3, (int) $exchange->exchangeOptions->first()->original_quantity);
    }

    public function test_request_does_not_restore_stock_on_acceptance(): void
    {
        $order = $this->deliveredOrder();
        $productOptionId = $order->options->first()->product_option_id;
        $before = (int) ProductOption::find($productOptionId)->stock_quantity;

        $this->service->requestExchange($order, $this->payload($order));

        $this->assertSame($before, (int) ProductOption::find($productOptionId)->stock_quantity);
        $this->assertTrue((bool) $order->options->first()->fresh()->is_stock_deducted);
    }

    public function test_request_does_not_merge_course_rows_of_two_exchanges(): void
    {
        $order = $this->deliveredOrder(29000, 3);

        $first = $this->service->requestExchange($order, $this->payload($order, quantity: 1));
        $second = $this->service->requestExchange($order->fresh(['options', 'shippings', 'payment']), [
            'items' => [[
                'order_option_id' => $order->fresh(['options'])->options
                    ->firstWhere('option_status', OrderStatusEnum::DELIVERED)->id,
                'quantity' => 1,
            ]],
            'reason_code' => 'size_mismatch',
        ]);

        $this->assertNotNull($first->exchangeOptions->first()->fresh());
        $this->assertNotNull($second->exchangeOptions->first()->fresh());
        $this->assertNotSame(
            $first->exchangeOptions->first()->order_option_id,
            $second->exchangeOptions->first()->order_option_id
        );
        $this->assertSame(
            2,
            $order->fresh(['options'])->options->where('option_status', OrderStatusEnum::EXCHANGE_IN_PROGRESS)->count()
        );
    }

    public function test_request_does_not_change_order_status(): void
    {
        $order = $this->deliveredOrder();

        $this->service->requestExchange($order, $this->payload($order));

        $this->assertSame(OrderStatusEnum::DELIVERED, $order->fresh()->order_status);
    }

    public function test_guest_request_has_no_requester(): void
    {
        $order = $this->deliveredOrder();
        $payload = $this->payload($order);
        $payload['requested_by'] = null;

        $exchange = $this->service->requestExchange($order, $payload);

        $this->assertNull($exchange->requested_by);
        $this->assertTrue($exchange->isGuestRequest());
    }

    // ================================================================
    // 신청 — 자격 / 수량 잠금
    // ================================================================

    public function test_request_rejects_option_not_in_requestable_status(): void
    {
        $order = $this->createSingleItemClaimOrder();

        $this->assertClaimException('claim_option_not_claimable', fn () => $this->service->requestExchange($order, $this->payload($order)));
    }

    public function test_request_rejects_quantity_over_order_quantity(): void
    {
        $order = $this->deliveredOrder();

        $this->assertClaimException('claim_quantity_invalid', fn () => $this->service->requestExchange($order, $this->payload($order, quantity: 2)));
    }

    public function test_request_rejects_empty_items(): void
    {
        $order = $this->deliveredOrder();

        $this->assertClaimException('claim_items_required', fn () => $this->service->requestExchange($order, [
            'items' => [],
            'reason_code' => 'size_mismatch',
        ]));
    }

    public function test_request_rejects_unknown_option(): void
    {
        $order = $this->deliveredOrder();

        $this->assertClaimException('claim_option_not_found', fn () => $this->service->requestExchange($order, [
            'items' => [['order_option_id' => 999999, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
        ]));
    }

    public function test_request_rejects_inactive_and_unknown_reason(): void
    {
        $order = $this->deliveredOrder();

        $this->assertClaimException('claim_reason_unavailable', fn () => $this->service->requestExchange($order, $this->payload($order, 'retired_reason')));
        $this->assertClaimException('claim_reason_unavailable', fn () => $this->service->requestExchange($order, $this->payload($order, 'nope')));
    }

    public function test_request_rejects_quantity_locked_by_another_claim(): void
    {
        $order = $this->deliveredOrder(29000, 3);
        $this->service->requestExchange($order, $this->payload($order, quantity: 2));

        $remaining = $order->fresh(['options'])->options->firstWhere('option_status', OrderStatusEnum::DELIVERED);

        $this->assertClaimException('claim_quantity_invalid', fn () => $this->service->requestExchange(
            $order->fresh(['options', 'shippings', 'payment']),
            ['items' => [['order_option_id' => $remaining->id, 'quantity' => 2]], 'reason_code' => 'size_mismatch']
        ));
    }

    public function test_request_rejects_after_request_period(): void
    {
        $order = $this->deliveredOrder();
        app(EcommerceSettingsService::class)->setSetting('claim.request_period_days', 1);
        $order->options->first()->forceFill(['delivered_at' => now()->subDays(3)])->save();

        $this->assertClaimException('claim_request_period_expired', fn () => $this->service->requestExchange(
            $order->fresh(['options', 'shippings', 'payment']),
            $this->payload($order)
        ));
    }

    // ================================================================
    // 신청 — 옵션 변경 / 차액 3분기 (저가·동가·고가)
    // ================================================================

    public function test_request_defaults_to_same_product_exchange_with_zero_difference(): void
    {
        $order = $this->deliveredOrder();

        $exchange = $this->service->requestExchange($order, $this->payload($order));
        $item = $exchange->exchangeOptions->first();

        $this->assertNull($item->new_product_option_id);
        $this->assertNull($item->new_option_snapshot);
        $this->assertSame(29000.0, (float) $item->new_unit_price);
        $this->assertSame(0.0, (float) $item->price_difference);
        $this->assertSame(0.0, (float) $exchange->additional_product_amount);
        $this->assertSame(0.0, (float) $exchange->refund_product_amount);
        $this->assertSame(0.0, (float) $exchange->total_charge_amount);
        $this->assertNull($exchange->charge_method);
    }

    public function test_request_treats_the_same_option_id_as_same_product_exchange(): void
    {
        $order = $this->deliveredOrder();

        $exchange = $this->service->requestExchange($order, $this->payload(
            $order,
            newProductOptionId: $order->options->first()->product_option_id,
        ));

        $this->assertNull($exchange->exchangeOptions->first()->new_product_option_id);
        $this->assertSame(0.0, (float) $exchange->exchangeOptions->first()->price_difference);
    }

    public function test_request_with_higher_priced_option_charges_the_difference(): void
    {
        $order = $this->deliveredOrder(29000, 2);
        $newOption = $this->siblingOption($order, 3000);

        $exchange = $this->service->requestExchange($order, $this->payload(
            $order,
            chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST,
            newProductOptionId: $newOption->id,
        ));
        $item = $exchange->exchangeOptions->first();

        $this->assertSame($newOption->id, $item->new_product_option_id);
        $this->assertSame(32000.0, (float) $item->new_unit_price);
        $this->assertSame(6000.0, (float) $item->price_difference, '차액은 (신규 − 원) × 수량이다.');
        $this->assertSame($newOption->id, $item->new_option_snapshot['id']);
        $this->assertTrue($item->hasAdditionalCharge());

        $this->assertSame(6000.0, (float) $exchange->additional_product_amount);
        $this->assertSame(0.0, (float) $exchange->refund_product_amount);
        $this->assertSame(6000.0, (float) $exchange->total_charge_amount);
        $this->assertSame(ClaimChargeMethodEnum::PAY_ON_REQUEST, $exchange->charge_method);
        $this->assertTrue($exchange->requiresAdditionalPayment());
    }

    public function test_request_with_lower_priced_option_records_a_refundable_difference(): void
    {
        $order = $this->deliveredOrder(29000, 1);
        $newOption = $this->siblingOption($order, -4000);

        $exchange = $this->service->requestExchange($order, $this->payload($order, newProductOptionId: $newOption->id));
        $item = $exchange->exchangeOptions->first();

        $this->assertSame(-4000.0, (float) $item->price_difference, '저가 교환의 차액은 음수로 보존된다 (D4).');
        $this->assertTrue($item->hasRefundableDifference());
        $this->assertSame(0.0, (float) $exchange->additional_product_amount);
        $this->assertSame(4000.0, (float) $exchange->refund_product_amount, '선점 집계(D19 ③)가 읽는 저장값이다.');
        $this->assertSame(0.0, (float) $exchange->total_charge_amount);
    }

    public function test_request_nets_mixed_differences_across_items(): void
    {
        $policy = $this->createClaimShippingPolicy();
        [$productA, $optionA] = $this->createClaimProduct(10000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(20000, $policy);
        $order = $this->deliver($this->attachPolicyShippings($this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem($productA->id, $optionA->id, 1),
            new CalculationItem($productB->id, $optionB->id, 1),
        ])), $policy));

        $higherA = ProductOptionFactory::new()->forProduct($productA)->create(['price_adjustment' => 5000, 'stock_quantity' => 10, 'is_active' => true]);
        $lowerB = ProductOptionFactory::new()->forProduct($productB)->create(['price_adjustment' => -2000, 'stock_quantity' => 10, 'is_active' => true]);

        $items = [];
        foreach ($order->options as $option) {
            $items[] = [
                'order_option_id' => $option->id,
                'quantity' => 1,
                'new_product_option_id' => (int) $option->product_option_id === (int) $optionA->id ? $higherA->id : $lowerB->id,
            ];
        }

        $exchange = $this->service->requestExchange($order, [
            'items' => $items,
            'reason_code' => 'size_mismatch',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
        ]);

        // 상품별 내역은 각각 남기되, 청구는 합계로 상계한다 (D29).
        $this->assertEqualsCanonicalizing(
            [5000.0, -2000.0],
            $exchange->exchangeOptions->map(fn ($i) => (float) $i->price_difference)->all()
        );
        $this->assertSame(3000.0, (float) $exchange->additional_product_amount);
        $this->assertSame(0.0, (float) $exchange->refund_product_amount);
        $this->assertSame(3000.0, (float) $exchange->total_charge_amount);
    }

    public function test_request_rejects_an_option_of_another_product(): void
    {
        $order = $this->deliveredOrder();
        [, $foreignOption] = $this->createClaimProduct(15000);

        $this->assertClaimException('claim_new_option_invalid', fn () => $this->service->requestExchange(
            $order,
            $this->payload($order, newProductOptionId: $foreignOption->id)
        ));
    }

    public function test_request_rejects_an_inactive_or_unknown_option(): void
    {
        $order = $this->deliveredOrder();
        $inactive = $this->siblingOption($order, 0);
        $inactive->forceFill(['is_active' => false])->save();

        $this->assertClaimException('claim_new_option_invalid', fn () => $this->service->requestExchange($order, $this->payload($order, newProductOptionId: $inactive->id)));
        $this->assertClaimException('claim_new_option_invalid', fn () => $this->service->requestExchange($order, $this->payload($order, newProductOptionId: 999999)));
    }

    public function test_request_rejects_when_the_new_option_has_no_stock(): void
    {
        $order = $this->deliveredOrder(29000, 2);
        $scarce = $this->siblingOption($order, 1000, stock: 1);

        $this->assertClaimException('claim_new_option_out_of_stock', fn () => $this->service->requestExchange(
            $order,
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST, newProductOptionId: $scarce->id)
        ));
        $this->assertSame(OrderStatusEnum::DELIVERED, $order->options->first()->fresh()->option_status, '거절된 신청은 코스 상태를 남기지 않는다.');
    }

    public function test_request_keeps_custom_option_inputs_in_the_snapshot(): void
    {
        $order = $this->deliveredOrder();
        $payload = $this->payload($order);
        $payload['items'][0]['custom_options'] = ['engraving' => 'G7'];

        $exchange = $this->service->requestExchange($order, $payload);

        $this->assertSame(['engraving' => 'G7'], $exchange->exchangeOptions->first()->new_option_snapshot['custom_options']);
    }

    // ================================================================
    // 신청 — 배송비 / 청구 방식
    // ================================================================

    public function test_request_freezes_round_trip_fee_and_total_charge(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setExchangeFixedFee($policy, 6000);
        $newOption = $this->siblingOption($order, 3000);

        $exchange = $this->service->requestExchange($order, $this->payload(
            $order,
            chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST,
            newProductOptionId: $newOption->id,
        ));

        // 승인 시안 §6-3: 왕복배송비 6,000 + 옵션 차액 3,000 = 총 청구액 9,000.
        $this->assertSame(6000.0, (float) $exchange->claim_shipping_fee);
        $this->assertSame(3000.0, (float) $exchange->additional_product_amount);
        $this->assertSame(9000.0, (float) $exchange->total_charge_amount);
        $this->assertSame('fixed', $exchange->shipping_fee_mode->value);
        $this->assertSame(6000, $exchange->shipping_fee_snapshot['fee']);
    }

    public function test_request_charges_only_the_difference_for_seller_fault(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setExchangeFixedFee($policy, 6000);
        $newOption = $this->siblingOption($order, 3000);

        $exchange = $this->service->requestExchange($order, $this->payload(
            $order,
            'defective_product',
            chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST,
            newProductOptionId: $newOption->id,
        ));

        $this->assertSame(0.0, (float) $exchange->claim_shipping_fee);
        $this->assertSame(3000.0, (float) $exchange->total_charge_amount);
    }

    public function test_request_requires_charge_method_when_total_charge_is_positive(): void
    {
        $order = $this->deliveredOrder();
        $newOption = $this->siblingOption($order, 3000);

        $this->assertClaimException('claim_charge_method_required', fn () => $this->service->requestExchange(
            $order,
            $this->payload($order, newProductOptionId: $newOption->id)
        ));
    }

    public function test_request_nulls_charge_method_when_total_charge_is_zero(): void
    {
        $order = $this->deliveredOrder();

        $exchange = $this->service->requestExchange($order, $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST));

        $this->assertNull($exchange->charge_method);
        $this->assertFalse($exchange->requiresAdditionalPayment());
    }

    public function test_request_rejects_charge_method_not_allowed_by_settings(): void
    {
        $order = $this->deliveredOrder();
        $newOption = $this->siblingOption($order, 3000);
        app(EcommerceSettingsService::class)->setSetting('claim.allowed_charge_methods', [ClaimChargeMethodEnum::PAY_ON_APPROVAL->value]);

        $this->assertClaimException('claim_charge_method_not_allowed', fn () => $this->service->requestExchange(
            $order,
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST, newProductOptionId: $newOption->id)
        ));
    }

    public function test_request_rejects_deduct_from_refund_for_a_higher_priced_exchange(): void
    {
        $order = $this->deliveredOrder();
        $newOption = $this->siblingOption($order, 3000);

        // 고가 교환은 환불이 발생하지 않아 차감할 금액이 없다.
        $this->assertClaimException('claim_charge_method_not_allowed', fn () => $this->service->requestExchange(
            $order,
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND, newProductOptionId: $newOption->id)
        ));
    }

    public function test_request_accepts_deduct_from_refund_when_the_refundable_difference_covers_the_fee(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setExchangeFixedFee($policy, 3000);
        $newOption = $this->siblingOption($order, -10000);

        $exchange = $this->service->requestExchange($order, $this->payload(
            $order,
            chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            newProductOptionId: $newOption->id,
        ));

        $this->assertSame(ClaimChargeMethodEnum::DEDUCT_FROM_REFUND, $exchange->charge_method);
        $this->assertSame(3000.0, (float) $exchange->total_charge_amount);
        $this->assertSame(10000.0, (float) $exchange->refund_product_amount);
        $this->assertFalse($exchange->requiresAdditionalPayment(), '환불금 차감은 별도 결제 대상이 아니다.');
    }

    public function test_request_rejects_deduct_from_refund_when_the_fee_exceeds_the_refundable_difference(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setExchangeFixedFee($policy, 6000);
        $newOption = $this->siblingOption($order, -4000);

        $this->assertClaimException('claim_charge_method_not_allowed', fn () => $this->service->requestExchange(
            $order,
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND, newProductOptionId: $newOption->id)
        ));
    }

    public function test_request_records_multi_currency_amounts_from_the_order_snapshot(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setExchangeFixedFee($policy, 6000);
        $newOption = $this->siblingOption($order, 3000);

        $order->forceFill(['currency_snapshot' => [
            'base_currency' => 'KRW',
            'order_currency' => 'KRW',
            'exchange_rate' => 1.0,
            'base_unit' => 1000,
            'exchange_rates' => [
                'KRW' => ['rate' => 1.0, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'base_unit' => 1000],
                'USD' => ['rate' => 0.75, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2, 'base_unit' => 1000],
            ],
            'snapshot_at' => '2026-08-22T00:00:00+09:00',
        ]])->save();

        $exchange = $this->service->requestExchange(
            $order->fresh(['options', 'shippings', 'payment', 'shippingAddress']),
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST, newProductOptionId: $newOption->id)
        );

        $this->assertSame('KRW', $exchange->currency_code);
        $this->assertSame(6000, $exchange->mc_claim_shipping_fee['KRW']);
        $this->assertSame(4.5, $exchange->mc_claim_shipping_fee['USD']);
        $this->assertSame(9000, $exchange->mc_total_charge_amount['KRW']);
        $this->assertSame(6.75, $exchange->mc_total_charge_amount['USD']);
        $this->assertSame(2.25, $exchange->exchangeOptions->first()->mc_price_difference['USD']);
    }

    // ================================================================
    // 신청 — 회수지 / 재발송지 (D14 3축)
    // ================================================================

    public function test_request_snapshots_return_address_from_the_exchange_claim_address(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);

        ShippingPolicyClaimAddress::factory()->create([
            'shipping_policy_id' => $policy->id,
            'claim_type' => 'exchange',
            'recipient_name' => '교환센터',
            'zipcode' => '12345',
        ]);
        ShippingPolicyClaimAddress::factory()->create([
            'shipping_policy_id' => $policy->id,
            'claim_type' => 'return',
            'recipient_name' => '반품센터',
        ]);

        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $this->assertSame('교환센터', $exchange->return_address_snapshot['recipient_name']);
        $this->assertSame($policy->id, $exchange->return_address_snapshot['shipping_policy_id']);
    }

    public function test_request_defaults_redelivery_address_to_the_order_shipping_address(): void
    {
        $order = $this->deliveredOrder();

        $exchange = $this->service->requestExchange($order, $this->payload($order));
        $snapshot = $exchange->redelivery_address_snapshot;

        $this->assertSame('06236', $snapshot['zipcode']);
        $this->assertSame('KR', $snapshot['country_code']);
        $this->assertSame('홍길동', $snapshot['recipient_name']);
        $this->assertFalse($snapshot['changed_by_user']);
        $this->assertFalse($exchange->shipping_fee_snapshot['redelivery_changed']);
    }

    /**
     * D14 ⓐ — 재발송지가 실제로 배송비 산정기에 전달되는지.
     *
     * 우편번호가 바뀌면 산정 근거의 `redelivery_changed` 가 참이 된다. 전달하지 않으면 산정기가
     * 원 배송지로 두 구간을 같게 보고 조용히 "변경 안 함" 으로 처리한다.
     */
    public function test_request_passes_the_redelivery_address_to_the_fee_calculation(): void
    {
        $order = $this->deliveredOrder();

        $exchange = $this->service->requestExchange($order, $this->payload($order, redeliveryAddress: [
            'zipcode' => '63000',
            'address1' => '제주시 첨단로 242',
        ]));

        $this->assertTrue($exchange->shipping_fee_snapshot['redelivery_changed']);
        $this->assertSame('63000', $exchange->redelivery_address_snapshot['zipcode']);
        $this->assertSame('제주시 첨단로 242', $exchange->redelivery_address_snapshot['address1']);
        $this->assertSame('홍길동', $exchange->redelivery_address_snapshot['recipient_name'], '비운 필드는 주문 배송지를 유지한다.');
        $this->assertTrue($exchange->redelivery_address_snapshot['changed_by_user']);
    }

    /**
     * D14 ⓑ — 값이 같아도 고객이 손댔다는 신호는 그대로 저장된다.
     */
    public function test_request_stores_changed_by_user_even_when_the_address_is_unchanged(): void
    {
        $order = $this->deliveredOrder();

        $exchange = $this->service->requestExchange($order, $this->payload($order, redeliveryAddress: [
            'zipcode' => '06236',
            'changed_by_user' => true,
        ]));

        $this->assertTrue($exchange->redelivery_address_snapshot['changed_by_user']);
        $this->assertFalse($exchange->shipping_fee_snapshot['redelivery_changed'], '배송비 근거의 변경 여부는 별개 축이다.');
    }

    /**
     * D14 ⓒ — 국가 변경은 서비스도 막는다 (교환 해외배송 미지원).
     */
    public function test_request_rejects_a_redelivery_address_in_another_country(): void
    {
        $order = $this->deliveredOrder();

        $this->assertClaimException('claim_redelivery_country_change_not_allowed', fn () => $this->service->requestExchange(
            $order,
            $this->payload($order, redeliveryAddress: ['country_code' => 'jp', 'zipcode' => '100-0001'])
        ));
        $this->assertSame(OrderStatusEnum::DELIVERED, $order->options->first()->fresh()->option_status);
    }

    public function test_request_accepts_the_same_country_in_any_letter_case(): void
    {
        $order = $this->deliveredOrder();

        $exchange = $this->service->requestExchange($order, $this->payload($order, redeliveryAddress: ['country_code' => 'kr']));

        $this->assertSame('KR', $exchange->redelivery_address_snapshot['country_code']);
    }

    // ================================================================
    // 신청 — 훅 / 자동 승인
    // ================================================================

    public function test_request_fires_requested_hook(): void
    {
        $fired = 0;
        HookManager::addAction('sirsoft-ecommerce.claim.exchange.requested', function () use (&$fired) {
            $fired++;
        });

        $order = $this->deliveredOrder();
        $this->service->requestExchange($order, $this->payload($order));

        $this->assertSame(1, $fired);
    }

    public function test_request_auto_approves_zero_charge_and_deducts_new_stock_when_the_setting_is_on(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.auto_approve_zero_charge', true);
        $order = $this->deliveredOrder();
        $productOptionId = $order->options->first()->product_option_id;
        $before = (int) ProductOption::find($productOptionId)->stock_quantity;

        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $this->assertSame(ExchangeStatusEnum::APPROVED, $exchange->exchange_status);
        $this->assertNotNull($exchange->approved_at);
        // 동일상품 재발송은 원 옵션의 재고를 다시 점유한다.
        $this->assertSame($before - 1, (int) ProductOption::find($productOptionId)->stock_quantity);
        $this->assertTrue((bool) $exchange->exchangeOptions->first()->is_new_stock_deducted);
    }

    public function test_request_stays_requested_when_auto_approve_is_off_or_a_charge_remains(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.auto_approve_zero_charge', true);
        $order = $this->deliveredOrder();
        $newOption = $this->siblingOption($order, 3000);

        $charged = $this->service->requestExchange($order, $this->payload(
            $order,
            chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST,
            newProductOptionId: $newOption->id,
        ));
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $charged->exchange_status);

        app(EcommerceSettingsService::class)->setSetting('claim.auto_approve_zero_charge', false);
        $other = $this->deliveredOrder();
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $this->service->requestExchange($other, $this->payload($other))->exchange_status);
    }

    /**
     * 자동 승인의 재고 차감이 실패하면 자동 승인만 건너뛰고 '교환신청' 으로 접수를 마친다.
     *
     * 접수 훅은 신청 커밋 뒤·자동 승인 전에 발화하므로, 그 틈에 다른 주문이 마지막 재고를 가져간
     * 상황을 훅으로 재현한다. 예외가 그대로 올라오면 신청서는 남아 있는데 고객은 실패 응답을 받는다.
     */
    public function test_request_keeps_the_requested_state_when_auto_approval_loses_the_last_stock(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.auto_approve_zero_charge', true);
        $order = $this->deliveredOrder();
        $productOptionId = $order->options->first()->product_option_id;
        HookManager::addAction('sirsoft-ecommerce.claim.exchange.requested', function () use ($productOptionId) {
            ProductOption::where('id', $productOptionId)->update(['stock_quantity' => 0]);
        });

        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->exchange_status);
        $this->assertNull($exchange->approved_at);
        $this->assertFalse((bool) $exchange->exchangeOptions->first()->is_new_stock_deducted);
        $this->assertSame(0, (int) ProductOption::find($productOptionId)->stock_quantity, '실패한 승인이 재고를 건드리면 안 된다.');
    }

    // ================================================================
    // 미리보기
    // ================================================================

    public function test_preview_matches_requested_amounts(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setExchangeFixedFee($policy, 6000);
        $newOption = $this->siblingOption($order, 3000);
        $payload = $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST, newProductOptionId: $newOption->id);

        $preview = $this->service->previewExchange($order, $payload['items'], 'size_mismatch', ClaimChargeMethodEnum::PAY_ON_REQUEST);
        $exchange = $this->service->requestExchange($order, $payload);

        $this->assertSame((float) $exchange->total_charge_amount, $preview['total_charge_amount']);
        $this->assertSame((float) $exchange->additional_product_amount, $preview['additional_product_amount']);
        $this->assertSame($exchange->shipping_fee_snapshot['fee'], $preview['shipping_fee']->fee);
        $this->assertTrue($preview['requires_payment']);
        $this->assertSame(3000.0, $preview['items'][0]['price_difference']);
        $this->assertTrue($preview['items'][0]['is_option_change']);
    }

    public function test_preview_does_not_write_any_record(): void
    {
        $order = $this->deliveredOrder();
        $payload = $this->payload($order);

        $this->service->previewExchange($order, $payload['items'], 'size_mismatch');

        $this->assertSame(0, OrderExchange::count());
        $this->assertSame(OrderStatusEnum::DELIVERED, $order->options->first()->fresh()->option_status);
    }

    public function test_preview_lower_price_exchange_reports_a_refund(): void
    {
        $order = $this->deliveredOrder();
        $newOption = $this->siblingOption($order, -4000);
        $payload = $this->payload($order, newProductOptionId: $newOption->id);

        $preview = $this->service->previewExchange($order, $payload['items'], 'size_mismatch');

        $this->assertSame(4000.0, $preview['refund_product_amount']);
        $this->assertSame(0.0, $preview['total_charge_amount']);
        $this->assertSame(4000.0, $preview['adjustment']->getTotalRefundAmount());
    }

    /**
     * D14 ⓐ — 미리보기도 신청과 같은 재발송지를 배송비 산정기에 넘긴다.
     *
     * 미리보기는 입력 원본을, 신청은 주문 배송지와 병합한 스냅샷을 넘기므로 두 경로의 산정
     * 근거가 갈리면 고객이 본 배송비와 신청서에 기록된 배송비가 달라진다.
     */
    public function test_preview_passes_the_redelivery_address_like_the_request(): void
    {
        $order = $this->deliveredOrder();
        $redelivery = ['zipcode' => '63000', 'address1' => '제주시 첨단로 242'];
        $payload = $this->payload($order, redeliveryAddress: $redelivery);

        $preview = $this->service->previewExchange(
            $order,
            $payload['items'],
            'size_mismatch',
            null,
            RefundPriorityEnum::PG_FIRST,
            $redelivery,
        );
        $exchange = $this->service->requestExchange($order, $payload);

        $previewSnapshot = $preview['shipping_fee']->toArray();

        $this->assertTrue($previewSnapshot['redelivery_changed'], '미리보기도 재발송지 변경을 산정기에 알린다.');
        $this->assertSame($exchange->shipping_fee_snapshot['redelivery_changed'], $previewSnapshot['redelivery_changed']);
        $this->assertSame($exchange->shipping_fee_snapshot['fee'], $previewSnapshot['fee']);
        $this->assertSame((float) $exchange->total_charge_amount, $preview['total_charge_amount']);
    }

    // ================================================================
    // 철회 / 반려 / 복귀
    // ================================================================

    public function test_withdraw_reverts_order_option_to_original_status(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $withdrawn = $this->service->withdraw($exchange);

        $this->assertSame(ExchangeStatusEnum::WITHDRAWN, $withdrawn->exchange_status);
        $this->assertNotNull($withdrawn->withdrawn_at);
        $this->assertSame(OrderStatusEnum::DELIVERED, $order->options->first()->fresh()->option_status);
    }

    public function test_withdraw_is_blocked_after_approval(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->service->transition($this->service->requestExchange($order, $this->payload($order)), ExchangeStatusEnum::APPROVED);

        $this->assertClaimException('claim_not_withdrawable', fn () => $this->service->withdraw($exchange));
    }

    public function test_reject_reverts_order_option_and_records_reason(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $rejected = $this->service->transition($exchange, ExchangeStatusEnum::REJECTED, ['reject_reason' => '재고 소진']);

        $this->assertSame('재고 소진', $rejected->reject_reason);
        $this->assertNotNull($rejected->rejected_at);
        $this->assertSame(OrderStatusEnum::DELIVERED, $order->options->first()->fresh()->option_status);
        $this->assertSame(ExchangeStatusEnum::REJECTED, $rejected->exchangeOptions->first()->option_status);
    }

    public function test_revert_restores_each_item_to_its_own_original_status(): void
    {
        $policy = $this->createClaimShippingPolicy();
        [$productA, $optionA] = $this->createClaimProduct(10000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(20000, $policy);
        $order = $this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem($productA->id, $optionA->id, 1),
            new CalculationItem($productB->id, $optionB->id, 1),
        ]));

        $order->options[0]->forceFill(['option_status' => OrderStatusEnum::SHIPPING, 'is_stock_deducted' => true])->save();
        $order->options[1]->forceFill(['option_status' => OrderStatusEnum::DELIVERED, 'delivered_at' => now()->subDay(), 'is_stock_deducted' => true])->save();
        app(EcommerceSettingsService::class)->setSetting('claim.exchange_requestable_statuses', [
            OrderStatusEnum::SHIPPING->value,
            OrderStatusEnum::DELIVERED->value,
        ]);

        $order = $this->deliver($order->fresh(['options']));
        $order->options[0]->forceFill(['option_status' => OrderStatusEnum::SHIPPING])->save();
        $order = $order->fresh(['options', 'shippings', 'payment', 'shippingAddress']);

        $exchange = $this->service->requestExchange($order, $this->payload($order));
        $this->service->withdraw($exchange);

        $statuses = $order->fresh(['options'])->options
            ->pluck('option_status')->map(fn (OrderStatusEnum $s) => $s->value)->sort()->values()->all();

        $this->assertSame([OrderStatusEnum::DELIVERED->value, OrderStatusEnum::SHIPPING->value], $statuses);
    }

    public function test_revert_does_not_touch_stock(): void
    {
        $order = $this->deliveredOrder();
        $productOptionId = $order->options->first()->product_option_id;
        $before = (int) ProductOption::find($productOptionId)->stock_quantity;

        $exchange = $this->service->requestExchange($order, $this->payload($order));
        $this->service->withdraw($exchange);

        $this->assertSame($before, (int) ProductOption::find($productOptionId)->stock_quantity);
    }

    // ================================================================
    // 전이 — 게이트 / 시각 / 재고
    // ================================================================

    public function test_transition_follows_the_gate_through_the_whole_lifecycle(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $sequence = [];
        HookManager::addAction('sirsoft-ecommerce.claim.exchange.status_changed', function ($e, $from, $to) use (&$sequence) {
            $sequence[] = $to;
        });

        $exchange = $this->advanceTo($exchange, ExchangeStatusEnum::REDELIVERED);

        $this->assertSame(ExchangeStatusEnum::REDELIVERED, $exchange->exchange_status);
        $this->assertNotNull($exchange->approved_at);
        $this->assertNotNull($exchange->collection_completed_at);
        $this->assertNotNull($exchange->redelivered_at);
        $this->assertSame(
            ['approved', 'collection_ready', 'collecting', 'collected', 'redelivery_ready', 'redelivering', 'redelivered'],
            $sequence
        );
    }

    public function test_transition_blocks_a_target_outside_the_gate(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $this->assertClaimException('claim_transition_not_allowed', fn () => $this->service->transition($exchange, ExchangeStatusEnum::COLLECTED));
        $this->assertClaimException('claim_transition_not_allowed', fn () => $this->service->transition($exchange, ExchangeStatusEnum::REDELIVERING));
    }

    public function test_transition_to_completed_is_blocked(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->advanceTo($this->service->requestExchange($order, $this->payload($order)), ExchangeStatusEnum::REDELIVERED);

        $this->assertClaimException('claim_completion_flow_required', fn () => $this->service->transition($exchange, ExchangeStatusEnum::COMPLETED));
    }

    public function test_transition_stamps_on_hold_time(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->service->transition($this->service->requestExchange($order, $this->payload($order)), ExchangeStatusEnum::ON_HOLD);

        $this->assertNotNull($exchange->on_hold_at);
        $this->assertSame(ExchangeStatusEnum::ON_HOLD, $exchange->exchangeOptions->first()->option_status);
    }

    public function test_approve_deducts_stock_of_the_new_option(): void
    {
        $order = $this->deliveredOrder(29000, 2);
        $newOption = $this->siblingOption($order, 3000, stock: 10);
        $originalStock = (int) ProductOption::find($order->options->first()->product_option_id)->stock_quantity;

        $exchange = $this->service->transition(
            $this->service->requestExchange($order, $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST, newProductOptionId: $newOption->id)),
            ExchangeStatusEnum::APPROVED
        );

        $this->assertSame(8, (int) $newOption->fresh()->stock_quantity);
        $this->assertSame($originalStock, (int) ProductOption::find($order->options->first()->product_option_id)->stock_quantity, '원 옵션 재고는 회수 뒤 운영자가 고른다.');
        $this->assertTrue((bool) $exchange->exchangeOptions->first()->is_new_stock_deducted);
    }

    public function test_approve_fails_when_the_new_option_ran_out_of_stock_after_the_request(): void
    {
        $order = $this->deliveredOrder();
        $newOption = $this->siblingOption($order, 3000, stock: 1);
        $exchange = $this->service->requestExchange($order, $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST, newProductOptionId: $newOption->id));

        $newOption->forceFill(['stock_quantity' => 0])->save();

        $this->assertClaimException('claim_new_option_out_of_stock', fn () => $this->service->transition($exchange, ExchangeStatusEnum::APPROVED));

        $fresh = $exchange->fresh(['exchangeOptions']);
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $fresh->exchange_status, '재고 부족은 승인 전이 전체를 되돌린다.');
        $this->assertNull($fresh->approved_at);
        $this->assertFalse((bool) $fresh->exchangeOptions->first()->is_new_stock_deducted);
    }

    public function test_transition_fires_status_changed_and_specific_hooks(): void
    {
        $changed = [];
        $approved = 0;
        HookManager::addAction('sirsoft-ecommerce.claim.exchange.status_changed', function ($e, $from, $to) use (&$changed) {
            $changed[] = $from.'>'.$to;
        });
        HookManager::addAction('sirsoft-ecommerce.claim.exchange.approved', function () use (&$approved) {
            $approved++;
        });

        $order = $this->deliveredOrder();
        $this->service->transition($this->service->requestExchange($order, $this->payload($order)), ExchangeStatusEnum::APPROVED);

        $this->assertSame(['requested>approved'], $changed);
        $this->assertSame(1, $approved);
    }

    public function test_bulk_transition_reports_skipped_and_missing_entries(): void
    {
        $order = $this->deliveredOrder();
        $requested = $this->service->requestExchange($order, $this->payload($order));
        $other = $this->deliveredOrder();
        $approved = $this->service->transition($this->service->requestExchange($other, $this->payload($other)), ExchangeStatusEnum::APPROVED);

        $result = $this->service->bulkTransition([$requested->id, $approved->id, 999999], ExchangeStatusEnum::ON_HOLD);

        $this->assertSame([$requested->id], $result['succeeded']);
        $this->assertCount(2, $result['skipped']);
        $this->assertSame($approved->exchange_number, $result['skipped'][0]['exchange_number']);
        $this->assertSame(__('sirsoft-ecommerce::exceptions.claim_not_found'), $result['skipped'][1]['message']);
    }

    // ================================================================
    // 상품 단위 전이
    // ================================================================

    public function test_transition_option_moves_only_selected_items_and_header_when_all_agree(): void
    {
        [$exchange, $itemA, $itemB] = $this->twoItemExchange();

        $result = $this->service->transitionOption($exchange, [$itemA], ExchangeStatusEnum::APPROVED);

        $this->assertSame([$itemA], $result['succeeded']);
        $this->assertSame(ExchangeStatusEnum::APPROVED, OrderExchangeOption::find($itemA)->option_status);
        $this->assertSame(ExchangeStatusEnum::REQUESTED, OrderExchangeOption::find($itemB)->option_status);
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->fresh()->exchange_status);

        $this->service->transitionOption($exchange->fresh(['exchangeOptions']), [$itemB], ExchangeStatusEnum::APPROVED);

        $this->assertSame(ExchangeStatusEnum::APPROVED, $exchange->fresh()->exchange_status);
    }

    public function test_transition_option_reports_skip_for_invalid_target_and_blocks_completed(): void
    {
        [$exchange, $itemA] = $this->twoItemExchange();

        $result = $this->service->transitionOption($exchange, [$itemA], ExchangeStatusEnum::COLLECTED);

        $this->assertSame([], $result['succeeded']);
        $this->assertSame($itemA, $result['skipped'][0]['id']);
        $this->assertClaimException('claim_completion_flow_required', fn () => $this->service->transitionOption($exchange, [$itemA], ExchangeStatusEnum::COMPLETED));
    }

    /**
     * 끝난 상품은 같은 상태를 다시 지시해도 처리하지 않는다.
     *
     * 자기 전이는 게이트가 언제나 허용하므로(일괄 전이에 이미 그 상태인 건이 섞여도 실패하지
     * 않기 위한 규약), 종결 상품은 게이트가 아니라 이 가드가 막는다.
     */
    public function test_transition_option_skips_an_item_that_is_already_finished(): void
    {
        [$exchange, $itemA] = $this->twoItemExchange();

        $this->service->transitionOption($exchange, [$itemA], ExchangeStatusEnum::REJECTED);
        $rejected = OrderExchangeOption::find($itemA);

        $result = $this->service->transitionOption($exchange->fresh(['exchangeOptions']), [$itemA], ExchangeStatusEnum::REJECTED, ['processed_by' => 99]);

        $this->assertSame([], $result['succeeded']);
        $this->assertSame($itemA, $result['skipped'][0]['id']);
        $this->assertNull(OrderExchangeOption::find($itemA)->processed_by, '건너뛴 상품에는 처리자가 덮어써지지 않는다.');
        $this->assertEquals($rejected->updated_at, OrderExchangeOption::find($itemA)->updated_at, '종결 상품은 다시 쓰이지 않는다.');
    }

    public function test_transition_option_rejecting_one_item_reverts_only_that_item(): void
    {
        [$exchange, $itemA, $itemB] = $this->twoItemExchange();

        $this->service->transitionOption($exchange, [$itemA], ExchangeStatusEnum::REJECTED);

        $this->assertSame(OrderStatusEnum::DELIVERED, OrderExchangeOption::find($itemA)->orderOption->option_status);
        $this->assertSame(OrderStatusEnum::EXCHANGE_IN_PROGRESS, OrderExchangeOption::find($itemB)->orderOption->option_status);
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->fresh()->exchange_status, '끝난 상품은 헤더 판정에서 빠진다.');
    }

    /**
     * 상품 일부가 헤더보다 앞서 있으면 신청서 단위 반려·철회는 거부된다 — 상품 단위로만 처리한다.
     *
     * 거부하지 않으면 앞선 상품은 살아 있는데 신청서만 종결되어 어디로도 못 가고, 재고 차감·후속 옵션의
     * 근거가 사라진다.
     */
    public function test_header_rejection_is_refused_while_an_item_has_moved_past_the_gate(): void
    {
        [$exchange, $itemA, $itemB] = $this->twoItemExchange();
        $this->service->transitionOption($exchange, [$itemA], ExchangeStatusEnum::APPROVED);

        $this->assertClaimException('claim_transition_not_allowed', fn () => $this->service->transition($exchange->fresh(['exchangeOptions']), ExchangeStatusEnum::REJECTED));
        $this->assertClaimException('claim_transition_not_allowed', fn () => $this->service->withdraw($exchange->fresh(['exchangeOptions'])));
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->fresh()->exchange_status);
        $this->assertSame(ExchangeStatusEnum::APPROVED, OrderExchangeOption::find($itemA)->option_status);
        $this->assertSame(OrderStatusEnum::EXCHANGE_IN_PROGRESS, OrderExchangeOption::find($itemB)->orderOption->option_status, '거부된 반려가 다른 상품을 되돌렸습니다.');

        // 상품 단위로는 여전히 처리된다.
        $result = $this->service->transitionOption($exchange->fresh(['exchangeOptions']), [$itemB], ExchangeStatusEnum::REJECTED);
        $this->assertSame([$itemB], $result['succeeded']);
        $this->assertSame(ExchangeStatusEnum::APPROVED, $exchange->fresh()->exchange_status, '살아 있는 상품이 승인 하나뿐이면 헤더가 따라간다.');
    }

    public function test_transition_option_moves_header_when_every_item_is_rejected(): void
    {
        [$exchange, $itemA, $itemB] = $this->twoItemExchange();

        $this->service->transitionOption($exchange, [$itemA, $itemB], ExchangeStatusEnum::REJECTED);

        $this->assertSame(ExchangeStatusEnum::REJECTED, $exchange->fresh()->exchange_status);
    }

    public function test_transition_option_skips_an_item_whose_new_option_is_out_of_stock_and_approves_the_rest(): void
    {
        $policy = $this->createClaimShippingPolicy();
        [$productA, $optionA] = $this->createClaimProduct(10000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(20000, $policy);
        $order = $this->deliver($this->attachPolicyShippings($this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem($productA->id, $optionA->id, 1),
            new CalculationItem($productB->id, $optionB->id, 1),
        ])), $policy));
        $scarceA = ProductOptionFactory::new()->forProduct($productA)->create(['price_adjustment' => 0, 'stock_quantity' => 1, 'is_active' => true]);

        $items = [];
        foreach ($order->options as $option) {
            $items[] = [
                'order_option_id' => $option->id,
                'quantity' => 1,
                'new_product_option_id' => (int) $option->product_option_id === (int) $optionA->id ? $scarceA->id : null,
            ];
        }
        $exchange = $this->service->requestExchange($order, ['items' => $items, 'reason_code' => 'size_mismatch']);
        $scarceA->forceFill(['stock_quantity' => 0])->save();

        $result = $this->service->transitionOption($exchange, [], ExchangeStatusEnum::APPROVED);

        $this->assertCount(1, $result['succeeded']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame(__('sirsoft-ecommerce::exceptions.claim_new_option_out_of_stock'), $result['skipped'][0]['message']);
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->fresh()->exchange_status, '한 상품이 막혀 있으면 헤더는 움직이지 않는다.');
    }

    public function test_header_follows_items_through_intermediate_steps_after_staggered_progress(): void
    {
        $changed = [];
        HookManager::addAction('sirsoft-ecommerce.claim.exchange.status_changed', function ($e, $from, $to) use (&$changed) {
            $changed[] = $from.'>'.$to;
        });

        [$exchange, $itemA, $itemB] = $this->twoItemExchange();
        $exchange = $this->advanceTo($exchange, ExchangeStatusEnum::COLLECTED);
        $fresh = fn () => $exchange->fresh(['exchangeOptions']);

        $this->service->transitionOption($fresh(), [$itemA], ExchangeStatusEnum::REDELIVERY_READY);
        $this->service->transitionOption($fresh(), [$itemA], ExchangeStatusEnum::REDELIVERING);
        $this->service->transitionOption($fresh(), [$itemB], ExchangeStatusEnum::REDELIVERY_READY);

        $this->assertSame(ExchangeStatusEnum::COLLECTED, $exchange->fresh()->exchange_status);

        $this->service->transitionOption($fresh(), [$itemB], ExchangeStatusEnum::REDELIVERING);

        $this->assertSame(ExchangeStatusEnum::REDELIVERING, $exchange->fresh()->exchange_status);
        $this->assertSame(
            ['requested>approved', 'approved>collection_ready', 'collection_ready>collecting', 'collecting>collected', 'collected>redelivery_ready', 'redelivery_ready>redelivering'],
            $changed
        );
        // 두 상품 모두 후속 주문옵션이 생겼다 — 상품 단위 경로도 같은 부수효과를 탄다.
        $this->assertNotNull(OrderExchangeOption::find($itemA)->new_order_option_id);
        $this->assertNotNull(OrderExchangeOption::find($itemB)->new_order_option_id);
    }

    public function test_transition_option_keeps_the_existing_processor_when_none_is_given(): void
    {
        [$exchange, $itemA] = $this->twoItemExchange();

        $this->service->transitionOption($exchange, [$itemA], ExchangeStatusEnum::APPROVED, ['processed_by' => $exchange->order->user_id]);
        $this->service->transitionOption($exchange->fresh(['exchangeOptions']), [$itemA], ExchangeStatusEnum::COLLECTION_READY);

        $this->assertSame($exchange->order->user_id, OrderExchangeOption::find($itemA)->processed_by);
    }

    // ================================================================
    // 송장 — 회수 / 재발송
    // ================================================================

    public function test_register_collection_tracking_moves_to_collecting(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->advanceTo($this->service->requestExchange($order, $this->payload($order)), ExchangeStatusEnum::COLLECTION_READY);
        $carrier = $this->carrier();

        $tracked = $this->service->registerCollectionTracking($exchange, $carrier->id, ' 1234567890 ');

        $this->assertSame(ExchangeStatusEnum::COLLECTING, $tracked->exchange_status);
        $this->assertSame('1234567890', $tracked->collection_tracking_number);
        $this->assertSame($carrier->id, $tracked->collection_carrier_id);
    }

    public function test_register_tracking_requires_both_fields(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->advanceTo($this->service->requestExchange($order, $this->payload($order)), ExchangeStatusEnum::COLLECTION_READY);

        $this->assertClaimException('claim_tracking_incomplete', fn () => $this->service->registerCollectionTracking($exchange, null, 'ABC'));
        $this->assertClaimException('claim_tracking_incomplete', fn () => $this->service->registerRedeliveryTracking($exchange, 1, ''));
    }

    public function test_register_redelivery_tracking_moves_to_redelivering_and_creates_the_successor_option(): void
    {
        $order = $this->deliveredOrder();
        $newOption = $this->siblingOption($order, 0);
        $exchange = $this->advanceTo(
            $this->service->requestExchange($order, $this->payload($order, newProductOptionId: $newOption->id)),
            ExchangeStatusEnum::REDELIVERY_READY
        );
        $carrier = $this->carrier();
        $original = $order->options->first()->fresh();

        $moving = $this->service->registerRedeliveryTracking($exchange, $carrier->id, 'RD-0001');

        $this->assertSame(ExchangeStatusEnum::REDELIVERING, $moving->exchange_status);
        $this->assertSame('RD-0001', $moving->redelivery_tracking_number);

        $item = $moving->exchangeOptions->first();
        $successor = OrderOption::find($item->new_order_option_id);

        $this->assertNotNull($successor);
        $this->assertSame(OrderOptionSourceTypeEnum::EXCHANGE, $successor->source_type);
        $this->assertSame($original->id, $successor->source_option_id);
        $this->assertTrue($successor->isExchanged());
        $this->assertSame(OrderStatusEnum::SHIPPING, $successor->option_status);
        $this->assertSame($newOption->id, $successor->product_option_id);
        $this->assertTrue((bool) $successor->is_stock_deducted);
        $this->assertSame(1, (int) $successor->quantity);
        $this->assertNull($successor->parent_option_id);
        // 원 행은 여전히 교환 진행 중이다 — 완료 전까지 회계 주체는 원 행이다.
        $this->assertSame(OrderStatusEnum::EXCHANGE_IN_PROGRESS, $original->fresh()->option_status);

        $shipping = OrderShipping::where('order_option_id', $successor->id)->first();
        $this->assertNotNull($shipping, '후속 옵션도 배송정책을 승계한 배송 행을 가져야 한다.');
        $this->assertSame($order->shippings->first()->shipping_policy_id, $shipping->shipping_policy_id);
        $this->assertSame('RD-0001', $shipping->tracking_number);
        $this->assertSame($carrier->id, $shipping->carrier_id);
    }

    public function test_successor_option_inherits_money_columns_and_only_changes_identity(): void
    {
        $order = $this->deliveredOrder();
        $newOption = $this->siblingOption($order, 3000);
        $original = $order->options->first();
        $exchange = $this->advanceTo(
            $this->service->requestExchange($order, $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST, newProductOptionId: $newOption->id)),
            ExchangeStatusEnum::REDELIVERING
        );
        $successor = OrderOption::find($exchange->exchangeOptions->first()->new_order_option_id);

        // 금액은 원 행 그대로 — 차액은 클레임 정산에 속한다.
        $this->assertSame((float) $original->unit_price, (float) $successor->unit_price);
        $this->assertSame((float) $original->subtotal_price, (float) $successor->subtotal_price);
        $this->assertSame((float) $original->subtotal_paid_amount, (float) $successor->subtotal_paid_amount);
        $this->assertSame($original->option_snapshot['price_adjustment'], $successor->option_snapshot['price_adjustment']);
        // 정체는 신규 옵션.
        $this->assertSame($newOption->id, $successor->option_snapshot['id']);
        $this->assertSame($newOption->option_name, $successor->option_name);
        $this->assertSame($newOption->sku, $successor->sku);
        // 신규 단가는 교환 신청 상품 행이 갖는다.
        $this->assertSame(32000.0, (float) $exchange->exchangeOptions->first()->new_unit_price);
    }

    public function test_successor_option_is_created_once_and_delivered_on_arrival(): void
    {
        [$exchange, $order, $successor] = $this->redeliveredExchange();

        $this->assertSame(OrderStatusEnum::DELIVERED, $successor->option_status);
        $this->assertNotNull($successor->delivered_at);
        $this->assertSame(1, OrderOption::where('source_option_id', $order->options->first()->id)->count(), '후속 옵션은 한 번만 만들어진다.');
        $this->assertNotNull($exchange->redelivered_at);
    }

    // ================================================================
    // 금액 수정
    // ================================================================

    public function test_update_amounts_moves_total_charge_with_the_fee_and_keeps_the_difference(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setExchangeFixedFee($policy, 6000);
        $newOption = $this->siblingOption($order, 3000);
        $exchange = $this->service->requestExchange($order, $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST, newProductOptionId: $newOption->id));

        $updated = $this->service->updateAmounts($exchange, ['claim_shipping_fee' => 2000, 'admin_memo' => '할인 적용']);

        $this->assertSame(2000.0, (float) $updated->claim_shipping_fee);
        $this->assertSame(5000.0, (float) $updated->total_charge_amount, '총 청구액 = 수정 배송비 + 추가 차액.');
        $this->assertSame(3000.0, (float) $updated->additional_product_amount);
        $this->assertSame('할인 적용', $updated->admin_memo);
        $this->assertTrue($updated->shipping_fee_snapshot['admin_adjusted']);
        $this->assertEquals(6000, $updated->shipping_fee_snapshot['admin_adjusted_from']);
        $this->assertSame(ClaimChargeMethodEnum::PAY_ON_REQUEST, $updated->charge_method);
    }

    public function test_update_amounts_clears_charge_method_only_when_nothing_remains_to_charge(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setExchangeFixedFee($policy, 6000);
        $exchange = $this->service->requestExchange($order, $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST));

        $updated = $this->service->updateAmounts($exchange, ['claim_shipping_fee' => 0]);

        $this->assertNull($updated->charge_method);
        $this->assertSame(0.0, (float) $updated->total_charge_amount);
        $this->assertClaimException('claim_amount_invalid', fn () => $this->service->updateAmounts($exchange, ['claim_shipping_fee' => -1]));
    }

    /**
     * 청구 방식이 없는 신청서(접수 시 청구 0원)의 청구액을 양수로 올리면 거절한다 — 결제할 수 없는
     * 청구가 남는다. 청구 방식을 함께 정하는 금액 수정 API 가 그 경로다(반품과 같은 규칙).
     */
    public function test_update_amounts_refuses_to_raise_a_charge_without_a_charge_method(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->service->requestExchange($order, $this->payload($order));
        $this->assertNull($exchange->charge_method);

        $this->assertClaimException('claim_charge_method_required', fn () => $this->service->updateAmounts($exchange, ['claim_shipping_fee' => 3000]));
        $this->assertSame(0.0, (float) $exchange->fresh()->total_charge_amount);
        $this->assertSame(0.0, (float) $exchange->fresh()->claim_shipping_fee);
    }

    // ================================================================
    // 완료 (D26)
    // ================================================================

    public function test_complete_marks_item_and_order_option_exchanged(): void
    {
        [$exchange, $order] = $this->redeliveredExchange();
        $fired = 0;
        HookManager::addAction('sirsoft-ecommerce.claim.exchange.completed', function () use (&$fired) {
            $fired++;
        });

        $completed = $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, false));

        $this->assertSame(ExchangeStatusEnum::COMPLETED, $completed->exchange_status);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame(ExchangeStatusEnum::COMPLETED, $completed->exchangeOptions->first()->option_status);
        $this->assertSame(OrderStatusEnum::EXCHANGED, $order->options->first()->fresh()->option_status);
        $this->assertSame(1, $fired);
    }

    /**
     * 완료 시 원 옵션의 적립예정 마일리지를 회수한다 — 후속 옵션이 구매확정될 때 다시 적립되므로
     * 회수하지 않으면 한 구매에 두 번 적립된다.
     */
    public function test_complete_cancels_the_pending_earn_of_the_original_option(): void
    {
        [$exchange, $order] = $this->redeliveredExchange();
        $orderOptionId = (int) $exchange->exchangeOptions->first()->order_option_id;

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

        $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, false));

        $this->assertSame(
            1,
            MileageTransaction::where('order_option_id', $orderOptionId)
                ->where('type', MileageTransactionTypeEnum::EARN_CANCEL->value)
                ->count(),
            '원 옵션의 적립 lot 이 회수되어야 한다.'
        );
    }

    public function test_complete_rejects_items_that_have_not_arrived(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->advanceTo($this->service->requestExchange($order, $this->payload($order)), ExchangeStatusEnum::REDELIVERING);

        $this->assertClaimException('claim_completion_items_invalid', fn () => $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, false)));
    }

    public function test_complete_restores_original_stock_only_for_checked_items(): void
    {
        [$exchange, $order] = $this->redeliveredExchange();
        $productOptionId = $order->options->first()->product_option_id;
        $afterApproval = (int) ProductOption::find($productOptionId)->stock_quantity;

        $completed = $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, true, false));

        $this->assertSame($afterApproval + 1, (int) ProductOption::find($productOptionId)->stock_quantity);
        $this->assertTrue((bool) $completed->is_stock_restored);
        $this->assertFalse((bool) $order->options->first()->fresh()->is_stock_deducted);
    }

    public function test_complete_leaves_stock_untouched_when_not_checked(): void
    {
        [$exchange, $order] = $this->redeliveredExchange();
        $productOptionId = $order->options->first()->product_option_id;
        $before = (int) ProductOption::find($productOptionId)->stock_quantity;

        $completed = $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, false));

        $this->assertSame($before, (int) ProductOption::find($productOptionId)->stock_quantity);
        $this->assertFalse((bool) $completed->is_stock_restored);
        $this->assertTrue((bool) $order->options->first()->fresh()->is_stock_deducted);
    }

    public function test_complete_ignores_settings_defaults(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.complete_defaults', ['create_refund_request' => true, 'restore_stock' => true]);
        [$exchange, $order] = $this->redeliveredExchange(-4000);
        $before = (int) ProductOption::find($order->options->first()->product_option_id)->stock_quantity;

        $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, false));

        $this->assertSame($before, (int) ProductOption::find($order->options->first()->product_option_id)->stock_quantity);
        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());
    }

    public function test_complete_without_refund_request_creates_no_refund(): void
    {
        [$exchange, $order] = $this->redeliveredExchange(-4000);

        $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, false));

        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());
    }

    public function test_complete_with_refund_request_creates_exactly_one_refund_for_the_difference(): void
    {
        [$exchange, $order] = $this->redeliveredExchange(-4000);

        $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, true));

        $refunds = OrderRefund::where('order_id', $order->id)->get();
        $this->assertCount(1, $refunds);
        $refund = $refunds->first();
        $this->assertSame(RefundSourceTypeEnum::EXCHANGE, $refund->refund_source_type);
        $this->assertSame($exchange->id, $refund->refund_source_id);
        $this->assertSame(RefundStatusEnum::REQUESTED, $refund->refund_status);
        $this->assertSame(4000.0, (float) $refund->refund_amount);
        $this->assertSame(0.0, (float) $refund->refund_shipping_amount);
        $this->assertSame($exchange->requested_by, $refund->requested_by);

        $rows = OrderRefundOption::where('order_refund_id', $refund->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(4000.0, (float) $rows->first()->refund_amount);
        $this->assertSame($exchange->exchangeOptions->first()->order_option_id, $rows->first()->order_option_id);
    }

    public function test_complete_refund_deducts_the_round_trip_fee_when_charged_from_the_refund(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setExchangeFixedFee($policy, 3000);
        $newOption = $this->siblingOption($order, -10000);
        $exchange = $this->advanceTo(
            $this->service->requestExchange($order, $this->payload($order, chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND, newProductOptionId: $newOption->id)),
            ExchangeStatusEnum::REDELIVERED
        );

        $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, true));

        $refund = OrderRefund::where('order_id', $order->id)->first();
        $this->assertSame(7000.0, (float) $refund->refund_amount, '차액 10,000 − 왕복배송비 3,000.');
        $this->assertSame(-3000.0, (float) $refund->refund_shipping_amount);
    }

    /**
     * 차액 환불은 주문이 실제로 낸 돈에 맞춰 수단을 나눈다 — 전액 마일리지 결제면 마일리지 환불액에 싣는다.
     *
     * 반품의 환불신청서가 재계산 결과의 배분(PG 우선)을 싣는 것과 같은 규칙을 동결 차액에 적용한다.
     * 전액을 PG 칸에 실으면 환불 수단은 마일리지인데 금액은 PG 환불액인 어긋난 신청서가 된다.
     */
    public function test_refund_request_puts_the_difference_on_points_when_the_order_was_paid_with_points_only(): void
    {
        $order = $this->deliveredOrderPaidWith(0, 29000);
        $newOption = $this->siblingOption($order, -4000);
        $exchange = $this->advanceTo(
            $this->service->requestExchange($order, $this->payload($order, newProductOptionId: $newOption->id)),
            ExchangeStatusEnum::REDELIVERED
        );

        $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, true));

        $refund = OrderRefund::where('order_id', $order->id)->first();
        $this->assertSame(RefundMethodEnum::POINTS, $refund->refund_method);
        $this->assertSame(0.0, (float) $refund->refund_amount);
        $this->assertSame(4000.0, (float) $refund->refund_points_amount);
        $this->assertSame(4000.0, (float) OrderRefundOption::where('order_refund_id', $refund->id)->sum('refund_amount'), '상품 행 합계는 수단과 무관하게 차액 전액이다.');
    }

    public function test_refund_request_splits_the_difference_pg_first_by_what_the_order_paid(): void
    {
        // PG 3,000 + 마일리지 26,000 으로 낸 주문 — 차액 4,000 은 PG 잔여 3,000 을 먼저 채우고 나머지 1,000 이 마일리지다.
        $order = $this->deliveredOrderPaidWith(3000, 26000);
        $newOption = $this->siblingOption($order, -4000);
        $exchange = $this->advanceTo(
            $this->service->requestExchange($order, $this->payload($order, newProductOptionId: $newOption->id)),
            ExchangeStatusEnum::REDELIVERED
        );

        $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, true));

        $refund = OrderRefund::where('order_id', $order->id)->first();
        $this->assertSame(3000.0, (float) $refund->refund_amount);
        $this->assertSame(1000.0, (float) $refund->refund_points_amount);
        $this->assertSame(4000.0, (float) $refund->refund_amount + (float) $refund->refund_points_amount, '두 칸의 합은 동결 차액과 같다.');
    }

    public function test_complete_with_refund_request_is_rejected_when_nothing_is_refundable(): void
    {
        [$exchange, $order] = $this->redeliveredExchange(3000);

        $this->assertClaimException('claim_refund_not_applicable', fn () => $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, true)));

        $this->assertSame(ExchangeStatusEnum::REDELIVERED, $exchange->fresh()->exchange_status, '거절은 완료 전체를 되돌린다.');
        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());
    }

    public function test_complete_rejects_a_second_refund_request_and_late_request_works_once(): void
    {
        [$exchange, $order] = $this->redeliveredExchange(-4000);
        $completed = $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, false));

        $refund = $this->service->createRefundRequestForCompleted($completed);

        $this->assertSame(1, OrderRefund::where('order_id', $order->id)->count());
        $this->assertSame(4000.0, (float) $refund->refund_amount);
        $this->assertClaimException('claim_refund_request_exists', fn () => $this->service->createRefundRequestForCompleted($completed));
    }

    /**
     * 완료 라운드마다 그 라운드의 저가 상품으로 환불신청서를 접수한다 — 신청서당 N건.
     */
    public function test_each_completion_round_can_file_its_own_refund_request(): void
    {
        [$exchange, $itemA, $itemB, $order] = $this->twoItemOptionChangeExchange(-4000, -6000);

        $first = $this->service->completeExchange($exchange, [$itemA], $this->completionOptions($exchange, false, true));
        $this->assertSame(ExchangeStatusEnum::REDELIVERED, $first->exchange_status);

        $second = $this->service->completeExchange($first, [$itemB], $this->completionOptions($first, false, true));
        $this->assertSame(ExchangeStatusEnum::COMPLETED, $second->exchange_status);

        $refunds = OrderRefund::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(2, $refunds);
        $this->assertSame(4000.0, (float) $refunds[0]->refund_amount);
        $this->assertSame(6000.0, (float) $refunds[1]->refund_amount);
        $this->assertEquals(
            [OrderExchangeOption::find($itemA)->order_option_id],
            $refunds[0]->refundOptions->pluck('order_option_id')->all(),
            '1회차 신청서는 1회차 상품만 담는다.'
        );
        $this->assertEquals(
            [OrderExchangeOption::find($itemB)->order_option_id],
            $refunds[1]->refundOptions->pluck('order_option_id')->all(),
            '2회차 신청서는 앞서 접수된 상품을 다시 담지 않는다.'
        );
        $this->assertEquals([$itemB], $refunds[1]->original_calculation_snapshot['exchange_option_ids']);
    }

    /**
     * 반려된 환불신청서에 담겼던 상품은 다시 접수할 수 있다.
     */
    public function test_a_rejected_refund_request_lets_the_same_items_be_filed_again(): void
    {
        [$exchange, $order] = $this->redeliveredExchange(-4000);
        $completed = $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, true));

        $first = OrderRefund::where('order_id', $order->id)->firstOrFail();
        $this->assertClaimException('claim_refund_request_exists', fn () => $this->service->createRefundRequestForCompleted($completed));

        $first->forceFill(['refund_status' => RefundStatusEnum::REJECTED])->save();

        $again = $this->service->createRefundRequestForCompleted($completed);

        $this->assertSame(4000.0, (float) $again->refund_amount);
        $this->assertSame(2, OrderRefund::where('order_id', $order->id)->count(), '반려된 신청서는 남고 새 신청서가 추가된다.');
    }

    /**
     * '환불금 차감' 의 왕복 배송비는 신청서 전체에서 한 번만 뺀다.
     */
    public function test_the_round_trip_fee_is_deducted_only_once_across_refund_requests(): void
    {
        [$exchange, $itemA, $itemB, $order] = $this->twoItemOptionChangeExchange(-10000, -5000, ClaimChargeMethodEnum::DEDUCT_FROM_REFUND, 3000);

        $first = $this->service->completeExchange($exchange, [$itemA], $this->completionOptions($exchange, false, true));
        $this->service->completeExchange($first, [$itemB], $this->completionOptions($first, false, true));

        $refunds = OrderRefund::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(2, $refunds);
        $this->assertSame(7000.0, (float) $refunds[0]->refund_amount, '차액 10,000 − 왕복배송비 3,000.');
        $this->assertSame(-3000.0, (float) $refunds[0]->refund_shipping_amount);
        $this->assertSame(5000.0, (float) $refunds[1]->refund_amount, '배송비는 앞선 접수가 이미 뺐다.');
        $this->assertSame(0.0, (float) $refunds[1]->refund_shipping_amount);
    }

    /**
     * 이번 라운드 차액이 남은 배송비에 못 미치면 접수하지 않고, 다음 라운드가 앞 라운드 상품까지 함께 담는다.
     */
    public function test_a_round_whose_difference_cannot_cover_the_fee_is_filed_with_the_next_round(): void
    {
        [$exchange, $itemA, $itemB, $order] = $this->twoItemOptionChangeExchange(-2000, -5000, ClaimChargeMethodEnum::DEDUCT_FROM_REFUND, 3000);

        $this->assertClaimException('claim_refund_not_applicable', fn () => $this->service->completeExchange($exchange, [$itemA], $this->completionOptions($exchange, false, true)));
        $this->assertSame(ExchangeStatusEnum::REDELIVERED, OrderExchangeOption::find($itemA)->option_status, '접수할 수 없으면 완료도 되돌린다.');

        $fresh = $exchange->fresh(['exchangeOptions']);
        $first = $this->service->completeExchange($fresh, [$itemA], $this->completionOptions($fresh, false, false));
        $done = $this->service->completeExchange($first, [$itemB], $this->completionOptions($first, false, true));

        $this->assertSame(ExchangeStatusEnum::COMPLETED, $done->exchange_status);
        $this->assertSame(1, OrderRefund::where('order_id', $order->id)->count());

        $refund = OrderRefund::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(4000.0, (float) $refund->refund_amount, '2,000 + 5,000 − 3,000.');
        $this->assertSame(-3000.0, (float) $refund->refund_shipping_amount);
        $this->assertCount(2, $refund->refundOptions, '앞 라운드의 미접수 상품까지 함께 담는다.');
    }

    /**
     * 고가·저가가 섞인 신청서는 차액을 합계로 상계하므로(D29) 순추가 건에는 돌려줄 것이 없다.
     *
     * 상품별 음수 차액만 더하면 상계로 사라진 양수 차액만큼 더 나간다 — 고객은 이미 상계된
     * 순액을 청구받았기 때문이다.
     */
    public function test_a_mixed_exchange_that_nets_to_an_additional_charge_cannot_file_a_refund(): void
    {
        [$exchange, , , $order] = $this->twoItemOptionChangeExchange(5000, -2000, ClaimChargeMethodEnum::PAY_ON_REQUEST);

        $this->assertSame(3000.0, (float) $exchange->additional_product_amount);
        $this->assertSame(0.0, (float) $exchange->refund_product_amount, '동결된 환불 차액은 0 이다.');

        $this->assertClaimException(
            'claim_refund_not_applicable',
            fn () => $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, true))
        );

        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());
        $this->assertSame(ExchangeStatusEnum::REDELIVERED, $exchange->fresh()->exchange_status, '거절은 완료 전체를 되돌린다.');
    }

    /**
     * 순환불 혼합 건은 **동결된 순액**까지만 접수한다 (음수 차액 합이 아니다).
     */
    public function test_a_mixed_exchange_files_only_the_frozen_net_refund(): void
    {
        [$exchange, , $itemB, $order] = $this->twoItemOptionChangeExchange(2000, -5000);

        $this->assertSame(3000.0, (float) $exchange->refund_product_amount, '동결 순액 = −5,000 + 2,000.');

        $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, true));

        $refund = OrderRefund::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(3000.0, (float) $refund->refund_amount, '음수 차액 합(5,000)이 아니라 동결 순액.');
        $this->assertSame(2000.0, (float) $refund->original_calculation_snapshot['offset_by_higher_priced_items']);

        $rows = OrderRefundOption::where('order_refund_id', $refund->id)->get();
        $this->assertCount(1, $rows, '저가 상품 행만 담는다.');
        $this->assertSame($itemB, (int) OrderExchangeOption::where('order_option_id', $rows->first()->order_option_id)->value('id'));
        $this->assertSame(3000.0, (float) $rows->first()->refund_amount, '행 합계는 신청서 금액과 같아야 한다.');
    }

    /**
     * 상계분은 저가 상품이 둘 이상일 때 **차액 비율로 안분**되고, 행 합계는 신청서 금액과 같다.
     */
    public function test_a_mixed_exchange_spreads_the_offset_across_the_lower_priced_items(): void
    {
        [$exchange] = $this->threeItemMixedExchange(6000, -4000, -2000);

        $this->assertSame(0.0, (float) $exchange->refund_product_amount, '순액은 0 — 6,000 − 4,000 − 2,000.');

        $this->assertClaimException(
            'claim_refund_not_applicable',
            fn () => $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, true))
        );

        [$netExchange, $netOrder] = $this->threeItemMixedExchange(3000, -4000, -2000);

        $this->service->completeExchange($netExchange, [], $this->completionOptions($netExchange, false, true));

        $refund = OrderRefund::where('order_id', $netOrder->id)->firstOrFail();
        $this->assertSame(3000.0, (float) $refund->refund_amount, '동결 순액 = 4,000 + 2,000 − 3,000.');

        $rows = OrderRefundOption::where('order_refund_id', $refund->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(
            3000.0,
            (float) $rows->sum(fn ($row) => (float) $row->refund_amount),
            '안분해도 행 합계는 신청서 금액과 정확히 같다.'
        );
        $this->assertEqualsCanonicalizing(
            [2000.0, 1000.0],
            $rows->map(fn ($row) => (float) $row->refund_amount)->all(),
            '차액 비율 4:2 로 안분한다.'
        );
    }

    /**
     * 한 주문서의 상품 일부는 교환, 일부는 반품 상태일 수 있다 — 수량 잠금(D15)은 상품 단위다.
     */
    public function test_one_order_can_hold_an_exchange_and_a_return_on_different_items(): void
    {
        ClaimReason::firstOrCreate(
            ['type' => 'return', 'code' => 'defective_product'],
            [
                'name' => ['ko' => 'defective_product', 'en' => 'defective_product'],
                'fault_type' => ClaimReasonFaultTypeEnum::SELLER,
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]
        );

        $policy = $this->createClaimShippingPolicy();
        [$productA, $optionA] = $this->createClaimProduct(10000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(20000, $policy);

        $order = $this->deliver($this->attachPolicyShippings($this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem($productA->id, $optionA->id, 1),
            new CalculationItem($productB->id, $optionB->id, 1),
        ])), $policy));

        $rowA = $order->options->firstWhere('product_option_id', $optionA->id);
        $rowB = $order->options->firstWhere('product_option_id', $optionB->id);

        $exchange = $this->service->requestExchange($order, [
            'items' => [['order_option_id' => $rowA->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
            'requested_by' => $order->user_id,
        ]);

        $return = app(OrderReturnService::class)->requestReturn($order->fresh(['options', 'shippings', 'payment', 'shippingAddress']), [
            'items' => [['order_option_id' => $rowB->id, 'quantity' => 1]],
            'reason_code' => 'defective_product',
            'requested_by' => $order->user_id,
        ]);

        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->exchange_status);
        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->return_status);
        $this->assertSame(OrderStatusEnum::EXCHANGE_IN_PROGRESS, $rowA->fresh()->option_status);
        $this->assertSame(OrderStatusEnum::RETURN_IN_PROGRESS, $rowB->fresh()->option_status);
    }

    public function test_late_refund_request_requires_completed_items(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->advanceTo($this->service->requestExchange($order, $this->payload($order)), ExchangeStatusEnum::REDELIVERED);

        $this->assertClaimException('claim_completion_items_invalid', fn () => $this->service->createRefundRequestForCompleted($exchange));
    }

    public function test_complete_of_some_items_keeps_the_header_open(): void
    {
        [$exchange, $itemA, $itemB] = $this->twoItemExchange();
        $exchange = $this->advanceTo($exchange, ExchangeStatusEnum::REDELIVERED);

        $partial = $this->service->completeExchange($exchange, [$itemA], $this->completionOptions($exchange, false, false));

        $this->assertSame(ExchangeStatusEnum::REDELIVERED, $partial->exchange_status);
        $this->assertSame(ExchangeStatusEnum::COMPLETED, OrderExchangeOption::find($itemA)->option_status);
        $this->assertSame(ExchangeStatusEnum::REDELIVERED, OrderExchangeOption::find($itemB)->option_status);

        $done = $this->service->completeExchange($partial, [$itemB], $this->completionOptions($partial, false, false));
        $this->assertSame(ExchangeStatusEnum::COMPLETED, $done->exchange_status);
    }

    /**
     * D26 건별 재고 복구 — 한 신청서 안에서 상품마다 선택이 다를 수 있다.
     *
     * 전건 동일 적용 헬퍼로는 `ClaimCompletionOptions` 의 행 단위 분기가 검증되지 않는다.
     */
    public function test_complete_restores_stock_only_for_the_items_checked_in_a_multi_item_exchange(): void
    {
        [$exchange, $itemA, $itemB] = $this->twoItemExchange();
        $exchange = $this->advanceTo($exchange, ExchangeStatusEnum::REDELIVERED);

        $productOptionA = (int) OrderExchangeOption::find($itemA)->orderOption->product_option_id;
        $productOptionB = (int) OrderExchangeOption::find($itemB)->orderOption->product_option_id;
        $beforeA = (int) ProductOption::find($productOptionA)->stock_quantity;
        $beforeB = (int) ProductOption::find($productOptionB)->stock_quantity;

        $completed = $this->service->completeExchange(
            $exchange,
            [],
            new ClaimCompletionOptions([$itemA => true, $itemB => false], false),
        );

        $this->assertSame($beforeA + 1, (int) ProductOption::find($productOptionA)->stock_quantity, '체크한 상품만 복구된다.');
        $this->assertSame($beforeB, (int) ProductOption::find($productOptionB)->stock_quantity, '체크하지 않은 상품은 그대로다.');
        $this->assertTrue((bool) $completed->is_stock_restored, '한 건이라도 복구됐으면 헤더 플래그가 선다.');
        $this->assertSame(ExchangeStatusEnum::COMPLETED, $completed->exchange_status);
    }

    public function test_completed_exchange_is_excluded_from_active_claim_quantity(): void
    {
        [$exchange, $order] = $this->redeliveredExchange();
        $courseOptionId = $exchange->exchangeOptions->first()->order_option_id;
        $guard = app(ClaimQuantityGuard::class);

        $this->assertSame(1, $guard->lockedQuantity($courseOptionId));

        $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, false));

        $this->assertSame(0, $guard->lockedQuantity($courseOptionId));
    }

    // ================================================================
    // 재계산 정합 — 교환 뒤의 부분취소
    // ================================================================

    /**
     * 교환이 끝난 주문에서 다른 상품을 취소하면 환불액은 그 상품 몫이어야 한다.
     *
     * 원 행(EXCHANGED)과 후속 행이 둘 다 잔여로 잡히면 환불액이 교환 상품 금액만큼 작아지고,
     * 후속 행이 신규 단가를 실으면 차액만큼 어긋난다 — 둘 다 오류 없이 금액만 틀어진다.
     */
    public function test_partial_cancel_after_an_exchange_refunds_only_the_cancelled_item(): void
    {
        $policy = $this->createClaimShippingPolicy();
        [$productA, $optionA] = $this->createClaimProduct(29000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(10000, $policy);
        $order = $this->deliver($this->attachPolicyShippings($this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem($productA->id, $optionA->id, 1),
            new CalculationItem($productB->id, $optionB->id, 1),
        ])), $policy));
        $higherA = ProductOptionFactory::new()->forProduct($productA)->create(['price_adjustment' => 3000, 'stock_quantity' => 10, 'is_active' => true]);

        $rowA = $order->options->firstWhere('product_option_id', $optionA->id);
        $rowB = $order->options->firstWhere('product_option_id', $optionB->id);

        $exchange = $this->advanceTo($this->service->requestExchange($order, [
            'items' => [['order_option_id' => $rowA->id, 'quantity' => 1, 'new_product_option_id' => $higherA->id]],
            'reason_code' => 'size_mismatch',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
        ]), ExchangeStatusEnum::REDELIVERED);

        $adjustmentService = app(OrderAdjustmentService::class);
        $cancelB = new CancellationAdjustment([['order_option_id' => $rowB->id, 'cancel_quantity' => 1]]);

        // 재발송 구간 — 원 행이 아직 회계 주체다.
        $during = $adjustmentService->preview($order->fresh(['options', 'shippings', 'payment']), $cancelB);
        $this->assertSame(10000.0, (float) $during->getTotalRefundAmount());

        $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, false));

        // 완료 뒤 — 후속 행이 승계했고, 금액은 원 행과 같다.
        $after = $adjustmentService->preview($order->fresh(['options', 'shippings', 'payment']), $cancelB);
        $this->assertSame(10000.0, (float) $after->getTotalRefundAmount());
    }
}
