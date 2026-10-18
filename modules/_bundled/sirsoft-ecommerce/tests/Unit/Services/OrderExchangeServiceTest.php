<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\CancellationAdjustment;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderOptionSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOption;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOptionValue;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyClaimAddress;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\ClaimQuantityGuard;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderAdjustmentService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\AssertsClaimExceptions;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimAdjustmentOrders;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\ProvidesClaimTracking;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 교환 클레임 서비스 테스트 (S4)
 *
 * 검증 축:
 * - 신청 — 동일상품/옵션변경, 저가·동가·고가 3분기의 차액·청구액, 신규 옵션 유효성·재고, 코스 상태
 *   진입(전량/부분 분할), 수량 잠금(D15), 청구 방식 규칙, 회수지·재발송지 스냅샷(D14 3축)
 * - 전이 — ExchangeStatusEnum 14종 게이트 전 케이스, 승인 시 신규 재고 차감, 시각 기록, 상품 단위 전이
 * - 재발송 — 후속 주문옵션 생성(금액 승계·정체 교체), 배송 행 복제, 도착 처리
 * - 복귀 — 반려·철회 시 상품옵션 단위 복귀, 재고 불변
 * - 완료 — D26: 건별 원 옵션 재고 복구, 저가 교환 차액 환불신청서, 설정이 동작을 바꾸지 않음
 * - 재계산 정합 — 교환 뒤 다른 상품의 부분취소 환불액이 차액에 오염되지 않음
 */
class OrderExchangeServiceTest extends ModuleTestCase
{
    use AssertsClaimExceptions;
    use BuildsClaimAdjustmentOrders;
    use ProvidesClaimTracking;

    protected OrderExchangeService $service;

    /**
     * 통화 설정을 심고 교환 서비스와 클레임 사유를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->service = app(OrderExchangeService::class);
        $this->seedExchangeReasons();
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

    // ================================================================
    // 헬퍼
    // ================================================================

    /**
     * 교환 사유 카탈로그를 시드합니다.
     *
     * @return void 반환값 없음
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
     * 배송완료된 해외(미국) 단일 상품 주문을 만듭니다.
     *
     * 국내 주소 칸은 체크아웃이 해외 주문에 남기는 그대로 빈 문자열이다.
     *
     * @return Order 주문
     */
    protected function internationalDeliveredOrder(): Order
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->attachPolicyShippings($this->createSingleItemClaimOrder(29000, 1, $policy), $policy);

        OrderAddress::factory()->create([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'recipient_name' => 'Jane Doe',
            'recipient_phone' => '+1-212-555-0101',
            'recipient_country_code' => 'US',
            'zipcode' => '',
            'address' => '',
            'address_detail' => '',
            'address_line_1' => '350 5th Ave',
            'address_line_2' => 'Suite 2',
            'intl_city' => 'New York',
            'intl_state' => 'NY',
            'intl_postal_code' => '10118',
        ]);

        return $this->deliver($order, 'US');
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
     * @return void 반환값 없음
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

            $exchange = $this->service->transition($exchange, $step, $this->claimTrackingFor($step));

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

