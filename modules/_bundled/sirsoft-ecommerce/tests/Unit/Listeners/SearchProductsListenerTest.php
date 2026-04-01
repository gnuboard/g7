<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Modules\Sirsoft\Ecommerce\Listeners\SearchProductsListener;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Tests\TestCase;

/**
 * SearchProductsListener 단위 테스트
 */
class SearchProductsListenerTest extends TestCase
{
    private SearchProductsListener $listener;

    private ProductService $productService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->productService = $this->createMock(ProductService::class);
        $this->listener = new SearchProductsListener($this->productService);

        // 기본적으로 권한 허용 (개별 테스트에서 오버라이드 가능)
        Gate::before(fn () => true);
    }

    /**
     * getSubscribedHooks()가 올바른 훅 목록을 반환하는지 확인
     */
    public function test_getSubscribedHooks_returns_correct_hooks(): void
    {
        $hooks = SearchProductsListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.search.results', $hooks);
        $this->assertArrayHasKey('core.search.build_response', $hooks);
        $this->assertArrayHasKey('core.search.validation_rules', $hooks);

        // 모두 filter 타입인지 확인
        foreach ($hooks as $hook) {
            $this->assertEquals('filter', $hook['type']);
        }

        // priority가 20인지 확인
        foreach ($hooks as $hook) {
            $this->assertEquals(20, $hook['priority']);
        }
    }

    /**
     * addValidationRules()가 sort에 가격순 옵션을 추가하는지 확인
     */
    public function test_addValidationRules_adds_price_sort_and_category_id(): void
    {
        $rules = ['q' => ['nullable', 'string', 'max:200']];

        $result = $this->listener->addValidationRules($rules);

        // sort 규칙에 price_asc, price_desc가 포함되는지 확인
        $this->assertArrayHasKey('sort', $result);
        $sortRule = implode(',', $result['sort']);
        $this->assertStringContainsString('price_asc', $sortRule);
        $this->assertStringContainsString('price_desc', $sortRule);

        // category_id 규칙이 추가되었는지 확인
        $this->assertArrayHasKey('category_id', $result);

        // 기존 규칙이 유지되는지 확인
        $this->assertArrayHasKey('q', $result);
    }

    /**
     * searchProducts()가 type이 products가 아닐 때 count만 반환하는지 확인
     */
    public function test_searchProducts_returns_count_only_when_type_is_not_products_or_all(): void
    {
        $this->productService
            ->method('countByKeyword')
            ->willReturn(3);

        $results = [];
        $context = ['type' => 'posts', 'q' => '테스트', 'user' => User::factory()->make()];

        $result = $this->listener->searchProducts($results, $context);

        $this->assertArrayHasKey('products', $result);
        $this->assertEquals(3, $result['products']['total']);
        $this->assertEmpty($result['products']['items']);
    }

    /**
     * searchProducts()가 빈 검색어일 때 스킵하는지 확인
     */
    public function test_searchProducts_skips_when_keyword_is_empty(): void
    {
        $results = [];
        $context = ['type' => 'all', 'q' => ''];

        $result = $this->listener->searchProducts($results, $context);

        $this->assertArrayNotHasKey('products', $result);
    }

    /**
     * searchProducts()가 권한 없을 때 빈 결과를 반환하는지 확인
     */
    public function test_searchProducts_returns_empty_when_no_permission(): void
    {
        // 모든 권한 거부
        Gate::before(fn () => false);

        $results = [];
        $context = ['type' => 'all', 'q' => '테스트', 'user' => User::factory()->make()];

        $result = $this->listener->searchProducts($results, $context);

        // 상품 키가 추가되지 않아야 함 (검색 미수행)
        $this->assertArrayNotHasKey('products', $result);
    }

    /**
     * searchProducts()가 비회원(user=null)이고 guest 권한 없을 때 빈 결과를 반환하는지 확인
     */
    public function test_searchProducts_returns_empty_for_guest_without_permission(): void
    {
        // Gate::before 해제 — guest는 Gate를 거치지 않으므로 DB 기반 체크
        // guest role에 권한이 없으면 거부됨
        Gate::before(fn () => null); // null = Gate::before가 판단하지 않음

        $results = [];
        $context = ['type' => 'all', 'q' => '테스트', 'user' => null];

        $result = $this->listener->searchProducts($results, $context);

        // guest 권한이 DB에 없으므로 빈 결과
        $this->assertArrayNotHasKey('products', $result);
    }

    /**
     * buildProductsResponse()가 products 결과가 없을 때 스킵하는지 확인
     */
    public function test_buildProductsResponse_skips_when_no_products_results(): void
    {
        $response = ['posts_count' => 5];
        $results = []; // products 키 없음
        $context = ['type' => 'all'];

        $result = $this->listener->buildProductsResponse($response, $results, $context);

        // 기존 응답이 유지되는지 확인
        $this->assertEquals(5, $result['posts_count']);
        $this->assertArrayNotHasKey('products', $result);
    }

    /**
     * buildProductsResponse()가 all 탭에서 5개로 제한하는지 확인
     */
    public function test_buildProductsResponse_limits_items_for_all_tab(): void
    {
        $items = [];
        for ($i = 0; $i < 10; $i++) {
            $items[] = ['id' => $i + 1, 'name' => "상품 {$i}"];
        }

        $response = [];
        $results = ['products' => ['total' => 10, 'items' => $items]];
        $context = ['type' => 'all'];

        $result = $this->listener->buildProductsResponse($response, $results, $context);

        $this->assertCount(5, $result['products']);
        $this->assertEquals(10, $result['products_count']);
    }

    /**
     * buildProductsResponse()가 products 탭에서 페이지네이션 정보를 추가하는지 확인
     */
    public function test_buildProductsResponse_adds_pagination_for_products_tab(): void
    {
        $items = [];
        for ($i = 0; $i < 10; $i++) {
            $items[] = ['id' => $i + 1, 'name' => "상품 {$i}"];
        }

        $response = [];
        $results = ['products' => ['total' => 25, 'items' => $items]];
        $context = ['type' => 'products', 'page' => 1, 'per_page' => 10];

        $result = $this->listener->buildProductsResponse($response, $results, $context);

        $this->assertArrayHasKey('current_page', $result);
        $this->assertArrayHasKey('per_page', $result);
        $this->assertArrayHasKey('last_page', $result);
        $this->assertEquals(1, $result['current_page']);
        $this->assertEquals(10, $result['per_page']);
        $this->assertEquals(3, $result['last_page']); // ceil(25/10) = 3
        $this->assertCount(10, $result['products']);
    }

    /**
     * buildProductsResponse()가 products_count를 설정하는지 확인
     */
    public function test_buildProductsResponse_sets_products_count(): void
    {
        $response = [];
        $results = ['products' => ['total' => 7, 'items' => [['id' => 1]]]];
        $context = ['type' => 'all'];

        $result = $this->listener->buildProductsResponse($response, $results, $context);

        $this->assertEquals(7, $result['products_count']);
    }

    /**
     * buildProductsResponse()가 rating_avg, review_count 필드를 유지하는지 확인
     */
    public function test_buildProductsResponse_preserves_rating_fields(): void
    {
        $items = [
            ['id' => 1, 'name' => '상품1', 'rating_avg' => 4.5, 'review_count' => 10],
            ['id' => 2, 'name' => '상품2', 'rating_avg' => 0.0, 'review_count' => 0],
        ];

        $response = [];
        $results = ['products' => ['total' => 2, 'items' => $items]];
        $context = ['type' => 'products'];

        $result = $this->listener->buildProductsResponse($response, $results, $context);

        $this->assertArrayHasKey('products', $result);
        $this->assertEquals(4.5, $result['products'][0]['rating_avg']);
        $this->assertEquals(10, $result['products'][0]['review_count']);
        $this->assertEquals(0.0, $result['products'][1]['rating_avg']);
        $this->assertEquals(0, $result['products'][1]['review_count']);
    }
}
