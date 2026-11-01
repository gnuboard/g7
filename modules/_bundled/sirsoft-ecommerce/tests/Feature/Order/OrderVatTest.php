<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Order;

use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\Summary;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionMethod;

/**
 * 주문 부가세 산출 테스트 (E5)
 *
 * 수정 전에는 `total_vat_amount` 쓰기 지점이 부분취소 재계산 1곳뿐이라, 부분취소를 겪은
 * 주문만 값이 정상화되고 나머지 주문은 영구 0 이었습니다. 같은 화면에서 주문마다 세금
 * 표기가 달랐습니다.
 *
 * @scenario actor=member, change_mode=api
 */
class OrderVatTest extends ModuleTestCase
{
    /**
     * 판매가·세금 설정을 지정한 상품 옵션을 만듭니다.
     *
     * @param  int  $price  판매가
     * @param  ProductTaxStatus  $taxStatus  과세 여부
     * @param  float|null  $taxRate  세율 (%)
     * @return int 상품 옵션 ID
     */
    private function makeOption(int $price, ProductTaxStatus $taxStatus, ?float $taxRate): int
    {
        $product = ProductFactory::new()->create([
            'shipping_policy_id' => null,
            'list_price' => $price,
            'selling_price' => $price,
            'tax_status' => $taxStatus,
            // tax_rate 컬럼은 NOT NULL — 면세 상품도 값은 존재하며 과세 분류에서 무시된다
            'tax_rate' => $taxRate ?? 10.0,
        ]);

        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'selling_price' => $price,
            'price_adjustment' => 0,
            'stock_quantity' => 100,
        ]);

        return $option->id;
    }

    /**
     * 계산 결과를 반환합니다.
     *
     * @param  array<int, array{option_id: int, product_id: int, quantity: int}>  $items  계산 대상
     * @return OrderCalculationResult 계산 결과
     */
    private function calculate(array $items): OrderCalculationResult
    {
        $calculationItems = array_map(
            fn (array $item) => new CalculationItem(
                productId: $item['product_id'],
                productOptionId: $item['option_id'],
                quantity: $item['quantity'],
            ),
            $items
        );

        return app(OrderCalculationService::class)->calculate(new CalculationInput(items: $calculationItems));
    }

    /**
     * 과세 상품의 부가세가 계산 단계에서 산출되는지 테스트합니다.
     *
     * @effects vat_calculated_for_taxable_items
     *
     * @return void 반환값 없음
     */
    public function test_vat_is_calculated_for_taxable_items(): void
    {
        $optionId = $this->makeOption(11000, ProductTaxStatus::TAXABLE, 10.0);
        $option = ProductOption::find($optionId);

        $result = $this->calculate([
            ['option_id' => $optionId, 'product_id' => $option->product_id, 'quantity' => 1],
        ]);

        $this->assertSame(11000, $result->summary->taxableAmount);
        // 수정 전: summary 에 부가세 자체가 없어 주문에 0 이 기록됐다
        $this->assertSame(1000, $result->summary->vatAmount);
    }

    /**
     * 면세 상품은 부가세가 0 인지 테스트합니다.
     *
     * @effects tax_free_item_has_no_vat
     *
     * @return void 반환값 없음
     */
    public function test_tax_free_item_has_no_vat(): void
    {
        $optionId = $this->makeOption(11000, ProductTaxStatus::TAX_FREE, 10.0);
        $option = ProductOption::find($optionId);

        $result = $this->calculate([
            ['option_id' => $optionId, 'product_id' => $option->product_id, 'quantity' => 1],
        ]);

        $this->assertSame(0, $result->summary->taxableAmount);
        $this->assertSame(11000, $result->summary->taxFreeAmount);
        $this->assertSame(0, $result->summary->vatAmount);
    }

    /**
     * 세율이 섞인 주문에서 옵션별로 합산되는지 테스트합니다.
     *
     * 전체 과세표준에 단일 세율을 적용하면 값이 어긋난다.
     *
     * @effects mixed_tax_rates_summed_per_option
     *
     * @return void 반환값 없음
     */
    public function test_mixed_tax_rates_are_summed_per_option(): void
    {
        $standardId = $this->makeOption(11000, ProductTaxStatus::TAXABLE, 10.0);
        $reducedId = $this->makeOption(10500, ProductTaxStatus::TAXABLE, 5.0);

        $standard = ProductOption::find($standardId);
        $reduced = ProductOption::find($reducedId);

        $result = $this->calculate([
            ['option_id' => $standardId, 'product_id' => $standard->product_id, 'quantity' => 1],
            ['option_id' => $reducedId, 'product_id' => $reduced->product_id, 'quantity' => 1],
        ]);

        $this->assertSame(21500, $result->summary->taxableAmount);
        // 1000(10%) + 500(5%) = 1500. 단일 세율 계산이었다면 1955.
        $this->assertSame(1500, $result->summary->vatAmount);
    }

    /**
     * 면세 상품의 세율 값은 부가세에 반영되지 않는지 테스트합니다.
     *
     * (세율 미지정 시 기본 세율 폴백은 VatCalculatorTest 가 커버 — DB 컬럼이 NOT NULL 이라
     *  Product 레벨에서는 null 세율이 존재할 수 없고, 스냅샷 모드에서만 가능하다.)
     *
     * @effects tax_free_item_rate_ignored
     *
     * @return void 반환값 없음
     */
    public function test_tax_free_item_ignores_its_rate(): void
    {
        $taxableId = $this->makeOption(11000, ProductTaxStatus::TAXABLE, 10.0);
        $freeId = $this->makeOption(50000, ProductTaxStatus::TAX_FREE, 10.0);

        $taxable = ProductOption::find($taxableId);
        $free = ProductOption::find($freeId);

        $result = $this->calculate([
            ['option_id' => $taxableId, 'product_id' => $taxable->product_id, 'quantity' => 1],
            ['option_id' => $freeId, 'product_id' => $free->product_id, 'quantity' => 1],
        ]);

        // 면세 50,000 은 과세표준에 들어가지 않으므로 부가세는 과세 11,000 분만
        $this->assertSame(11000, $result->summary->taxableAmount);
        $this->assertSame(50000, $result->summary->taxFreeAmount);
        $this->assertSame(1000, $result->summary->vatAmount);
    }

    /**
     * 주문 생성 시점에 total_vat_amount 가 기록되는지 테스트합니다.
     *
     * 수정 전: 쓰기 지점이 부분취소 재계산뿐이라 신규 주문은 영구 0.
     *
     * @effects order_row_records_vat_at_creation
     * @effects payment_record_vat_matches_order
     *
     * @return void 반환값 없음
     */
    public function test_order_row_records_vat_at_creation(): void
    {
        $optionId = $this->makeOption(11000, ProductTaxStatus::TAXABLE, 10.0);
        $option = ProductOption::find($optionId);

        $user = $this->createUser();
        $cart = CartFactory::new()
            ->forUser($user)
            ->forOption($option)
            ->create(['quantity' => 1]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', ['item_ids' => [$cart->id]])
            ->assertStatus(201);

        $response = $this->actingAs($user, 'sanctum')->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            [
                'orderer' => ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'vat@test.com'],
                'shipping' => [
                    'recipient_name' => '김철수',
                    'recipient_phone' => '010-9876-5432',
                    'country_code' => 'KR',
                    'zipcode' => '12345',
                    'address' => '서울시 강남구 테헤란로 123',
                    'address_detail' => '101동 1001호',
                ],
                'payment_method' => PaymentMethodEnum::DBANK->value,
                'expected_total_amount' => 11000,
                'depositor_name' => '홍길동',
                'dbank' => [
                    'bank_code' => 'KB',
                    'bank_name' => '국민은행',
                    'account_number' => '123-456-789012',
                    'account_holder' => '주식회사 테스트',
                ],
            ]
        );

        $response->assertStatus(201);

        $order = Order::where('user_id', $user->id)->latest('id')->first();
        $this->assertSame(1000, (int) $order->total_vat_amount);
        $this->assertSame(1000, (int) $order->payment->vat_amount);
    }

    /**
     * 계산 단계가 부가세 0 을 낸 주문은 주문·결제 행에도 0 으로 기록되는지 테스트합니다.
     *
     * 수정 전: 주문 생성이 「부가세 0 = 구버전 임시주문」으로 보고 과세표준/11 을 다시 채워,
     * 세율 0 과세 상품만 든 주문의 부가세가 10% 로 기록됐다(#612 S1).
     *
     * @effects zero_vat_calculation_not_refilled_at_creation
     *
     * @return void 반환값 없음
     */
    public function test_zero_vat_from_calculation_is_not_refilled_at_order_creation(): void
    {
        $optionId = $this->makeOption(10000, ProductTaxStatus::TAXABLE, 0.0);
        $option = ProductOption::find($optionId);

        $order = $this->placeOrder($option, 10000);

        $this->assertSame(10000, (int) $order->total_tax_amount);
        $this->assertSame(0, (int) $order->total_vat_amount);
        $this->assertSame(0, (int) $order->payment->vat_amount);
    }

    /**
     * 회원 장바구니 → 체크아웃 → 무통장 주문을 만들고 주문을 반환합니다.
     *
     * @param  ProductOption  $option  주문할 옵션
     * @param  int  $expectedTotal  예상 결제 금액
     * @param  int  $quantity  수량
     * @return Order 생성된 주문
     */
    private function placeOrder(ProductOption $option, int $expectedTotal, int $quantity = 1): Order
    {
        $user = $this->createUser();
        $cart = CartFactory::new()
            ->forUser($user)
            ->forOption($option)
            ->create(['quantity' => $quantity]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', ['item_ids' => [$cart->id]])
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            [
                'orderer' => ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'vat@test.com'],
                'shipping' => [
                    'recipient_name' => '김철수',
                    'recipient_phone' => '010-9876-5432',
                    'country_code' => 'KR',
                    'zipcode' => '12345',
                    'address' => '서울시 강남구 테헤란로 123',
                    'address_detail' => '101동 1001호',
                ],
                'payment_method' => PaymentMethodEnum::DBANK->value,
                'expected_total_amount' => $expectedTotal,
                'depositor_name' => '홍길동',
                'dbank' => [
                    'bank_code' => 'KB',
                    'bank_name' => '국민은행',
                    'account_number' => '123-456-789012',
                    'account_holder' => '주식회사 테스트',
                ],
            ]
        )->assertStatus(201);

        return Order::where('user_id', $user->id)->latest('id')->first();
    }

    /**
     * 주문 이후 상품 세율을 바꿔도 기존 주문의 부가세가 소급 변경되지 않는지 테스트합니다.
     *
     * 부분취소·재계산은 스냅샷 모드로 돈다. 이때 세율을 DB 현재값에서 읽으면 관리자가
     * 세율을 조정한 순간 과거 주문의 세액이 통째로 뒤바뀐다 — 이미 발행한 증빙과 어긋나는
     * 회계 사고다. 스냅샷의 주문 시점 세율만 쓰는지 고정한다.
     *
     * @effects snapshot_rate_freezes_order_time_value
     *
     * @return void 반환값 없음
     */
    public function test_snapshot_rate_freezes_order_time_value(): void
    {
        // 주문 시점 세율 10% → 이후 관리자가 5% 로 변경한 상태를 DB 로 표현
        $product = ProductFactory::new()->create([
            'shipping_policy_id' => null,
            'list_price' => 11000,
            'selling_price' => 11000,
            'tax_status' => ProductTaxStatus::TAXABLE,
            'tax_rate' => 5.0,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'selling_price' => 11000,
            'price_adjustment' => 0,
            'stock_quantity' => 100,
        ]);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $product->id,
                    productOptionId: $option->id,
                    quantity: 1,
                    productSnapshot: [
                        'id' => $product->id,
                        'name' => '주문 시 이름',
                        'selling_price' => 11000,
                        'tax_status' => ProductTaxStatus::TAXABLE->value,
                        'tax_rate' => 10.0,
                    ],
                    optionSnapshot: [
                        'id' => $option->id,
                        'selling_price' => 11000,
                        'price_adjustment' => 0,
                        'weight' => null,
                        'volume' => null,
                    ],
                ),
            ],
            metadata: ['snapshot_mode' => true],
        );

        $result = app(OrderCalculationService::class)->calculate($input);

        // 주문 시점 10% → 1,000. 현재 DB 세율 5% 를 읽었다면 524 가 된다.
        $this->assertSame(11000, $result->summary->taxableAmount);
        $this->assertSame(1000, $result->summary->vatAmount);
    }

    /**
     * 계산 결과 직렬화/역직렬화에서 부가세가 보존되는지 테스트합니다.
     *
     * 임시주문은 calculation_result 를 JSON 으로 저장했다가 주문 확정 시 다시 읽는다.
     *
     * @effects vat_survives_calculation_result_round_trip
     *
     * @return void 반환값 없음
     */
    public function test_vat_survives_array_round_trip(): void
    {
        $optionId = $this->makeOption(11000, ProductTaxStatus::TAXABLE, 10.0);
        $option = ProductOption::find($optionId);

        $result = $this->calculate([
            ['option_id' => $optionId, 'product_id' => $option->product_id, 'quantity' => 1],
        ]);

        $array = $result->summary->toArray();
        $this->assertSame(1000, $array['vat_amount']);

        $restored = Summary::fromArray($array);
        $this->assertSame(1000, $restored->vatAmount);
    }

    /**
     * 한 상품에 과세 종류가 다른 옵션들을 만듭니다.
     *
     * @param  ProductTaxStatus  $productStatus  상품 기본 과세 종류
     * @param  array<int, array{price: int, tax_status: ProductTaxStatus|null}>  $options  옵션 정의 (tax_status null = 상품 따름)
     * @return array<int, ProductOption> 만든 옵션 (정의 순서)
     */
    private function makeProductWithOptions(ProductTaxStatus $productStatus, array $options): array
    {
        $product = ProductFactory::new()->create([
            'shipping_policy_id' => null,
            'list_price' => 20000,
            'selling_price' => 0,
            'tax_status' => $productStatus,
            'tax_rate' => 10.0,
        ]);

        return array_map(fn (array $def) => ProductOptionFactory::new()->forProduct($product)->create([
            'selling_price' => $def['price'],
            'price_adjustment' => $def['price'],
            'stock_quantity' => 100,
            'tax_status' => $def['tax_status'],
        ]), $options);
    }

    /**
     * 영세 옵션만 든 주문은 부가세 0, 영세 금액 = 과세표준으로 기록되는지 테스트합니다.
     *
     * 상품 기본값은 과세이고 옵션만 영세로 지정한 가장 흔한 형태다(#612).
     *
     * @effects zero_rated_only_order_records_zero_vat_and_ledger
     *
     * @return void 반환값 없음
     */
    public function test_zero_rated_only_order_records_zero_vat_and_ledger(): void
    {
        [$option] = $this->makeProductWithOptions(ProductTaxStatus::TAXABLE, [
            ['price' => 10000, 'tax_status' => ProductTaxStatus::ZERO_RATED],
        ]);

        $order = $this->placeOrder($option, 10000);

        $this->assertSame(10000, (int) $order->total_tax_amount);
        $this->assertSame(10000, (int) $order->total_zero_rated_amount);
        $this->assertSame(0, (int) $order->total_vat_amount);
        $this->assertSame(0, (int) $order->total_tax_free_amount);
        $this->assertSame(0, (int) $order->payment->vat_amount);
        $this->assertSame(ProductTaxStatus::ZERO_RATED, $order->options->first()->tax_status);
        // 결제사에는 영세를 면세로 넘긴다 (P2)
        $this->assertSame(10000.0, $order->pgTaxFreeAmount());
        $this->assertSame(0.0, $order->pgTaxableAmount());
    }

    /**
     * 과세·면세·영세 옵션이 섞인 주문의 장부 4값이 갈래별로 나뉘는지 테스트합니다.
     *
     * 옵션 지정(면세·영세)이 상품 기본값(과세)을 덮고, 지정이 없는 옵션은 상품을 따른다.
     *
     * @effects mixed_zero_rated_order_splits_ledger
     *
     * @return void 반환값 없음
     */
    public function test_mixed_zero_rated_order_splits_ledger(): void
    {
        [$inherit, $free, $zero] = $this->makeProductWithOptions(ProductTaxStatus::TAXABLE, [
            ['price' => 11000, 'tax_status' => null],
            ['price' => 10000, 'tax_status' => ProductTaxStatus::TAX_FREE],
            ['price' => 10000, 'tax_status' => ProductTaxStatus::ZERO_RATED],
        ]);

        $result = $this->calculate([
            ['option_id' => $inherit->id, 'product_id' => $inherit->product_id, 'quantity' => 1],
            ['option_id' => $free->id, 'product_id' => $free->product_id, 'quantity' => 1],
            ['option_id' => $zero->id, 'product_id' => $zero->product_id, 'quantity' => 1],
        ]);

        // 과세표준 = 과세 11,000 + 영세 10,000, 부가세는 과세분만
        $this->assertSame(21000, $result->summary->taxableAmount);
        $this->assertSame(10000, $result->summary->zeroRatedAmount);
        $this->assertSame(1000, $result->summary->vatAmount);
        $this->assertSame(10000, $result->summary->taxFreeAmount);

        $statuses = array_map(fn ($item) => $item->taxStatus, $result->items);
        $this->assertSame(['taxable', 'tax_free', 'zero_rated'], $statuses);
    }

    /**
     * 상품 기본값이 영세이고 옵션 하나만 과세로 지정하면 그 옵션만 부가세가 붙는지 테스트합니다.
     *
     * @effects mixed_zero_rated_order_splits_ledger
     *
     * @return void 반환값 없음
     */
    public function test_option_taxable_overrides_zero_rated_product(): void
    {
        [$inherit, $taxable] = $this->makeProductWithOptions(ProductTaxStatus::ZERO_RATED, [
            ['price' => 10000, 'tax_status' => null],
            ['price' => 11000, 'tax_status' => ProductTaxStatus::TAXABLE],
        ]);

        $result = $this->calculate([
            ['option_id' => $inherit->id, 'product_id' => $inherit->product_id, 'quantity' => 1],
            ['option_id' => $taxable->id, 'product_id' => $taxable->product_id, 'quantity' => 1],
        ]);

        $this->assertSame(21000, $result->summary->taxableAmount);
        $this->assertSame(10000, $result->summary->zeroRatedAmount);
        $this->assertSame(1000, $result->summary->vatAmount);
        $this->assertSame(0, $result->summary->taxFreeAmount);
    }

    /**
     * 면세 상품(세율 0 저장)의 옵션 하나를 과세로 지정하면 그 옵션에 기본 세율 부가세가 붙는지 테스트합니다.
     *
     * 면세·영세 상품의 저장 세율은 과세 세율이 아니다(샘플·관리자 저장값 0). 그 값을 옵션 과세에 쓰면
     * 「과세」로 표시되면서 부가세가 0 으로 신고된다.
     *
     * @effects mixed_zero_rated_order_splits_ledger
     *
     * @return void 반환값 없음
     */
    public function test_option_taxable_under_tax_free_product_uses_default_rate(): void
    {
        [$inherit, $taxable] = $this->makeProductWithOptions(ProductTaxStatus::TAX_FREE, [
            ['price' => 10000, 'tax_status' => null],
            ['price' => 11000, 'tax_status' => ProductTaxStatus::TAXABLE],
        ]);
        $inherit->product->forceFill(['tax_rate' => 0])->save();

        $result = $this->calculate([
            ['option_id' => $inherit->id, 'product_id' => $inherit->product_id, 'quantity' => 1],
            ['option_id' => $taxable->id, 'product_id' => $taxable->product_id, 'quantity' => 1],
        ]);

        $this->assertSame(11000, $result->summary->taxableAmount);
        $this->assertSame(1000, $result->summary->vatAmount);
        $this->assertSame(10000, $result->summary->taxFreeAmount);
    }

    /**
     * 스냅샷 모드(클레임 재계산)는 주문 시점 옵션 스냅샷의 과세 종류를 쓰는지 테스트합니다.
     *
     * 주문 뒤 운영자가 옵션을 과세로 바꿔도 지난 주문의 영세 분류가 소급 변경되지 않아야 한다.
     *
     * @effects snapshot_rate_freezes_order_time_value
     *
     * @return void 반환값 없음
     */
    public function test_snapshot_mode_uses_option_snapshot_tax_status(): void
    {
        [$option] = $this->makeProductWithOptions(ProductTaxStatus::TAXABLE, [
            ['price' => 10000, 'tax_status' => ProductTaxStatus::TAXABLE],
        ]);

        $input = new CalculationInput(
            items: [
                new CalculationItem(
                    productId: $option->product_id,
                    productOptionId: $option->id,
                    quantity: 1,
                    productSnapshot: [
                        'id' => $option->product_id,
                        'name' => '주문 시 이름',
                        'selling_price' => 0,
                        'tax_status' => ProductTaxStatus::TAXABLE->value,
                        'tax_rate' => 10.0,
                    ],
                    optionSnapshot: [
                        'id' => $option->id,
                        'selling_price' => 10000,
                        'price_adjustment' => 10000,
                        'tax_status' => ProductTaxStatus::ZERO_RATED->value,
                    ],
                ),
            ],
            metadata: ['snapshot_mode' => true],
        );

        $result = app(OrderCalculationService::class)->calculate($input);

        $this->assertSame(10000, $result->summary->zeroRatedAmount);
        $this->assertSame(0, $result->summary->vatAmount);
    }

    /**
     * 부가세 합산 도입 이전 임시주문(부가세 키 없음)만 과세표준으로 다시 채우고, 그때도 영세분은 뺀다.
     *
     * @effects zero_vat_calculation_not_refilled_at_creation
     *
     * @return void 반환값 없음
     */
    public function test_legacy_summary_without_vat_key_refills_excluding_zero_rated(): void
    {
        $legacy = Summary::fromArray(['taxable_amount' => 21000, 'zero_rated_amount' => 10000]);
        $this->assertFalse($legacy->vatAmountKnown);

        $current = Summary::fromArray(['taxable_amount' => 10000, 'vat_amount' => 0, 'zero_rated_amount' => 10000]);
        $this->assertTrue($current->vatAmountKnown);

        $service = app(OrderProcessingService::class);
        $resolve = new ReflectionMethod($service, 'resolveVatAmount');

        $this->assertSame(1000, $resolve->invoke($service, new OrderCalculationResult(summary: $legacy)));
        $this->assertSame(0, $resolve->invoke($service, new OrderCalculationResult(summary: $current)));
    }

    /**
     * 전체상품 정률 상품쿠폰을 발급합니다.
     *
     * @param  float  $rate  할인율 (%)
     * @return CouponIssue 사용 가능한 발급 내역
     */
    private function issueProductRateCoupon(float $rate): CouponIssue
    {
        $coupon = Coupon::create([
            'name' => ['ko' => '영세 쿠폰', 'en' => 'Zero-rated coupon'],
            'description' => ['ko' => '테스트용 쿠폰', 'en' => 'Test coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'discount_type' => CouponDiscountType::RATE,
            'discount_value' => $rate,
            'discount_max_amount' => null,
            'min_order_amount' => 0,
            'target_scope' => CouponTargetScope::ALL,
            'is_combinable' => true,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDays(30),
        ]);

        return CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->createUser()->id,
            'coupon_code' => 'ZR'.uniqid(),
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expired_at' => now()->addDays(30),
        ]);
    }

    /**
     * 상품쿠폰 할인 뒤 금액으로 과세·영세를 나누는지 테스트합니다(#612).
     *
     * 과세 11,000 · 영세 10,000 에 전체상품 10% 쿠폰 → 과세 9,900(부가세 900) · 영세 9,000.
     * 쿠폰 전 소계로 분류하면 영세 10,000 · 부가세 1,000 이 되어 결제 금액보다 큰 과세표준을 신고한다.
     *
     * @effects zero_rated_classified_after_product_coupon
     *
     * @return void 반환값 없음
     */
    public function test_zero_rated_classification_uses_amount_after_product_coupon(): void
    {
        [$taxable] = $this->makeProductWithOptions(ProductTaxStatus::TAXABLE, [
            ['price' => 11000, 'tax_status' => null],
        ]);
        [$zero] = $this->makeProductWithOptions(ProductTaxStatus::TAXABLE, [
            ['price' => 10000, 'tax_status' => ProductTaxStatus::ZERO_RATED],
        ]);
        $issue = $this->issueProductRateCoupon(10);

        $result = app(OrderCalculationService::class)->calculate(new CalculationInput(
            items: [
                new CalculationItem(productId: $taxable->product_id, productOptionId: $taxable->id, quantity: 1),
                new CalculationItem(productId: $zero->product_id, productOptionId: $zero->id, quantity: 1),
            ],
            couponIssueIds: [$issue->id],
        ));

        // 전제: 쿠폰이 두 품목에 10% 씩 걸렸다
        $this->assertSame(2100, $result->summary->productCouponDiscount);

        $this->assertSame(9900 + 9000, $result->summary->taxableAmount);
        $this->assertSame(9000, $result->summary->zeroRatedAmount);
        $this->assertSame(900, $result->summary->vatAmount);
        $this->assertSame(0, $result->summary->taxFreeAmount);

        $byOption = collect($result->items)->keyBy('productOptionId');
        $this->assertSame('zero_rated', $byOption[$zero->id]->taxStatus);
        $this->assertSame(9000, $byOption[$zero->id]->zeroRatedAmount);
        $this->assertSame(9000, $byOption[$zero->id]->taxableAmount);
        $this->assertSame(0, $byOption[$taxable->id]->zeroRatedAmount);
        $this->assertSame(9900, $byOption[$taxable->id]->taxableAmount);
    }

    /**
     * 국내 고정 배송비 정책을 만듭니다.
     *
     * @param  int  $baseFee  기본 배송비
     * @return ShippingPolicy 배송정책
     */
    private function makeFixedShippingPolicy(int $baseFee): ShippingPolicy
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '영세 배송정책', 'en' => 'Zero-rated shipping'],
            'is_default' => false,
            'is_active' => true,
        ]);

        $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => ChargePolicyEnum::FIXED,
            'base_fee' => $baseFee,
            'is_active' => true,
        ]);

        return $policy;
    }

    /**
     * 여러 옵션을 한 장바구니로 담아 회원 무통장 주문을 만듭니다.
     *
     * @param  array<int, ProductOption>  $options  주문할 옵션 (각 1개)
     * @param  int  $expectedTotal  예상 결제 금액
     * @return Order 생성된 주문
     */
    private function placeOrderForOptions(array $options, int $expectedTotal): Order
    {
        $user = $this->createUser();
        $cartIds = array_map(
            fn (ProductOption $option) => CartFactory::new()->forUser($user)->forOption($option)->create(['quantity' => 1])->id,
            $options,
        );

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', ['item_ids' => $cartIds])
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')->postJson('/api/modules/sirsoft-ecommerce/user/orders', [
            'orderer' => ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'vat@test.com'],
            'shipping' => [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9876-5432',
                'country_code' => 'KR',
                'zipcode' => '12345',
                'address' => '서울시 강남구 테헤란로 123',
                'address_detail' => '101동 1001호',
            ],
            'payment_method' => PaymentMethodEnum::DBANK->value,
            'expected_total_amount' => $expectedTotal,
            'depositor_name' => '홍길동',
            'dbank' => [
                'bank_code' => 'KB',
                'bank_name' => '국민은행',
                'account_number' => '123-456-789012',
                'account_holder' => '주식회사 테스트',
            ],
        ])->assertStatus(201);

        return Order::where('user_id', $user->id)->latest('id')->first();
    }

    /**
     * 과세·영세 혼합 주문의 배송비 부가세는 배송비의 과세 몫에서만 나오는지 테스트합니다(#612).
     *
     * 과세 11,000 · 영세 10,000 · 배송비 3,000(안분) → 배송비 과세 1,571 · 영세 1,429.
     * 부가세 = 상품 1,000 + round(1,571 / 11) = 143 → 1,143. 배송비 전액(3,000)을 과세로 보면
     * 1,273 이 되어 영세 운송분에도 부가세가 붙는다.
     *
     * @effects shipping_vat_from_taxable_share_only
     *
     * @return void 반환값 없음
     */
    public function test_shipping_vat_comes_only_from_taxable_shipping_share(): void
    {
        app(EcommerceSettingsService::class)->setSetting('order_settings.shipping_fee_tax_policy', 'proportional');

        [$inherit, $zero] = $this->makeProductWithOptions(ProductTaxStatus::TAXABLE, [
            ['price' => 11000, 'tax_status' => null],
            ['price' => 10000, 'tax_status' => ProductTaxStatus::ZERO_RATED],
        ]);
        $inherit->product->forceFill(['shipping_policy_id' => $this->makeFixedShippingPolicy(3000)->id])->save();

        $order = $this->placeOrderForOptions([$inherit->fresh(), $zero->fresh()], 24000);

        // 전제: 배송비 3,000 이 한 번 붙었다
        $this->assertSame(3000, (int) $order->total_shipping_amount);

        $this->assertSame(24000, (int) $order->total_tax_amount);
        $this->assertSame(10000 + 1429, (int) $order->total_zero_rated_amount);
        $this->assertSame(1000 + 143, (int) $order->total_vat_amount);
        $this->assertSame(0, (int) $order->total_tax_free_amount);
        $this->assertSame(1143, (int) $order->payment->vat_amount);
        // 결제사 과세 = 상품 과세 11,000 + 배송비 과세 1,571, 면세 = 영세 합
        $this->assertSame(12571.0, $order->pgTaxableAmount());
        $this->assertSame(11429.0, $order->pgTaxFreeAmount());
    }
}
