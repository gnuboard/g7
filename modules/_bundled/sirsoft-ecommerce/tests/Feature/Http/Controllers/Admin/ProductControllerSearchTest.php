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
     * all 검색에서 숫자 키워드로 상품 ID 정확 매칭이 잡히는지 확인
     */
    public function test_search_all_matches_by_product_id(): void
    {
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $target = Product::factory()->create();
        Product::factory()->create(); // 노이즈

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?search_field=all&search_keyword='.$target->id);

        $response->assertOk();
        $data = $response->json('data.data');
        $ids = array_column($data, 'id');
        $this->assertContains($target->id, $ids);
    }

    /**
     * all 검색에서 상품코드로 매칭이 잡히는지 확인 (id 매칭 추가 후 회귀 방지)
     */
    public function test_search_all_matches_by_product_code(): void
    {
        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create(['product_code' => 'ALLCODE1234ABCD']);

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?search_field=all&search_keyword=ALLCODE1234ABCD');

        $response->assertOk();
        $ids = array_column($response->json('data.data'), 'id');
        $this->assertContains($product->id, $ids);
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
     */
    private function swapScoutEngineToLikeFallback(): void
    {
        $manager = $this->app->make(EngineManager::class);
        $manager->extend('mysql-fulltext', fn () => new LikeFallbackEngine);

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

    /**
     * A19①: search_field=all + product_code 매칭 검색어 시 total 이 실제 결과 행 수와 일치하는지 확인.
     *
     * 회귀: Scout queryCallback total 재계산 시 보조필드 orWhere 가 MATCH 절 없이 재적용되어
     * total=0 으로 잘못 표시되던 결함을 가드합니다. (수정 전 total=0, count>0 → fail)
     */
    public function test_search_field_all_total_matches_count_for_product_code(): void
    {
        $this->swapScoutEngineToLikeFallback();

        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        // 이름에는 키워드가 없고 product_code 에만 매칭되는 상품
        Product::factory()->create([
            'name' => ['ko' => '평범한상품', 'en' => 'Ordinary Product'],
            'product_code' => 'AUXCODE-7777',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?search_field=all&search_keyword=AUXCODE-7777');

        $response->assertOk();

        $data = $response->json('data.data');
        $total = $response->json('data.pagination.total');

        $this->assertNotEmpty($data);
        $this->assertSame(count($data), $total, 'total 은 실제 결과 행 수와 일치해야 합니다 (A19① 회귀).');
        $this->assertSame('AUXCODE-7777', $data[0]['product_code']);
    }

    /**
     * A19①: search_field=all + name 매칭 검색어 시 total 정합 확인.
     */
    public function test_search_field_all_total_matches_count_for_name(): void
    {
        $this->swapScoutEngineToLikeFallback();

        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        Product::factory()->create([
            'name' => ['ko' => '유니크네임검색', 'en' => 'Unique Name Search'],
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?search_field=all&search_keyword=유니크네임검색');

        $response->assertOk();

        $data = $response->json('data.data');
        $total = $response->json('data.pagination.total');

        $this->assertNotEmpty($data);
        $this->assertSame(count($data), $total);
    }

    /**
     * A19①: search_field=name 경로(보조필드 orWhere 없음)의 total 정합이 회귀하지 않는지 가드.
     */
    public function test_search_field_name_total_matches_count(): void
    {
        $this->swapScoutEngineToLikeFallback();

        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        Product::factory()->create([
            'name' => ['ko' => '네임전용검색', 'en' => 'Name Only Search'],
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?search_field=name&search_keyword=네임전용검색');

        $response->assertOk();

        $data = $response->json('data.data');
        $total = $response->json('data.pagination.total');

        $this->assertNotEmpty($data);
        $this->assertSame(count($data), $total);
    }

    /**
     * A19①: 검색어 + 판매상태(sales_status[]) 동시 적용 시 total 정합 확인 (검수 시나리오 재현).
     */
    public function test_search_field_all_with_sales_status_total_matches_count(): void
    {
        $this->swapScoutEngineToLikeFallback();

        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create([
            'name' => ['ko' => '판매중상품검색', 'en' => 'On Sale Search'],
            'product_code' => 'SALECODE-1',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?search_field=all&search_keyword=SALECODE-1&sales_status[]='.$product->sales_status->value);

        $response->assertOk();

        $data = $response->json('data.data');
        $total = $response->json('data.pagination.total');

        $this->assertSame(count($data), $total);
    }

    /**
     * A19①: 매칭 없는 검색어는 total=0 + 빈 목록이 정상 (버그였던 total=0 과 구분).
     */
    public function test_search_field_all_no_match_returns_zero_total(): void
    {
        $this->swapScoutEngineToLikeFallback();

        $user = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        Product::factory()->create([
            'name' => ['ko' => '무관한상품', 'en' => 'Irrelevant'],
            'product_code' => 'XYZ-1',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?search_field=all&search_keyword=절대없는키워드ZZZ');

        $response->assertOk();

        $data = $response->json('data.data');
        $total = $response->json('data.pagination.total');

        $this->assertEmpty($data);
        $this->assertSame(0, $total);
    }
}