    /**
     * 교환 접수가 EX 로 시작하는 신청번호와 `requested` 상태의 신청서를 만든다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_request_freezes_calculation_snapshots
     */
    public function test_request_creates_exchange_with_number_and_requested_status(): void
    {
        $order = $this->deliveredOrder();

        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $this->assertStringStartsWith('EX', $exchange->exchange_number);
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->exchange_status);
        $this->assertSame($order->id, $exchange->order_id);
        $this->assertCount(1, $exchange->exchangeOptions);
    }

    /**
     * 접수 시점의 사유 코드와 귀책 유형을 신청서에 그대로 굳힌다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_request_freezes_calculation_snapshots
     */
    public function test_request_snapshots_reason_and_fault_type(): void
    {
        $order = $this->deliveredOrder();

        $exchange = $this->service->requestExchange($order, $this->payload($order, 'defective_product'));

        $this->assertSame('defective_product', $exchange->reason_code);
        $this->assertSame(ClaimReasonFaultTypeEnum::SELLER, $exchange->fault_type);
    }

    /**
     * 접수는 대상 주문옵션을 「교환 진행중」으로 옮긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_request_freezes_calculation_snapshots
     */
    public function test_request_moves_order_option_into_exchange_in_progress(): void
    {
        $order = $this->deliveredOrder();

        $this->service->requestExchange($order, $this->payload($order));

        $this->assertSame(OrderStatusEnum::EXCHANGE_IN_PROGRESS, $order->options->first()->fresh()->option_status);
    }

    /**
     * 되돌릴 때 쓸 원래 상태를 항목마다 기록해 둔다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_reject_reverts_each_item_to_its_own_original_status
     */
    public function test_request_records_original_option_status_for_revert(): void
    {
        $order = $this->deliveredOrder();

        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $this->assertSame(OrderStatusEnum::DELIVERED, $exchange->exchangeOptions->first()->original_option_status);
    }

    /**
     * 수량 일부만 교환하면 주문옵션이 교환분과 잔여분으로 갈라진다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_request_freezes_calculation_snapshots
     */
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

    /**
     * 접수만으로는 재고를 되돌리지 않는다 — 상품이 아직 돌아오지 않았다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_completion_restores_original_stock_only_for_checked_items
     */
    public function test_request_does_not_restore_stock_on_acceptance(): void
    {
        $order = $this->deliveredOrder();
        $productOptionId = $order->options->first()->product_option_id;
        $before = (int) ProductOption::find($productOptionId)->stock_quantity;

        $this->service->requestExchange($order, $this->payload($order));

        $this->assertSame($before, (int) ProductOption::find($productOptionId)->stock_quantity);
        $this->assertTrue((bool) $order->options->first()->fresh()->is_stock_deducted);
    }

    /**
     * 같은 주문에 교환을 두 번 걸어도 두 신청서의 행이 한 줄로 합쳐지지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_request_freezes_calculation_snapshots
     */
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

    /**
     * 접수는 주문옵션만 움직이고 주문 헤더 상태는 건드리지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_request_freezes_calculation_snapshots
     */
    public function test_request_does_not_change_order_status(): void
    {
        $order = $this->deliveredOrder();

        $this->service->requestExchange($order, $this->payload($order));

        $this->assertSame(OrderStatusEnum::DELIVERED, $order->fresh()->order_status);
    }

    /**
     * 비회원 판정은 **주문의 주인**이 정한다 — 신청자 컬럼이 아니다.
     *
     * `requested_by` 는 비회원 신청·운영자 대리 접수·시스템 자동 취소가 모두 null 이라(§4-3-1),
     * 그 값으로 가르면 **회원 주문의 운영자 접수 건이 「비회원」으로 표시된다**(2026-09-12 실측).
     *
     * @return void 반환값 없음
     *
     * @effects exchange_guest_flag_reads_the_order_owner
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
    public function test_guest_flag_reads_the_order_owner(): void
    {
        $memberOrder = $this->deliveredOrder();
        $payload = $this->payload($memberOrder);
        $payload['requested_by'] = null;

        $memberExchange = $this->service->requestExchange($memberOrder, $payload);

        $this->assertNull($memberExchange->requested_by);
        $this->assertFalse(
            $memberExchange->fresh('order')->isGuestRequest(),
            '회원 주문의 신청을 비회원으로 표시하면 운영자가 연락 수단을 잘못 고른다',
        );

        $guestOrder = $this->deliveredOrder();
        $guestOrder->forceFill(['user_id' => null])->save();
        $guestPayload = $this->payload($guestOrder->fresh());
        $guestPayload['requested_by'] = null;

        $guestExchange = $this->service->requestExchange($guestOrder->fresh(), $guestPayload);

        $this->assertTrue(
            $guestExchange->fresh('order')->isGuestRequest(),
            '주인이 없는 주문은 비회원이다 — 이 방향까지 잠가야 판정이 한쪽으로 굳지 않는다',
        );
    }

    // ================================================================
    // 신청 — 자격 / 수량 잠금
    // ================================================================

    /**
     * 신청할 수 없는 상태의 주문옵션은 접수를 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_request_period_is_measured_from_delivery
     */
    public function test_request_rejects_option_not_in_requestable_status(): void
    {
        $order = $this->createSingleItemClaimOrder();

        $this->assertClaimException('claim_option_not_claimable', fn () => $this->service->requestExchange($order, $this->payload($order)));
    }

    /**
     * 주문 수량보다 많은 수량은 접수를 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_request_freezes_calculation_snapshots
     */
    public function test_request_rejects_quantity_over_order_quantity(): void
    {
        $order = $this->deliveredOrder();

        $this->assertClaimException('claim_quantity_invalid', fn () => $this->service->requestExchange($order, $this->payload($order, quantity: 2)));
    }

    /**
     * 항목이 하나도 없는 접수는 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_request_freezes_calculation_snapshots
     */
    public function test_request_rejects_empty_items(): void
    {
        $order = $this->deliveredOrder();

        $this->assertClaimException('claim_items_required', fn () => $this->service->requestExchange($order, [
            'items' => [],
            'reason_code' => 'size_mismatch',
        ]));
    }

    /**
     * 이 주문에 없는 주문옵션을 지목하면 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_request_freezes_calculation_snapshots
     */
    public function test_request_rejects_unknown_option(): void
    {
        $order = $this->deliveredOrder();

        $this->assertClaimException('claim_option_not_found', fn () => $this->service->requestExchange($order, [
            'items' => [['order_option_id' => 999999, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
        ]));
    }

    /**
     * 비활성 사유와 없는 사유 코드는 접수를 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_request_freezes_calculation_snapshots
     */
    public function test_request_rejects_inactive_and_unknown_reason(): void
    {
        $order = $this->deliveredOrder();

        $this->assertClaimException('claim_reason_unavailable', fn () => $this->service->requestExchange($order, $this->payload($order, 'retired_reason')));
        $this->assertClaimException('claim_reason_unavailable', fn () => $this->service->requestExchange($order, $this->payload($order, 'nope')));
    }

    /**
     * 다른 클레임이 이미 잠근 수량은 접수를 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_request_freezes_calculation_snapshots
     */
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

    /**
     * 신청 기간이 지나면 접수를 거절한다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_request_period_is_measured_from_delivery
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
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

    /**
     * 배송완료 시각이 없으면 구매확정 시각을 신청 기간 기준으로 쓴다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_request_period_is_measured_from_delivery
     */
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

        $this->assertClaimException('claim_request_period_expired', fn () => $this->service->requestExchange(
            $order->fresh(['options', 'shippings', 'payment']),
            $this->payload($order)
        ));
    }

    /**
     * 기준 시각이 모두 기간 안이면 접수가 통과한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_request_period_is_measured_from_delivery
     */
    public function test_request_is_allowed_when_no_timestamp_is_stale(): void
    {
        // 두 시각이 모두 없는 구형 주문(컴럼이 생기기 전)은 마지막 변경 시각으로 내려간다 —
        // 창은 닫히되 정상 신청은 막지 않는다.
        app(EcommerceSettingsService::class)->setSetting('claim.request_period_days', 1);

        $order = $this->deliveredOrder();
        $order->options->first()->forceFill(['delivered_at' => null, 'confirmed_at' => null])->save();

        $exchange = $this->service->requestExchange(
            $order->fresh(['options', 'shippings', 'payment']),
            $this->payload($order)
        );

        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->exchange_status);
    }

    // ================================================================
    // 신청 — 옵션 변경 / 차액 3분기 (저가·동가·고가)
    // ================================================================

    /**
     * 대상 옵션을 주지 않으면 차액 0 의 동일상품 교환이 된다.
     *
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_same_product_request_has_zero_difference
     */
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

    /**
     * 같은 옵션 ID 를 지목하면 동일상품 교환으로 다룬다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_same_product_request_has_zero_difference
     */
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

    /**
     * 비싼 옵션으로 바꾸면 그 차액을 청구한다.
     *
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=higher, charge_method=pay_on_request, additional_options=unchanged
     *
     * @effects exchange_higher_priced_option_charges_the_difference
     */
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

    /**
     * 싼 옵션으로 바꾸면 돌려줄 차액으로 기록한다.
     *
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=lower, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_lower_priced_option_freezes_a_refundable_difference
     */
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

    /**
     * 오르고 내린 차액이 섞이면 항목 전체를 상계해 낸다.
     *
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=mixed, charge_method=pay_on_request, additional_options=unchanged
     *
     * @effects exchange_mixed_items_are_netted_into_one_charge
     */
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

    /**
     * 다른 상품의 옵션으로는 교환할 수 없다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_new_option_must_belong_to_the_same_product
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
    public function test_request_rejects_an_option_of_another_product(): void
    {
        $order = $this->deliveredOrder();
        [, $foreignOption] = $this->createClaimProduct(15000);

        $this->assertClaimException('claim_new_option_invalid', fn () => $this->service->requestExchange(
            $order,
            $this->payload($order, newProductOptionId: $foreignOption->id)
        ));
    }

    /**
     * 비활성이거나 없는 교환 대상 옵션은 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_same_option_is_rejected_when_inactive
     */
    public function test_request_rejects_an_inactive_or_unknown_option(): void
    {
        $order = $this->deliveredOrder();
        $inactive = $this->siblingOption($order, 0);
        $inactive->forceFill(['is_active' => false])->save();

        $this->assertClaimException('claim_new_option_invalid', fn () => $this->service->requestExchange($order, $this->payload($order, newProductOptionId: $inactive->id)));
        $this->assertClaimException('claim_new_option_invalid', fn () => $this->service->requestExchange($order, $this->payload($order, newProductOptionId: 999999)));
    }

    /**
     * 동일옵션 교환도 판매 중지된 옵션이면 거절한다.
     *
     * 옵션을 바꾸는 경로만 `is_active` 를 보고 **동일옵션 경로는 그 검사를 건너뛰던** 것을 막는다 —
     * 판매를 접은 옵션을 그대로 다시 보내 달라는 신청이 접수됐다(2026-09-22 #599 3차 실측 L-4 · T2-D11:
     * 원 옵션을 `is_active=false` 로 두고 동일옵션 교환을 내자 201 로 접수됐다).
     *
     * @return void 반환값 없음
     *
     * @effects exchange_same_option_is_rejected_when_inactive
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
    public function test_request_rejects_the_same_option_when_it_is_inactive(): void
    {
        $order = $this->deliveredOrder();
        $orderedOption = $order->options->first()->productOption;
        $orderedOption->forceFill(['is_active' => false])->save();

        // newProductOptionId 를 보내지 않는 동일옵션 교환
        $this->assertClaimException(
            'claim_new_option_invalid',
            fn () => $this->service->requestExchange($order, $this->payload($order))
        );
    }

    /**
     * 지금 살 수 없는 상품으로는 교환받을 수 없다 — 상품 단위 조건 4종.
     *
     * 옵션의 `is_active` 만 보던 검사가 **상품의 판매·전시·구매제한 상태를 보지 않아**, 품절·판매중지·
     * 출시예정·구매등급제한 상품으로도 교환이 접수됐다(2026-09-22 #599 3차 실측 L-4 · T2-D11 후속 조사).
     *
     * @return void 반환값 없음
     *
     * @effects exchange_new_option_must_be_purchasable_now
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
    public function test_request_rejects_when_the_product_is_not_purchasable(): void
    {
        foreach ([
            ['sales_status' => ProductSalesStatus::SOLD_OUT->value],
            ['sales_status' => ProductSalesStatus::SUSPENDED->value],
            ['sales_status' => ProductSalesStatus::COMING_SOON->value],
            ['display_status' => ProductDisplayStatus::HIDDEN->value],
            ['purchase_restriction' => 'restricted', 'allowed_roles' => ['nobody']],
        ] as $override) {
            $order = $this->deliveredOrder();
            $sibling = $this->siblingOption($order, 0);
            $sibling->product->forceFill($override)->save();

            $this->assertClaimException(
                'claim_new_option_invalid',
                fn () => $this->service->requestExchange($order, $this->payload($order, newProductOptionId: $sibling->id)),
            );
        }
    }

    /**
     * 새 옵션에 재고가 없으면 접수를 거절한다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_new_option_without_stock_is_rejected
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
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

    /**
     * 구매자가 적어 넣은 주문제작 입력값을 스냅샷에 남긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_request_freezes_calculation_snapshots
     */
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

    /**
     * 왕복 배송비와 총 청구액을 접수 시점 값으로 굳힌다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_round_trip_fee_and_total_charge_are_frozen
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
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

    /**
     * 판매자 귀책이면 배송비 없이 차액만 청구한다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_seller_fault_charges_only_the_difference
     *
     * @scenario price_delta=higher, charge_method=pay_on_request, additional_options=unchanged
     */
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

    /**
     * 총 청구액이 있으면 청구 방식을 요구한다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_charge_method_is_required_when_a_charge_remains
     *
     * @scenario price_delta=higher, charge_method=pay_on_request, additional_options=unchanged
     */
    public function test_request_requires_charge_method_when_total_charge_is_positive(): void
    {
        $order = $this->deliveredOrder();
        $newOption = $this->siblingOption($order, 3000);

        $this->assertClaimException('claim_charge_method_required', fn () => $this->service->requestExchange(
            $order,
            $this->payload($order, newProductOptionId: $newOption->id)
        ));
    }

    /**
     * 총 청구액이 0 이면 청구 방식을 비운다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_charge_method_is_cleared_when_nothing_is_charged
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
    public function test_request_nulls_charge_method_when_total_charge_is_zero(): void
    {
        $order = $this->deliveredOrder();

        $exchange = $this->service->requestExchange($order, $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST));

        $this->assertNull($exchange->charge_method);
        $this->assertFalse($exchange->requiresAdditionalPayment());
    }

    /**
     * 설정이 허용하지 않는 청구 방식은 거절한다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_charge_method_outside_the_allowed_list_is_rejected
     *
     * @scenario price_delta=higher, charge_method=pay_on_request, additional_options=unchanged
     */
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

    /**
     * 차액을 더 내야 하는 교환에는 「환불금에서 차감」을 쓸 수 없다.
     *
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=higher, charge_method=deduct_from_refund, additional_options=unchanged
     *
     * @effects exchange_deduct_from_refund_requires_a_covering_refundable_difference
     */
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

    /**
     * 돌려줄 차액이 배송비를 덮으면 「환불금에서 차감」을 받아들인다.
     *
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=lower, charge_method=deduct_from_refund, additional_options=unchanged
     *
     * @effects exchange_deduct_from_refund_requires_a_covering_refundable_difference
     */
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

    /**
     * 환불 차액보다 회수비가 크면 「환불금에서 차감」을 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=lower, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_deduct_from_refund_requires_a_covering_refundable_difference
     */
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

    /**
     * 같은 가격의 옵션으로 바꿔도 왕복 배송비는 청구한다.
     *
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=pay_on_request, additional_options=unchanged
     *
     * @effects exchange_round_trip_fee_and_total_charge_are_frozen
     */
    public function test_request_with_an_equal_priced_option_still_charges_the_round_trip_fee(): void
    {
        // 옵션 차액이 0 이어도 왕복 배송비가 남으면 청구는 성립한다 — 청구 방식을 고르는 축은
        // 차액이 아니라 총 청구액이다.
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setExchangeFixedFee($policy, 6000);

        $exchange = $this->service->requestExchange($order, $this->payload(
            $order,
            chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST,
        ));

        $this->assertSame(0.0, (float) $exchange->exchangeOptions->first()->price_difference);
        $this->assertSame(6000.0, (float) $exchange->total_charge_amount);
        $this->assertSame(ClaimChargeMethodEnum::PAY_ON_REQUEST, $exchange->charge_method);
        $this->assertTrue($exchange->requiresAdditionalPayment());
    }

    /**
     * 차액이 0 인 교환에는 「환불금에서 차감」을 쓸 수 없다.
     *
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=deduct_from_refund, additional_options=unchanged
     *
     * @effects exchange_deduct_from_refund_requires_a_covering_refundable_difference
     */
    public function test_request_rejects_deduct_from_refund_for_an_equal_priced_exchange(): void
    {
        // 동가 교환은 돌려줄 차액이 0 이라 왕복 배송비를 뺄 곳이 없다.
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setExchangeFixedFee($policy, 6000);

        $this->assertClaimException('claim_charge_method_not_allowed', fn () => $this->service->requestExchange(
            $order,
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::DEDUCT_FROM_REFUND)
        ));
    }

    /**
     * 저가 교환에서도 배송비를 따로 결제하는 방식을 고를 수 있다.
     *
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=lower, charge_method=pay_on_request, additional_options=unchanged
     *
     * @effects exchange_lower_priced_option_freezes_a_refundable_difference
     */
    public function test_request_can_charge_the_fee_separately_for_a_lower_priced_exchange(): void
    {
        // 저가 교환이라도 환불금 차감을 고르지 않으면 배송비는 별도 결제로 남고, 차액은 그대로
        // 환불 대상에 머문다 — 두 금액이 서로를 상계하지 않는다.
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setExchangeFixedFee($policy, 6000);
        $newOption = $this->siblingOption($order, -4000);

        $exchange = $this->service->requestExchange($order, $this->payload(
            $order,
            chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST,
            newProductOptionId: $newOption->id,
        ));

        $this->assertSame(6000.0, (float) $exchange->total_charge_amount);
        $this->assertSame(4000.0, (float) $exchange->refund_product_amount);
        $this->assertSame(ClaimChargeMethodEnum::PAY_ON_REQUEST, $exchange->charge_method);
        $this->assertTrue($exchange->requiresAdditionalPayment());
    }

    /**
     * 차액이 섞인 교환은 굳혀 둔 순 환불액에서 배송비를 뺀다.
     *
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=mixed, charge_method=deduct_from_refund, additional_options=unchanged
     *
     * @effects exchange_mixed_net_refund_is_frozen_at_request_time
     */
    public function test_request_deducts_the_fee_from_the_frozen_net_refund_of_a_mixed_exchange(): void
    {
        // 고가·저가가 섞인 신청서의 환불금 차감은 상계 **후** 순액을 기준으로 판정한다 —
        // 음수 차액 합(5,000)이 아니라 동결 순액(3,000)이 배송비 3,000 을 덮는지 본다.
        [$exchange] = $this->twoItemOptionChangeExchange(2000, -5000, ClaimChargeMethodEnum::DEDUCT_FROM_REFUND, 3000);

        $this->assertSame(3000.0, (float) $exchange->refund_product_amount, '동결 순액 = −5,000 + 2,000.');
        $this->assertSame(ClaimChargeMethodEnum::DEDUCT_FROM_REFUND, $exchange->charge_method);
        $this->assertSame(3000.0, (float) $exchange->total_charge_amount);
        $this->assertFalse($exchange->requiresAdditionalPayment(), '환불금 차감은 별도 결제 대상이 아니다.');
    }

    /**
     * @effects exchange_multi_currency_amounts_come_from_the_order_snapshot
     */
    /**
     * 차액 0 교환도 「승인 후 결제」를 받아들인다.
     *
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=pay_on_approval, additional_options=unchanged
     *
     * @effects exchange_pay_on_approval_does_not_ask_for_payment_at_request_time
     */
    public function test_request_accepts_pay_on_approval_for_an_equal_priced_exchange(): void
    {
        // 승인 시점 결제는 접수 시점에 결제를 요구하지 않는다 — 금액 구성은 신청 시점 결제와
        // 같고, 결제 행이 만들어지는 순간만 뒤로 밀린다.
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setExchangeFixedFee($policy, 6000);

        $exchange = $this->service->requestExchange($order, $this->payload(
            $order,
            chargeMethod: ClaimChargeMethodEnum::PAY_ON_APPROVAL,
        ));

        $this->assertSame(ClaimChargeMethodEnum::PAY_ON_APPROVAL, $exchange->charge_method);
        $this->assertSame(6000.0, (float) $exchange->total_charge_amount);
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->exchange_status);
    }

    /**
     * 고가 교환도 「승인 후 결제」를 받아들인다.
     *
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=higher, charge_method=pay_on_approval, additional_options=unchanged
     *
     * @effects exchange_pay_on_approval_does_not_ask_for_payment_at_request_time
     */
    public function test_request_accepts_pay_on_approval_for_a_higher_priced_exchange(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setExchangeFixedFee($policy, 6000);
        $newOption = $this->siblingOption($order, 3000);

        $exchange = $this->service->requestExchange($order, $this->payload(
            $order,
            chargeMethod: ClaimChargeMethodEnum::PAY_ON_APPROVAL,
            newProductOptionId: $newOption->id,
        ));

        $this->assertSame(ClaimChargeMethodEnum::PAY_ON_APPROVAL, $exchange->charge_method);
        $this->assertSame(9000.0, (float) $exchange->total_charge_amount, '왕복배송비 6,000 + 차액 3,000.');
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->exchange_status);
    }

    /**
     * 저가 교환도 「승인 후 결제」를 받아들인다.
     *
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=lower, charge_method=pay_on_approval, additional_options=unchanged
     *
     * @effects exchange_pay_on_approval_does_not_ask_for_payment_at_request_time
     */
    public function test_request_accepts_pay_on_approval_for_a_lower_priced_exchange(): void
    {
        // 저가 교환에서도 승인 시점 결제를 고르면 배송비는 별도 청구로 남고 차액은 환불 대상에
        // 머문다 — 두 금액이 서로를 상계하지 않는다(환불금 차감과 갈리는 지점).
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(29000, 1, $policy);
        $this->setExchangeFixedFee($policy, 6000);
        $newOption = $this->siblingOption($order, -4000);

        $exchange = $this->service->requestExchange($order, $this->payload(
            $order,
            chargeMethod: ClaimChargeMethodEnum::PAY_ON_APPROVAL,
            newProductOptionId: $newOption->id,
        ));

        $this->assertSame(ClaimChargeMethodEnum::PAY_ON_APPROVAL, $exchange->charge_method);
        $this->assertSame(6000.0, (float) $exchange->total_charge_amount);
        $this->assertSame(4000.0, (float) $exchange->refund_product_amount);
    }

    /**
     * 차액이 섞인 교환도 「승인 후 결제」를 받아들인다.
     *
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=mixed, charge_method=pay_on_approval, additional_options=unchanged
     *
     * @effects exchange_pay_on_approval_does_not_ask_for_payment_at_request_time
     */
    public function test_request_accepts_pay_on_approval_for_a_mixed_exchange(): void
    {
        [$exchange] = $this->twoItemOptionChangeExchange(2000, -5000, ClaimChargeMethodEnum::PAY_ON_APPROVAL, 3000);

        $this->assertSame(ClaimChargeMethodEnum::PAY_ON_APPROVAL, $exchange->charge_method);
        $this->assertSame(3000.0, (float) $exchange->refund_product_amount, '동결 순액 = −5,000 + 2,000.');
        $this->assertSame(3000.0, (float) $exchange->claim_shipping_fee);
        $this->assertSame(3000.0, (float) $exchange->total_charge_amount, '상계 순액이 환불이라 청구는 배송비뿐이다.');
    }

    /**
     * 보조 통화 금액은 주문 시점 통화 스냅샷으로 환산해 기록한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_multi_currency_amounts_come_from_the_order_snapshot
     */
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

    /**
     * 회수 주소는 교환 정책의 클레임 주소에서 굳힌다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_redelivery_address_defaults_to_the_order_shipping_address
     */
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

    /**
     * 재발송지를 주지 않으면 주문 배송지를 기본으로 쓴다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_redelivery_address_defaults_to_the_order_shipping_address
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
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
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_redelivery_address_defaults_to_the_order_shipping_address
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
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_redelivery_address_defaults_to_the_order_shipping_address
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
     *
     * @return void 반환값 없음
     *
     * @effects exchange_redelivery_address_must_stay_in_the_same_country
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
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

    /**
     * 국가 코드의 대소문자가 달라도 같은 나라로 받아들인다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_redelivery_address_must_stay_in_the_same_country
     */
    public function test_request_accepts_the_same_country_in_any_letter_case(): void
    {
        $order = $this->deliveredOrder();

        $exchange = $this->service->requestExchange($order, $this->payload($order, redeliveryAddress: ['country_code' => 'kr']));

        $this->assertSame('KR', $exchange->redelivery_address_snapshot['country_code']);
    }

    // ================================================================
    // 신청 — 훅 / 자동 승인
    // ================================================================

    /**
     * 접수가 `requested` 훅을 발행한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_request_freezes_calculation_snapshots
     */
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

    /**
     * 설정이 켜져 있고 청구액이 0 이면 자동 승인하며 새 옵션 재고를 뺀다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_auto_approval_needs_a_zero_charge
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
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

    /**
     * 자동 승인이 꺼져 있거나 청구액이 남으면 접수에서 멈춘다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_auto_approval_needs_a_zero_charge
     *
     * @scenario price_delta=higher, charge_method=pay_on_request, additional_options=unchanged
     */
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
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_auto_approval_needs_a_zero_charge
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

    /**
     * 미리보기 금액이 실제 접수 금액과 같다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_preview_matches_the_requested_amounts
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
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

    /**
     * 미리보기가 보조통화 청구액을 함께 낸다 — 접수 시 박제되는 값과 같아야 한다.
     *
     * 이 값이 없으면 보조통화를 쓰는 구매자는 신청 화면에서 자기 통화의 청구액을 볼 수 없고
     * 결제 단계에 가서야 실제 금액을 알게 된다. 화면이 스스로 환산하는 것은 답이 아니다 —
     * 예고와 청구의 근거가 갈린다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_multi_currency_amounts_come_from_the_order_snapshot
     */
    public function test_preview_reports_multi_currency_charge_matching_the_request(): void
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

        $order = $order->fresh(['options', 'shippings', 'payment', 'shippingAddress']);
        $payload = $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST, newProductOptionId: $newOption->id);

        $preview = $this->service->previewExchange($order, $payload['items'], 'size_mismatch', ClaimChargeMethodEnum::PAY_ON_REQUEST);
        $exchange = $this->service->requestExchange($order, $payload);

        $this->assertSame(9000, $preview['mc_total_charge_amount']['KRW']);
        $this->assertSame(6.75, $preview['mc_total_charge_amount']['USD']);
        // 예고와 기록이 같은 값이어야 한다.
        $this->assertSame($exchange->mc_total_charge_amount, $preview['mc_total_charge_amount']);

        // 옵션 차액 줄도 형제 금액 줄과 같은 규약으로 보조통화를 낸다(S17 트랙 A F-1).
        // 이 값이 없으면 요약의 '옵션 차액' 만 기준 통화 한 줄로 남는다 — 바로 위 배송비와 아래
        // 총 청구액에는 보조통화가 붙어 있어 같은 카드 안에서 표기 규약이 갈린다. 저장 행에는
        // 이미 `order_exchange_options.mc_price_difference` 로 실재하는 값이라 예고 경로만 비어 있었다.
        $this->assertSame(3000, $preview['mc_price_difference']['KRW']);
        $this->assertSame(2.25, $preview['mc_price_difference']['USD']);
        $this->assertSame(3000, $preview['mc_additional_product_amount']['KRW']);
        $this->assertSame(0, $preview['mc_refund_product_amount']['KRW']);
    }

    /**
     * 통화 스냅샷이 없는 주문은 보조통화를 낼 근거가 없으므로 null 이다 (화면이 그 줄을 세우지 않는다).
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_multi_currency_amounts_come_from_the_order_snapshot
     */
    public function test_preview_reports_null_multi_currency_when_the_order_has_no_snapshot(): void
    {
        $order = $this->deliveredOrder();
        $order->forceFill(['currency_snapshot' => []])->save();
        $payload = $this->payload($order);

        $preview = $this->service->previewExchange($order->fresh(['options', 'shippings', 'payment', 'shippingAddress']), $payload['items'], 'size_mismatch');

        $this->assertNull($preview['mc_total_charge_amount']);
    }

    /**
     * 미리보기는 어떤 기록도 쓰지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_preview_matches_the_requested_amounts
     */
    public function test_preview_does_not_write_any_record(): void
    {
        $order = $this->deliveredOrder();
        $payload = $this->payload($order);

        $this->service->previewExchange($order, $payload['items'], 'size_mismatch');

        $this->assertSame(0, OrderExchange::count());
        $this->assertSame(OrderStatusEnum::DELIVERED, $order->options->first()->fresh()->option_status);
    }

    /**
     * 저가 옵션으로 바꾸는 교환의 미리보기는 돌려줄 차액을 답한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=lower, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_preview_matches_the_requested_amounts
     */
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
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_preview_matches_the_requested_amounts
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

    /**
     * 철회는 주문옵션을 원래 상태로 되돌린다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_withdraw_reverts_the_order_option
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
    public function test_withdraw_reverts_order_option_to_original_status(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $withdrawn = $this->service->withdraw($exchange);

        $this->assertSame(ExchangeStatusEnum::WITHDRAWN, $withdrawn->exchange_status);
        $this->assertNotNull($withdrawn->withdrawn_at);
        $this->assertSame(OrderStatusEnum::DELIVERED, $order->options->first()->fresh()->option_status);
    }

    /**
     * 승인 뒤에는 철회할 수 없다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_withdraw_is_blocked_after_approval
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
    public function test_withdraw_is_blocked_after_approval(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->service->transition($this->service->requestExchange($order, $this->payload($order)), ExchangeStatusEnum::APPROVED);

        $this->assertClaimException('claim_not_withdrawable', fn () => $this->service->withdraw($exchange));
    }

    /**
     * 반려는 주문옵션을 원래 상태로 되돌리고 반려 사유를 남긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_reject_reverts_each_item_to_its_own_original_status
     */
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

    /**
     * 되돌림은 항목마다 자기 원래 상태로 복원한다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_reject_reverts_each_item_to_its_own_original_status
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
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

    /**
     * 되돌림은 재고를 건드리지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_reject_reverts_each_item_to_its_own_original_status
     */
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

    /**
     * 전이가 생애주기 전 구간에서 게이트를 따른다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_transition_follows_the_status_gate
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
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

    /**
     * 게이트 밖 상태로의 전이는 막는다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_transition_follows_the_status_gate
     */
    public function test_transition_blocks_a_target_outside_the_gate(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $this->assertClaimException('claim_transition_not_allowed', fn () => $this->service->transition($exchange, ExchangeStatusEnum::COLLECTED));
        $this->assertClaimException('claim_transition_not_allowed', fn () => $this->service->transition($exchange, ExchangeStatusEnum::REDELIVERING));
    }

    /**
     * 완료로의 직접 전이는 막는다 — 완료는 완료 처리의 소유다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_completion_is_not_reachable_by_a_plain_transition
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
    public function test_transition_to_completed_is_blocked(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->advanceTo($this->service->requestExchange($order, $this->payload($order)), ExchangeStatusEnum::REDELIVERED);

        $this->assertClaimException('claim_completion_flow_required', fn () => $this->service->transition($exchange, ExchangeStatusEnum::COMPLETED));
    }

    /**
     * 보류 전이가 보류 시각을 찍는다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_on_hold_is_stamped_and_released_through_the_gate
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
    public function test_transition_stamps_on_hold_time(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->service->transition($this->service->requestExchange($order, $this->payload($order)), ExchangeStatusEnum::ON_HOLD);

        $this->assertNotNull($exchange->on_hold_at);
        $this->assertSame(ExchangeStatusEnum::ON_HOLD, $exchange->exchangeOptions->first()->option_status);
    }

    /**
     * 승인이 새 옵션의 재고를 뺀다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_approval_deducts_the_new_option_stock
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
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

    /**
     * 접수 뒤 새 옵션이 품절되면 승인이 실패한다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_approval_fails_when_the_new_option_ran_out
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
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

    /**
     * 전이가 공통 상태변경 훅과 그 상태 전용 훅을 함께 발행한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_transition_follows_the_status_gate
     */
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

    /**
     * 일괄 전이는 건너뛴 건과 없는 건을 결과에 적어 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_transition_follows_the_status_gate
     */
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

    /**
     * 선택한 상품만 옮기고, 전부 같은 상태가 되면 헤더도 따라간다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_item_level_transition_moves_the_header_only_when_items_agree
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
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

    /**
     * 상품 단위 전이는 잘못된 목표를 건너뛰고 완료로는 옮기지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_item_level_transition_moves_the_header_only_when_items_agree
     */
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
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_item_level_transition_moves_the_header_only_when_items_agree
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

    /**
     * 한 상품만 반려하면 그 상품만 되돌아간다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_reject_reverts_each_item_to_its_own_original_status
     */
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
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_item_level_transition_moves_the_header_only_when_items_agree
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

    /**
     * 모든 상품이 반려되면 신청서 헤더도 따라 반려로 간다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_item_level_transition_moves_the_header_only_when_items_agree
     */
    public function test_transition_option_moves_header_when_every_item_is_rejected(): void
    {
        [$exchange, $itemA, $itemB] = $this->twoItemExchange();

        $this->service->transitionOption($exchange, [$itemA, $itemB], ExchangeStatusEnum::REJECTED);

        $this->assertSame(ExchangeStatusEnum::REJECTED, $exchange->fresh()->exchange_status);
    }

    /**
     * 새 옵션이 품절인 상품은 건너뛰고 나머지는 승인한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_approval_fails_when_the_new_option_ran_out
     */
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

    /**
     * 상품들이 시차를 두고 진행돼도 헤더가 따라간다 — 재발송 단계는 건너뛸 수 있으므로(#599 후속 운영 결정)
     * 회수도착에서 곧바로 발송중으로 옮기고, 지나간 발송준비중 알림은 뒤늦게 보내지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_item_level_transition_moves_the_header_only_when_items_agree
     */
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
        $this->service->transitionOption($fresh(), [$itemA], ExchangeStatusEnum::REDELIVERING, $this->claimTrackingFor(ExchangeStatusEnum::REDELIVERING));
        $this->service->transitionOption($fresh(), [$itemB], ExchangeStatusEnum::REDELIVERY_READY);

        $this->assertSame(ExchangeStatusEnum::COLLECTED, $exchange->fresh()->exchange_status);

        $this->service->transitionOption($fresh(), [$itemB], ExchangeStatusEnum::REDELIVERING, $this->claimTrackingFor(ExchangeStatusEnum::REDELIVERING));

        $this->assertSame(ExchangeStatusEnum::REDELIVERING, $exchange->fresh()->exchange_status);
        $this->assertSame(
            ['requested>approved', 'approved>collection_ready', 'collection_ready>collecting', 'collecting>collected', 'collected>redelivering'],
            $changed
        );
        // 두 상품 모두 후속 주문옵션이 생겼다 — 상품 단위 경로도 같은 부수효과를 탄다.
        $this->assertNotNull(OrderExchangeOption::find($itemA)->new_order_option_id);
        $this->assertNotNull(OrderExchangeOption::find($itemB)->new_order_option_id);
    }

    /**
     * 처리자를 주지 않은 전이는 기존 처리자를 그대로 둔다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_item_level_transition_moves_the_header_only_when_items_agree
     */
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

    /**
     * 회수 송장 저장은 송장만 기록하고 상태는 옮기지 않는다 (#599 후속 운영 결정 — 저장과 전이는 별개).
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects tracking_save_does_not_move_the_status
     */
    public function test_register_collection_tracking_saves_without_moving_the_status(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->advanceTo($this->service->requestExchange($order, $this->payload($order)), ExchangeStatusEnum::COLLECTION_READY);
        $carrier = $this->carrier();

        $itemId = (int) $exchange->exchangeOptions->first()->id;

        // 「송장만 저장」 은 고른 상품 행에 송장을 적을 뿐 상태를 옮기지 않는다(R7). 앞뒤 공백은 다듬는다.
        $result = $this->service->writeItemTracking($exchange, ClaimTrackingDirectionEnum::COLLECTION, [$itemId], $carrier->id, ' 1234567890 ');

        $row = OrderExchangeOption::find($itemId);
        $this->assertSame(ExchangeStatusEnum::COLLECTION_READY, $result['claim']->fresh()->exchange_status, '송장 저장이 상태를 옮겼습니다.');
        $this->assertSame(ExchangeStatusEnum::COLLECTION_READY, $row->option_status);
        $this->assertSame('1234567890', $row->collection_tracking_number);
        $this->assertSame($carrier->id, (int) $row->collection_carrier_id);
    }

    /**
     * 송장은 택배사와 번호를 함께 넣어야 등록된다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_transition_follows_the_status_gate
     */
    public function test_register_tracking_requires_both_fields(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->advanceTo($this->service->requestExchange($order, $this->payload($order)), ExchangeStatusEnum::COLLECTION_READY);

        $itemId = (int) $exchange->exchangeOptions->first()->id;

        $this->assertClaimException('claim_tracking_incomplete', fn () => $this->service->writeItemTracking($exchange, ClaimTrackingDirectionEnum::COLLECTION, [$itemId], null, 'ABC'));
        $this->assertClaimException('claim_tracking_incomplete', fn () => $this->service->writeItemTracking($exchange, ClaimTrackingDirectionEnum::REDELIVERY, [$itemId], 1, ''));
    }

    /**
     * 송장을 실은 발송중 전이가 재발송 송장을 기록하고 후속 주문옵션을 만든다 — 그 배송 행에 송장이 실린다.
     *
     * 「송장만 저장」(`writeItemTracking`)은 상태를 옮기지 않는다(R7). 발송중은 전이가 송장을 실어 들어가고,
     * 후속 주문옵션의 배송 행은 **그 상품 행의** 재발송 송장을 읽는다(R11) — 전이가 실은 송장이 먼저 적힌 값을 덮는다.
     *
     * @return void 반환값 없음
     *
     * @effects exchange_redelivery_tracking_creates_the_successor_option
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
    public function test_redelivering_with_tracking_creates_the_successor_option(): void
    {
        $order = $this->deliveredOrder();
        $newOption = $this->siblingOption($order, 0);
        $exchange = $this->advanceTo(
            $this->service->requestExchange($order, $this->payload($order, newProductOptionId: $newOption->id)),
            ExchangeStatusEnum::REDELIVERY_READY
        );
        $carrier = $this->carrier();
        $original = $order->options->first()->fresh();

        $itemId = (int) $exchange->exchangeOptions->first()->id;
        $saved = $this->service->writeItemTracking($exchange, ClaimTrackingDirectionEnum::REDELIVERY, [$itemId], $carrier->id, 'RD-0000')['claim'];
        $this->assertSame(ExchangeStatusEnum::REDELIVERY_READY, $saved->fresh()->exchange_status, '송장 저장이 상태를 옮겼습니다.');

        $moving = $this->service->transition($saved->fresh(['exchangeOptions']), ExchangeStatusEnum::REDELIVERING, ['carrier_id' => $carrier->id, 'tracking_number' => 'RD-0001']);

        $this->assertSame(ExchangeStatusEnum::REDELIVERING, $moving->exchange_status);

        $item = $moving->fresh(['exchangeOptions'])->exchangeOptions->first();
        $this->assertSame('RD-0001', $item->redelivery_tracking_number);
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

    /**
     * 후속 주문옵션은 금액 컬럼을 물려받고 식별 값만 새 옵션으로 바뀐다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_substitution_inherits_shipping_policy_and_coupons
     */
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

    /**
     * 후속 주문옵션은 한 번만 만들어지고 도착 시 배송완료가 된다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_redelivery_tracking_creates_the_successor_option
     */
    public function test_successor_option_is_created_once_and_delivered_on_arrival(): void
    {
        [$exchange, $order, $successor] = $this->redeliveredExchange();

        $this->assertSame(OrderStatusEnum::DELIVERED, $successor->option_status);
        $this->assertNotNull($successor->delivered_at);
        $this->assertSame(1, OrderOption::where('source_option_id', $order->options->first()->id)->count(), '후속 옵션은 한 번만 만들어진다.');
        $this->assertNotNull($exchange->redelivered_at);
    }

    /**
     * 발송중인 교환품을 구매자가 먼저 구매확정해도 발송도착·교환완료 처리가 막히지 않는다.
     *
     * 후속 주문옵션은 `shipping` 으로 생겨 구매확정 대상이다. 확정은 받았다는 뜻이므로 도착 처리는
     * 확정 상태를 배송완료로 되돌리지 않고 배송 행만 도착으로 옮긴다 — 되돌리면 구매확정 → 배송완료
     * 역전이로 전이 가드가 완료를 통째로 거절해 신청서가 발송중에 갇힌다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_redelivery_arrival_keeps_a_successor_the_buyer_confirmed
     */
    public function test_redelivery_arrival_and_completion_keep_a_successor_the_buyer_already_confirmed(): void
    {
        foreach (['complete', 'arrive'] as $path) {
            $order = $this->deliveredOrder();
            $exchange = $this->advanceTo(
                $this->service->requestExchange($order, $this->payload($order)),
                ExchangeStatusEnum::REDELIVERING
            );
            $item = $exchange->fresh(['exchangeOptions'])->exchangeOptions->first();
            $successor = OrderOption::findOrFail($item->new_order_option_id);
            $this->assertSame(OrderStatusEnum::SHIPPING, $successor->option_status);

            app(OrderService::class)->confirmOption($order->fresh(), $successor);
            $confirmedAt = $successor->fresh()->confirmed_at;
            $this->assertNotNull($confirmedAt);

            $result = $path === 'complete'
                ? $this->service->completeExchange($exchange->fresh(['exchangeOptions']), [(int) $item->id], $this->completionOptions($exchange->fresh(['exchangeOptions']), false, false))
                : $this->service->transition($exchange->fresh(['exchangeOptions']), ExchangeStatusEnum::REDELIVERED, $this->claimTrackingFor(ExchangeStatusEnum::REDELIVERED));

            $this->assertSame(
                $path === 'complete' ? ExchangeStatusEnum::COMPLETED : ExchangeStatusEnum::REDELIVERED,
                $result->exchange_status,
                "[{$path}] 구매확정된 후속 옵션이 처리를 막았습니다."
            );
            $this->assertSame(OrderStatusEnum::CONFIRMED, $successor->fresh()->option_status, "[{$path}] 확정된 후속 옵션이 배송완료로 되돌아갔습니다.");
            $this->assertEquals($confirmedAt, $successor->fresh()->confirmed_at, "[{$path}] 확정 시각이 바뀌었습니다.");
            $this->assertSame(
                ShippingStatusEnum::DELIVERED,
                OrderShipping::where('order_option_id', $successor->id)->firstOrFail()->shipping_status,
                "[{$path}] 후속 옵션의 배송 행이 도착으로 옮겨지지 않았습니다."
            );
        }
    }

    // ================================================================
    // 금액 수정
    // ================================================================

    /**
     * 금액 수정이 회수비를 따라 총 청구액을 옮기고 옵션 차액은 그대로 둔다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=higher, charge_method=pay_on_request, additional_options=unchanged
     *
     * @effects exchange_round_trip_fee_and_total_charge_are_frozen
     */
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

    /**
     * 청구할 것이 하나도 남지 않을 때만 청구 방식을 비운다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_charge_method_is_cleared_when_nothing_is_charged
     */
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
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=higher, charge_method=pay_on_request, additional_options=unchanged
     *
     * @effects exchange_charge_method_is_required_when_a_charge_remains
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

    /**
     * 완료는 신청 항목과 주문옵션을 교환완료로 찍는다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_completion_refund_is_filed_only_when_asked
     */
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
     * 완료해도 원 옵션의 **이미 확정된 적립**은 회수하지 않는다 — 교환은 매출이 신규 옵션으로
     * 승계되므로, 후속 옵션이 구매확정에 닿으면 그쪽에서 적립이 다시 계산된다. 원 옵션의 적립은
     * 이미 원장에 확정된 기록이라 되돌리지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects exchange_completion_keeps_the_earned_mileage
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
    public function test_complete_keeps_the_earned_mileage_of_the_original_option(): void
    {
        [$exchange, $order] = $this->redeliveredExchange();
        $orderOptionId = (int) $exchange->exchangeOptions->first()->order_option_id;

        $earnLot = MileageTransaction::create([
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

        // 운영 결정 2026-09-23 — 원장에 확정된 적립은 교환완료로도 되돌리지 않는다.
        // 교환은 매출이 신규 옵션으로 승계되므로, 새 옵션이 구매확정에 도달하면 그쪽에서 적립이
        // 다시 계산된다. 원 옵션의 적립을 여기서 빼면 이미 확정된 기록만 깨진다.
        $this->assertSame(
            0,
            MileageTransaction::where('order_option_id', $orderOptionId)
                ->where('type', MileageTransactionTypeEnum::EARN_CANCEL->value)
                ->count(),
            '적립이 끝난 건은 교환완료로 회수하지 않아야 한다.'
        );

        $this->assertSame(
            '500.00',
            (string) $earnLot->fresh()->remaining_amount,
            '적립 lot 의 잔여가 그대로여야 한다 — 자기 lot 에서 빼는 것도 회수다.'
        );
    }

    /**
     * 발송되지 않은 상품은 완료 처리를 거절한다 — 발송중부터는 완료할 수 있다(#599 후속 운영 결정), 그 전(발송준비중)은 아니다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_completion_refund_is_filed_only_when_asked
     */
    public function test_complete_rejects_items_that_have_not_arrived(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->advanceTo($this->service->requestExchange($order, $this->payload($order)), ExchangeStatusEnum::REDELIVERY_READY);

        $this->assertClaimException('claim_completion_items_invalid', fn () => $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, false)));
    }

    /**
     * 완료는 체크한 상품의 원래 재고만 되돌린다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_completion_restores_original_stock_only_for_checked_items
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
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

    /**
     * 체크하지 않은 상품의 재고는 완료에서 건드리지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_completion_restores_original_stock_only_for_checked_items
     */
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

    /**
     * 완료 실행은 설정 기본값이 아니라 요청이 체크한 것만 본다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_completion_restores_original_stock_only_for_checked_items
     */
    public function test_complete_ignores_settings_defaults(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.complete_defaults', ['create_refund_request' => true, 'restore_stock' => true]);
        [$exchange, $order] = $this->redeliveredExchange(-4000);
        $before = (int) ProductOption::find($order->options->first()->product_option_id)->stock_quantity;

        $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, false));

        $this->assertSame($before, (int) ProductOption::find($order->options->first()->product_option_id)->stock_quantity);
        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());
    }

    /**
     * 환불신청서를 고르지 않은 완료는 환불신청서를 만들지 않는다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_completion_refund_is_filed_only_when_asked
     *
     * @scenario price_delta=lower, charge_method=none, additional_options=unchanged
     */
    public function test_complete_without_refund_request_creates_no_refund(): void
    {
        [$exchange, $order] = $this->redeliveredExchange(-4000);

        $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, false));

        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());
    }

    /**
     * 교환 차액 환불의 **신청상품 행 생성**도 상품 축 활동로그를 남긴다 (§7-1 C11).
     *
     * 헤더 훅만 쏘면 "차액 환불신청서가 생겼다" 한 줄로 끝나고, 어느 상품의 차액인지는 어떤 줄에도
     * 남지 않는다. DoD 4 는 §7-1 표의 `✕ 없음` 행이 0 이어야 한다고 적었다.
     * 새로 생기는 행이라 이전 값은 `null` 이다(교환 재발송 신규행과 같은 표기).
     *
     * 되돌리면 red — 생성 지점에서 상품 축 훅 발행을 빼면 수집된 배열이 빈다.
     *
     * @return void 반환값 없음
     *
     * @scenario exchange_mode=change, preview_result=ok
     *
     * @effects exchange_refund_option_rows_announce_their_creation
     */
    public function test_complete_refund_announces_the_created_option_rows(): void
    {
        $announced = [];

        HookManager::addAction(
            'sirsoft-ecommerce.claim.refund.option_status_changed',
            function ($refund, array $changes = []) use (&$announced) {
                foreach ($changes as $change) {
                    $announced[] = $change;
                }
            },
        );

        [$exchange, $order] = $this->redeliveredExchange(-4000);

        $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, true));

        $refund = OrderRefund::where('order_id', $order->id)->firstOrFail();

        $this->assertNotEmpty(
            $announced,
            '교환 차액 환불의 신청상품 행 생성이 상품 축 훅을 발행해야 활동로그가 상품 단위로 남는다',
        );
        $this->assertArrayHasKey(
            'previous',
            $announced[0],
            '이전 값 키가 없으면 활동로그가 「무엇에서 무엇으로」를 그릴 수 없다',
        );
        $this->assertNull(
            $announced[0]['previous'],
            '새로 생기는 행이므로 이전 값은 null 이다',
        );
        $this->assertSame(
            OrderRefundOption::where('order_refund_id', $refund->id)->count(),
            count($announced),
            '생성된 신청상품 행 전건이 알려져야 한다 — 일부만 알리면 나머지는 로그에 없다',
        );
    }

    /**
     * 환불신청서 접수를 고르면 차액에 대한 환불신청서가 정확히 한 건 생긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=lower, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_completion_refund_is_filed_only_when_asked
     */
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

    /**
     * 차액 환불이 집행되면 **취소신청서**가 생기고, 상품 상태는 `exchanged` 를 유지한다 (D13).
     *
     * 교환도 환불 프로세스를 따르므로 돈이 빠진 사실은 취소신청서로 남는다. 그렇다고 상품까지
     * `cancelled` 로 뒤집으면 **교환 이력이 사라진다** — 고객은 물건을 받았고(후속 옵션),
     * 원 행은 그 사실을 `exchanged` 로 기록한다.
     *
     * 금액 축도 다르다. 교환 차액 환불은 상품이 주문에 **남으므로** 취소 기록이 주문 합계를
     * 재계산하지 않는다 — 재계산하면 고객이 가진 물건의 값이 주문에서 사라진다(§12-B B2).
     *
     * @return void 반환값 없음
     *
     * @scenario exchange_mode=change, preview_result=ok
     *
     * @effects exchange_refund_completion_records_a_cancellation
     */
    public function test_a_completed_exchange_refund_records_a_cancellation_while_items_stay_exchanged(): void
    {
        [$exchange, $order] = $this->redeliveredExchange(-4000);

        $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, true));

        $refund = OrderRefund::where('order_id', $order->id)->firstOrFail();

        // 교환 차액 환불은 접수에서 멈춘다 — 운영자가 승인하고 [환불하기]를 눌러야 집행된다.
        $refundService = app(OrderRefundRequestService::class);
        $refundService->transition($refund, RefundStatusEnum::APPROVED);
        $refundService->executeRefund($refund->fresh(['refundOptions']));

        $cancel = OrderCancel::where('order_id', $order->id)->first();
        $this->assertNotNull($cancel, '환불이 집행됐으면 취소신청서가 생긴다 (D3·D13)');
        $this->assertSame($cancel->id, $refund->fresh()->order_cancel_id);

        // 상품은 교환완료로 남는다 — 취소로 뒤집히지 않는다.
        $this->assertSame(
            OrderStatusEnum::EXCHANGED,
            $order->options->first()->fresh()->option_status,
            '교환 상품이 취소로 뒤집히면 교환 이력이 사라진다 (D13)',
        );
    }

    /**
     * 「환불금에서 차감」이면 완료 시 환불액에서 왕복 배송비를 뺀다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=lower, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_round_trip_fee_is_deducted_once_across_refund_rounds
     */
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
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=lower, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_refund_request_splits_by_what_the_order_paid
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

    /**
     * 차액 환불은 주문이 실제로 낸 방식대로 PG 분을 먼저 나눈다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_refund_request_splits_by_what_the_order_paid
     *
     * @scenario price_delta=lower, charge_method=none, additional_options=unchanged
     */
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

    /**
     * 돌려줄 돈이 없으면 환불신청서 접수를 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_completion_refund_is_filed_only_when_asked
     */
    public function test_complete_with_refund_request_is_rejected_when_nothing_is_refundable(): void
    {
        [$exchange, $order] = $this->redeliveredExchange(3000);

        $this->assertClaimException('claim_refund_not_applicable', fn () => $this->service->completeExchange($exchange, [], $this->completionOptions($exchange, false, true)));

        $this->assertSame(ExchangeStatusEnum::REDELIVERED, $exchange->fresh()->exchange_status, '거절은 완료 전체를 되돌린다.');
        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());
    }

    /**
     * 두 번째 환불신청서는 거절하고 사후 접수는 한 번만 된다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=lower, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_completion_refund_is_filed_only_when_asked
     */
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
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=lower, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_completion_refund_is_filed_only_when_asked
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
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=lower, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_completion_refund_is_filed_only_when_asked
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
     *
     * @return void 반환값 없음
     *
     * @effects exchange_round_trip_fee_is_deducted_once_across_refund_rounds
     *
     * @scenario price_delta=lower, charge_method=none, additional_options=unchanged
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
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=lower, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_round_trip_fee_is_deducted_once_across_refund_rounds
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
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=mixed, charge_method=deduct_from_refund, additional_options=unchanged
     *
     * @effects exchange_completion_refund_is_filed_only_when_asked
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
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=mixed, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_mixed_net_refund_is_frozen_at_request_time
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
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=mixed, charge_method=deduct_from_refund, additional_options=unchanged
     *
     * @effects exchange_mixed_items_are_netted_into_one_charge
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
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_request_freezes_calculation_snapshots
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

    /**
     * 사후 환불신청서는 완료된 상품이 있어야 접수된다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=lower, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_completion_refund_is_filed_only_when_asked
     */
    public function test_late_refund_request_requires_completed_items(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->advanceTo($this->service->requestExchange($order, $this->payload($order)), ExchangeStatusEnum::REDELIVERED);

        $this->assertClaimException('claim_completion_items_invalid', fn () => $this->service->createRefundRequestForCompleted($exchange));
    }

    /**
     * 일부 상품만 완료하면 신청서 헤더는 열린 채로 둔다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_item_level_transition_moves_the_header_only_when_items_agree
     */
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
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_completion_restores_original_stock_only_for_checked_items
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

    /**
     * 완료된 교환은 진행 중 클레임 수량에서 빠진다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_completion_refund_is_filed_only_when_asked
     */
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
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchanged_successor_row_refunds_at_new_option_price
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

    // ================================================================
    // 미리보기 자격 가드 (S5) / 승인 전 수정 (D13 · D14)
    // ================================================================

    /**
     * 신청할 수 없는 상태의 상품은 미리보기가 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_preview_matches_the_requested_amounts
     */
    public function test_preview_rejects_an_item_that_is_not_in_a_requestable_status(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 1, $this->createClaimShippingPolicy());

        $this->assertClaimException('claim_option_not_claimable', fn () => $this->service->previewExchange(
            $order,
            [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'size_mismatch',
        ));
    }

    /**
     * 살아 있는 환불신청서가 잠근 수량은 미리보기가 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_preview_matches_the_requested_amounts
     */
    public function test_preview_rejects_a_quantity_locked_by_a_live_refund_request(): void
    {
        $order = $this->deliveredOrder(29000, 2);
        $option = $order->options->first();
        // 직접 환불신청은 원 주문옵션을 판매 상태 그대로 두므로 상태 가드가 아닌 수량 잠금이 막는다.
        $this->makeDirectRefund($order, $option, 2);

        $this->assertClaimException('claim_quantity_locked', fn () => $this->service->previewExchange(
            $order->fresh(['options']),
            [['order_option_id' => $option->id, 'quantity' => 1]],
            'size_mismatch',
        ));
    }

    /**
     * 수정 중인 교환 자신은 수량 잠금에서 제외한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_preview_matches_the_requested_amounts
     */
    public function test_preview_excludes_the_exchange_being_edited_from_the_lock(): void
    {
        $order = $this->deliveredOrder(29000, 2);
        $exchange = $this->service->requestExchange($order, $this->payload($order, quantity: 2));

        $preview = $this->service->previewExchange(
            $order->fresh(['options']),
            [['order_option_id' => $order->options->first()->id, 'quantity' => 2]],
            'size_mismatch',
            excludeExchangeId: $exchange->id,
        );

        $this->assertSame(2, $preview['items'][0]['quantity']);
    }

    /**
     * 신청 기간이 지난 건은 미리보기가 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_request_period_is_measured_from_delivery
     */
    public function test_preview_rejects_a_request_after_the_request_period(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.request_period_days', 3);
        $order = $this->deliveredOrder();
        $order->options->first()->forceFill(['delivered_at' => now()->subDays(10)])->save();

        $this->assertClaimException('claim_request_period_expired', fn () => $this->service->previewExchange(
            $order->fresh(['options']),
            [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'size_mismatch',
        ));
    }

    /**
     * 사유가 바뀌면 수정이 배송비를 다시 계산한다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_update_request_recalculates_the_fee_when_the_reason_changes
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
    public function test_update_request_recalculates_the_fee_when_the_reason_changes(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(policy: $policy);
        $this->setExchangeFixedFee($policy, 6000);
        $exchange = $this->service->requestExchange($order, $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST));
        $this->assertSame(6000.0, (float) $exchange->total_charge_amount);

        // 판매자 귀책 사유로 바꾸면 왕복 배송비가 0 이 되고 청구 방식도 의미를 잃는다.
        $updated = $this->service->updateRequest($exchange, ['reason_code' => 'defective_product', 'reason_detail' => '불량']);

        $this->assertSame('defective_product', $updated->reason_code);
        $this->assertSame(ClaimReasonFaultTypeEnum::SELLER, $updated->fault_type);
        $this->assertSame(0.0, (float) $updated->claim_shipping_fee);
        $this->assertSame(0.0, (float) $updated->total_charge_amount);
        $this->assertNull($updated->charge_method);
        $this->assertSame('불량', $updated->reason_detail);
    }

    /**
     * 수정이 재발송지를 바꾸고 국가는 그대로 유지한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_redelivery_address_must_stay_in_the_same_country
     */
    public function test_update_request_changes_the_redelivery_address_and_keeps_the_country(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $updated = $this->service->updateRequest($exchange, [
            'redelivery_address' => ['zipcode' => '63000', 'address1' => '제주 서귀포시 1', 'changed_by_user' => true],
        ]);

        $this->assertSame('63000', $updated->redelivery_address_snapshot['zipcode']);
        $this->assertSame('KR', $updated->redelivery_address_snapshot['country_code']);
        $this->assertTrue($updated->redelivery_address_snapshot['changed_by_user']);

        $this->assertClaimException('claim_redelivery_country_change_not_allowed', fn () => $this->service->updateRequest($updated, [
            'redelivery_address' => ['country_code' => 'JP'],
        ]));
    }

    /**
     * 다른 칸만 고친 수정은 앞서 고친 값을 지우지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_redelivery_address_must_stay_in_the_same_country
     */
    public function test_update_request_keeps_the_previous_partial_edit_when_another_field_is_patched(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $first = $this->service->updateRequest($exchange, [
            'redelivery_address' => ['zipcode' => '63000', 'address1' => '제주 서귀포시 1'],
        ]);

        // 두 번째 수정은 수령인만 보낸다 — 앞서 바꾼 우편번호·주소가 주문 배송지로 되돌아가면 안 된다.
        $second = $this->service->updateRequest($first, [
            'redelivery_address' => ['recipient_name' => '김철수'],
        ]);

        $this->assertSame('김철수', $second->redelivery_address_snapshot['recipient_name']);
        $this->assertSame('63000', $second->redelivery_address_snapshot['zipcode']);
        $this->assertSame('제주 서귀포시 1', $second->redelivery_address_snapshot['address1']);
        $this->assertTrue($second->redelivery_address_snapshot['changed_by_user']);
    }

    /**
     * 재발송지를 null 로 보낸 수정은 원 배송지로 되돌리고 배송비도 그 주소로 다시 계산한다.
     *
     * 수정 화면의 [원 배송지로 되돌리기]가 보내는 값이다. 키가 없으면 "손대지 않음"이라
     * 앞서 바꾼 주소를 지키지만, null 은 "바꾼 주소를 버린다"는 뜻이다 — 둘을 같게 다루면
     * 화면은 원 배송지를 보여 주는데 신청서에는 바꿔 둔 주소가 남는다(2026-10-06 S2 실측).
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_redelivery_address_must_stay_in_the_same_country
     */
    public function test_update_request_with_a_null_redelivery_address_restores_the_order_address(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $moved = $this->service->updateRequest($exchange, [
            'redelivery_address' => ['zipcode' => '63000', 'address1' => '제주 서귀포시 1', 'address2' => '201호', 'changed_by_user' => true],
        ]);
        $this->assertSame('63000', $moved->redelivery_address_snapshot['zipcode']);
        $this->assertTrue($moved->shipping_fee_snapshot['redelivery_changed']);

        $restored = $this->service->updateRequest($moved, ['redelivery_address' => null]);
        $address = $order->fresh()->shippingAddress;

        $this->assertSame($address->zipcode, $restored->redelivery_address_snapshot['zipcode']);
        $this->assertSame($address->address, $restored->redelivery_address_snapshot['address1']);
        $this->assertSame($address->address_detail, $restored->redelivery_address_snapshot['address2'], '바꿔 둔 상세 주소도 함께 버린다.');
        $this->assertFalse($restored->redelivery_address_snapshot['changed_by_user']);
        $this->assertFalse($restored->shipping_fee_snapshot['redelivery_changed'], '배송비도 원 배송지 기준으로 다시 계산한다.');
    }

    /**
     * 해외 주문의 재발송지는 해외 주소 칸(거리·도시·주·우편번호)을 그대로 옮겨 담는다.
     *
     * 주문 배송지의 국내 칸은 해외 주문에서 빈 문자열로 저장된다. 스냅샷에 도시·주 칸이 없으면
     * 구매자가 아무것도 바꾸지 않아도 운영자가 보는 재발송지가 「350 5th Ave 10118」처럼 도시·주가
     * 빠진 주소가 된다(2026-10-07 S4 실측, 주문 710 → 인계 S2-D8).
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_redelivery_address_keeps_the_international_city_and_state
     */
    public function test_international_order_redelivery_snapshot_carries_the_city_and_state(): void
    {
        $order = $this->internationalDeliveredOrder();

        $exchange = $this->service->requestExchange($order, $this->payload($order));
        $snapshot = $exchange->redelivery_address_snapshot;

        $this->assertSame('10118', $snapshot['zipcode']);
        $this->assertSame('350 5th Ave', $snapshot['address1']);
        $this->assertSame('Suite 2', $snapshot['address2']);
        $this->assertSame('New York', $snapshot['city']);
        $this->assertSame('NY', $snapshot['state']);
        $this->assertFalse($snapshot['changed_by_user']);
        $this->assertFalse($this->service->redeliveryAddressDiffersFromOrder($exchange), '손대지 않은 해외 재발송지는 주문 배송지와 같다.');

        $moved = $this->service->updateRequest($exchange, [
            'redelivery_address' => ['address1' => '1 Main St', 'city' => 'Boston', 'state' => 'MA', 'zipcode' => '02108'],
        ]);

        $this->assertSame('Boston', $moved->redelivery_address_snapshot['city']);
        $this->assertSame('MA', $moved->redelivery_address_snapshot['state']);
        $this->assertTrue($moved->redelivery_address_snapshot['changed_by_user']);
        $this->assertTrue($this->service->redeliveryAddressDiffersFromOrder($moved->fresh()));
    }

    /**
     * 국내 주문의 재발송지는 도시·주 칸을 비워 두고, 그 칸이 없던 예전 스냅샷은 「다름」으로 판정하지 않는다.
     *
     * 도시·주 칸은 이 수정으로 생겼다. 그 전에 저장된 신청서는 두 칸이 아예 없는데, 없는 칸을
     * 주문 배송지 값과 비교하면 해외 주문의 신청서가 모두 「배송지 다름」 뱃지를 달게 된다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_redelivery_address_keeps_the_international_city_and_state
     */
    public function test_domestic_redelivery_leaves_city_and_state_empty_and_older_snapshots_are_not_flagged(): void
    {
        $domestic = $this->service->requestExchange($order = $this->deliveredOrder(), $this->payload($order));

        $this->assertNull($domestic->redelivery_address_snapshot['city']);
        $this->assertNull($domestic->redelivery_address_snapshot['state']);

        $international = $this->internationalDeliveredOrder();
        $exchange = $this->service->requestExchange($international, $this->payload($international));
        $older = $exchange->redelivery_address_snapshot;
        unset($older['city'], $older['state']);
        $exchange->forceFill(['redelivery_address_snapshot' => $older])->save();

        $this->assertFalse($this->service->redeliveryAddressDiffersFromOrder($exchange->fresh()));
    }

    /**
     * 수정으로 청구액이 생기면 청구 방식을 요구한다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=higher, charge_method=pay_on_request, additional_options=unchanged
     *
     * @effects exchange_charge_method_is_required_when_a_charge_remains
     */
    public function test_update_request_requires_a_charge_method_when_the_charge_becomes_positive(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->deliveredOrder(policy: $policy);
        $this->setExchangeFixedFee($policy, 6000);
        // 판매자 귀책으로 접수해 청구 0원 — 청구 방식 없음
        $exchange = $this->service->requestExchange($order, $this->payload($order, 'defective_product'));
        $this->assertNull($exchange->charge_method);

        $this->assertClaimException('claim_charge_method_required', fn () => $this->service->updateRequest($exchange, ['reason_code' => 'size_mismatch']));

        $updated = $this->service->updateRequest($exchange->fresh(), ['reason_code' => 'size_mismatch', 'charge_method' => 'pay_on_request']);
        $this->assertSame(6000.0, (float) $updated->total_charge_amount);
        $this->assertSame(ClaimChargeMethodEnum::PAY_ON_REQUEST, $updated->charge_method);
    }

    /**
     * 승인 뒤에는 신청서를 수정할 수 없다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_update_request_is_blocked_after_approval
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     */
    public function test_update_request_is_blocked_after_approval(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->service->requestExchange($order, $this->payload($order));
        $approved = $this->service->transition($exchange, ExchangeStatusEnum::APPROVED);

        $this->assertClaimException('claim_not_editable', fn () => $this->service->updateRequest($approved, ['reason_detail' => 'x']));
    }

    /**
     * 바뀐 것이 없는 수정은 신청서를 그대로 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=unchanged
     *
     * @effects exchange_update_request_recalculates_the_fee_when_the_reason_changes
     */
    public function test_update_request_without_changes_returns_the_exchange_untouched(): void
    {
        $order = $this->deliveredOrder();
        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $same = $this->service->updateRequest($exchange, []);

        $this->assertSame($exchange->id, $same->id);
        $this->assertSame($exchange->updated_at->toIso8601String(), $same->updated_at->toIso8601String());
    }

    // ================================================================
    // 신청 — 추가옵션 (이름 동결 · 청구 반영)
    // ================================================================

    /**
     * 그 상품에 딸린 추가옵션 그룹과 선택지 하나를 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  int  $priceAdjustment  추가금
     * @param  string  $groupName  그룹 이름
     * @param  string  $valueName  선택지 이름
     * @return ProductAdditionalOptionValue 선택지
     */
    protected function additionalOptionValue(
        Order $order,
        int $priceAdjustment,
        string $groupName = '포장 방식',
        string $valueName = '선물 포장',
    ): ProductAdditionalOptionValue {
        $group = ProductAdditionalOption::create([
            'product_id' => $order->options->first()->product_id,
            'name' => ['ko' => $groupName, 'en' => $groupName],
            'is_required' => false,
            'sort_order' => 0,
        ]);

        return ProductAdditionalOptionValue::create([
            'additional_option_id' => $group->id,
            'name' => ['ko' => $valueName, 'en' => $valueName],
            'price_adjustment' => $priceAdjustment,
            'is_default' => false,
            'is_active' => true,
            'allow_custom_text' => false,
            'sort_order' => 0,
        ]);
    }

    /**
     * 구매자가 고른 추가 옵션 값을 청구에 싣는다.
     *
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=pay_on_request, additional_options=picked
     *
     * @effects exchange_additional_options_picked_are_charged
     */
    public function test_request_charges_the_additional_options_the_buyer_picked(): void
    {
        // 주문의 소계 규칙(D6)은 `(옵션가 + 추가옵션 단위합계) × 수량` 이다. 교환 차액이 추가옵션
        // 항을 빼면 구매자가 3,000원짜리 포장을 골라도 상점은 0원을 청구한다(2026-09-01 실측).
        $order = $this->deliveredOrder();
        $value = $this->additionalOptionValue($order, 3000);

        $payload = $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST);
        $payload['items'][0]['custom_options'] = ['selections' => [(string) $value->additional_option_id => $value->id]];

        $exchange = $this->service->requestExchange($order, $payload);
        $item = $exchange->exchangeOptions->first();

        $this->assertSame(3000.0, (float) $item->price_difference, '동일상품 교환이라도 새로 고른 추가옵션은 청구된다.');
    }

    /**
     * 교환이 추가 옵션을 버리면 원래 추가 옵션 값을 돌려준다.
     *
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=dropped
     *
     * @effects exchange_additional_options_dropped_are_returned
     */
    public function test_request_returns_the_original_additional_options_when_the_exchange_drops_them(): void
    {
        // 원 주문에 유료 추가옵션이 있었는데 교환에서 고르지 않으면 그 상품은 그 추가옵션 없이
        // 재발송된다 — 같은 식(D6)이라면 그만큼이 구매자에게 돌아와야 한다.
        $order = $this->deliveredOrder();
        $order->options->first()->update(['additional_options_total' => 3000]);
        $order->load('options');

        $exchange = $this->service->requestExchange($order, $this->payload($order));

        $this->assertSame(-3000.0, (float) $exchange->exchangeOptions->first()->price_difference);
    }

    /**
     * @effects exchange_additional_option_names_and_prices_are_frozen
     */
    /**
     * 「승인 후 결제」에서도 고른 추가 옵션을 청구한다.
     *
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=pay_on_approval, additional_options=picked
     *
     * @effects exchange_additional_options_picked_are_charged
     */
    public function test_request_charges_picked_additional_options_on_approval_too(): void
    {
        // 추가옵션이 만든 청구도 승인 시점 결제로 받을 수 있다 — 청구를 만드는 축(추가옵션)과
        // 그것을 언제 받는가(청구 시점)는 서로를 제한하지 않는다.
        $order = $this->deliveredOrder();
        $value = $this->additionalOptionValue($order, 3000);

        $payload = $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_APPROVAL);
        $payload['items'][0]['custom_options'] = ['selections' => [(string) $value->additional_option_id => $value->id]];

        $exchange = $this->service->requestExchange($order, $payload);

        $this->assertSame(3000.0, (float) $exchange->exchangeOptions->first()->price_difference);
        $this->assertSame(ClaimChargeMethodEnum::PAY_ON_APPROVAL, $exchange->charge_method);
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->exchange_status);
    }

    /**
     * 추가 옵션의 이름과 가격을 접수 시점에 스냅샷으로 굳힌다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=pay_on_request, additional_options=picked
     *
     * @effects exchange_additional_option_names_and_prices_are_frozen
     */
    public function test_request_freezes_additional_option_names_and_prices_in_the_snapshot(): void
    {
        // 예전에는 선택 ID 만 남아 이름을 복원할 방법이 없었고, 그래서 신청 후 화면에 추가옵션이
        // 아예 나오지 않았다. 주문과 같은 형태로 동결한다.
        $order = $this->deliveredOrder();
        $value = $this->additionalOptionValue($order, 3000);

        $payload = $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST);
        $payload['items'][0]['custom_options'] = ['selections' => [(string) $value->additional_option_id => $value->id]];

        $exchange = $this->service->requestExchange($order, $payload);
        $snapshot = $exchange->exchangeOptions->first()->new_option_snapshot;

        $this->assertSame(3000, $snapshot['additional_options_total']);
        $this->assertCount(1, $snapshot['additional_options_snapshot']);
        $this->assertSame($value->id, $snapshot['additional_options_snapshot'][0]['value_id']);
        $this->assertSame('선물 포장', $snapshot['additional_options_snapshot'][0]['name']['ko']);
        $this->assertSame(3000, $snapshot['additional_options_snapshot'][0]['price_adjustment']);
    }

    /**
     * 다른 상품의 추가 옵션은 무시한다.
     *
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=equal, charge_method=none, additional_options=foreign
     *
     * @effects exchange_additional_options_of_another_product_are_ignored
     */
    public function test_request_ignores_additional_options_that_belong_to_another_product(): void
    {
        // 다른 상품의 선택지 ID 로 금액이나 표기를 만들 수 없어야 한다(D12).
        $order = $this->deliveredOrder();
        $otherOrder = $this->deliveredOrder();
        $foreign = $this->additionalOptionValue($otherOrder, 9000);

        $payload = $this->payload($order);
        $payload['items'][0]['custom_options'] = ['selections' => [(string) $foreign->additional_option_id => $foreign->id]];

        $exchange = $this->service->requestExchange($order, $payload);
        $item = $exchange->exchangeOptions->first();

        $this->assertSame(0.0, (float) $item->price_difference, '남의 상품 선택지는 청구에 실리지 않는다.');
        $this->assertArrayNotHasKey('additional_options_snapshot', $item->new_option_snapshot ?? []);
    }

    /**
     * 미리보기와 접수가 같은 추가 옵션을 같은 값으로 청구한다.
     *
     *
     * @return void 반환값 없음
     *
     * @effects exchange_preview_and_request_charge_the_same_additional_options
     *
     * @scenario price_delta=equal, charge_method=pay_on_request, additional_options=picked
     */
    public function test_preview_and_request_charge_the_same_additional_options(): void
    {
        // 화면이 예고하는 것과 실행하는 것이 같아야 한다 — 미리보기와 접수가 같은 경로를 탄다.
        $order = $this->deliveredOrder();
        $value = $this->additionalOptionValue($order, 3000);

        $payload = $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST);
        $payload['items'][0]['custom_options'] = ['selections' => [(string) $value->additional_option_id => $value->id]];

        $preview = $this->service->previewExchange(
            $order,
            $payload['items'],
            'size_mismatch',
            ClaimChargeMethodEnum::PAY_ON_REQUEST
        );
        $exchange = $this->service->requestExchange($order, $payload);

        $this->assertSame(3000.0, (float) $preview['items'][0]['price_difference']);
        $this->assertSame(3000.0, (float) $exchange->exchangeOptions->first()->price_difference);
    }

    // ================================================================
    // 주문금액 비교(전/후) — 옵션 치환 2-패스 · 접수 스냅샷 동결 (#599 후속)
    // ================================================================

    /**
     * 미리보기의 재계산 스냅샷은 신규 옵션 단가를 반영한다. ("교환 후" 열 — 전 = 후가 아니다)
     *
     * @return void 반환값 없음
     *
     * @scenario exchange_mode=change, preview_result=ok
     *
     * @effects claim_apply_exchange_after_column_reflects_substitution
     */
    public function test_preview_recalculated_snapshot_reflects_new_option_price(): void
    {
        $order = $this->deliveredOrder();
        $newOption = $this->siblingOption($order, 3000);
        $payload = $this->payload($order, newProductOptionId: $newOption->id);

        $preview = $this->service->previewExchange($order, $payload['items'], 'size_mismatch');
        $adjustment = $preview['adjustment'];

        $this->assertSame(29000.0, $adjustment->originalSnapshot['subtotal_amount']);
        $this->assertSame(32000.0, $adjustment->recalculatedSnapshot['subtotal_amount']);
        $this->assertCount(1, $adjustment->substitutedItems);
        $this->assertSame((int) $newOption->id, $adjustment->substitutedItems[0]['product_option_id']);
        $this->assertSame(29000.0, $adjustment->substitutedItems[0]['unit_price']);
        $this->assertSame(32000.0, $adjustment->substitutedItems[0]['new_unit_price']);
        // 산식 축은 종전 그대로 — 고가 교환은 환불이 없고 차액은 청구다.
        $this->assertSame(3000.0, $preview['additional_product_amount']);
        $this->assertSame(0.0, $adjustment->getTotalRefundAmount());
    }

    /**
     * 고가 교환의 표시 스냅샷은 후열이 커지지만 취소 차단으로 읽히지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario exchange_mode=change, preview_result=ok
     *
     * @effects claim_apply_exchange_after_column_reflects_substitution
     */
    public function test_preview_cancel_blocked_is_false_for_higher_priced_exchange(): void
    {
        $order = $this->deliveredOrder();
        $newOption = $this->siblingOption($order, 3000);
        $payload = $this->payload($order, newProductOptionId: $newOption->id);

        $preview = $this->service->previewExchange($order, $payload['items'], 'size_mismatch');
        $array = $preview['adjustment']->toPreviewArray();

        $this->assertGreaterThan($array['original_snapshot']['total_paid_amount'], $array['recalculated_snapshot']['total_paid_amount']);
        $this->assertFalse($array['cancel_blocked']);
        $this->assertNull($array['cancel_blocked_reason']);
    }

    /**
     * 접수는 미리보기와 같은 재료로 계산한 전/후 스냅샷을 신청서에 동결한다. (치환 근거 행 포함)
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=higher, charge_method=pay_on_request, additional_options=unchanged
     *
     * @effects exchange_request_freezes_calculation_snapshots
     */
    public function test_request_freezes_calculation_snapshots(): void
    {
        $order = $this->deliveredOrder();
        $newOption = $this->siblingOption($order, 3000);

        $exchange = $this->service->requestExchange(
            $order,
            $this->payload($order, chargeMethod: ClaimChargeMethodEnum::PAY_ON_REQUEST, newProductOptionId: $newOption->id)
        );

        $this->assertSame(29000.0, (float) $exchange->original_calculation_snapshot['subtotal_amount']);
        $this->assertSame(32000.0, (float) $exchange->recalculated_snapshot['subtotal_amount']);
        $this->assertSame('KRW', $exchange->recalculated_snapshot['base_currency']);
        $this->assertArrayHasKey('formatted', $exchange->recalculated_snapshot);

        $substituted = $exchange->recalculated_snapshot['_substituted_items'];
        $this->assertCount(1, $substituted);
        $this->assertSame((int) $newOption->id, $substituted[0]['product_option_id']);
        $this->assertLessThan(0, $substituted[0]['line_key']);
    }

    // ================================================================
    // 부분 반려 뒤 청구 재산정 (S5)
    // ================================================================

    /**
     * 차액이 있는 상품 하나를 반려하면 청구액이 살아 있는 상품 기준으로 다시 계산되고, 결제 대기 건이 따라간다. (S5)
     *
     * 예전에는 헤더 청구액이 접수 때 값 그대로라, 고객은 반려된 상품의 차액까지 결제해야 했다.
     *
     * @return void 반환값 없음
     *
     * @scenario price_delta=higher, charge_method=pay_on_request, additional_options=unchanged
     *
     * @effects exchange_partial_rejection_reprices_the_charge_for_living_items
     */
    public function test_partial_rejection_reprices_the_charge_for_living_items(): void
    {
        $policy = $this->createClaimShippingPolicy();
        [$productA, $optionA] = $this->createClaimProduct(30000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(20000, $policy);

        $order = $this->deliver($this->attachPolicyShippings($this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem($productA->id, $optionA->id, 1),
            new CalculationItem($productB->id, $optionB->id, 1),
        ])), $policy));

        $pricier = ProductOptionFactory::new()->forProduct($productA)->create([
            'price_adjustment' => 5000,
            'stock_quantity' => 100,
            'is_default' => false,
            'is_active' => true,
        ]);

        $items = [];

        foreach ($order->options as $option) {
            $items[] = [
                'order_option_id' => $option->id,
                'quantity' => (int) $option->quantity,
                'new_product_option_id' => (int) $option->product_option_id === (int) $optionA->id ? $pricier->id : null,
            ];
        }

        $exchange = $this->service->requestExchange($order, [
            'items' => $items,
            'reason_code' => 'size_mismatch',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'requested_by' => $order->user_id,
        ]);

        $this->assertEquals(5000, (float) $exchange->additional_product_amount);
        $totalBefore = (float) $exchange->total_charge_amount;

        $pending = ClaimPayment::factory()->create([
            'payable_type' => 'exchange',
            'payable_id' => $exchange->id,
            'order_id' => $order->id,
            'amount' => $totalBefore,
        ]);

        $itemA = (int) $exchange->exchangeOptions
            ->firstWhere(fn ($item) => (int) $order->options->firstWhere('id', $item->order_option_id)?->product_option_id === (int) $optionA->id)
            ->id;

        app(AdminClaimService::class)->transitionOptions($exchange, [$itemA], 'rejected', ['reject_reason' => '재고 없음']);

        $fresh = $exchange->fresh();
        $this->assertEquals(0, (float) $fresh->additional_product_amount);
        $this->assertEquals($totalBefore - 5000, (float) $fresh->total_charge_amount);

        $pending = $pending->fresh();

        if ((float) $fresh->total_charge_amount <= 0) {
            $this->assertNotSame(ClaimPaymentStatusEnum::PENDING, $pending->payment_status);
        } else {
            $this->assertEquals((float) $fresh->total_charge_amount, (float) $pending->amount);
        }
    }
}
