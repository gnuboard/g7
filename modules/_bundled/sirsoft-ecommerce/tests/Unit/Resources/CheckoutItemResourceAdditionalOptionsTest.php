<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Http\Resources\CheckoutItemResource;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOption;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOptionValue;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * CheckoutItemResource 추가옵션 노출 + per-item 소계 합산 회귀 테스트
 *
 * 회귀: 체크아웃 per-item 표시에서 additional_options 가 누락되고(별행 미렌더),
 * subtotal 이 추가옵션을 미합산해 과소 표시되던 결함.
 * (결제 총액은 calculation 경유라 정상이었으나, per-item 표시·소계가 어긋남)
 * CartItemResource/OrderOptionResource 와 동일 표시 계약을 공유해야 한다.
 */
class CheckoutItemResourceAdditionalOptionsTest extends ModuleTestCase
{
    /**
     * 추가옵션 선택지를 가진 상품 + 옵션 + items 픽스처를 구성합니다.
     *
     * @return array{items: array, optionId: int, valueEngraveId: int, valueWrapId: int}
     */
    private function makeFixture(): array
    {
        $product = ProductFactory::new()->create([
            'selling_price' => 10000,
            'list_price' => 12000,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 50,
            'is_default' => true,
            'price_adjustment' => 0,
        ]);

        $engrave = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '각인 문구', 'en' => 'Engraving'],
            'is_required' => true,
            'sort_order' => 0,
        ]);
        $engraveValue = ProductAdditionalOptionValue::create([
            'additional_option_id' => $engrave->id,
            'name' => ['ko' => '각인 추가', 'en' => 'Engrave'],
            'price_adjustment' => 5000,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $wrap = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '선물포장', 'en' => 'Gift Wrap'],
            'is_required' => false,
            'sort_order' => 1,
        ]);
        $wrapValue = ProductAdditionalOptionValue::create([
            'additional_option_id' => $wrap->id,
            'name' => ['ko' => '기본포장', 'en' => 'Basic'],
            'price_adjustment' => 3000,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $items = [
            [
                'cart_id' => 1,
                'product_id' => $product->id,
                'product_option_id' => $option->id,
                'quantity' => 5,
                'additional_option_selections' => [
                    ['additional_option_id' => $engrave->id, 'value_id' => $engraveValue->id],
                    ['additional_option_id' => $wrap->id, 'value_id' => $wrapValue->id],
                ],
            ],
        ];

        return [
            'items' => $items,
            'optionId' => $option->id,
            'valueEngraveId' => $engraveValue->id,
            'valueWrapId' => $wrapValue->id,
        ];
    }

    /**
     * 체크아웃 아이템이 선택된 추가옵션을 그룹명·선택지명·추가금으로 노출한다.
     */
    public function test_exposes_selected_additional_options(): void
    {
        $f = $this->makeFixture();

        $result = CheckoutItemResource::collectionFromArray($f['items']);
        $item = $result[0];

        $this->assertArrayHasKey('additional_options', $item);
        $this->assertCount(2, $item['additional_options']);

        $byValueId = collect($item['additional_options'])->keyBy('value_id');
        $engrave = $byValueId[$f['valueEngraveId']];
        $wrap = $byValueId[$f['valueWrapId']];

        $this->assertSame('각인 문구', $engrave['group_name']);
        $this->assertSame('각인 추가', $engrave['name']);
        $this->assertSame(5000, $engrave['price_adjustment']);
        $this->assertSame('선물포장', $wrap['group_name']);
        $this->assertSame(3000, $wrap['price_adjustment']);

        $this->assertSame(8000, $item['additional_options_total']);
    }

    /**
     * per-item 소계가 (원옵션가 + 추가옵션 합계) × 수량 으로 계산된다 (안B/D6).
     */
    public function test_subtotal_includes_additional_options(): void
    {
        $f = $this->makeFixture();

        $result = CheckoutItemResource::collectionFromArray($f['items']);
        $item = $result[0];

        // (10000 + 5000 + 3000) × 5 = 90000
        $this->assertSame(90000, $item['subtotal']);
    }

    /**
     * 직접입력(custom_text)이 허용 선택지에 한해 노출된다 (E3).
     */
    public function test_exposes_custom_text_for_allowed_value(): void
    {
        $product = ProductFactory::new()->create(['selling_price' => 10000, 'list_price' => 10000]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 50,
            'is_default' => true,
            'price_adjustment' => 0,
        ]);

        $engrave = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '각인 문구', 'en' => 'Engraving'],
            'is_required' => true,
            'sort_order' => 0,
        ]);
        $engraveValue = ProductAdditionalOptionValue::create([
            'additional_option_id' => $engrave->id,
            'name' => ['ko' => '각인 추가', 'en' => 'Engrave'],
            'price_adjustment' => 5000,
            'is_default' => false,
            'is_active' => true,
            'allow_custom_text' => true,
            'sort_order' => 0,
        ]);

        $items = [[
            'cart_id' => 1,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
            'additional_option_selections' => [
                ['additional_option_id' => $engrave->id, 'value_id' => $engraveValue->id, 'custom_text' => '홍길동'],
            ],
        ]];

        $result = CheckoutItemResource::collectionFromArray($items);
        $item = $result[0];

        $this->assertSame('홍길동', $item['additional_options'][0]['custom_text']);
    }
}
