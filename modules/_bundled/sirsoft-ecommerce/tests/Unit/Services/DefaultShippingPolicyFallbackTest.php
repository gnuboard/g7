<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\ShippingAddress;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 기본 배송정책 폴백 계산 Unit 테스트
 *
 * 상품에 배송정책이 부여되지 않은 경우(shipping_policy_id=null) 계산 단계에서
 * 기본 배송정책(is_default=true)으로 폴백되어 배송비가 부과되는지 검증합니다.
 *
 * 회귀 배경: 기존 groupByShippingPolicy 는 정책이 null 인 상품을 배송비 그룹에서
 * 제외(continue)해 "무료"로 처리했고, 일본(JP) 선택 시 배송불가로 판정되었습니다.
 */
class DefaultShippingPolicyFallbackTest extends ModuleTestCase
{
    protected OrderCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupTestCurrencySettings();
        $this->service = app(OrderCalculationService::class);
    }

    /**
     * 테스트용 KRW 단일 통화 설정을 저장합니다.
     */
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

    /**
     * 기본 배송정책(KR/JP 각 FIXED 600)을 생성합니다.
     *
     * @return ShippingPolicy 생성된 기본 배송정책
     */
    protected function makeDefaultPolicy(): ShippingPolicy
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '기본 배송정책', 'en' => 'Default Policy'],
            'is_default' => true,
            'is_active' => true,
        ]);

        foreach (['KR', 'JP'] as $code) {
            $policy->countrySettings()->create([
                'country_code' => $code,
                'shipping_method' => 'parcel',
                'currency_code' => 'KRW',
                'charge_policy' => ChargePolicyEnum::FIXED,
                'base_fee' => 600,
                'is_active' => true,
            ]);
        }

        return $policy->load('countrySettings');
    }

    /**
     * 배송정책 없는 상품+옵션을 생성합니다.
     *
     * @return array{0: Product, 1: ProductOption}
     */
    protected function makeProductWithoutPolicy(int $price = 30000): array
    {
        $product = ProductFactory::new()->create([
            'tax_status' => 'taxable',
            'selling_price' => $price,
            'list_price' => $price,
            'shipping_policy_id' => null,
        ]);

        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'price_adjustment' => 0,
            'stock_quantity' => 100,
            'is_default' => true,
        ]);

        return [$product, $option];
    }

    /**
     * 정책 없는 상품이 KR 배송지에서 기본 배송정책의 배송비(600)를 부과받는다.
     *
     * 회귀: 기존엔 정책 null 상품이 배송비 그룹에서 제외되어 0원(무료)이었음.
     */
    public function test_null_policy_product_charges_default_policy_fee_for_kr(): void
    {
        $this->makeDefaultPolicy();
        [$product, $option] = $this->makeProductWithoutPolicy();

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1)],
            shippingAddress: new ShippingAddress(countryCode: 'KR'),
        );

        $result = $this->service->calculate($input);

        $this->assertEquals(600, $result->summary->totalShipping);
    }

    /**
     * 정책 없는 상품이 JP 배송지에서도 기본 배송정책의 배송비(600)를 부과받는다.
     *
     * 회귀: 기존엔 JP 선택 시 배송불가 + 무료였음.
     */
    public function test_null_policy_product_charges_default_policy_fee_for_jp(): void
    {
        $this->makeDefaultPolicy();
        [$product, $option] = $this->makeProductWithoutPolicy();

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1)],
            shippingAddress: new ShippingAddress(countryCode: 'JP'),
        );

        $result = $this->service->calculate($input);

        $this->assertEquals(600, $result->summary->totalShipping);
    }

    /**
     * 기본 배송정책이 없으면 정책 없는 상품은 배송비 0원(국내 기본 배송)으로 처리된다.
     */
    public function test_null_policy_product_is_free_when_no_default_policy_exists(): void
    {
        [$product, $option] = $this->makeProductWithoutPolicy();

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1)],
            shippingAddress: new ShippingAddress(countryCode: 'KR'),
        );

        $result = $this->service->calculate($input);

        $this->assertEquals(0, $result->summary->totalShipping);
    }

    /**
     * 폴백 계산 결과의 적용 배송정책이 기본 배송정책 ID 와 스냅샷을 동결한다.
     *
     * 환불 재계산은 이 동결된 스냅샷(policy_id + country_setting)을 기준으로 동작하므로,
     * 주문 시점의 기본 배송정책이 이후 변경되어도 원 주문 기준이 보존된다.
     */
    public function test_fallback_freezes_default_policy_into_applied_snapshot(): void
    {
        $default = $this->makeDefaultPolicy();
        [$product, $option] = $this->makeProductWithoutPolicy();

        $input = new CalculationInput(
            items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1)],
            shippingAddress: new ShippingAddress(countryCode: 'JP'),
        );

        $result = $this->service->calculate($input);

        $applied = $result->items[0]->appliedShippingPolicy;

        $this->assertNotNull($applied);
        $this->assertSame($default->id, $applied->policyId);
        $this->assertSame('JP', $applied->policySnapshot['country_code'] ?? null);
        $this->assertSame(600, $applied->policySnapshot['base_fee'] ?? null);
    }
}
