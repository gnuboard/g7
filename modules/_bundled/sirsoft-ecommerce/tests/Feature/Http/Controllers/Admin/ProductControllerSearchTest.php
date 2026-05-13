<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Search\Engines\DatabaseFulltextEngine;
use Laravel\Scout\EngineManager;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * LIKE fallback 전용 Scout 엔진
 *
 * MySQL FULLTEXT는 트랜잭션 내 미커밋 데이터를 인덱싱하지 않으므로,
 * 테스트에서 Scout 파이프라인 전체를 검증하기 위해 LIKE fallback을 강제합니다.
 */
class LikeFallbackEngine extends DatabaseFulltextEngine
{
    public static function supportsFulltext(): bool
    {
        return false;
    }
}

/**
 * ProductController 검색 관련 엔드포인트 테스트
 *
 * search_field 파라미터 검증 및 검색 결과를 테스트합니다.
 * FULLTEXT 대상 필드(name, description)는 Scout 파이프라인을 통해 검색되며,
 * 테스트에서는 LIKE fallback 엔진으로 교체하여 트랜잭션 내에서도 동작합니다.
 */
class ProductControllerSearchTest extends ModuleTestCase
{
    /**
     * product_code 검색 필드가 허용되는지 확인
     */
    public function test_search_field_accepts_product_code(): void
    {
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create([
            'product_code' => 'TEST-CODE-1234',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?search_field=product_code&search_keyword=TEST-CODE-1234');

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $data = $response->json('data.data');
        $this->assertNotEmpty($data);
        $this->assertEquals($product->id, $data[0]['id']);
    }

    /**
     * barcode 검색 필드가 허용되는지 확인
     */
    public function test_search_field_accepts_barcode(): void
    {
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create([
            'barcode' => '8801234567890',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?search_field=barcode&search_keyword=8801234567890');

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $data = $response->json('data.data');
        $this->assertNotEmpty($data);
        $this->assertEquals($product->id, $data[0]['id']);
    }

    /**
     * code 검색 필드(잘못된 값)가 거부되는지 확인
     */
    public function test_search_field_rejects_code(): void
    {
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?search_field=code&search_keyword=test');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('search_field');
    }

    /**
     * name 검색 필드로 상품명 검색이 Scout 파이프라인을 통해 정상 동작하는지 확인
     *
     * MySQL FULLTEXT는 트랜잭션 내 미커밋 데이터를 인덱싱하지 않으므로,
     * LIKE fallback 엔진으로 교체하여 Scout 경로 전체를 검증합니다.
     */
    public function test_search_field_name_returns_matching_products(): void
    {
        // Scout 엔진을 LIKE fallback으로 교체 (트랜잭션 호환)
        $this->swapScoutEngineToLikeFallback();

        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        Product::factory()->create([
            'name' => ['ko' => '유니크테스트상품명', 'en' => 'Unique Test Product'],
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?search_field=name&search_keyword=유니크테스트상품명');

        $response->assertOk();

        $data = $response->json('data.data');
        $this->assertNotEmpty($data);
    }

    /**
     * all 검색 필드로 Scout + LIKE 혼합 검색이 정상 동작하는지 확인
     */
    public function test_search_field_all_returns_matching_products_via_scout(): void
    {
        $this->swapScoutEngineToLikeFallback();

        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        Product::factory()->create([
            'name' => ['ko' => '스카우트통합검색상품', 'en' => 'Scout All Search'],
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?search_field=all&search_keyword=스카우트통합검색상품');

        $response->assertOk();

        $data = $response->json('data.data');
        $this->assertNotEmpty($data);
    }

    /**
     * sku 검색 필드가 허용되는지 확인
     */
    public function test_search_field_accepts_sku(): void
    {
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create([
            'sku' => 'SKU-UNIQUE-TEST-9999',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?search_field=sku&search_keyword=SKU-UNIQUE-TEST-9999');

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $data = $response->json('data.data');
        $this->assertNotEmpty($data);
        $this->assertEquals($product->id, $data[0]['id']);
    }

    /**
     * Scout 엔진을 LIKE fallback 모드로 교체합니다.
     *
     * MySQL FULLTEXT는 트랜잭션 내 미커밋 데이터를 검색하지 못하므로,
     * Scout 파이프라인 전체(Model::search → EngineManager → performSearch → 쿼리)를
     * 검증하기 위해 LIKE fallback 엔진으로 교체합니다.
     *
     * @return void
     */
    private function swapScoutEngineToLikeFallback(): void
    {
        $manager = $this->app->make(EngineManager::class);
        $manager->extend('mysql-fulltext', fn () => new LikeFallbackEngine());

        // EngineManager 캐시된 드라이버 인스턴스 초기화
        $reflection = new \ReflectionClass($manager);
        $property = $reflection->getProperty('drivers');
        $property->setAccessible(true);
        $property->setValue($manager, []);
    }

    /**
     * 상품 목록 응답에 pagination.total이 포함되는지 확인
     */
    public function test_product_list_response_contains_pagination_total(): void
    {
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        Product::factory()->count(3)->create();

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data' => [
                'data',
                'pagination' => [
                    'total',
                    'current_page',
                    'last_page',
                    'per_page',
                ],
            ],
        ]);

        $total = $response->json('data.pagination.total');
        $this->assertGreaterThanOrEqual(3, $total);
    }
}
