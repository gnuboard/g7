<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\TempOrderFactory;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\PromotionsSummary;
use Modules\Sirsoft\Ecommerce\DTO\Summary;
use Modules\Sirsoft\Ecommerce\Exceptions\CartUnavailableException;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
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

    /**
     * 테스트마다 주문 처리 서비스를 컨테이너에서 새로 받습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OrderProcessingService::class);
    }

    /**
     * 항상 같은 금액을 돌려주는 계산 서비스 목을 등록합니다.
     *
     * @param  int  $finalAmount  계산 결과 최종 결제 금액
     * @return void 반환값 없음
     */
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
     * 배송국가에 따라 금액이 달라지는 계산 서비스 목을 등록합니다(KR 15,000 · 그 밖 27,000).
     *
     * @return void 반환값 없음
     */
    private function mockCalcByCountry(): void
    {
        /** @var OrderCalculationService&MockObject $mock */
        $mock = $this->createMock(OrderCalculationService::class);
        $mock->method('calculate')->willReturnCallback(function ($input) {
            $country = strtoupper((string) ($input->shippingAddress?->countryCode ?? 'KR'));
            $amount = $country === 'KR' ? 15000 : 27000;

            return new OrderCalculationResult(
                items: [],
                summary: new Summary(subtotal: 12000, finalAmount: $amount, paymentAmount: $amount),
                promotions: new PromotionsSummary,
            );
        });
        $this->app->instance(OrderCalculationService::class, $mock);
        $this->service = app(OrderProcessingService::class);
    }

    /**
     * 배송정책 + 단일 국가 설정(활성) 생성.
     *
     * @param  string  $countryCode  활성화할 배송국가 코드
     * @return ShippingPolicy 국가 설정을 함께 불러온 배송정책
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

    /**
     * 회원 임시 주문을 만듭니다.
     *
     * @param  User  $user  주문 회원
     * @param  array  $items  임시 주문 상품 목록
     * @param  int  $finalAmount  저장할 최종 결제 금액
     * @return ModulesSirsoftEcommerceModelsTempOrder 생성된 임시 주문
     */
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

    /**
     * 해외 주문의 해외 주소 칸(address_line_1/2·intl_*)이 주문 배송지에 그대로 저장된다.
     *
     * @scenario actor=member, setting_location=checkout_select, module_state=intl_on, shippability=all_shippable
     *
     * @effects intl_address_persisted_on_order
     *
     * @return void 반환값 없음
     */
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

    /**
     * 배송정책 적용 스냅샷에 배송국가·우편번호가 보존된다.
     *
     * @scenario actor=member, setting_location=checkout_select, module_state=intl_on, shippability=all_shippable
     *
     * @effects snapshot_preserves_shipping_country
     *
     * @return void 반환값 없음
     */
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

    /**
     * 고른 배송국가로 보낼 수 없는 상품이 있으면 주문 전체를 막는다.
     *
     * @scenario actor=member, setting_location=checkout_select, module_state=intl_on, shippability=mixed
     *
     * @effects not_shippable_blocks_purchase
     *
     * @return void 반환값 없음
     */
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

    /**
     * 고른 배송국가로 보낼 수 있는 상품만 있으면 주문을 만든다.
     *
     * @scenario actor=member, setting_location=checkout_select, module_state=intl_on, shippability=all_shippable
     *
     * @effects not_shippable_blocks_purchase
     *
     * @return void 반환값 없음
     */
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

    /**
     * 주문서에서 해외 주소를 직접 입력해 주문하면 임시 주문에 국내(KR) 주소가 남아 있어도 제출한 배송국가 금액으로 주문된다.
     *
     * 화면은 미국 배송비(27,000)를 보여 주고 그 금액을 보내는데, 임시 주문이 KR(15,000)로 저장돼 있으면
     * 「결제 금액이 일치하지 않습니다」 로 주문이 막혔다(결함인계대장 2026-10-07T17:25Z, S2 → S4).
     *
     * @scenario actor=member, setting_location=checkout_select, module_state=intl_on, shippability=all_shippable
     *
     * @effects order_priced_by_submitted_country
     *
     * @return void 반환값 없음
     */
    public function test_direct_input_foreign_address_is_priced_by_submitted_country(): void
    {
        $user = User::factory()->create();
        $tempOrder = TempOrderFactory::new()
            ->forUser($user)
            ->withItems([])
            ->withShippingAddress(['country_code' => 'KR', 'zipcode' => null])
            ->withCalculationResult(['summary' => ['final_amount' => 15000, 'subtotal' => 12000], 'items' => [], 'validation_errors' => []])
            ->create();
        $this->mockCalcByCountry();

        $order = $this->service->createFromTempOrder(
            $tempOrder,
            ['name' => 'John', 'phone' => '010-1111-2222', 'email' => 'john@example.com'],
            [
                'recipient_name' => 'John',
                'recipient_phone' => '010-1111-2222',
                'country_code' => 'US',
                'address_line_1' => '123 Main St',
                'intl_city' => 'NYC',
                'intl_state' => 'NY',
                'intl_postal_code' => '10001',
            ],
            'card',
            27000,
        );

        $this->assertSame(27000, (int) $order->total_due_amount);
        $this->assertSame('US', $tempOrder->fresh()?->getShippingAddress()['country_code'] ?? 'US');
    }

    /**
     * 해외 주소를 제출하면서 국내 배송비 금액을 보내면 주문을 막는다(배송비를 덜 내는 주문 방지).
     *
     * @scenario actor=member, setting_location=checkout_select, module_state=intl_on, shippability=all_shippable
     *
     * @effects foreign_address_domestic_amount_rejected
     *
     * @return void 반환값 없음
     */
    public function test_foreign_address_with_domestic_amount_is_rejected(): void
    {
        $user = User::factory()->create();
        $tempOrder = TempOrderFactory::new()
            ->forUser($user)
            ->withItems([])
            ->withShippingAddress(['country_code' => 'KR', 'zipcode' => null])
            ->withCalculationResult(['summary' => ['final_amount' => 15000, 'subtotal' => 12000], 'items' => [], 'validation_errors' => []])
            ->create();
        $this->mockCalcByCountry();

        $this->expectException(PaymentAmountMismatchException::class);

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
            15000,
        );
    }
}
