<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Order;

use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\Summary;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 부가세 산출 테스트 (E5)
 *
 * 수정 전에는 `total_vat_amount` 쓰기 지점이 부분취소 재계산 1곳뿐이라, 부분취소를 겪은
 * 주문만 값이 정상화되고 나머지 주문은 영구 0 이었습니다. 같은 화면에서 주문마다 세금
 * 표기가 달랐습니다.
 *
 * @scenario actor=member, change_mode=api
 *
 * @effects order_vat_calculated_at_creation, mixed_tax_rate_summed_per_option
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
     * 주문 이후 상품 세율을 바꿔도 기존 주문의 부가세가 소급 변경되지 않는지 테스트합니다.
     *
     * 부분취소·재계산은 스냅샷 모드로 돈다. 이때 세율을 DB 현재값에서 읽으면 관리자가
     * 세율을 조정한 순간 과거 주문의 세액이 통째로 뒤바뀐다 — 이미 발행한 증빙과 어긋나는
     * 회계 사고다. 스냅샷의 주문 시점 세율만 쓰는지 고정한다.
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
}
