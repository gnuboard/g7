<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOption;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOptionValue;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 추가옵션(유료) 계산 Unit 테스트
 *
 * OrderCalculationService 가 추가옵션 추가금을 안B 산식으로 가산하는지 검증합니다.
 * - subtotal = (unit_price + additional_options_total) × quantity (D6)
 * - unit_price 는 원옵션가 유지 (안B)
 * - 가격은 value_id 기준 서버 재조회 (클라 가격 신뢰 금지)
 * - 정률 적립은 추가옵션 포함, 정액 적립은 무관 (D5)
 * - 타상품/비활성 value_id 는 무시
 */
class AdditionalOptionsCalculationTest extends ModuleTestCase
{
    protected OrderCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupTestCurrencySettings();
        $this->service = app(OrderCalculationService::class);
    }

    /**
     * 테스트용 통화/마일리지 설정을 저장합니다.
     */
    protected function setupTestCurrencySettings(): void
    {
        $settingsPath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($settingsPath)) {
            mkdir($settingsPath, 0755, true);
        }

        file_put_contents(
            $settingsPath.'/language_currency.json',
            json_encode([
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
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        file_put_contents(
            $settingsPath.'/mileage.json',
            json_encode([
                'enabled' => true,
                'default_earn_rate' => 1,
                'earn_trigger' => 'confirmed',
                'earn_delay_days' => 0,
                'currency_rules' => [
                    ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0],
                ],
                'expiry_enabled' => true,
                'expiry_days' => 365,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    protected function tearDown(): void
    {
        foreach (['language_currency', 'mileage'] as $name) {
            $file = storage_path("framework/testing/modules/sirsoft-ecommerce/settings/{$name}.json");
            if (file_exists($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    /**
     * 추가옵션 그룹 + 선택지를 생성합니다.
     *
     * @param  Product  $product  상품
     * @param  int  $priceAdjustment  선택지 추가금
     * @param  bool  $isRequired  필수 그룹 여부
     * @param  bool  $isActive  선택지 활성 여부
     * @return ProductAdditionalOptionValue 생성된 선택지
     */
    protected function createAdditionalOptionValue(
        Product $product,
        int $priceAdjustment,
        bool $isRequired = false,
        bool $isActive = true,
        bool $allowCustomText = false
    ): ProductAdditionalOptionValue {
        $group = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '각인', 'en' => 'Engraving'],
            'is_required' => $isRequired,
            'sort_order' => 0,
        ]);

        return ProductAdditionalOptionValue::create([
            'additional_option_id' => $group->id,
            'name' => ['ko' => '각인 추가', 'en' => 'Add engraving'],
            'price_adjustment' => $priceAdjustment,
            'is_default' => false,
            'is_active' => $isActive,
            'allow_custom_text' => $allowCustomText,
            'sort_order' => 0,
        ]);
    }

    /**
     * 테스트용 상품/옵션을 생성합니다.
     *
     * @param  int  $price  판매가
     * @param  string  $mileageType  적립 유형 (percent/fixed)
     * @param  float|null  $mileageValue  적립 값
     * @return array{0: Product, 1: ProductOption}
     */
    protected function createProductWithOption(int $price = 10000, ?string $mileageType = null, ?float $mileageValue = null): array
    {
        $product = ProductFactory::new()->create([
            'tax_status' => 'taxable',
            'selling_price' => $price,
            'list_price' => $price,
        ]);

        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'price_adjustment' => 0,
            'stock_quantity' => 100,
            'is_default' => true,
            'mileage_type' => $mileageType,
            'mileage_value' => $mileageValue,
        ]);

        return [$product, $option];
    }

    public function test_subtotal_includes_additional_option_times_quantity(): void
    {
        // Given: 10,000원 상품 × 3개 + 각인 추가 5,000원
        [$product, $option] = $this->createProductWithOption(price: 10000);
        $value = $this->createAdditionalOptionValue($product, priceAdjustment: 5000);

        $input = new CalculationInput(items: [
            new CalculationItem(
                productId: $product->id,
                productOptionId: $option->id,
                quantity: 3,
                additionalOptionSelections: [
                    ['additional_option_id' => $value->additional_option_id, 'value_id' => $value->id],
                ],
            ),
        ]);

        // When
        $result = $this->service->calculate($input);

        // Then: subtotal = (10000 + 5000) × 3 = 45,000, unit_price 는 원옵션가 10,000 유지 (안B)
        $this->assertCount(1, $result->items);
        $this->assertEquals(10000, $result->items[0]->unitPrice);
        $this->assertEquals(5000, $result->items[0]->additionalOptionsTotal);
        $this->assertEquals(45000, $result->items[0]->subtotal);
    }

    public function test_no_additional_option_keeps_base_subtotal(): void
    {
        // Given: 추가옵션 미선택
        [$product, $option] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2),
        ]);

        $result = $this->service->calculate($input);

        $this->assertEquals(10000, $result->items[0]->unitPrice);
        $this->assertEquals(0, $result->items[0]->additionalOptionsTotal);
        $this->assertEquals(20000, $result->items[0]->subtotal);
    }

    public function test_price_is_resolved_server_side_ignoring_client_value(): void
    {
        // Given: 선택지 추가금은 서버 DB 5,000원. 클라가 다른 값을 보내도 무시되어야 함.
        [$product, $option] = $this->createProductWithOption(price: 10000);
        $value = $this->createAdditionalOptionValue($product, priceAdjustment: 5000);

        $input = new CalculationInput(items: [
            new CalculationItem(
                productId: $product->id,
                productOptionId: $option->id,
                quantity: 1,
                // 클라가 임의 price 를 끼워넣어도 DTO 는 value_id 만 신뢰
                additionalOptionSelections: [
                    ['additional_option_id' => $value->additional_option_id, 'value_id' => $value->id, 'price_adjustment' => 999999],
                ],
            ),
        ]);

        $result = $this->service->calculate($input);

        // 서버 DB 값(5000)으로 계산
        $this->assertEquals(5000, $result->items[0]->additionalOptionsTotal);
        $this->assertEquals(15000, $result->items[0]->subtotal);
    }

    public function test_value_from_other_product_is_ignored(): void
    {
        // Given: 다른 상품의 선택지 value_id 를 선택 → 무시되어 추가금 0
        [$product, $option] = $this->createProductWithOption(price: 10000);
        [$otherProduct] = $this->createProductWithOption(price: 8000);
        $otherValue = $this->createAdditionalOptionValue($otherProduct, priceAdjustment: 7000);

        $input = new CalculationInput(items: [
            new CalculationItem(
                productId: $product->id,
                productOptionId: $option->id,
                quantity: 1,
                additionalOptionSelections: [
                    ['additional_option_id' => $otherValue->additional_option_id, 'value_id' => $otherValue->id],
                ],
            ),
        ]);

        $result = $this->service->calculate($input);

        $this->assertEquals(0, $result->items[0]->additionalOptionsTotal);
        $this->assertEquals(10000, $result->items[0]->subtotal);
    }

    public function test_inactive_value_is_ignored(): void
    {
        // Given: 비활성 선택지 → 추가금 0
        [$product, $option] = $this->createProductWithOption(price: 10000);
        $value = $this->createAdditionalOptionValue($product, priceAdjustment: 5000, isActive: false);

        $input = new CalculationInput(items: [
            new CalculationItem(
                productId: $product->id,
                productOptionId: $option->id,
                quantity: 1,
                additionalOptionSelections: [
                    ['additional_option_id' => $value->additional_option_id, 'value_id' => $value->id],
                ],
            ),
        ]);

        $result = $this->service->calculate($input);

        $this->assertEquals(0, $result->items[0]->additionalOptionsTotal);
    }

    public function test_percent_mileage_includes_additional_option(): void
    {
        // Given: 정률 1% 적립, 10,000 + 5,000 = 15,000 → 적립 150
        [$product, $option] = $this->createProductWithOption(price: 10000, mileageType: 'percent', mileageValue: 1.0);
        $value = $this->createAdditionalOptionValue($product, priceAdjustment: 5000);

        $input = new CalculationInput(items: [
            new CalculationItem(
                productId: $product->id,
                productOptionId: $option->id,
                quantity: 1,
                additionalOptionSelections: [
                    ['additional_option_id' => $value->additional_option_id, 'value_id' => $value->id],
                ],
            ),
        ]);

        $result = $this->service->calculate($input);

        // 정률 적립은 추가옵션 포함 subtotal(15000) 기준 → 150
        $this->assertEquals(150, $result->items[0]->pointsEarning);
    }

    public function test_fixed_mileage_excludes_additional_option(): void
    {
        // Given: 정액 500 적립 × 수량 2 = 1000, 추가옵션과 무관
        [$product, $option] = $this->createProductWithOption(price: 10000, mileageType: 'fixed', mileageValue: 500.0);
        $value = $this->createAdditionalOptionValue($product, priceAdjustment: 5000);

        $input = new CalculationInput(items: [
            new CalculationItem(
                productId: $product->id,
                productOptionId: $option->id,
                quantity: 2,
                additionalOptionSelections: [
                    ['additional_option_id' => $value->additional_option_id, 'value_id' => $value->id],
                ],
            ),
        ]);

        $result = $this->service->calculate($input);

        // 정액 적립: 500 × 2 = 1000 (추가옵션 무관)
        $this->assertEquals(1000, $result->items[0]->pointsEarning);
    }

    public function test_additional_options_snapshot_is_built(): void
    {
        // Given
        [$product, $option] = $this->createProductWithOption(price: 10000);
        $value = $this->createAdditionalOptionValue($product, priceAdjustment: 5000);

        $input = new CalculationInput(items: [
            new CalculationItem(
                productId: $product->id,
                productOptionId: $option->id,
                quantity: 1,
                additionalOptionSelections: [
                    ['additional_option_id' => $value->additional_option_id, 'value_id' => $value->id],
                ],
            ),
        ]);

        $result = $this->service->calculate($input);

        $snapshot = $result->items[0]->additionalOptionsSnapshot;
        $this->assertIsArray($snapshot);
        $this->assertCount(1, $snapshot);
        $this->assertEquals($value->id, $snapshot[0]['value_id']);
        $this->assertEquals(5000, $snapshot[0]['price_adjustment']);
    }

    public function test_custom_text_is_frozen_into_snapshot(): void
    {
        // Given: allow_custom_text 선택지 + 직접입력 텍스트 → 스냅샷에 동결 (E3)
        [$product, $option] = $this->createProductWithOption(price: 10000);
        $value = $this->createAdditionalOptionValue($product, priceAdjustment: 5000, allowCustomText: true);

        $input = new CalculationInput(items: [
            new CalculationItem(
                productId: $product->id,
                productOptionId: $option->id,
                quantity: 1,
                additionalOptionSelections: [
                    ['additional_option_id' => $value->additional_option_id, 'value_id' => $value->id, 'custom_text' => '홍길동'],
                ],
            ),
        ]);

        $result = $this->service->calculate($input);

        $snapshot = $result->items[0]->additionalOptionsSnapshot;
        $this->assertSame('홍길동', $snapshot[0]['custom_text']);
        // 직접입력 텍스트는 가격에 무관 (E2)
        $this->assertEquals(5000, $result->items[0]->additionalOptionsTotal);
    }

    public function test_custom_text_not_frozen_when_value_disallows(): void
    {
        // Given: allow_custom_text=false 선택지 — custom_text 전송돼도 스냅샷 미주입 (E4)
        [$product, $option] = $this->createProductWithOption(price: 10000);
        $value = $this->createAdditionalOptionValue($product, priceAdjustment: 5000);

        $input = new CalculationInput(items: [
            new CalculationItem(
                productId: $product->id,
                productOptionId: $option->id,
                quantity: 1,
                additionalOptionSelections: [
                    ['additional_option_id' => $value->additional_option_id, 'value_id' => $value->id, 'custom_text' => '무시'],
                ],
            ),
        ]);

        $result = $this->service->calculate($input);

        $this->assertArrayNotHasKey('custom_text', $result->items[0]->additionalOptionsSnapshot[0]);
    }

    public function test_snapshot_mode_uses_frozen_additional_total(): void
    {
        // Given: 환불 재계산(스냅샷 모드) — DB 가 아닌 동결 스냅샷 추가금 사용
        [$product, $option] = $this->createProductWithOption(price: 10000);

        $input = new CalculationInput(items: [
            new CalculationItem(
                productId: $product->id,
                productOptionId: $option->id,
                quantity: 2,
                productSnapshot: $product->toSnapshotArray(),
                optionSnapshot: $option->toSnapshotArray(),
                additionalOptionsSnapshot: [
                    ['additional_option_id' => 999, 'value_id' => 888, 'name' => ['ko' => '각인'], 'price_adjustment' => 3000, 'mc_price_adjustment' => null],
                ],
            ),
        ]);

        $result = $this->service->calculate($input);

        // 동결 추가금 3000 사용: subtotal = (10000 + 3000) × 2 = 26000
        $this->assertEquals(3000, $result->items[0]->additionalOptionsTotal);
        $this->assertEquals(26000, $result->items[0]->subtotal);
    }
}
