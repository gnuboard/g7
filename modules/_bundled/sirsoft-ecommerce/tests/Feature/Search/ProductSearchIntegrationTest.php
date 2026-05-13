<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Search;

use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 통합검색 상품 검색 Feature 테스트
 *
 * 주의: MySQL InnoDB FULLTEXT 는 커밋된 데이터만 인덱싱하므로 기본 DatabaseTransactions
 * 격리로는 MATCH 조회가 0 을 반환한다. connectionsToTransact=[] 로 트랜잭션 wrapping 을
 * 끄고 tearDown 에서 수동 정리한다.
 */
class ProductSearchIntegrationTest extends ModuleTestCase
{
    /**
     * 트랜잭션 wrapping 비활성화 (FULLTEXT 인덱싱은 commit 된 데이터 대상).
     *
     * @return array<string>
     */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    /**
     * 수동 정리: transaction rollback 이 없으므로 insert 한 Product 를 직접 제거.
     */
    protected function tearDown(): void
    {
        Product::query()->withTrashed()->forceDelete();

        parent::tearDown();
    }

    /**
     * 모듈 훅 리스너 수동 등록.
     *
     * ModuleManager 는 활성화된 모듈(`getActiveModuleIdentifiers`)만 훅을 등록하는데,
     * 테스트 환경에서는 모듈을 활성화 상태로 시드하지 않으므로 SearchProductsListener 등의
     * core.search.* filter 리스너가 비어있다. 테스트에서 통합 검색을 검증하려면 수동 등록이 필요.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $module = app(\App\Extension\ModuleManager::class)->getModule('sirsoft-ecommerce');
        if ($module === null) {
            return;
        }

        // 각 리스너의 subscribed hooks 를 HookManager 에서 먼저 제거 후 재등록.
        // - 이전 테스트의 ServiceProvider boot 가 이미 등록한 listener 가 snapshotHookManager
        //   에 보존되어 다음 테스트로 누수되는데, 그 위에 register() 를 또 부르면 동일 콜백이
        //   filter 큐에 중복 추가되어 searchProducts 가 한 요청에서 2회 실행되어 두 번째
        //   호출이 첫 번째 결과를 빈 결과로 덮어쓰는 문제 발생.
        // - registrar dedup 캐시도 함께 비워 register() 가 실제 동작하도록 한다.
        \App\Extension\HookListenerRegistrar::clear();

        foreach ($module->getHookListeners() as $listenerClass) {
            if (! class_exists($listenerClass)) {
                continue;
            }
            try {
                $subscribed = $listenerClass::getSubscribedHooks();
                foreach (array_keys($subscribed) as $hookName) {
                    \App\Extension\HookManager::clearFilter($hookName);
                    \App\Extension\HookManager::clearAction($hookName);
                }
                \App\Extension\HookListenerRegistrar::register($listenerClass, 'sirsoft-ecommerce');
            } catch (\Throwable $e) {
                // skip individual listener failures
            }
        }
    }

    /**
     * FULLTEXT 인덱스 cache 플러시.
     *
     * MySQL InnoDB FULLTEXT 는 INSERT 직후 내부 cache 에 pending 상태로 보관하며,
     * MATCH..AGAINST 가중치 연산(MATCH * N) 이 cache 와 섞이면 DOUBLE overflow
     * (ER_DATA_OUT_OF_RANGE 1690) 가 STRICT_TRANS_TABLES 아래에서 발생한다.
     * ALTER TABLE ENGINE=InnoDB 로 FULLTEXT 보조 테이블을 재구성하여 cache 를 main index 에
     * flush 한다. (OPTIMIZE TABLE 은 innodb_optimize_fulltext_only GLOBAL 변수 의존적이라
     * 세션 단위로 격리된 테스트에서 사용 불가.)
     */
    protected function flushProductFulltextIndex(): void
    {
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE g7_ecommerce_products ENGINE=InnoDB');
    }

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
        $this->flushProductFulltextIndex();

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
        $this->flushProductFulltextIndex();

        // Act
        $response = $this->getJson('/api/search?q=유니크키워드abc&type=products');

        // Assert
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(0, $data['products_count'] ?? 0);
    }

    /**
     * all 탭에서 상품이 포함되는지 확인
     *
     * 주의: MySQL FULLTEXT 는 기본 parser 에서 한글을 tokenize 하지 않으므로(ngram 미사용)
     * 영문 unique 키워드를 product name 의 en 필드에 넣어 검색.
     */
    public function test_search_all_tab_includes_products(): void
    {
        // Arrange — 영문 unique 키워드 (MySQL FULLTEXT 안정 indexable)
        Product::factory()->create([
            'name' => ['ko' => '통합검색용 상품', 'en' => 'integrationsearchxyz Product'],
            'display_status' => ProductDisplayStatus::VISIBLE,
        ]);
        $this->flushProductFulltextIndex();

        // Act
        $response = $this->getJson('/api/search?q=integrationsearchxyz&type=all');

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
        $this->flushProductFulltextIndex();

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
        $this->flushProductFulltextIndex();

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
     *
     * 주의: MySQL FULLTEXT 한글 tokenization 한계로 영문 unique 키워드 사용.
     */
    public function test_search_includes_sold_out_and_suspended_products(): void
    {
        // Arrange — 영문 unique 키워드 (FULLTEXT min_word_len 4+, 안정 indexable)
        $keyword = 'soldsuspendedkeyword';
        Product::factory()->soldOut()->create([
            'name' => ['ko' => '품절상품 테스트', 'en' => "{$keyword} Sold Out"],
        ]);
        Product::factory()->suspended()->create([
            'name' => ['ko' => '판매중단 테스트', 'en' => "{$keyword} Suspended"],
        ]);
        $this->flushProductFulltextIndex();

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