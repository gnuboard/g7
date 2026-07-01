<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\TempOrderFactory;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\PromotionsSummary;
use Modules\Sirsoft\Ecommerce\DTO\Summary;
use Modules\Sirsoft\Ecommerce\Exceptions\CartUnavailableException;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * 주문 생성 — 배송국가 저장·차단 테스트 (MP08 후속)
 *
 * - B2: 해외 주문 시 해외 주소 필드(address_line_1/2, intl_*)가 OrderAddress 에 보존되는지 (회귀)
 * - B5: shipping_policy_applied_snapshot 에 배송지(국가/우편번호)가 보존되는지 (회귀)
 * - D1: 선택 배송국가로 배송 불가한 상품이 1개라도 있으면 주문 전체 차단 (혼재 시 전체 차단)
 */
class OrderProcessingShippingCountryTest extends ModuleTestCase
{
    protected OrderProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OrderProcessingService::class);
    }

    private function mockCalc(int $finalAmount = 100000): void
    {
        /** @var OrderCalculationService&MockObject $mock */
        $mock = $this->createMock(OrderCalculationService::class);
        $summary = new Summary(
            subtotal: $finalAmount,
            finalAmount: $finalAmount,
            paymentAmount: $finalAmount,
        );
        $result = new OrderCalculationResult(
            items: [],
            summary: $summary,
            promotions: new PromotionsSummary,
        );
        $mock->method('calculate')->willReturn($result);
        $this->app->instance(OrderCalculationService::class, $mock);
        $this->service = app(OrderProcessingService::class);
    }

    /**
     * 배송정책 + 단일 국가 설정(활성) 생성.
     */
    private function makePolicyForCountry(string $countryCode): ShippingPolicy
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '테스트 정책', 'en' => 'Test Policy'],
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 1,
        ]);

        $policy->countrySettings()->create([
            'country_code' => $countryCode,
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => 'fixed',
            'base_fee' => 3000,
            'is_active' => true,
        ]);

        return $policy->load('countrySettings');
    }

    private function makeTempOrder(User $user, array $items, int $finalAmount = 100000)
    {
        return TempOrderFactory::new()
            ->forUser($user)
            ->withItems($items)
            ->withCalculationResult([
                'summary' => ['final_amount' => $finalAmount, 'subtotal' => $finalAmount],
                'items' => [],
                'promotions' => [
                    'product_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                    'order_promotions' => ['coupons' => [], 'discount_codes' => [], 'events' => []],
                ],
                'validation_errors' => [],
            ])
            ->create();
    }

    public function test_b2_international_address_fields_are_persisted(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->makeTempOrder($user, []);
        $this->mockCalc();

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'John Doe', 'phone' => '010-1111-2222', 'email' => 'john@example.com'],
            [
                'recipient_name' => 'John Doe',
                'recipient_phone' => '010-1111-2222',
                'country_code' => 'US',
                'address_line_1' => '123 Main St',
                'address_line_2' => 'Apt 4',
                'intl_city' => 'New York',
                'intl_state' => 'NY',
                'intl_postal_code' => '10001',
            ],
            'card',
            100000,
        );

        $address = $order->addresses()->where('address_type', 'shipping')->first();
        $this->assertNotNull($address);
        $this->assertSame('US', $address->recipient_country_code);
        $this->assertSame('123 Main St', $address->address_line_1);
        $this->assertSame('Apt 4', $address->address_line_2);
        $this->assertSame('New York', $address->intl_city);
        $this->assertSame('NY', $address->intl_state);
        $this->assertSame('10001', $address->intl_postal_code);
    }

    public function test_b5_snapshot_preserves_shipping_country_and_zipcode(): void
    {
        $user = User::factory()->create();
        $tempOrder = $this->makeTempOrder($user, []);
        $this->mockCalc();

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'test@example.com'],
            ['recipient_name' => '홍길동', 'recipient_phone' => '010-1234-5678', 'country_code' => 'KR', 'zipcode' => '63000', 'address' => '제주시', 'address_detail' => '101호'],
            'card',
            100000,
        );

        $snapshot = $order->shipping_policy_applied_snapshot;
        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey('address', $snapshot);
        $this->assertSame('KR', $snapshot['address']['country_code']);
        $this->assertSame('63000', $snapshot['address']['zipcode']);
    }

    public function test_d1_blocks_order_when_product_not_shippable_to_selected_country(): void
    {
        $user = User::factory()->create();

        // 배송정책: KR 만 설정 (US 미지원)
        $policy = $this->makePolicyForCountry('KR');

        $product = Product::factory()->create(['shipping_policy_id' => $policy->id]);
        $option = ProductOption::factory()->create(['product_id' => $product->id]);

        $tempOrder = $this->makeTempOrder($user, [
            ['cart_id' => 1, 'product_id' => $product->id, 'product_option_id' => $option->id, 'quantity' => 1],
        ]);
        $this->mockCalc();

        $this->expectException(CartUnavailableException::class);

        // US 로 주문 시도 → 정책에 US 설정 없음 → 전체 차단
        $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'John', 'phone' => '010-1111-2222', 'email' => 'john@example.com'],
            [
                'recipient_name' => 'John',
                'recipient_phone' => '010-1111-2222',
                'country_code' => 'US',
                'address_line_1' => '123 Main St',
                'intl_city' => 'NYC',
                'intl_postal_code' => '10001',
            ],
            'card',
            100000,
        );
    }

    public function test_d1_allows_order_when_product_shippable_to_selected_country(): void
    {
        $user = User::factory()->create();

        $policy = $this->makePolicyForCountry('US');

        $product = Product::factory()->create(['shipping_policy_id' => $policy->id]);
        $option = ProductOption::factory()->create(['product_id' => $product->id]);

        $tempOrder = $this->makeTempOrder($user, [
            ['cart_id' => 1, 'product_id' => $product->id, 'product_option_id' => $option->id, 'quantity' => 1],
        ]);
        $this->mockCalc();

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'John', 'phone' => '010-1111-2222', 'email' => 'john@example.com'],
            [
                'recipient_name' => 'John',
                'recipient_phone' => '010-1111-2222',
                'country_code' => 'US',
                'address_line_1' => '123 Main St',
                'intl_city' => 'NYC',
                'intl_postal_code' => '10001',
            ],
            'card',
            100000,
        );

        $this->assertNotNull($order->id);
    }
}
