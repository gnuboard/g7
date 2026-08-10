<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 배송비 과세 정책 통합 테스트 (A-4)
 *
 * 단위 테스트(ShippingFeeTaxPolicyTest)가 분류 로직을 검증한다면, 본 테스트는
 * calculate() 전체 파이프라인을 통과한 Summary 에 배송비 과세액이 실제로 합산되는지 확인한다.
 *
 * 배송비는 단계 3 에서 계산되므로 단계 2-b 의 classifyTaxStatus 로는 분류할 수 없다.
 * 회귀 pin: 배송비가 Summary 의 taxableAmount/taxFreeAmount 에서 누락되면 실패한다.
 */
class ShippingFeeTaxIntegrationTest extends ModuleTestCase
{
    private OrderCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OrderCalculationService::class);
    }

    private function setPolicy(string $policy): void
    {
        app(EcommerceSettingsService::class)->setSetting('order_settings.shipping_fee_tax_policy', $policy);
    }

    /**
     * 고정 배송비 정책을 생성합니다.
     */
    private function makeShippingPolicy(int $baseFee): ShippingPolicy
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
            'charge_policy' => ChargePolicyEnum::FIXED,
            'base_fee' => $baseFee,
            'is_active' => true,
        ]);

        return $policy->load('countrySettings');
    }

    /**
     * 과세 상태와 배송정책을 지정한 상품을 생성합니다.
     *
     * @return array{0: Product, 1: ProductOption}
     */
    private function makeProduct(int $price, ProductTaxStatus $taxStatus, ?ShippingPolicy $policy = null): array
    {
        $product = ProductFactory::new()->create([
            'tax_status' => $taxStatus,
            'selling_price' => $price,
            'list_price' => $price,
            'shipping_policy_id' => $policy?->id,
        ]);

        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'price_adjustment' => 0,
            'stock_quantity' => 100,
            'is_default' => true,
        ]);

        return [$product, $option];
    }

    /**
     * 주어진 상품 구성으로 계산을 수행합니다.
     */
    private function calculate(array ...$productOptionPairs): OrderCalculationResult
    {
        $items = [];
        foreach ($productOptionPairs as [$product, $option]) {
            $items[] = new CalculationItem(
                productId: $product->id,
                productOptionId: $option->id,
                quantity: 1,
            );
        }

        return $this->service->calculate(new CalculationInput(items: $items));
    }

    #[Test]
    public function 기본_안분_정책에서_전액과세_주문의_배송비는_과세로_합산된다(): void
    {
        $policy = $this->makeShippingPolicy(3000);
        $taxable = $this->makeProduct(30000, ProductTaxStatus::TAXABLE, $policy);

        $result = $this->calculate($taxable);

        $this->assertEquals(3000, $result->summary->totalShipping);
        // 상품 30000 + 배송비 3000 = 33000 이 모두 과세
        $this->assertEquals(33000, $result->summary->taxableAmount, '배송비가 과세액에 합산되어야 한다');
        $this->assertEquals(0, $result->summary->taxFreeAmount);
    }

    #[Test]
    public function 기본_안분_정책에서_전액면세_주문의_배송비는_면세로_합산된다(): void
    {
        $policy = $this->makeShippingPolicy(3000);
        $taxFree = $this->makeProduct(30000, ProductTaxStatus::TAX_FREE, $policy);

        $result = $this->calculate($taxFree);

        $this->assertEquals(3000, $result->summary->totalShipping);
        $this->assertEquals(0, $result->summary->taxableAmount);
        $this->assertEquals(33000, $result->summary->taxFreeAmount, '배송비가 면세액에 합산되어야 한다');
    }

    #[Test]
    public function 안분_정책에서_복합_주문의_배송비는_과세비율만큼_나뉜다(): void
    {
        $this->setPolicy('proportional');
        $policy = $this->makeShippingPolicy(3000);
        $taxable = $this->makeProduct(6000, ProductTaxStatus::TAXABLE, $policy);
        $taxFree = $this->makeProduct(4000, ProductTaxStatus::TAX_FREE, $policy);

        $result = $this->calculate($taxable, $taxFree);

        // 상품 과세 6000 : 면세 4000 → 배송비 3000 을 1800 : 1200 로 안분
        $this->assertEquals(6000 + 1800, $result->summary->taxableAmount);
        $this->assertEquals(4000 + 1200, $result->summary->taxFreeAmount);

        // 합계 보존: 상품 10000 + 배송비 3000
        $this->assertEquals(
            13000,
            $result->summary->taxableAmount + $result->summary->taxFreeAmount,
        );
    }

    #[Test]
    public function 전액과세_정책은_면세_주문의_배송비도_과세로_분류한다(): void
    {
        $this->setPolicy('taxable');
        $policy = $this->makeShippingPolicy(3000);
        $taxFree = $this->makeProduct(30000, ProductTaxStatus::TAX_FREE, $policy);

        $result = $this->calculate($taxFree);

        $this->assertEquals(3000, $result->summary->taxableAmount, '배송비 전액이 과세');
        $this->assertEquals(30000, $result->summary->taxFreeAmount, '상품은 면세 유지');
    }

    #[Test]
    public function 주된재화_정책은_면세가_우세하면_배송비를_면세로_분류한다(): void
    {
        $this->setPolicy('follow_main_item');
        $policy = $this->makeShippingPolicy(3000);
        $taxable = $this->makeProduct(4000, ProductTaxStatus::TAXABLE, $policy);
        $taxFree = $this->makeProduct(6000, ProductTaxStatus::TAX_FREE, $policy);

        $result = $this->calculate($taxable, $taxFree);

        $this->assertEquals(4000, $result->summary->taxableAmount);
        $this->assertEquals(6000 + 3000, $result->summary->taxFreeAmount, '배송비가 주된재화(면세)를 따른다');
    }

    #[Test]
    public function 주된재화_정책은_과세가_우세하면_배송비를_과세로_분류한다(): void
    {
        $this->setPolicy('follow_main_item');
        $policy = $this->makeShippingPolicy(3000);
        $taxable = $this->makeProduct(6000, ProductTaxStatus::TAXABLE, $policy);
        $taxFree = $this->makeProduct(4000, ProductTaxStatus::TAX_FREE, $policy);

        $result = $this->calculate($taxable, $taxFree);

        $this->assertEquals(6000 + 3000, $result->summary->taxableAmount);
        $this->assertEquals(4000, $result->summary->taxFreeAmount);
    }

    #[Test]
    public function 배송비가_없으면_과세_면세액은_상품금액과_동일하다(): void
    {
        // 배송정책 미지정 → 배송비 0 (기존 회귀 pin)
        $taxable = $this->makeProduct(30000, ProductTaxStatus::TAXABLE);
        $taxFree = $this->makeProduct(20000, ProductTaxStatus::TAX_FREE);

        $result = $this->calculate($taxable, $taxFree);

        $this->assertEquals(0, $result->summary->totalShipping);
        $this->assertEquals(30000, $result->summary->taxableAmount);
        $this->assertEquals(20000, $result->summary->taxFreeAmount);
    }

    #[Test]
    public function 옵션별_과세액에는_배송비가_섞이지_않는다(): void
    {
        // 배송비는 Summary 에만 합산된다 — 옵션 단위 taxableAmount 오염 방지 pin
        $policy = $this->makeShippingPolicy(3000);
        $taxable = $this->makeProduct(30000, ProductTaxStatus::TAXABLE, $policy);

        $result = $this->calculate($taxable);

        $this->assertEquals(30000, $result->items[0]->taxableAmount, '옵션 과세액은 상품분만');
        $this->assertEquals(0, $result->items[0]->taxFreeAmount);
        $this->assertEquals(33000, $result->summary->taxableAmount, 'Summary 에만 배송비 합산');
    }
}
