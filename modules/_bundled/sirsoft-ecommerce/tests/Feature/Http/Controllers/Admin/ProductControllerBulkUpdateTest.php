<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * ProductController 통합 일괄 업데이트 Feature 테스트
 *
 * 상품 및 옵션 통합 일괄 업데이트 API 테스트
 */
class ProductControllerBulkUpdateTest extends ModuleTestCase
{
    protected User $adminUser;

    protected Product $product1;

    protected Product $product2;

    protected ProductOption $option1;

    protected ProductOption $option2;

    protected ProductOption $option3;

    protected ProductOption $option4;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 관리자 사용자 생성
        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.products.update']);

        // 테스트 상품 1 생성
        $this->product1 = Product::create([
            'name' => ['ko' => '테스트 상품 1', 'en' => 'Test Product 1'],
            'product_code' => 'TEST-001',
            'selling_price' => 10000,
            'list_price' => 12000,
            'stock_quantity' => 100,
            'sales_status' => ProductSalesStatus::ON_SALE,
            'display_status' => ProductDisplayStatus::VISIBLE,
            'has_options' => true,
        ]);

        // 테스트 상품 2 생성
        $this->product2 = Product::create([
            'name' => ['ko' => '테스트 상품 2', 'en' => 'Test Product 2'],
            'product_code' => 'TEST-002',
            'selling_price' => 20000,
            'list_price' => 25000,
            'stock_quantity' => 50,
            'sales_status' => ProductSalesStatus::ON_SALE,
            'display_status' => ProductDisplayStatus::VISIBLE,
            'has_options' => true,
        ]);

        // 상품 1의 옵션
        $this->option1 = ProductOption::create([
            'product_id' => $this->product1->id,
            'option_code' => 'OPT-001',
            'option_values' => ['색상' => '빨강'],
            'option_name' => '빨강',
            'price_adjustment' => 0,
            'stock_quantity' => 50,
            'is_active' => true,
        ]);

        $this->option2 = ProductOption::create([
            'product_id' => $this->product1->id,
            'option_code' => 'OPT-002',
            'option_values' => ['색상' => '파랑'],
            'option_name' => '파랑',
            'price_adjustment' => 1000,
            'stock_quantity' => 30,
            'is_active' => true,
        ]);

        // 상품 2의 옵션
        $this->option3 = ProductOption::create([
            'product_id' => $this->product2->id,
            'option_code' => 'OPT-003',
            'option_values' => ['사이즈' => 'L'],
            'option_name' => 'L',
            'price_adjustment' => 500,
            'stock_quantity' => 25,
            'is_active' => true,
        ]);

