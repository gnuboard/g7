<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOption;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use Modules\Sirsoft\Ecommerce\Models\ProductLabelAssignment;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ProductService 삭제 관련 메서드 테스트
 *
 * checkCanDelete()와 delete() 메서드를 테스트합니다.
 */
class ProductServiceDeleteTest extends ModuleTestCase
{
    protected ProductService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductService::class);
        Storage::fake('public');
    }

    // ========================================
    // 헬퍼 메서드
    // ========================================

    /**
     * 테스트용 ProductImage를 생성합니다.
     */
    protected function createProductImage(Product $product, array $overrides = []): ProductImage
    {
        return ProductImage::create(array_merge([
            'product_id' => $product->id,
            'hash' => Str::random(12),
            'original_filename' => 'test.jpg',
            'stored_filename' => Str::uuid().'.jpg',
            'disk' => 'public',
            'path' => 'products/test.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
        ], $overrides));
    }

    /**
     * 테스트용 ProductAdditionalOption을 생성합니다.
     */
    protected function createProductAdditionalOption(Product $product, array $overrides = []): ProductAdditionalOption
    {
        return ProductAdditionalOption::create(array_merge([
            'product_id' => $product->id,
            'name' => ['ko' => '추가옵션', 'en' => 'Additional Option'],
            'is_required' => false,
            'sort_order' => 0,
        ], $overrides));
    }

    /**
     * 테스트용 ProductLabel을 생성합니다.
     */
    protected function createProductLabel(array $overrides = []): ProductLabel
    {
        return ProductLabel::create(array_merge([
            'name' => '테스트 라벨',
            'color' => '#FF0000',
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides));
    }

    /**
     * 테스트용 ProductLabelAssignment를 생성합니다.
     */
    protected function createProductLabelAssignment(Product $product, ProductLabel $label): ProductLabelAssignment
    {
        return ProductLabelAssignment::create([
            'product_id' => $product->id,
            'label_id' => $label->id,
        ]);
    }

    /**
     * 테스트용 Category를 생성합니다.
     */
    protected function createCategory(array $overrides = []): Category
    {
        return Category::create(array_merge([
            'name' => ['ko' => '테스트 카테고리', 'en' => 'Test Category'],
            'path' => '1',
            'depth' => 0,
            'sort_order' => 0,
            'is_active' => true,
        ], $overrides));
    }

    // ========================================
    // checkCanDelete() 테스트
    // ========================================

    /**
     * 주문 이력이 없는 상품은 삭제 가능
     */
    public function test_check_can_delete_returns_true_when_no_orders(): void
    {
        // Given: 주문 이력이 없는 상품
        $product = Product::factory()->create();

        // When: 삭제 가능 여부 확인
        $result = $this->service->checkCanDelete($product);

        // Then: 삭제 가능
        $this->assertTrue($result['canDelete']);
        $this->assertNull($result['reason']);
        $this->assertEquals(0, $result['relatedData']['orders']);
    }

    /**
     * 주문 이력이 있는 상품은 삭제 불가
     */
    public function test_check_can_delete_returns_false_when_has_orders(): void
    {
        // Given: 주문 이력이 있는 상품
        $product = Product::factory()->create();
        OrderOption::factory()->create(['product_id' => $product->id]);

        // When: 삭제 가능 여부 확인
        $result = $this->service->checkCanDelete($product);

        // Then: 삭제 불가
        $this->assertFalse($result['canDelete']);
        $this->assertNotNull($result['reason']);
        $this->assertEquals(1, $result['relatedData']['orders']);
    }

    /**
     * 다중 주문 이력이 있는 경우 카운트 정확히 반환
     */
    public function test_check_can_delete_returns_correct_order_count(): void
    {
        // Given: 여러 주문 이력이 있는 상품
        $product = Product::factory()->create();
        OrderOption::factory()->count(5)->create(['product_id' => $product->id]);

        // When: 삭제 가능 여부 확인
        $result = $this->service->checkCanDelete($product);

        // Then: 주문 수 정확
        $this->assertFalse($result['canDelete']);
        $this->assertEquals(5, $result['relatedData']['orders']);
    }

    /**
     * 연관 데이터 카운트가 정확히 반환됨
     */
    public function test_check_can_delete_returns_accurate_related_data_counts(): void
    {
        // Given: 다양한 연관 데이터가 있는 상품
        $product = Product::factory()->create();

        // ProductImage 3개
        for ($i = 0; $i < 3; $i++) {
            $this->createProductImage($product);
        }

        // ProductOption 2개
        ProductOption::factory()->count(2)->create(['product_id' => $product->id]);

        // ProductAdditionalOption 1개
        $this->createProductAdditionalOption($product);

        // ProductLabelAssignment 2개
        $label1 = $this->createProductLabel(['name' => '라벨1']);
        $label2 = $this->createProductLabel(['name' => '라벨2']);
        $this->createProductLabelAssignment($product, $label1);
        $this->createProductLabelAssignment($product, $label2);

        // When: 삭제 가능 여부 확인
        $result = $this->service->checkCanDelete($product);

        // Then: 연관 데이터 카운트 정확
        $this->assertTrue($result['canDelete']);
        $this->assertEquals(3, $result['relatedData']['images']);
        $this->assertEquals(2, $result['relatedData']['options']);
        $this->assertEquals(1, $result['relatedData']['additionalOptions']);
        $this->assertEquals(2, $result['relatedData']['labelAssignments']);
    }

    // ========================================
    // delete() 테스트
    // ========================================

    /**
     * 삭제 시 모든 연관 데이터가 명시적으로 삭제됨
     */
    public function test_delete_removes_all_related_data_explicitly(): void
    {
        // Given: 연관 데이터가 있는 상품
        $product = Product::factory()->create();
        $productId = $product->id;

        // 이미지 2개
        for ($i = 0; $i < 2; $i++) {
            $this->createProductImage($product);
        }

        // 옵션 3개
        ProductOption::factory()->count(3)->create(['product_id' => $product->id]);

        // 추가옵션 1개
        $this->createProductAdditionalOption($product);

        // 라벨 2개
        $label1 = $this->createProductLabel(['name' => '삭제테스트라벨1']);
        $label2 = $this->createProductLabel(['name' => '삭제테스트라벨2']);
        $this->createProductLabelAssignment($product, $label1);
        $this->createProductLabelAssignment($product, $label2);

        // When: 삭제 실행
        $result = $this->service->delete($product);

        // Then: 삭제 성공 및 연관 데이터 삭제 확인
        $this->assertTrue($result);
        $this->assertDatabaseMissing('ecommerce_products', ['id' => $productId]);
        $this->assertDatabaseMissing('ecommerce_product_images', ['product_id' => $productId]);
        $this->assertDatabaseMissing('ecommerce_product_options', ['product_id' => $productId]);
        $this->assertDatabaseMissing('ecommerce_product_additional_options', ['product_id' => $productId]);
        $this->assertDatabaseMissing('ecommerce_product_label_assignments', ['product_id' => $productId]);
    }

    /**
     * 삭제 시 이미지 파일도 삭제됨
     */
    public function test_delete_removes_image_files_from_storage(): void
    {
        // Given: 이미지 파일이 있는 상품
        $product = Product::factory()->create();

        // 실제 이미지 파일 생성
        Storage::disk('public')->put('products/image1.jpg', 'fake image content');
        Storage::disk('public')->put('products/thumb1.jpg', 'fake thumb content');

        $this->createProductImage($product, [
            'path' => 'products/image1.jpg',
        ]);

        // When: 삭제 실행
        $this->service->delete($product);

        // Then: 이미지 파일 삭제 확인
        Storage::disk('public')->assertMissing('products/image1.jpg');
    }

    /**
     * 카테고리 연결도 해제됨
     */
    public function test_delete_detaches_categories(): void
    {
        // Given: 카테고리가 연결된 상품
        $product = Product::factory()->create();
        $category = $this->createCategory();
        $product->categories()->attach($category->id);

        $productId = $product->id;
        $categoryId = $category->id;

        // When: 삭제 실행
        $this->service->delete($product);

        // Then: 피벗 테이블에서 연결 해제 확인
        $this->assertDatabaseMissing('ecommerce_product_categories', [
            'product_id' => $productId,
            'category_id' => $categoryId,
        ]);
    }

    /**
     * 연관 데이터가 없는 상품도 정상 삭제됨
     */
    public function test_delete_works_for_product_without_related_data(): void
    {
        // Given: 연관 데이터가 없는 상품
        $product = Product::factory()->create();
        $productId = $product->id;

        // When: 삭제 실행
        $result = $this->service->delete($product);

        // Then: 삭제 성공
        $this->assertTrue($result);
        $this->assertDatabaseMissing('ecommerce_products', ['id' => $productId]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
