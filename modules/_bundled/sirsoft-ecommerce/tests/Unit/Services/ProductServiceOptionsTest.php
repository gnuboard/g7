<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Exceptions\OptionHasOrderHistoryException;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOption;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ProductService 옵션 관련 메서드 테스트
 *
 * syncOptions(), syncAdditionalOptions() 메서드의 옵션 삭제 검증을 테스트합니다.
 */
class ProductServiceOptionsTest extends ModuleTestCase
{
    protected ProductService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductService::class);
    }

    // ========================================
    // syncOptions() - 옵션 삭제 검증 테스트
    // ========================================

    /**
     * 주문 이력이 없는 옵션은 삭제 가능
     */
    public function test_sync_options_can_delete_options_without_orders(): void
    {
        // Given: 주문 이력이 없는 옵션이 있는 상품
        $product = Product::factory()->create(['has_options' => true]);
        $option1 = ProductOption::factory()->create(['product_id' => $product->id]);
        $option2 = ProductOption::factory()->create(['product_id' => $product->id]);

        // When: 하나의 옵션만 남기고 동기화
        $this->service->update($product, [
            'options' => [
                ['id' => $option1->id, 'option_name' => 'Updated Option'],
            ],
        ]);

        // Then: 삭제 성공
        $this->assertDatabaseHas('ecommerce_product_options', ['id' => $option1->id]);
        $this->assertDatabaseMissing('ecommerce_product_options', ['id' => $option2->id]);
    }

    /**
     * 주문 이력이 있는 옵션은 삭제 불가
     */
    public function test_sync_options_cannot_delete_options_with_orders(): void
    {
        // Given: 주문 이력이 있는 옵션이 있는 상품
        $product = Product::factory()->create(['has_options' => true]);
        $option1 = ProductOption::factory()->create(['product_id' => $product->id]);
        $option2 = ProductOption::factory()->create(['product_id' => $product->id]);

        // option2에 주문 이력 생성
        OrderOption::factory()->create([
            'product_id' => $product->id,
            'product_option_id' => $option2->id,
        ]);

        // When & Then: 예외 발생
        $this->expectException(OptionHasOrderHistoryException::class);

        $this->service->update($product, [
            'options' => [
                ['id' => $option1->id, 'option_name' => 'Updated Option'],
            ],
        ]);
    }

    /**
     * 주문 이력이 있는 옵션을 유지하면 정상 동작
     */
    public function test_sync_options_succeeds_when_keeping_options_with_orders(): void
    {
        // Given: 주문 이력이 있는 옵션이 있는 상품
        $product = Product::factory()->create(['has_options' => true]);
        $option1 = ProductOption::factory()->create(['product_id' => $product->id]);
        $option2 = ProductOption::factory()->create(['product_id' => $product->id]);

        // option2에 주문 이력 생성
        OrderOption::factory()->create([
            'product_id' => $product->id,
            'product_option_id' => $option2->id,
        ]);

        // When: 주문 이력 있는 옵션을 유지하고 동기화
        $this->service->update($product, [
            'options' => [
                ['id' => $option1->id, 'option_name' => 'Updated Option 1'],
                ['id' => $option2->id, 'option_name' => 'Updated Option 2'],
            ],
        ]);

        // Then: 모두 유지됨
        $this->assertDatabaseHas('ecommerce_product_options', ['id' => $option1->id]);
        $this->assertDatabaseHas('ecommerce_product_options', ['id' => $option2->id]);
    }

    // ========================================
    // syncAdditionalOptions() - 부분 업데이트 테스트
    // ========================================

    /**
     * 추가옵션 부분 업데이트 - 기존 옵션 수정
     */
    public function test_sync_additional_options_updates_existing_options(): void
    {
        // Given: 추가옵션이 있는 상품
        $product = Product::factory()->create();
        $additionalOption = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '기존 옵션', 'en' => 'Existing Option'],
            'is_required' => false,
            'sort_order' => 0,
        ]);

        // When: 기존 옵션 수정
        $this->service->update($product, [
            'additional_options' => [
                [
                    'id' => $additionalOption->id,
                    'name' => ['ko' => '수정된 옵션', 'en' => 'Updated Option'],
                    'is_required' => true,
                ],
            ],
        ]);

        // Then: 수정됨
        $this->assertDatabaseHas('ecommerce_product_additional_options', [
            'id' => $additionalOption->id,
            'is_required' => true,
        ]);
    }

    /**
     * 추가옵션 부분 업데이트 - 새 옵션 추가
     */
    public function test_sync_additional_options_creates_new_options(): void
    {
        // Given: 추가옵션이 있는 상품
        $product = Product::factory()->create();
        $existingOption = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '기존 옵션', 'en' => 'Existing Option'],
            'is_required' => false,
            'sort_order' => 0,
        ]);

        // When: 기존 옵션 유지하면서 새 옵션 추가
        $this->service->update($product, [
            'additional_options' => [
                [
                    'id' => $existingOption->id,
                    'name' => ['ko' => '기존 옵션', 'en' => 'Existing Option'],
                    'is_required' => false,
                ],
                [
                    'name' => ['ko' => '새 옵션', 'en' => 'New Option'],
                    'is_required' => true,
                ],
            ],
        ]);

        // Then: 기존 옵션 유지, 새 옵션 추가됨
        $this->assertDatabaseHas('ecommerce_product_additional_options', ['id' => $existingOption->id]);
        $this->assertEquals(2, ProductAdditionalOption::where('product_id', $product->id)->count());
    }

    /**
     * 추가옵션 부분 업데이트 - 일부 옵션 삭제
     */
    public function test_sync_additional_options_deletes_removed_options(): void
    {
        // Given: 추가옵션이 2개 있는 상품
        $product = Product::factory()->create();
        $option1 = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '옵션1', 'en' => 'Option 1'],
            'is_required' => false,
            'sort_order' => 0,
        ]);
        $option2 = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '옵션2', 'en' => 'Option 2'],
            'is_required' => false,
            'sort_order' => 1,
        ]);

        // When: 첫 번째 옵션만 유지
        $this->service->update($product, [
            'additional_options' => [
                [
                    'id' => $option1->id,
                    'name' => ['ko' => '옵션1', 'en' => 'Option 1'],
                    'is_required' => false,
                ],
            ],
        ]);

        // Then: option2 삭제됨
        $this->assertDatabaseHas('ecommerce_product_additional_options', ['id' => $option1->id]);
        $this->assertDatabaseMissing('ecommerce_product_additional_options', ['id' => $option2->id]);
    }

    /**
     * 추가옵션 전체 삭제
     */
    public function test_sync_additional_options_can_delete_all(): void
    {
        // Given: 추가옵션이 있는 상품
        $product = Product::factory()->create();
        ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '옵션1', 'en' => 'Option 1'],
            'is_required' => false,
            'sort_order' => 0,
        ]);
        ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '옵션2', 'en' => 'Option 2'],
            'is_required' => false,
            'sort_order' => 1,
        ]);

        // When: 빈 배열로 동기화
        $this->service->update($product, [
            'additional_options' => [],
        ]);

        // Then: 모든 추가옵션 삭제됨
        $this->assertEquals(0, ProductAdditionalOption::where('product_id', $product->id)->count());
    }

    // ========================================
    // syncProductSellingPriceFromDefaultOption() - A22 백엔드 안전망
    // ========================================

    /**
     * 옵션 동기화 후 상품 판매가가 기본 옵션 판매가로 보정된다 (프론트 우회 안전망)
     */
    public function test_sync_options_syncs_product_selling_price_from_default_option(): void
    {
        // Given: 옵션 보유 상품. 상품 판매가(39000)와 기본 옵션 판매가(35000)가 어긋난 상태
        $product = Product::factory()->create([
            'has_options' => true,
            'selling_price' => 39000,
        ]);
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'is_default' => true,
            'selling_price' => 35000,
        ]);

        // When: 어긋난 상품 판매가를 그대로 보내며 옵션 동기화
        $this->service->update($product, [
            'selling_price' => 39000,
            'options' => [
                ['id' => $option->id, 'is_default' => true, 'selling_price' => 35000],
            ],
        ]);

        // Then: 상품 판매가가 기본 옵션 판매가로 보정됨
        $this->assertEquals(35000, $product->fresh()->selling_price);
    }

    /**
     * 기본 옵션이 없으면 상품 판매가를 건드리지 않는다
     */
    public function test_sync_options_keeps_product_price_when_no_default_option(): void
    {
        // Given: 기본 옵션이 없는 옵션 보유 상품
        $product = Product::factory()->create([
            'has_options' => true,
            'selling_price' => 39000,
        ]);
        $option = ProductOption::factory()->create([
            'product_id' => $product->id,
            'is_default' => false,
            'selling_price' => 35000,
        ]);

        // When: 옵션 동기화
        $this->service->update($product, [
            'selling_price' => 39000,
            'options' => [
                ['id' => $option->id, 'is_default' => false, 'selling_price' => 35000],
            ],
        ]);

        // Then: 상품 판매가 불변
        $this->assertEquals(39000, $product->fresh()->selling_price);
    }

    /**
     * 옵션 미보유 상품은 판매가 보정 대상이 아니다
     */
    public function test_sync_does_not_apply_to_product_without_options(): void
    {
        // Given: 옵션 미보유 상품
        $product = Product::factory()->create([
            'has_options' => false,
            'selling_price' => 39000,
        ]);

        // When: 옵션 없이 업데이트
        $this->service->update($product, [
            'selling_price' => 39000,
            'options' => [],
        ]);

        // Then: 상품 판매가 불변
        $this->assertEquals(39000, $product->fresh()->selling_price);
    }
}
