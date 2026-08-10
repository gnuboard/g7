<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Enums\TotalRelation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\GuestRoleResolver;
use App\Support\Query\BoundedCount;
use Illuminate\Support\Facades\Gate;
use Modules\Sirsoft\Ecommerce\Listeners\SearchProductsListener;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * SearchProductsListener 단위 테스트
 */
class SearchProductsListenerTest extends ModuleTestCase
{
    private SearchProductsListener $listener;

    private ProductService $productService;

    /**
     * 권한 허용 여부 (권한 거부 케이스에서 false 로 바꾼다)
     *
     * Gate::before 는 **먼저 등록된** 콜백이 non-null 을 돌려주면 그 값으로 확정된다.
     * setUp 에서 곧바로 true 를 등록하면 개별 테스트가 뒤에 false 를 등록해도 절대
     * 이기지 못하므로, 판정을 이 플래그로 미룬다.
     */
    private bool $permissionsAllowed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->productService = $this->createMock(ProductService::class);
        $this->listener = new SearchProductsListener($this->productService);

        // 기본적으로 권한 허용 (개별 테스트에서 $this->permissionsAllowed 로 오버라이드)
        Gate::before(fn () => $this->permissionsAllowed ? true : false);
    }

    /**
     * guest 역할에서 상품 조회 권한을 떼어냅니다.
     *
     * 비회원 판정은 Gate 가 아니라 guest 역할의 권한 행을 보므로, 거부 케이스를 만들려면
     * 권한 자체를 없애야 합니다. 요청 스코프 캐시도 함께 비웁니다.
     */
    private function revokeGuestProductReadPermission(): void
    {
        $permission = Permission::where('identifier', 'sirsoft-ecommerce.user-products.read')->first();
        $guestRole = Role::where('identifier', 'guest')->first();

        if ($permission && $guestRole) {
            $guestRole->permissions()->detach($permission->id);
        }

        GuestRoleResolver::flush();
    }

    /**
     * getSubscribedHooks()가 올바른 훅 목록을 반환하는지 확인
     */
    public function test_get_subscribed_hooks_returns_correct_hooks(): void
    {
        $hooks = SearchProductsListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.search.results', $hooks);
        $this->assertArrayHasKey('core.search.build_response', $hooks);
        $this->assertArrayHasKey('core.search.index_validation_rules', $hooks);

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
    public function test_add_validation_rules_adds_price_sort_and_category_id(): void
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
    public function test_search_products_returns_count_only_when_type_is_not_products_or_all(): void
    {
        $this->productService
            ->method('countByKeyword')
            ->willReturn(new BoundedCount(3, TotalRelation::Exact, 10000));

        $results = [];
        $context = ['type' => 'posts', 'q' => '테스트', 'user' => User::factory()->make()];

        $result = $this->listener->searchProducts($results, $context);

        $this->assertArrayHasKey('products', $result);
        $this->assertEquals(3, $result['products']['total']);
        $this->assertEmpty($result['products']['items']);
    }

    /**
     * 비활성 탭 배지도 잘린 건수를 정확한 것처럼 보고하지 않는지 확인 (#519)
     *
     * 배지는 오류를 내지 않고 그냥 틀린 숫자로만 보이므로, 정확도가 함께 실리지 않으면
     * 상한에 걸린 10,000 이 "정확히 10,000 건" 으로 화면에 나간다.
     */
    public function test_count_only_badge_carries_accuracy_when_truncated(): void
    {
        $this->productService
            ->method('countByKeyword')
            ->willReturn(new BoundedCount(10000, TotalRelation::AtLeast, 10000));

        $result = $this->listener->searchProducts([], [
            'type' => 'posts',
            'q' => '테스트',
            'user' => User::factory()->make(),
        ]);

        $this->assertSame(10000, $result['products']['total']);
        $this->assertSame('at_least', $result['products']['total_relation']);
        $this->assertFalse($result['products']['total_is_exact']);
        $this->assertSame(10000, $result['products']['result_cap']);
    }

    /**
     * searchProducts()가 빈 검색어일 때 스킵하는지 확인
     */
    public function test_search_products_skips_when_keyword_is_empty(): void
    {
        $results = [];
        $context = ['type' => 'all', 'q' => ''];

        $result = $this->listener->searchProducts($results, $context);

        $this->assertArrayNotHasKey('products', $result);
    }

    /**
     * searchProducts()가 권한 없을 때 빈 결과를 반환하는지 확인
     */
    public function test_search_products_returns_empty_when_no_permission(): void
    {
        // 모든 권한 거부
        $this->permissionsAllowed = false;

        $results = [];
        $context = ['type' => 'all', 'q' => '테스트', 'user' => User::factory()->make()];

        $result = $this->listener->searchProducts($results, $context);

        // 상품 키가 추가되지 않아야 함 (검색 미수행)
        $this->assertArrayNotHasKey('products', $result);
    }

    /**
     * searchProducts()가 비회원(user=null)이고 guest 권한 없을 때 빈 결과를 반환하는지 확인
     */
    public function test_search_products_returns_empty_for_guest_without_permission(): void
    {
        // 비회원 판정은 Gate 가 아니라 guest 역할의 권한 행을 본다.
        // 기본 설치의 guest 는 상품 조회 권한을 가지므로, 그 권한을 실제로 떼고 확인한다.
        $this->revokeGuestProductReadPermission();

        $results = [];
        $context = ['type' => 'all', 'q' => '테스트', 'user' => null];

        $result = $this->listener->searchProducts($results, $context);

        // guest 권한이 없으므로 빈 결과
        $this->assertArrayNotHasKey('products', $result);
    }

    /**
     * buildProductsResponse()가 products 결과가 없을 때 스킵하는지 확인
     */
    public function test_build_products_response_skips_when_no_products_results(): void
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
     * buildProductsResponse()가 all 탭에서 조회된 항목을 그대로 싣는지 확인
     *
     * 미리보기 개수만큼만 조회해 오므로 여기서 다시 자르지 않는다. 종전에는 페이지 분량을
     * 받아 PHP 에서 잘랐고, 그만큼 쓰이지 않을 행까지 읽었다.
     */
    public function test_build_products_response_passes_through_items_for_all_tab(): void
    {
        $items = [];
        for ($i = 0; $i < 5; $i++) {
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
    public function test_build_products_response_adds_pagination_for_products_tab(): void
    {
        $items = [];
        for ($i = 0; $i < 10; $i++) {
            $items[] = ['id' => $i + 1, 'name' => "상품 {$i}"];
        }

        $response = [];
        $results = ['products' => [
            'total' => 25,
            'items' => $items,
            'last_page' => 3,
            'has_more_pages' => true,
        ]];
        $context = ['type' => 'products', 'page' => 1, 'per_page' => 10];

        $result = $this->listener->buildProductsResponse($response, $results, $context);

        $this->assertArrayHasKey('current_page', $result);
        $this->assertArrayHasKey('per_page', $result);
        $this->assertArrayHasKey('last_page', $result);
        $this->assertEquals(1, $result['current_page']);
        $this->assertEquals(10, $result['per_page']);
        $this->assertEquals(3, $result['last_page']);
        $this->assertTrue($result['has_more_pages']);
        $this->assertCount(10, $result['products']);
    }

    /**
     * 총 건수가 상한에 걸리면 마지막 페이지가 null 로 전달되는지 확인
     */
    public function test_build_products_response_omits_last_page_when_total_is_bounded(): void
    {
        $response = [];
        $results = ['products' => [
            'total' => 10000,
            'total_is_exact' => false,
            'total_relation' => 'at_least',
            'result_cap' => 10000,
            'items' => [['id' => 1]],
            'last_page' => null,
            'has_more_pages' => true,
        ]];
        $context = ['type' => 'products', 'page' => 1, 'per_page' => 10];

        $result = $this->listener->buildProductsResponse($response, $results, $context);

        $this->assertNull($result['last_page'], '마지막 페이지는 계산 불가');
        $this->assertTrue($result['has_more_pages'], '"다음" 이동은 계속 열려 있다');
        $this->assertFalse($result['products_total_is_exact']);
        $this->assertEquals('at_least', $result['products_total_relation']);
    }

    /**
     * buildProductsResponse()가 products_count를 설정하는지 확인
     */
    public function test_build_products_response_sets_products_count(): void
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
    public function test_build_products_response_preserves_rating_fields(): void
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