        $this->option4 = ProductOption::create([
            'product_id' => $this->product2->id,
            'option_code' => 'OPT-004',
            'option_values' => ['사이즈' => 'XL'],
            'option_name' => 'XL',
            'price_adjustment' => 1500,
            'stock_quantity' => 15,
            'is_active' => true,
        ]);
    }

    // ==================== 상품 일괄 변경 테스트 ====================

    /**     * 상품 통합 일괄 업데이트 - bulk_changes로 판매상태 일괄 변경
     */
    #[Test]
    public function test_bulk_update_sales_status(): void
    {
        // Given: bulk_changes로 판매상태 변경
        $data = [
            'ids' => [$this->product1->id, $this->product2->id],
            'bulk_changes' => [
                'sales_status' => ProductSalesStatus::SOLD_OUT->value,
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/products/bulk-update', $data);

        // Then: 성공 응답 및 DB 반영 확인
        $response->assertStatus(200);
        $response->assertJsonPath('data.products_updated', 2);

        $this->product1->refresh();
        $this->product2->refresh();

        $this->assertEquals(ProductSalesStatus::SOLD_OUT, $this->product1->sales_status);
        $this->assertEquals(ProductSalesStatus::SOLD_OUT, $this->product2->sales_status);
    }

    /**     * 상품 통합 일괄 업데이트 - bulk_changes로 전시상태 일괄 변경
     */
    #[Test]
    public function test_bulk_update_display_status(): void
    {
        // Given: bulk_changes로 전시상태 변경
        $data = [
            'ids' => [$this->product1->id, $this->product2->id],
            'bulk_changes' => [
                'display_status' => ProductDisplayStatus::HIDDEN->value,
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/products/bulk-update', $data);

        // Then: 성공 응답 및 DB 반영 확인
        $response->assertStatus(200);

        $this->product1->refresh();
        $this->product2->refresh();

        $this->assertEquals(ProductDisplayStatus::HIDDEN, $this->product1->display_status);
        $this->assertEquals(ProductDisplayStatus::HIDDEN, $this->product2->display_status);
    }

    /**     * 상품 통합 일괄 업데이트 - items로 개별 인라인 수정
     */
    #[Test]
    public function test_bulk_update_with_items_inline_edit(): void
    {
        // Given: items로 개별 상품 수정
        $data = [
            'ids' => [$this->product1->id, $this->product2->id],
            'items' => [
                [
                    'id' => $this->product1->id,
                    'name' => ['ko' => '수정된 상품 1', 'en' => 'Modified Product 1'],
                    'list_price' => 15000,
                ],
                [
                    'id' => $this->product2->id,
                    'selling_price' => 18000,
                ],
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/products/bulk-update', $data);

        // Then: 성공 응답 및 DB 반영 확인
        $response->assertStatus(200);

        $this->product1->refresh();
        $this->product2->refresh();

        $this->assertEquals(['ko' => '수정된 상품 1', 'en' => 'Modified Product 1'], $this->product1->name);
        $this->assertEquals(15000, $this->product1->list_price);
        $this->assertEquals(18000, $this->product2->selling_price);
    }

    /**     * 상품 통합 일괄 업데이트 - bulk_changes가 items보다 우선
     */
    #[Test]
    public function test_bulk_update_bulk_changes_overrides_items(): void
    {
        // Given: bulk_changes와 items 모두 설정
        $data = [
            'ids' => [$this->product1->id, $this->product2->id],
            'bulk_changes' => [
                'sales_status' => ProductSalesStatus::SUSPENDED->value,
            ],
            'items' => [
                [
                    'id' => $this->product1->id,
                    'sales_status' => ProductSalesStatus::ON_SALE->value, // bulk_changes에 의해 무시됨
                    'name' => ['ko' => '이름은 적용됨', 'en' => 'Name is applied'],
                ],
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/products/bulk-update', $data);

        // Then: 성공 응답
        $response->assertStatus(200);

        $this->product1->refresh();
        $this->product2->refresh();

        // bulk_changes의 sales_status가 적용됨
        $this->assertEquals(ProductSalesStatus::SUSPENDED, $this->product1->sales_status);
        $this->assertEquals(ProductSalesStatus::SUSPENDED, $this->product2->sales_status);

        // items의 다른 필드는 적용됨
        $this->assertEquals(['ko' => '이름은 적용됨', 'en' => 'Name is applied'], $this->product1->name);
    }

    // ==================== 옵션 일괄 변경 테스트 (상품 API 통해) ====================

    /**     * 상품 통합 일괄 업데이트 - option_bulk_changes로 해당 상품 옵션 일괄 변경
     */
    #[Test]
    public function test_bulk_update_with_option_bulk_changes(): void
    {
        // Given: option_bulk_changes로 옵션 가격 조정
        $data = [
            'ids' => [$this->product1->id],
            'option_bulk_changes' => [
                'price_adjustment' => [
                    'method' => 'set',
                    'value' => 2000,
                ],
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/products/bulk-update', $data);

        // Then: 성공 응답 및 DB 반영 확인
        $response->assertStatus(200);
        $response->assertJsonPath('data.options_updated', 2); // product1의 옵션 2개

        $this->option1->refresh();
        $this->option2->refresh();

        $this->assertEquals(2000, $this->option1->price_adjustment);
        $this->assertEquals(2000, $this->option2->price_adjustment);

        // product2의 옵션은 변경되지 않음
        $this->option3->refresh();
        $this->assertEquals(500, $this->option3->price_adjustment);
    }

    /**     * 상품 통합 일괄 업데이트 - option_items로 개별 옵션 수정
     */
    #[Test]
    public function test_bulk_update_with_option_items(): void
    {
        // Given: option_items로 개별 옵션 수정
        $data = [
            'ids' => [$this->product1->id, $this->product2->id],
            'option_items' => [
                [
                    'product_id' => $this->product1->id,
                    'option_id' => $this->option1->id,
                    'option_name' => ['ko' => '변경된 빨강', 'en' => 'Changed Red'],
                    'sku' => 'NEW-SKU-001',
                ],
                [
                    'product_id' => $this->product2->id,
                    'option_id' => $this->option3->id,
                    'safe_stock_quantity' => 5,
                ],
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/products/bulk-update', $data);

        // Then: 성공 응답 및 DB 반영 확인
        $response->assertStatus(200);

        $this->option1->refresh();
        $this->option3->refresh();

        $this->assertEquals(['ko' => '변경된 빨강', 'en' => 'Changed Red'], $this->option1->option_name);
        $this->assertEquals('NEW-SKU-001', $this->option1->sku);
        $this->assertEquals(5, $this->option3->safe_stock_quantity);
    }

    /**     * 상품 통합 일괄 업데이트 - 상품과 옵션 동시 변경
     */
    #[Test]
    public function test_bulk_update_products_and_options_together(): void
    {
        // Given: 상품 bulk_changes + 옵션 bulk_changes
        $data = [
            'ids' => [$this->product1->id, $this->product2->id],
            'bulk_changes' => [
                'sales_status' => ProductSalesStatus::SUSPENDED->value,
            ],
            'option_bulk_changes' => [
                'stock_quantity' => [
                    'method' => 'set',
                    'value' => 100,
                ],
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/products/bulk-update', $data);

        // Then: 성공 응답 및 DB 반영 확인
        $response->assertStatus(200);
        $response->assertJsonPath('data.products_updated', 2);
        $response->assertJsonPath('data.options_updated', 4); // 상품 2개의 옵션 총 4개

        // 상품 확인
        $this->product1->refresh();
        $this->product2->refresh();
        $this->assertEquals(ProductSalesStatus::SUSPENDED, $this->product1->sales_status);
        $this->assertEquals(ProductSalesStatus::SUSPENDED, $this->product2->sales_status);

        // 옵션 확인
        $this->option1->refresh();
        $this->option2->refresh();
        $this->option3->refresh();
        $this->option4->refresh();
        $this->assertEquals(100, $this->option1->stock_quantity);
        $this->assertEquals(100, $this->option2->stock_quantity);
        $this->assertEquals(100, $this->option3->stock_quantity);
        $this->assertEquals(100, $this->option4->stock_quantity);
    }

    // ==================== 유효성 검증 테스트 ====================

    /**     * 상품 통합 일괄 업데이트 - ids 필수
     */
    #[Test]
    public function test_bulk_update_requires_ids(): void
    {
        // Given: ids 없음
        $data = [
            'bulk_changes' => [
                'sales_status' => ProductSalesStatus::SOLD_OUT->value,
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/products/bulk-update', $data);

        // Then: 검증 실패
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ids']);
    }

    /**     * 상품 통합 일괄 업데이트 - 존재하지 않는 상품 ID
     */
    #[Test]
    public function test_bulk_update_validates_product_exists(): void
    {
        // Given: 존재하지 않는 상품 ID
        $data = [
            'ids' => [99999],
            'bulk_changes' => [
                'sales_status' => ProductSalesStatus::SOLD_OUT->value,
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/products/bulk-update', $data);

        // Then: 검증 실패
        $response->assertStatus(422);
    }

    /**     * 상품 통합 일괄 업데이트 - 잘못된 sales_status 값
     */
    #[Test]
    public function test_bulk_update_validates_sales_status(): void
    {
        // Given: 잘못된 sales_status 값
        $data = [
            'ids' => [$this->product1->id],
            'bulk_changes' => [
                'sales_status' => 'invalid_status',
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/products/bulk-update', $data);

        // Then: 검증 실패
        $response->assertStatus(422);
    }

    /**     * 상품 통합 일괄 업데이트 - 잘못된 display_status 값
     */
    #[Test]
    public function test_bulk_update_validates_display_status(): void
    {
        // Given: 잘못된 display_status 값
        $data = [
            'ids' => [$this->product1->id],
            'bulk_changes' => [
                'display_status' => 'invalid_status',
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/products/bulk-update', $data);

        // Then: 검증 실패
        $response->assertStatus(422);
    }

    /**     * 상품 통합 일괄 업데이트 - 인증 필요
     */
    #[Test]
    public function test_bulk_update_requires_authentication(): void
    {
        // Given: 인증 없이 요청
        $data = [
            'ids' => [$this->product1->id],
            'bulk_changes' => [
                'sales_status' => ProductSalesStatus::SOLD_OUT->value,
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->patchJson('/api/modules/sirsoft-ecommerce/admin/products/bulk-update', $data);

        // Then: 인증 필요 에러
        $response->assertStatus(401);
    }

    /**     * 상품 통합 일괄 업데이트 - 빈 요청도 성공 (아무것도 변경 안 함)
     */
    #[Test]
    public function test_bulk_update_with_no_changes(): void
    {
        // Given: ids만 있고 변경 사항 없음
        $data = [
            'ids' => [$this->product1->id],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/products/bulk-update', $data);

        // Then: 성공 응답 (0개 변경)
        $response->assertStatus(200);
        $response->assertJsonPath('data.products_updated', 0);
        $response->assertJsonPath('data.options_updated', 0);
    }
}
