<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ProductController 복사 관련 엔드포인트 테스트
 *
 * showForCopy 엔드포인트의 copy_options 필터링을 테스트합니다.
 */
class ProductControllerCopyTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 상품 시퀀스 레코드 생성 (generateUniqueCode에 필요)
        $defaultConfig = SequenceType::PRODUCT->getDefaultConfig();
        Sequence::firstOrCreate(
            ['type' => SequenceType::PRODUCT->value],
            [
                'algorithm' => $defaultConfig['algorithm']->value,
                'prefix' => $defaultConfig['prefix'],
                'current_value' => 0,
                'increment' => 1,
                'min_value' => 1,
                'max_value' => $defaultConfig['max_value'],
                'cycle' => false,
                'pad_length' => $defaultConfig['pad_length'],
                'max_history_count' => $defaultConfig['max_history_count'],
            ]
        );
    }

    /**
     * 기본 복사 데이터 조회 성공
     */
    public function test_show_for_copy_returns_product_data(): void
    {
        // Given: 권한이 있는 관리자와 상품
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create([
            'description' => 'Test description',
            'meta_title' => 'SEO Title',
            'meta_description' => 'SEO Description',
        ]);

        // When: 복사용 데이터 조회
        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/copy");

        // Then: 성공하고 새 product_code가 생성됨
        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertNotEquals(
            $product->product_code,
            $response->json('data.product_code')
        );
    }

    /**
     * SEO 옵션 비활성화 시 SEO 필드가 null로 반환
     */
    public function test_show_for_copy_filters_seo_when_disabled(): void
    {
        // Given
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create([
            'meta_title' => 'SEO Title',
            'meta_description' => 'SEO Description',
        ]);

        // When: copy_seo=0으로 요청
        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/copy?copy_seo=0");

        // Then: SEO 필드가 null
        $response->assertOk();
        $this->assertNull($response->json('data.meta_title'));
        $this->assertNull($response->json('data.meta_description'));
    }

    /**
     * SEO 옵션 활성화 시 SEO 필드 포함
     */
    public function test_show_for_copy_includes_seo_when_enabled(): void
    {
        // Given
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create([
            'meta_title' => 'SEO Title',
            'meta_description' => 'SEO Description',
        ]);

        // When: copy_seo=1로 요청
        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/copy?copy_seo=1");

        // Then: SEO 필드 포함
        $response->assertOk();
        $this->assertEquals('SEO Title', $response->json('data.meta_title'));
        $this->assertEquals('SEO Description', $response->json('data.meta_description'));
    }

    /**
     * 이미지 옵션 비활성화 시 이미지 배열이 비어있음
     */
    public function test_show_for_copy_filters_images_when_disabled(): void
    {
        // Given
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create();

        // When: copy_images=0으로 요청
        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/copy?copy_images=0");

        // Then: 이미지 배열이 비어있음
        $response->assertOk();
        $this->assertEmpty($response->json('data.images'));
    }

    /**
     * 카테고리 옵션 비활성화 시 카테고리 비어있음
     */
    public function test_show_for_copy_filters_categories_when_disabled(): void
    {
        // Given
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create();

        // When: copy_categories=0으로 요청
        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/copy?copy_categories=0");

        // Then: 카테고리 배열이 비어있음
        $response->assertOk();
        $this->assertEmpty($response->json('data.category_ids'));
        $this->assertNull($response->json('data.primary_category_id'));
    }

    /**
     * 설명 옵션 비활성화 시 description이 null
     */
    public function test_show_for_copy_filters_description_when_disabled(): void
    {
        // Given
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create([
            'description' => '<p>Detailed product description</p>',
        ]);

        // When: copy_description=0으로 요청
        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/copy?copy_description=0");

        // Then: description이 null
        $response->assertOk();
        $this->assertNull($response->json('data.description'));
    }

    /**
     * 배송 정책 옵션 비활성화 시 shipping_policy_id가 null
     */
    public function test_show_for_copy_filters_shipping_when_disabled(): void
    {
        // Given
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create();

        // When: copy_shipping=0으로 요청
        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/copy?copy_shipping=0");

        // Then: shipping_policy_id가 null
        $response->assertOk();
        $this->assertNull($response->json('data.shipping_policy_id'));
    }

    /**
     * 식별 코드 옵션 비활성화 시 SKU/바코드가 null
     */
    public function test_show_for_copy_filters_identification_when_disabled(): void
    {
        // Given
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create([
            'sku' => 'TEST-SKU-001',
            'barcode' => '1234567890',
        ]);

        // When: copy_identification=0으로 요청
        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/copy?copy_identification=0");

        // Then: 식별 코드가 null
        $response->assertOk();
        $this->assertNull($response->json('data.sku'));
        $this->assertNull($response->json('data.barcode'));
    }

    /**
     * 공통 정보 옵션 비활성화 시 common_info_id가 null
     */
    public function test_show_for_copy_filters_common_info_when_disabled(): void
    {
        // Given
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create();

        // When: copy_common_info=0으로 요청
        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/copy?copy_common_info=0");

        // Then: common_info_id가 null
        $response->assertOk();
        $this->assertNull($response->json('data.common_info_id'));
    }

    /**
     * 판매 정보 옵션 비활성화 시 가격/재고가 기본값
     */
    public function test_show_for_copy_filters_sales_info_when_disabled(): void
    {
        // Given
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create([
            'list_price' => 50000,
            'selling_price' => 45000,
            'stock_quantity' => 100,
        ]);

        // When: copy_sales_info=0으로 요청
        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/copy?copy_sales_info=0");

        // Then: 가격/재고가 기본값
        $response->assertOk();
        $this->assertEquals(0, $response->json('data.list_price'));
        $this->assertEquals(0, $response->json('data.selling_price'));
        $this->assertEquals(0, $response->json('data.stock_quantity'));
    }

    /**
     * 존재하지 않는 상품의 복사 요청 시 404
     */
    public function test_show_for_copy_returns_404_for_nonexistent_product(): void
    {
        // Given
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);

        // When: 존재하지 않는 상품 복사 요청
        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products/NONEXISTENT999/copy');

        // Then: 404
        $response->assertNotFound();
    }

    /**
     * 권한 없는 사용자의 복사 요청 시 403
     */
    public function test_show_for_copy_returns_403_without_permission(): void
    {
        // Given: 권한 없는 관리자
        $user = $this->createAdminUser([]);
        $product = Product::factory()->create();

        // When: 복사 요청
        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/copy");

        // Then: 403
        $response->assertForbidden();
    }

    /**
     * 기본 옵션으로 복사 시 created_at/updated_at이 포함되지 않음
     */
    public function test_show_for_copy_excludes_date_fields(): void
    {
        // Given
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create();

        // When: 기본 복사 요청
        $response = $this->actingAs($user)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/copy");

        // Then: 날짜 필드 미포함
        $response->assertOk();
        $this->assertArrayNotHasKey('created_at', $response->json('data'));
        $this->assertArrayNotHasKey('updated_at', $response->json('data'));
    }
}
