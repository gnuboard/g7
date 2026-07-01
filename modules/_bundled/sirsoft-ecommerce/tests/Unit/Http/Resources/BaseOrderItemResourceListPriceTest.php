<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\BaseOrderItemResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 정가/할인율 공통 베이스 테스트 (U5·U4②)
 *
 * BaseOrderItemResource::formatOptionInfo() 공통 베이스 1곳에 정가 계열
 * (list_price/list_price_formatted/multi_currency_list_price/discount_rate)을 추가해
 * CartItemResource·CheckoutItemResource 양쪽 응답에 정가가 실리는지 검증한다.
 */
class BaseOrderItemResourceListPriceTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HasMultiCurrencyPrices::clearCurrencySettingsCache();
    }

    /**
     * formatOptionInfo() 를 직접 노출하는 테스트용 구체 리소스를 생성합니다.
     */
    private function resource(): object
    {
        return new class(null) extends BaseOrderItemResource
        {
            public function toArray(Request $request): array
            {
                return [];
            }

            public function exposeOptionInfo(ProductOption $option): array
            {
                return $this->formatOptionInfo($option);
            }
        };
    }

    /**
     * Product 관계가 부착된 ProductOption 을 DB 없이 구성합니다.
     */
    private function makeOption(int $listPrice, int $sellingPrice, int $adjustment = 0): ProductOption
    {
        $product = Product::factory()->make([
            'list_price' => $listPrice,
            'selling_price' => $sellingPrice,
        ]);

        $option = ProductOption::factory()->make([
            'price_adjustment' => $adjustment,
        ]);
        $option->setRelation('product', $product);

        return $option;
    }

    public function test_format_option_info_includes_list_price_fields(): void
    {
        $option = $this->makeOption(listPrice: 10000, sellingPrice: 8000);
        $info = $this->resource()->exposeOptionInfo($option);

        $this->assertArrayHasKey('list_price', $info);
        $this->assertArrayHasKey('list_price_formatted', $info);
        $this->assertArrayHasKey('multi_currency_list_price', $info);
        // 가격 컬럼 decimal(15,2) 전환으로 float 반환 — 값 동등 비교
        $this->assertEquals(10000, $info['list_price']);
    }

    public function test_format_option_info_includes_discount_rate(): void
    {
        $option = $this->makeOption(listPrice: 10000, sellingPrice: 8000);
        $info = $this->resource()->exposeOptionInfo($option);

        $this->assertArrayHasKey('discount_rate', $info);
        // (1 - 8000/10000) * 100 = 20.0
        $this->assertEquals(20.0, $info['discount_rate']);
    }

    public function test_discount_rate_zero_when_list_equals_selling(): void
    {
        $option = $this->makeOption(listPrice: 10000, sellingPrice: 10000);
        $info = $this->resource()->exposeOptionInfo($option);

        $this->assertEquals(0, $info['discount_rate']);
    }

    public function test_discount_rate_zero_when_list_nonpositive(): void
    {
        // list_price <= 0 (0나눗셈 가드)
        $option = $this->makeOption(listPrice: 0, sellingPrice: 0);
        $info = $this->resource()->exposeOptionInfo($option);

        $this->assertEquals(0, $info['discount_rate']);
    }

    public function test_price_adjustment_reflected_in_both_prices(): void
    {
        // 옵션 조정액 +2000 → 정가 12000, 판매가 10000
        $option = $this->makeOption(listPrice: 10000, sellingPrice: 8000, adjustment: 2000);
        $info = $this->resource()->exposeOptionInfo($option);

        $this->assertEquals(12000, $info['list_price']);
        $this->assertEquals(10000, $info['selling_price']);
        // (1 - 10000/12000) * 100 = 16.7
        $this->assertEquals(16.7, $info['discount_rate']);
    }

    public function test_selling_price_fields_still_present(): void
    {
        // 기존 판매가 필드 비회귀
        $option = $this->makeOption(listPrice: 10000, sellingPrice: 8000);
        $info = $this->resource()->exposeOptionInfo($option);

        $this->assertArrayHasKey('selling_price', $info);
        $this->assertArrayHasKey('selling_price_formatted', $info);
        $this->assertArrayHasKey('multi_currency_selling_price', $info);
        $this->assertEquals(8000, $info['selling_price']);
    }
}
