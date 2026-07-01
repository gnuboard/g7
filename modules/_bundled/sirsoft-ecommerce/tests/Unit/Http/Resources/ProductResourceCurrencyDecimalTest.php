<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductListResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 응답 raw 가격의 통화 자릿수 정규화 통합 테스트
 *
 * ProductListResource 의 raw 가격 필드(list_price/selling_price)가 기본 통화의
 * decimal_places 를 따라 응답되는지 검증한다. JPY(0자리)는 정수, USD(2자리)는 소수.
 * decimal(15,2) cast 가 항상 "200.00" 으로 내려보내던 회귀를 차단한다.
 */
class ProductResourceCurrencyDecimalTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HasMultiCurrencyPrices::clearCurrencySettingsCache();
    }

    protected function tearDown(): void
    {
        HasMultiCurrencyPrices::clearCurrencySettingsCache();
        parent::tearDown();
    }

    /**
     * 기본통화를 설정합니다.
     *
     * @param  string  $code  기본통화 코드
     */
    private function setBaseCurrency(string $code): void
    {
        $languageCurrency = [
            'default_currency' => $code,
            'currencies' => [
                ['code' => 'JPY', 'name' => ['ja' => '円'], 'is_default' => $code === 'JPY', 'decimal_places' => 0, 'base_unit' => 100, 'exchange_rate' => $code === 'JPY' ? null : 100],
                ['code' => 'USD', 'name' => ['en' => 'Dollar'], 'is_default' => $code === 'USD', 'decimal_places' => 2, 'base_unit' => 1, 'exchange_rate' => $code === 'USD' ? null : 0.85],
            ],
        ];

        app(EcommerceSettingsService::class)->setSetting('language_currency', $languageCurrency);
        config(['g7_settings.modules.sirsoft-ecommerce.language_currency' => $languageCurrency]);

        // trait 의 통화 설정 캐시는 use 한 클래스별로 보존되므로, 검증 대상 리소스 클래스의
        // 캐시를 명시적으로 초기화한다 (정적 trait 호출만으로는 리소스 클래스 static 미반영).
        ProductListResource::clearCurrencySettingsCache();
    }

    /**
     * 가격을 가진 상품을 DB 없이 구성하고 ProductListResource 배열로 변환합니다.
     *
     * @param  float  $listPrice  정가 (decimal:2 cast 시뮬레이션)
     * @param  float  $sellingPrice  판매가
     * @return array 리소스 배열
     */
    private function toArray(float $listPrice, float $sellingPrice): array
    {
        $product = Product::factory()->make([
            'list_price' => $listPrice,
            'selling_price' => $sellingPrice,
        ]);

        $resource = new ProductListResource($product);

        return $resource->toArray(Request::create('/'));
    }

    public function test_jpy_base_returns_integer_raw_prices(): void
    {
        $this->setBaseCurrency('JPY');
        $arr = $this->toArray(200.00, 200.00);

        // decimal(15,2) 라도 JPY 0자리 → 정수로 응답 ("200.00" 회귀 차단)
        $this->assertSame(200, $arr['list_price']);
        $this->assertSame(200, $arr['selling_price']);
        $this->assertIsInt($arr['list_price']);
        $this->assertIsInt($arr['selling_price']);
    }

    public function test_usd_base_returns_decimal_raw_prices(): void
    {
        $this->setBaseCurrency('USD');
        $arr = $this->toArray(1.70, 1.50);

        // USD 2자리 → 소수 보존
        $this->assertSame(1.7, $arr['list_price']);
        $this->assertSame(1.5, $arr['selling_price']);
    }

    public function test_formatted_field_still_present_and_currency_aware(): void
    {
        $this->setBaseCurrency('JPY');
        $arr = $this->toArray(200.00, 200.00);

        // 표시용 formatted 는 자릿수 없이 그대로 (비회귀)
        $this->assertArrayHasKey('selling_price_formatted', $arr);
        $this->assertStringNotContainsString('.00', (string) $arr['selling_price_formatted']);
    }
}
