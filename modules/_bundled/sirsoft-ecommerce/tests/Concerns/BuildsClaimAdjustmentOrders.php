<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Concerns;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;

/**
 * 클레임 정산 테스트용 주문 조립 헬퍼 (S2c)
 *
 * `OrderCalculationService` 로 실제 계산을 돌린 뒤 그 결과로 주문 레코드를 만든다.
 * 금액을 손으로 박아 넣으면 재계산 결과와 원 주문 스냅샷이 어긋나 환불액이 실제 코드와
 * 무관한 값이 되므로, 조립 경로는 주문 생성 프로세스와 같은 순서를 따른다.
 *
 * `OrderAdjustmentServiceTest` 의 동형 헬퍼에서 쿠폰·카테고리 축을 뺀 축소판이다 —
 * 클레임 정산 테스트가 보는 축은 상품 금액·배송비·마일리지·결제행이며, 쿠폰 복원 축은
 * 그쪽 테스트가 이미 덮고 있다.
 */
trait BuildsClaimAdjustmentOrders
{
    /**
     * 테스트용 통화 설정을 기록합니다.
     *
     * @param  array<int, array>|null  $currencies  통화 목록 (기본: KRW 단일)
     * @param  string  $defaultCurrency  기본 통화 코드
     * @return void 반환값 없음
     */
    protected function setupTestCurrencySettings(?array $currencies = null, string $defaultCurrency = 'KRW'): void
    {
        $settingsPath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');

        if (! is_dir($settingsPath)) {
            mkdir($settingsPath, 0755, true);
        }

        $currencies ??= [[
            'code' => 'KRW',
            'name' => ['ko' => 'KRW (원)', 'en' => 'KRW (Won)'],
            'exchange_rate' => null,
            'rounding_unit' => '1',
            'rounding_method' => 'floor',
            'is_default' => true,
        ]];

        file_put_contents(
            $settingsPath.'/language_currency.json',
            json_encode([
                'default_language' => 'ko',
                'default_currency' => $defaultCurrency,
                'currencies' => $currencies,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 테스트용 통화 설정 파일을 제거합니다.
     *
     * @return void 반환값 없음
     */
    protected function clearTestCurrencySettings(): void
    {
        $path = storage_path('framework/testing/modules/sirsoft-ecommerce/settings/language_currency.json');

        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * 테스트용 배송정책을 생성합니다.
     *
     * @param  ChargePolicyEnum  $chargePolicy  배송비 부과정책
     * @param  int  $baseFee  기본 배송비
     * @param  int|null  $freeThreshold  무료배송 기준금액
     * @return ShippingPolicy 배송정책
     */
    protected function createClaimShippingPolicy(
        ChargePolicyEnum $chargePolicy = ChargePolicyEnum::FREE,
        int $baseFee = 0,
        ?int $freeThreshold = null,
    ): ShippingPolicy {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '클레임 테스트 배송정책', 'en' => 'Claim Test Shipping Policy'],
            'is_default' => false,
            'is_active' => true,
        ]);

        $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => $chargePolicy,
            'base_fee' => $baseFee,
            'free_threshold' => $freeThreshold,
            'extra_fee_enabled' => false,
            'is_active' => true,
        ]);

        return $policy->load('countrySettings');
    }

