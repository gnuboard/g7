<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\ShippingAddress;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * B5 — 취소/환불 스냅샷 배송국가 보존 테스트 (MP08 후속)
 *
 * 취소 레코드(ecommerce_order_cancels.shipping_snapshot)에 취소 "시점"의 배송국가·우편번호·
 * 취소 대상 배송정책이 독립 보존되는지 검증한다. 주문 주소가 사후 변경/삭제되어도 취소 이력의
 * 배송 맥락이 잔존해야 도서산간/국가별 환불 정책 판단을 복원할 수 있다.
 */
class OrderCancellationShippingSnapshotTest extends ModuleTestCase
{
    protected OrderCancellationService $cancellationService;

    protected OrderCalculationService $calculationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->setupSequences();
        $this->cancellationService = app(OrderCancellationService::class);
        $this->calculationService = app(OrderCalculationService::class);
    }

    public function test_partial_cancel_preserves_international_shipping_snapshot(): void
    {
        $policy = $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 20000, shippingPolicy: $policy);
        [$pB, $oB] = $this->createProductWithOption(price: 10000, shippingPolicy: $policy);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
                new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
            ],
            shippingAddress: new ShippingAddress(countryCode: 'US', zipcode: '10001'),
        );
        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        $result = $this->cancellationService->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $optionA->id, 'cancel_quantity' => 1]],
            cancelPg: false,
        );

        $cancel = $result->orderCancel->fresh();
        $snapshot = $cancel->shipping_snapshot;

        $this->assertIsArray($snapshot);
        $this->assertSame('US', $snapshot['country_code']);
        $this->assertSame('10001', $snapshot['zipcode']);
        // 취소 대상(oA) 상품의 정책만 보존, 미취소(oB) 정책은 제외
        $this->assertArrayHasKey('policies', $snapshot);
        $optionIds = array_column($snapshot['policies'], 'product_option_id');
        $this->assertContains($oA->id, $optionIds);
        $this->assertNotContains($oB->id, $optionIds);
    }

    public function test_domestic_cancel_preserves_kr_snapshot(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 15000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
            shippingAddress: new ShippingAddress(countryCode: 'KR', zipcode: '06236'),
        );
        $order = $this->createOrderFromCalculation($input);
        $optionA = $order->options->where('product_option_id', $oA->id)->first();

        $result = $this->cancellationService->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $optionA->id, 'cancel_quantity' => 1]],
            cancelPg: false,
        );

        $snapshot = $result->orderCancel->fresh()->shipping_snapshot;
        $this->assertSame('KR', $snapshot['country_code']);
        $this->assertSame('06236', $snapshot['zipcode']);
    }

    public function test_snapshot_survives_order_address_deletion(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 12000);

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
            shippingAddress: new ShippingAddress(countryCode: 'US', zipcode: '94105'),
        );
        $order = $this->createOrderFromCalculation($input);

        // 주문 배송주소 레코드 생성 후 삭제 — 취소 스냅샷은 이력 독립적으로 잔존해야 한다.
        $order->addresses()->create([
            'address_type' => 'shipping',
            'orderer_name' => 'John Doe',
            'orderer_phone' => '010-1111-2222',
            'orderer_email' => 'john@example.com',
            'recipient_name' => 'John Doe',
            'recipient_phone' => '010-1111-2222',
            'recipient_country_code' => 'US',
            'zipcode' => '',
            'address' => '',
            'address_detail' => '',
            'address_line_1' => '123 Main St',
            'intl_city' => 'San Francisco',
            'intl_postal_code' => '94105',
        ]);

        $optionA = $order->options->where('product_option_id', $oA->id)->first();
        $result = $this->cancellationService->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $optionA->id, 'cancel_quantity' => 1]],
            cancelPg: false,
        );
        $cancelId = $result->orderCancel->id;

        // 주문 주소 삭제(사후 변경 시뮬레이션)
        $order->addresses()->delete();

        // 취소 레코드를 DB 에서 재로드해도 배송 스냅샷은 보존
        $reloaded = OrderCancel::find($cancelId);
        $this->assertSame('US', $reloaded->shipping_snapshot['country_code']);
        $this->assertSame('94105', $reloaded->shipping_snapshot['zipcode']);
    }

    public function test_snapshot_falls_back_to_current_address_when_order_snapshot_missing(): void
    {
        $this->createShippingPolicy();
        [$pA, $oA] = $this->createProductWithOption(price: 9000);

        // shippingAddress 없이 주문 생성 → order->shipping_policy_applied_snapshot['address'] 부재
        $input = new CalculationInput(
            items: [new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1)],
        );
        $order = $this->createOrderFromCalculation($input);

        // 현재 배송주소로 폴백 복원되어야 함
        $order->addresses()->create([
            'address_type' => 'shipping',
            'orderer_name' => '홍길동',
            'orderer_phone' => '010-1234-5678',
            'orderer_email' => 'hong@example.com',
            'recipient_name' => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'recipient_country_code' => 'KR',
            'zipcode' => '06236',
            'address' => '서울시 강남구',
            'address_detail' => '101호',
        ]);
        $order->load('shippingAddress');

        $optionA = $order->options->where('product_option_id', $oA->id)->first();
        $result = $this->cancellationService->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $optionA->id, 'cancel_quantity' => 1]],
            cancelPg: false,
        );

        $snapshot = $result->orderCancel->fresh()->shipping_snapshot;
        $this->assertSame('KR', $snapshot['country_code']);
        $this->assertSame('06236', $snapshot['zipcode']);
    }

    // ───────────────────────────────────────────────
    // 헬퍼 (OrderCancellationServiceTest 패턴 재사용)
    // ───────────────────────────────────────────────

    protected function setupTestCurrencySettings(): void
    {
        $settingsPath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($settingsPath)) {
            mkdir($settingsPath, 0755, true);
        }

        $settings = [
            'default_language' => 'ko',
            'default_currency' => 'KRW',
            'currencies' => [
                [
                    'code' => 'KRW',
                    'name' => ['ko' => 'KRW (원)', 'en' => 'KRW (Won)'],
                    'exchange_rate' => null,
                    'rounding_unit' => '1',
                    'rounding_method' => 'floor',
                    'is_default' => true,
                ],
            ],
        ];

        file_put_contents(
            $settingsPath.'/language_currency.json',
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    protected function setupSequences(): void
    {
        foreach ([SequenceType::CANCEL, SequenceType::REFUND] as $type) {
            $config = $type->getDefaultConfig();
            Sequence::firstOrCreate(
                ['type' => $type->value],
                [
                    'algorithm' => $config['algorithm']->value,
                    'prefix' => $config['prefix'],
                    'current_value' => 0,
                    'increment' => 1,
                    'min_value' => 1,
                    'max_value' => $config['max_value'],
                    'cycle' => false,
                    'pad_length' => $config['pad_length'],
                ]
            );
        }
    }

    protected function tearDown(): void
    {
        $settingsDir = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        foreach (['language_currency.json', 'order_settings.json'] as $file) {
            $path = $settingsDir.'/'.$file;
            if (file_exists($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    protected function createProductWithOption(int $price = 10000, int $stock = 100, ?ShippingPolicy $shippingPolicy = null): array
    {
        $attrs = [
            'selling_price' => $price,
            'list_price' => $price,
            'tax_status' => 'taxable',
        ];
        if ($shippingPolicy) {
            $attrs['shipping_policy_id'] = $shippingPolicy->id;
        }
        $product = ProductFactory::new()->create($attrs);

        $option = ProductOption::factory()->forProduct($product)->create([
            'price_adjustment' => 0,
            'stock_quantity' => $stock,
        ]);

        return [$product, $option];
    }

    protected function createShippingPolicy(): ShippingPolicy
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '테스트 배송정책', 'en' => 'Test Shipping Policy'],
            'is_default' => false,
            'is_active' => true,
        ]);

        $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => ChargePolicyEnum::FREE,
            'base_fee' => 0,
            'free_threshold' => null,
            'ranges' => null,
            'extra_fee_enabled' => false,
            'extra_fee_settings' => null,
            'extra_fee_multiply' => false,
            'is_active' => true,
        ]);

        return $policy->load('countrySettings');
    }

    protected function createOrderFromCalculation(CalculationInput $input): Order
    {
        $result = $this->calculationService->calculate($input);
        $user = User::factory()->create();

        $shippingPolicySnapshot = [];
        if ($input->shippingAddress) {
            $shippingPolicySnapshot['address'] = $input->shippingAddress->toArray();
        }

        foreach ($result->items as $item) {
            if ($item->appliedShippingPolicy !== null) {
                $shippingPolicySnapshot[] = [
                    'product_option_id' => $item->productOptionId,
                    'policy' => array_merge(
                        $item->appliedShippingPolicy->policySnapshot,
                        [
                            'policy_id' => $item->appliedShippingPolicy->policyId,
                            'policy_name' => $item->appliedShippingPolicy->policyName,
                        ]
                    ),
                ];
            }
        }

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => $result->summary->subtotal,
            'total_shipping_amount' => $result->summary->totalShipping,
            'total_amount' => $result->summary->paymentAmount,
            'total_paid_amount' => $result->summary->finalAmount,
            'total_due_amount' => 0,
            'total_cancelled_amount' => 0,
            'total_refunded_amount' => 0,
            'cancellation_count' => 0,
            'currency' => 'KRW',
            'shipping_policy_applied_snapshot' => $shippingPolicySnapshot,
        ]);

        foreach ($result->items as $item) {
            $product = Product::find($item->productId);
            $productOption = ProductOption::find($item->productOptionId);

            OrderOption::factory()->forOrder($order)->create([
                'product_id' => $item->productId,
                'product_option_id' => $item->productOptionId,
                'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
                'quantity' => $item->quantity,
                'unit_price' => $item->unitPrice,
                'subtotal_price' => $item->subtotal,
                'subtotal_paid_amount' => $item->finalAmount,
                'product_snapshot' => $product?->toSnapshotArray() ?? [],
                'option_snapshot' => $productOption?->toSnapshotArray() ?? [],
            ]);
        }

        OrderShipping::factory()->forOrder($order)->create([
            'shipping_status' => 'pending',
            'total_shipping_amount' => $result->summary->totalShipping,
        ]);

        OrderPayment::factory()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_amount_local' => $result->summary->finalAmount,
            'paid_amount_base' => $result->summary->finalAmount,
            'paid_at' => now(),
        ]);

        return $order->load(['options', 'shippings', 'payment']);
    }
}
