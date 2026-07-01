<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOption;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOptionValue;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ProductService 추가옵션 선택지(values) 동기화 테스트
 *
 * createAdditionalOptions / syncAdditionalOptions 의 선택지 생성·수정·삭제와
 * 추가금 음수 차단(D16) 정규화를 검증합니다.
 */
class ProductServiceAdditionalOptionsTest extends ModuleTestCase
{
    protected ProductService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductService::class);
    }

    public function test_create_persists_group_with_values(): void
    {
        $product = Product::factory()->create();

        $this->service->update($product, [
            'additional_options' => [
                [
                    'name' => ['ko' => '각인', 'en' => 'Engraving'],
                    'is_required' => true,
                    'values' => [
                        ['name' => ['ko' => '없음', 'en' => 'None'], 'price_adjustment' => 0],
                        ['name' => ['ko' => '각인 추가', 'en' => 'Add'], 'price_adjustment' => 5000],
                    ],
                ],
            ],
        ]);

        $group = ProductAdditionalOption::where('product_id', $product->id)->firstOrFail();
        $this->assertEquals(2, $group->values()->count());
        $this->assertDatabaseHas('ecommerce_product_additional_option_values', [
            'additional_option_id' => $group->id,
            'price_adjustment' => 5000,
        ]);
    }

    public function test_allow_custom_text_is_persisted(): void
    {
        // 직접입력 허용 플래그가 저장/복원된다 (E1)
        $product = Product::factory()->create();

        $this->service->update($product, [
            'additional_options' => [
                [
                    'name' => ['ko' => '각인', 'en' => 'Engraving'],
                    'values' => [
                        ['name' => ['ko' => '각인 추가', 'en' => 'Add'], 'price_adjustment' => 5000, 'allow_custom_text' => true],
                        ['name' => ['ko' => '없음', 'en' => 'None'], 'price_adjustment' => 0],
                    ],
                ],
            ],
        ]);

        $group = ProductAdditionalOption::where('product_id', $product->id)->firstOrFail();
        $values = $group->values()->orderBy('sort_order')->get();

        $this->assertTrue($values[0]->allow_custom_text);
        $this->assertFalse($values[1]->allow_custom_text);
    }

    public function test_negative_price_adjustment_is_clamped_to_zero(): void
    {
        // D16: 추가금 음수 금지 → 0 으로 정규화 (FormRequest 이전 방어선)
        $product = Product::factory()->create();

        $this->service->update($product, [
            'additional_options' => [
                [
                    'name' => ['ko' => '각인', 'en' => 'Engraving'],
                    'values' => [
                        ['name' => ['ko' => '할인 시도', 'en' => 'Negative'], 'price_adjustment' => -3000],
                    ],
                ],
            ],
        ]);

        $value = ProductAdditionalOptionValue::firstOrFail();
        $this->assertEquals(0, $value->price_adjustment);
    }

    public function test_sync_updates_and_deletes_values(): void
    {
        $product = Product::factory()->create();
        $group = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '각인', 'en' => 'Engraving'],
            'is_required' => false,
            'sort_order' => 0,
        ]);
        $keep = ProductAdditionalOptionValue::create([
            'additional_option_id' => $group->id,
            'name' => ['ko' => '유지', 'en' => 'Keep'],
            'price_adjustment' => 1000,
            'sort_order' => 0,
        ]);
        $remove = ProductAdditionalOptionValue::create([
            'additional_option_id' => $group->id,
            'name' => ['ko' => '삭제', 'en' => 'Remove'],
            'price_adjustment' => 2000,
            'sort_order' => 1,
        ]);

        // When: keep 는 가격 수정, remove 는 누락 → 삭제, 신규 1개 추가
        $this->service->update($product, [
            'additional_options' => [
                [
                    'id' => $group->id,
                    'name' => ['ko' => '각인', 'en' => 'Engraving'],
                    'values' => [
                        ['id' => $keep->id, 'name' => ['ko' => '유지', 'en' => 'Keep'], 'price_adjustment' => 1500],
                        ['name' => ['ko' => '신규', 'en' => 'New'], 'price_adjustment' => 3000],
                    ],
                ],
            ],
        ]);

        $this->assertDatabaseHas('ecommerce_product_additional_option_values', [
            'id' => $keep->id,
            'price_adjustment' => 1500,
        ]);
        $this->assertDatabaseMissing('ecommerce_product_additional_option_values', ['id' => $remove->id]);
        $this->assertEquals(2, $group->values()->count());
    }

    public function test_deleting_group_cascades_values(): void
    {
        $product = Product::factory()->create();
        $group = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '각인', 'en' => 'Engraving'],
            'is_required' => false,
            'sort_order' => 0,
        ]);
        ProductAdditionalOptionValue::create([
            'additional_option_id' => $group->id,
            'name' => ['ko' => '추가', 'en' => 'Add'],
            'price_adjustment' => 5000,
            'sort_order' => 0,
        ]);

        // When: 그룹을 모두 제거
        $this->service->update($product, ['additional_options' => []]);

        $this->assertEquals(0, ProductAdditionalOption::where('product_id', $product->id)->count());
        $this->assertEquals(0, ProductAdditionalOptionValue::where('additional_option_id', $group->id)->count());
    }
}