    /**
     * 테스트용 상품과 옵션을 생성합니다.
     *
     * @param  int  $price  판매가
     * @param  ShippingPolicy|null  $shippingPolicy  배송정책
     * @return array{0: Product, 1: ProductOption} [상품, 옵션]
     */
    protected function createClaimProduct(int $price = 29000, ?ShippingPolicy $shippingPolicy = null): array
    {
        $attrs = [
            'tax_status' => 'taxable',
            'selling_price' => $price,
            'list_price' => $price,
        ];

        if ($shippingPolicy) {
            $attrs['shipping_policy_id'] = $shippingPolicy->id;
        }

        $product = ProductFactory::new()->create($attrs);

        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'price_adjustment' => 0,
            'stock_quantity' => 100,
            'is_default' => true,
        ]);

        return [$product, $option];
    }

    /**
     * 계산 결과를 바탕으로 주문 레코드 일습을 생성합니다.
     *
     * @param  CalculationInput  $input  계산 입력
     * @param  array  $orderOverrides  Order 오버라이드 속성
     * @param  array  $paymentOverrides  OrderPayment 오버라이드 속성
     * @return Order 생성된 주문 (관계 로드됨)
     */
    protected function createClaimOrder(
        CalculationInput $input,
        array $orderOverrides = [],
        array $paymentOverrides = [],
    ): Order {
        $calculationService = app(OrderCalculationService::class);
        $result = $calculationService->calculate($input);

        $user = User::factory()->create();

        $shippingPolicySnapshot = [];

        if ($input->shippingAddress) {
            $shippingPolicySnapshot['address'] = $input->shippingAddress->toArray();
        }

        foreach ($result->items as $item) {
            if ($item->appliedShippingPolicy) {
                $shippingPolicySnapshot[$item->productOptionId] = $item->appliedShippingPolicy->toArray();
            }
        }

        $order = Order::factory()->create(array_merge([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => $result->summary->subtotal,
            'total_discount_amount' => $result->summary->totalDiscount,
            'base_shipping_amount' => $result->summary->baseShippingTotal,
            'extra_shipping_amount' => $result->summary->extraShippingTotal,
            'shipping_discount_amount' => $result->summary->shippingDiscount,
            'total_shipping_amount' => $result->summary->totalShipping,
            // 실주문 생성(OrderProcessingService)과 같이 사용 마일리지를 뺀 합계로 둔다.
            'total_amount' => $result->summary->finalAmount,
            'total_paid_amount' => $result->summary->finalAmount,
            'total_due_amount' => 0,
            'total_points_used_amount' => $result->summary->pointsUsed,
            // 결제완료 주문은 사용 마일리지를 이미 차감했다(실주문은 결제 확정 때 `is_mileage_deducted` 를 켠다).
            // 차감하지 않은 주문(Q15 — 미리보기·실행 모두 마일리지 몫 0)은 호출부가 덮어써 만든다.
            'is_mileage_deducted' => $result->summary->pointsUsed > 0,
            'total_tax_amount' => $result->summary->taxableAmount,
            'total_tax_free_amount' => $result->summary->taxFreeAmount,
            'total_cancelled_amount' => 0,
            'total_refunded_amount' => 0,
            'total_refunded_points_amount' => 0,
            'cancellation_count' => 0,
            'currency' => 'KRW',
            'shipping_policy_applied_snapshot' => $shippingPolicySnapshot,
        ], $orderOverrides));

        foreach ($result->items as $item) {
            $product = Product::find($item->productId);
            $productOption = ProductOption::find($item->productOptionId);

            $createData = [
                'product_id' => $item->productId,
                'product_option_id' => $item->productOptionId,
                'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
                'quantity' => $item->quantity,
                'unit_price' => $item->unitPrice,
                'subtotal_price' => $item->subtotal,
                'subtotal_discount_amount' => 0,
                'coupon_discount_amount' => 0,
                'code_discount_amount' => 0,
                'subtotal_paid_amount' => $item->finalAmount,
                'subtotal_tax_amount' => $item->taxableAmount,
                'subtotal_tax_free_amount' => $item->taxFreeAmount,
                'subtotal_points_used_amount' => $item->pointsUsedShare,
                'subtotal_earned_points_amount' => $item->pointsEarning,
            ];

            if ($product) {
                $createData['product_snapshot'] = $product->toSnapshotArray();
            }

            if ($productOption) {
                $createData['option_snapshot'] = $productOption->toSnapshotArray();
            }

            OrderOption::factory()->forOrder($order)->create($createData);
        }

        OrderShipping::factory()->forOrder($order)->create([
            'shipping_status' => 'pending',
            'base_shipping_amount' => $result->summary->baseShippingTotal,
            'extra_shipping_amount' => $result->summary->extraShippingTotal,
            'total_shipping_amount' => $result->summary->totalShipping,
            'shipping_discount_amount' => $result->summary->shippingDiscount,
        ]);

        OrderPayment::factory()->forOrder($order)->create(array_merge([
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_amount_local' => $result->summary->finalAmount,
            'paid_amount_base' => $result->summary->finalAmount,
            'currency' => 'KRW',
            'paid_at' => now(),
        ], $paymentOverrides));

        return $order->load(['options', 'shippings', 'payment', 'payments']);
    }

    /**
     * 단일 상품 주문을 만듭니다. (가장 흔한 조립)
     *
     * @param  int  $price  판매가
     * @param  int  $quantity  수량
     * @param  ShippingPolicy|null  $policy  배송정책
     * @param  array  $orderOverrides  Order 오버라이드
     * @param  array  $paymentOverrides  OrderPayment 오버라이드
     * @return Order 생성된 주문
     */
    protected function createSingleItemClaimOrder(
        int $price = 29000,
        int $quantity = 1,
        ?ShippingPolicy $policy = null,
        array $orderOverrides = [],
        array $paymentOverrides = [],
    ): Order {
        $policy ??= $this->createClaimShippingPolicy();
        [$product, $option] = $this->createClaimProduct($price, $policy);

        $input = new CalculationInput(items: [
            new CalculationItem(
                productId: $product->id,
                productOptionId: $option->id,
                quantity: $quantity,
            ),
        ]);

        return $this->createClaimOrder($input, $orderOverrides, $paymentOverrides);
    }

    /**
     * 주문 옵션 전량을 대상으로 하는 클레임 아이템 배열을 만듭니다.
     *
     * @param  Order  $order  클레임 대상 주문 (옵션 관계를 순회한다)
     * @return array<int, array{order_option_id: int, cancel_quantity: int}> 옵션마다 원 수량 전량을 담은 클레임 아이템 목록
     */
    protected function claimItemsForAll(Order $order): array
    {
        $items = [];

        foreach ($order->options as $option) {
            $items[] = [
                'order_option_id' => $option->id,
                'cancel_quantity' => $option->quantity,
            ];
        }

        return $items;
    }

    /**
     * 직접 환불신청서(출처 direct)를 만듭니다. (수량 잠금 테스트의 제3축 — 원 주문옵션 상태 불변)
     *
     * @param  Order  $order  주문
     * @param  OrderOption  $option  주문 옵션
     * @param  int  $quantity  신청 수량
     * @param  RefundStatusEnum  $status  환불신청서 상태
     * @param  RefundSourceTypeEnum  $source  발생 출처
     * @return OrderRefund 환불신청서
     */
    protected function makeDirectRefund(
        Order $order,
        OrderOption $option,
        int $quantity,
        RefundStatusEnum $status = RefundStatusEnum::REQUESTED,
        RefundSourceTypeEnum $source = RefundSourceTypeEnum::DIRECT,
    ): OrderRefund {
        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => $source,
            'refund_number' => 'RF-TEST-'.uniqid(),
            'refund_status' => $status,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 0,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
        ]);

        OrderRefundOption::create([
            'order_refund_id' => $refund->id,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'option_status' => RefundStatusEnum::REQUESTED,
            'quantity' => $quantity,
            'unit_price' => (float) $option->unit_price,
            'subtotal_amount' => (float) $option->unit_price * $quantity,
            'discount_amount' => 0,
            'refund_amount' => 0,
        ]);

        return $refund;
    }
}
