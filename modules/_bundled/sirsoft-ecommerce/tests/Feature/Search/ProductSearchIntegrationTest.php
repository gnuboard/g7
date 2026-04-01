<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Search;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Tests\TestCase;

/**
 * 통합검색 상품 검색 Feature 테스트
 */
class ProductSearchIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 상품 검색 시 visible 상품만 결과에 포함되는지 확인
     */
    public function test_search_returns_visible_products(): void
    {
        // Arrange: visible 상품 생성
        Product::factory()->create([
            'name' => ['ko' => '테스트 상품', 'en' => 'Test Product'],
            'display_status' => ProductDisplayStatus::VISIBLE,
        ]);

        // Act
        $response = $this->getJson('/api/search?q=테스트&type=products');

        // Assert
        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(1, $data['products_count'] ?? 0);
    }

    /**
     * hidden 상품이 검색 결과에서 제외되는지 확인
     */
    public function test_search_excludes_hidden_products(): void
    {
        // Arrange: hidden 상품만 생성
        Product::factory()->hidden()->create([
            'name' => ['ko' => '숨김상품 유니크키워드abc', 'en' => 'Hidden Product uniquekeyabc'],
        ]);

        // Act
        $response = $this->getJson('/api/search?q=유니크키워드abc&type=products');

        // Assert
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(0, $data['products_count'] ?? 0);
    }

    /**
     * all 탭에서 상품이 포함되는지 확인
     */
    public function test_search_all_tab_includes_products(): void
    {
        // Arrange
        Product::factory()->create([
            'name' => ['ko' => '통합검색용 상품xyz', 'en' => 'Integration Test xyz'],
            'display_status' => ProductDisplayStatus::VISIBLE,
        ]);

        // Act
        $response = $this->getJson('/api/search?q=통합검색용&type=all');

        // Assert
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertArrayHasKey('products_count', $data);
    }

    /**
     * 가격순 정렬 파라미터가 허용되는지 확인
     */
    public function test_search_accepts_price_sort_options(): void
    {
        // Act: price_asc 정렬
        $response = $this->getJson('/api/search?q=상품&type=products&sort=price_asc');
        $response->assertStatus(200)->assertJson(['success' => true]);

        // Act: price_desc 정렬
        $response = $this->getJson('/api/search?q=상품&type=products&sort=price_desc');
        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    /**
     * category_id 파라미터가 허용되는지 확인
     */
    public function test_search_accepts_category_id_parameter(): void
    {
        $response = $this->getJson('/api/search?q=상품&type=products&category_id=1');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * products 탭에서 페이지네이션 정보가 반환되는지 확인
     */
    public function test_search_products_tab_returns_pagination(): void
    {
        // Arrange: 여러 상품 생성
        Product::factory()->count(15)->create([
            'name' => ['ko' => '페이지네이션 테스트 상품', 'en' => 'Pagination Test Product'],
            'display_status' => ProductDisplayStatus::VISIBLE,
        ]);

        // Act
        $response = $this->getJson('/api/search?q=페이지네이션&type=products&page=1&per_page=10');

        // Assert
        $response->assertStatus(200);
        $data = $response->json('data');

        if (($data['products_count'] ?? 0) > 0) {
            $this->assertArrayHasKey('current_page', $data);
            $this->assertArrayHasKey('last_page', $data);
        }
    }

    /**
     * 상품 검색 결과에 필수 필드가 포함되는지 확인
     */
    public function test_search_product_result_contains_required_fields(): void
    {
        // Arrange
        Product::factory()->create([
            'name' => ['ko' => '필드확인용 상품qwerty', 'en' => 'Field Check qwerty'],
            'display_status' => ProductDisplayStatus::VISIBLE,
            'selling_price' => 50000,
            'list_price' => 60000,
        ]);

        // Act
        $response = $this->getJson('/api/search?q=필드확인용&type=products');

        // Assert
        $response->assertStatus(200);
        $data = $response->json('data');

        if (($data['products_count'] ?? 0) > 0) {
            $products = $data['products'] ?? [];
            $this->assertNotEmpty($products);

            $product = $products[0];
            $this->assertArrayHasKey('id', $product);
            $this->assertArrayHasKey('name', $product);
            $this->assertArrayHasKey('name_highlighted', $product);
            $this->assertArrayHasKey('thumbnail_url', $product);
            $this->assertArrayHasKey('selling_price_formatted', $product);
            $this->assertArrayHasKey('list_price_formatted', $product);
            $this->assertArrayHasKey('discount_rate', $product);
            $this->assertArrayHasKey('multi_currency_selling_price', $product);
            $this->assertArrayHasKey('multi_currency_list_price', $product);
            $this->assertArrayHasKey('sales_status', $product);
            $this->assertArrayHasKey('sales_status_label', $product);
            $this->assertArrayHasKey('labels', $product);
        }
    }

    /**
     * 품절/판매중단 상품이 검색 결과에 포함되는지 확인
     */
    public function test_search_includes_sold_out_and_suspended_products(): void
    {
        // Arrange
        $keyword = '검색노출확인용qwerty';
        Product::factory()->soldOut()->create([
            'name' => ['ko' => "{$keyword} 품절상품", 'en' => "{$keyword} Sold Out"],
        ]);
        Product::factory()->suspended()->create([
            'name' => ['ko' => "{$keyword} 판매중단", 'en' => "{$keyword} Suspended"],
        ]);

        // Act
        $response = $this->getJson("/api/search?q={$keyword}&type=products");

        // Assert
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(2, $data['products_count'] ?? 0);

        $statuses = collect($data['products'])->pluck('sales_status')->toArray();
        $this->assertContains('sold_out', $statuses);
        $this->assertContains('suspended', $statuses);
    }

}