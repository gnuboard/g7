<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ProductController 삭제 관련 엔드포인트 테스트
 *
 * canDelete와 destroy 엔드포인트를 테스트합니다.
 */
class ProductControllerDeleteTest extends ModuleTestCase
{
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

    // ========================================
    // canDelete 엔드포인트 테스트
    // ========================================

    /**
     * 주문 이력이 없는 상품의 삭제 가능 확인
     */
    public function test_can_delete_returns_true_for_product_without_orders(): void
    {
        // Given: 권한이 있는 관리자와 상품
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.delete']);
        $product = Product::factory()->create();

        // When: canDelete API 호출
        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/can-delete");

        // Then: 삭제 가능
        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data' => [
                'canDelete' => true,
                'reason' => null,
            ],
        ]);
    }

    /**
     * 주문 이력이 있는 상품의 삭제 불가 확인
     */
    public function test_can_delete_returns_false_for_product_with_orders(): void
    {
        // Given: 권한이 있는 관리자와 주문이 있는 상품
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.delete']);
        $product = Product::factory()->create();
        OrderOption::factory()->create(['product_id' => $product->id]);

        // When: canDelete API 호출
        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/can-delete");

        // Then: 삭제 불가
        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data' => [
                'canDelete' => false,
            ],
        ]);
        $response->assertJsonPath('data.reason', fn ($reason) => $reason !== null);
    }

    /**
     * 연관 데이터 카운트가 응답에 포함됨
     */
    public function test_can_delete_includes_related_data_counts(): void
    {
        // Given: 연관 데이터가 있는 상품
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.delete']);
        $product = Product::factory()->create();

        // 이미지 2개 직접 생성
        for ($i = 0; $i < 2; $i++) {
            $this->createProductImage($product);
        }

        // 옵션 3개
        ProductOption::factory()->count(3)->create(['product_id' => $product->id]);

        // When: canDelete API 호출
        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/can-delete");

        // Then: 연관 데이터 카운트 포함
        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data' => [
                'canDelete',
                'reason',
                'relatedData' => [
                    'orders',
                    'images',
                    'options',
                ],
            ],
        ]);
        $response->assertJsonPath('data.relatedData.images', 2);
        $response->assertJsonPath('data.relatedData.options', 3);
    }

    /**
     * 권한 없는 사용자는 canDelete API 접근 불가
     */
    public function test_can_delete_requires_delete_permission(): void
    {
        // Given: 삭제 권한이 없는 관리자와 상품
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.view']);
        $product = Product::factory()->create();

        // When: canDelete API 호출
        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/can-delete");

        // Then: 403 Forbidden
        $response->assertForbidden();
    }

    /**
     * 인증되지 않은 사용자는 canDelete API 접근 불가
     */
    public function test_can_delete_requires_authentication(): void
    {
        // Given: 상품
        $product = Product::factory()->create();

        // When: 인증 없이 canDelete API 호출
        $response = $this->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/can-delete");

        // Then: 401 Unauthorized
        $response->assertUnauthorized();
    }

    /**
     * 존재하지 않는 상품에 대한 canDelete API 호출
     */
    public function test_can_delete_returns_404_for_non_existent_product(): void
    {
        // Given: 권한이 있는 관리자
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.delete']);

        // When: 존재하지 않는 상품 ID로 canDelete API 호출
        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products/99999/can-delete');

        // Then: 404 Not Found
        $response->assertNotFound();
    }

    // ========================================
    // destroy 엔드포인트 테스트
    // ========================================

    /**
     * 주문 이력이 없는 상품 삭제 성공
     */
    public function test_destroy_deletes_product_without_orders(): void
    {
        // Given: 권한이 있는 관리자와 상품
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.delete']);
        $product = Product::factory()->create();
        $productId = $product->id;

        // When: destroy API 호출
        $response = $this->actingAs($user)
            ->deleteJson("/api/modules/sirsoft-ecommerce/admin/products/{$productId}");

        // Then: 삭제 성공
        $response->assertOk();
        $this->assertDatabaseMissing('ecommerce_products', ['id' => $productId]);
    }

    /**
     * 연관 데이터가 함께 삭제됨
     */
    public function test_destroy_removes_related_data(): void
    {
        // Given: 연관 데이터가 있는 상품
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.delete']);
        $product = Product::factory()->create();
        $productId = $product->id;

        // 이미지 2개 직접 생성
        for ($i = 0; $i < 2; $i++) {
            $this->createProductImage($product);
        }

        // 옵션 3개
        ProductOption::factory()->count(3)->create(['product_id' => $product->id]);

        // When: destroy API 호출
        $response = $this->actingAs($user)
            ->deleteJson("/api/modules/sirsoft-ecommerce/admin/products/{$productId}");

        // Then: 상품과 연관 데이터 모두 삭제
        $response->assertOk();
        $this->assertDatabaseMissing('ecommerce_products', ['id' => $productId]);
        $this->assertDatabaseMissing('ecommerce_product_images', ['product_id' => $productId]);
        $this->assertDatabaseMissing('ecommerce_product_options', ['product_id' => $productId]);
    }

    /**
     * 권한 없는 사용자는 삭제 불가
     */
    public function test_destroy_requires_delete_permission(): void
    {
        // Given: 삭제 권한이 없는 관리자와 상품
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.view']);
        $product = Product::factory()->create();

        // When: destroy API 호출
        $response = $this->actingAs($user)
            ->deleteJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}");

        // Then: 403 Forbidden
        $response->assertForbidden();
    }
}
