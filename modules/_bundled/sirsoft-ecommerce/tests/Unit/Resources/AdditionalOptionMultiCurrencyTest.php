<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Http\Resources\CartItemResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\PublicProductResource;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOption;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOptionValue;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 추가옵션 추가금의 다통화 노출 회귀 테스트
 *
 * 상품가·옵션가는 `multi_currency_*` 맵으로 통화별 환산값을 함께 내보내는데,
 * 추가옵션 추가금만 기본통화 단일 값으로 나갔다. 그래서 기본통화가 아닌 통화로
 * 보는 구매자에게 추가금만 기준통화 표기(예: `+¥3,000`)로 남고, 화면이 그 값을
 * 환산된 상품가에 그대로 더해 통화가 섞인 합계가 표시됐다.
 *
 * 기본통화가 KRW 일 때만 우연히 맞았으므로, 이 테스트는 기본통화를 KRW 가 아닌
 * 통화(JPY)로 두고 검증한다.
 */
class AdditionalOptionMultiCurrencyTest extends ModuleTestCase
{
    /**
     * 기본통화 JPY + KRW 환율을 설정합니다.
     *
     * base_unit 1000 / exchange_rate 950 → 1,000 JPY = 950 KRW.
     */
    private function setJpyBaseWithKrw(): void
    {
        $languageCurrency = [
            'default_currency' => 'JPY',
            'currencies' => [
                [
                    'code' => 'JPY', 'name' => ['ja' => '円'], 'symbol' => '¥',
                    'is_default' => true, 'decimal_places' => 0, 'base_unit' => 1000,
                    'exchange_rate' => null,
                ],
                [
                    'code' => 'KRW', 'name' => ['ko' => '원'], 'symbol' => '₩',
                    'is_default' => false, 'decimal_places' => 0, 'base_unit' => 1,
                    'exchange_rate' => 950, 'rounding_unit' => '1', 'rounding_method' => 'floor',
                ],
            ],
        ];

        app(EcommerceSettingsService::class)->setSetting('language_currency', $languageCurrency);
        config(['g7_settings.modules.sirsoft-ecommerce.language_currency' => $languageCurrency]);
    }

    /**
     * 추가옵션 그룹 1개 + 선택지 1개를 가진 상품을 만듭니다.
     *
     * @param  int  $priceAdjustment  선택지 추가금 (기본통화 기준)
     * @return array{product: Product, value: ProductAdditionalOptionValue}
     */
    private function createProductWithAdditionalOption(int $priceAdjustment): array
    {
        $product = ProductFactory::new()->create([
            'selling_price' => 10000,
            'list_price' => 10000,
        ]);

        $group = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '포장 방식', 'en' => 'Packaging'],
            'is_required' => false,
            'sort_order' => 0,
        ]);

        $value = ProductAdditionalOptionValue::create([
            'additional_option_id' => $group->id,
            'name' => ['ko' => '선물 포장', 'en' => 'Gift Box'],
            'price_adjustment' => $priceAdjustment,
            'is_default' => false,
            'is_active' => true,
            'allow_custom_text' => false,
            'sort_order' => 0,
        ]);

        return ['product' => $product, 'value' => $value];
    }

    /**
     * 상품 상세 응답의 추가옵션 선택지가 통화별 추가금을 함께 내보낸다.
     *
     * 화면의 선택지 라벨과 합계가 표시통화를 따르려면 서버가 환산값을 줘야 한다.
     * 수정 전에는 `price_adjustment`(기본통화) 하나뿐이라 화면이 환산할 근거가 없었다.
     */
    public function test_public_product_resource_exposes_multi_currency_price_adjustment(): void
    {
        $this->setJpyBaseWithKrw();
        $data = $this->createProductWithAdditionalOption(3000);

        $product = Product::with('additionalOptions.values')->find($data['product']->id);
        $array = (new PublicProductResource($product))->toArray(Request::create('/'));

        $value = $array['additional_options'][0]['values'][0];

        $this->assertArrayHasKey(
            'multi_currency_price_adjustment',
            $value,
            '추가옵션 선택지도 상품가·옵션가와 같이 통화별 추가금을 내보내야 한다'
        );

        $map = $value['multi_currency_price_adjustment'];

        // 기본통화 JPY: 원값 그대로
        $this->assertSame(3000, (int) $map['JPY']['price']);
        $this->assertTrue($map['JPY']['is_default']);

        // KRW: 3,000 / base_unit 1000 × 950 = 2,850
        $this->assertSame(2850, (int) $map['KRW']['price']);
        $this->assertFalse($map['KRW']['is_default']);

        // 부호가 붙은 표시 문자열도 통화별로 제공된다
        $this->assertStringStartsWith('+', $map['KRW']['formatted']);
        $this->assertStringContainsString('2,850', $map['KRW']['formatted']);
    }

    /**
     * 장바구니 아이템의 추가옵션도 통화별 추가금을 함께 내보낸다.
     *
     * 장바구니 줄은 상품가·소계를 표시통화로 보여주면서 추가금 라벨만 기준통화로
     * 남아 있었다. 같은 줄 안에서 통화가 섞이면 구매자가 금액을 검산할 수 없다.
     */
    public function test_cart_item_resource_exposes_multi_currency_price_adjustment(): void
    {
        $this->setJpyBaseWithKrw();
        $data = $this->createProductWithAdditionalOption(5000);
        $option = ProductOptionFactory::new()->forProduct($data['product'])->create([
            'stock_quantity' => 10,
            'price_adjustment' => 0,
        ]);

        $cart = Cart::create([
            'cart_key' => 'ck_'.str_repeat('m', 32),
            'user_id' => null,
            'product_id' => $data['product']->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
            'additional_option_selections' => [[
                'additional_option_id' => $data['value']->additional_option_id,
                'value_id' => $data['value']->id,
            ]],
        ]);

        $cart->load(Cart::displayRelations());
        $array = (new CartItemResource($cart))->toArray(Request::create('/'));

        $addl = $array['additional_options'][0];

        $this->assertArrayHasKey(
            'multi_currency_price_adjustment',
            $addl,
            '장바구니 줄의 추가옵션도 통화별 추가금을 내보내야 한다'
        );

        // 5,000 / 1000 × 950 = 4,750
        $this->assertSame(4750, (int) $addl['multi_currency_price_adjustment']['KRW']['price']);
        $this->assertSame(5000, (int) $addl['multi_currency_price_adjustment']['JPY']['price']);
    }

    /**
     * 음수 추가금(할인)도 통화별로 환산되고 부호가 유지된다.
     */
    public function test_negative_price_adjustment_keeps_sign_across_currencies(): void
    {
        $this->setJpyBaseWithKrw();
        $data = $this->createProductWithAdditionalOption(-2000);

        $product = Product::with('additionalOptions.values')->find($data['product']->id);
        $array = (new PublicProductResource($product))->toArray(Request::create('/'));

        $map = $array['additional_options'][0]['values'][0]['multi_currency_price_adjustment'];

        // 부호는 표시 문자열에 있고, price 는 절대값이 아닌 부호 있는 값이다
        $this->assertSame(-2000, (int) $map['JPY']['price']);
        $this->assertSame(-1900, (int) $map['KRW']['price']);
        $this->assertStringStartsWith('-', $map['KRW']['formatted']);
    }
}
