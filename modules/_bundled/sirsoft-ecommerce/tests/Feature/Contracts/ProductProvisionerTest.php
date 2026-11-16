<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Contracts;

use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data\SimpleProductSpec;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\ProductProvisioner;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 단순 상품 생성 공개 계약 (#916)
 *
 * 결제사 시험 상품(KG이니시스 일본 결제 등)은 상품 서비스 대신 이 계약으로 만든다. 상품 가격은 쇼핑몰 기본 통화로만
 * 저장되므로, 다른 통화 금액을 주면 지금 환율로 그 통화 금액이 정확히 나오는 기본 통화 가격을 저장해야 한다.
 */
class ProductProvisionerTest extends ModuleTestCase
{
    /**
     * 스키마를 다시 만들지 않고 트랜잭션으로 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 기본 통화 KRW(기준 단위 1000), JPY 환율 115 로 통화 설정을 맞춥니다.
     *
     * @return void 반환값 없음
     */
    private function useKrwShopWithJpyRate(): void
    {
        $service = app(EcommerceSettingsService::class);
        $current = $service->getSettings('language_currency');
        $current['default_currency'] = 'KRW';
        foreach ($current['currencies'] as $i => $currency) {
            $current['currencies'][$i]['is_default'] = $currency['code'] === 'KRW';
            if ($currency['code'] === 'KRW') {
                $current['currencies'][$i]['base_unit'] = 1000;
            }
            if ($currency['code'] === 'JPY') {
                $current['currencies'][$i]['exchange_rate'] = 115;
                $current['currencies'][$i]['base_unit'] = 100;
                $current['currencies'][$i]['rounding_unit'] = '1';
                $current['currencies'][$i]['rounding_method'] = 'floor';
            }
        }
        $service->saveSettings(['language_currency' => $current]);
        app()->forgetInstance(CurrencyConversionService::class);
    }

    /**
     * 기본 통화 상점에서 JPY 100 상품을 만들면 기본 통화 가격으로 저장되고, JPY 로 환산하면 정확히 100엔이다.
     * 상품명과 설명의 다국어 값은 그대로 저장되고 기본 옵션 1개가 생긴다.
     *
     * @scenario case=jpy_priced_test_product_in_krw_shop
     *
     * @effects provisioned_product_charges_exactly_the_spec_currency_amount
     *
     * @return void 반환값 없음
     */
    public function test_jpy_price_is_stored_in_default_currency_and_converts_back_exactly(): void
    {
        $this->useKrwShopWithJpyRate();

        $result = app(ProductProvisioner::class)->createSimpleProduct(new SimpleProductSpec(
            name: ['ko' => '[테스트] CBT 일본 결제', 'en' => '[Test] CBT Japan Payment', 'ja' => '[テスト] CBT 日本決済'],
            productCode: 'CBT-TEST-916',
            sellingPrice: 100,
            stockQuantity: 9999,
            sku: 'KGINICIS-CBT-TEST-916',
            listPrice: 100,
            description: ['ko' => '시험 전용 상품', 'en' => 'Test product', 'ja' => 'テスト用商品'],
            defaultOptionName: ['ko' => '기본', 'en' => 'Default', 'ja' => 'デフォルト'],
            currency: 'JPY',
        ));

        $product = Product::query()->with('options')->findOrFail($result->id);
        $this->assertSame('CBT-TEST-916', $result->productCode);
        $this->assertSame('KRW', $product->currency_code);
        $this->assertSame('KRW', $result->storedCurrency);
        $this->assertEquals(870, $product->selling_price);
        $this->assertEquals(870, $product->list_price);
        $this->assertEquals(100, app(CurrencyConversionService::class)->convertToCurrency((int) $product->selling_price, 'JPY')['price']);
        $this->assertSame('[テスト] CBT 日本決済', $product->name['ja']);
        $this->assertSame('テスト用商品', $product->description['ja']);
        $this->assertCount(1, $product->options);
        $this->assertSame(9999, (int) $product->options->first()->stock_quantity);
    }

    /**
     * 통화를 주지 않거나 기본 통화를 주면 금액을 바꾸지 않는다.
     *
     * @scenario case=default_currency_product
     *
     * @effects provisioned_product_keeps_default_currency_amount
     *
     * @return void 반환값 없음
     */
    public function test_default_currency_price_is_kept(): void
    {
        $this->useKrwShopWithJpyRate();

        $result = app(ProductProvisioner::class)->createSimpleProduct(new SimpleProductSpec(
            name: ['ko' => '기본 통화 상품', 'en' => 'Default currency product'],
            productCode: 'PROV-916-KRW',
            sellingPrice: 12000,
            stockQuantity: 10,
        ));

        $this->assertEquals(12000, Product::query()->findOrFail($result->id)->selling_price);
        $this->assertEquals(12000, $result->storedSellingPrice);
    }

    /**
     * 통화 설정에 없는 통화는 거부한다(상품을 만들지 않는다).
     *
     * @scenario case=unknown_currency_rejected
     *
     * @effects provisioned_product_rejects_unconfigured_currency
     *
     * @return void 반환값 없음
     */
    public function test_unknown_currency_is_rejected(): void
    {
        $this->useKrwShopWithJpyRate();

        try {
            app(ProductProvisioner::class)->createSimpleProduct(new SimpleProductSpec(
                name: ['ko' => '없는 통화'],
                productCode: 'PROV-916-ZZZ',
                sellingPrice: 1,
                stockQuantity: 1,
                currency: 'ZZZ',
            ));
            $this->fail('통화 설정에 없는 통화는 거부해야 합니다.');
        } catch (InvalidArgumentException) {
            $this->assertFalse(Product::query()->where('product_code', 'PROV-916-ZZZ')->exists());
        }
    }
}
