<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Listeners\SearchProductsListener;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 공개 상품 목록·통합검색의 품절 제외 필터 테스트
 *
 * 품절 = 판매상태 「품절」 또는 활성 옵션 재고가 모두 0.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/public-product-exclude-sold-out.yaml
 */
class PublicProductListSoldOutTest extends ModuleTestCase
{
    private const URL = '/api/modules/sirsoft-ecommerce/products';

    /** @var array<string, Product> 경우별 상품 */
    private array $products = [];

    /**
     * 품절 판정 네 경우와 정상 상품을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.user-products.read'],
            ['name' => ['ko' => '상품 조회', 'en' => 'Read Products'], 'type' => PermissionType::User]
        );
        Role::where('identifier', 'user')->first()->permissions()->syncWithoutDetaching([$permission->id]);
        Role::where('identifier', 'guest')->first()?->permissions()->syncWithoutDetaching([$permission->id]);

        // 판매상태가 품절 (옵션 재고는 남아 있음)
        $this->products['status_sold_out'] = $this->makeProduct('SOLDOUT-STATUS', ProductSalesStatus::SOLD_OUT, [[10, true]]);
        // 판매중인데 활성 옵션 재고가 모두 0
        $this->products['all_options_empty'] = $this->makeProduct('SOLDOUT-STOCK', ProductSalesStatus::ON_SALE, [[0, true], [0, true]]);
        // 옵션 일부만 재고 있음 → 품절 아님
        $this->products['partial_stock'] = $this->makeProduct('SOLDOUT-PARTIAL', ProductSalesStatus::ON_SALE, [[0, true], [3, true]]);
        // 재고가 비활성 옵션에만 있음 → 품절
        $this->products['only_inactive_stock'] = $this->makeProduct('SOLDOUT-INACTIVE', ProductSalesStatus::ON_SALE, [[0, true], [9, false]]);
    }

    /**
     * 조건을 주지 않거나 0 이면 품절 상품도 그대로 나온다.
     *
     * @scenario surface=public_list, exclude_sold_out=off
     *
     * @effects list_keeps_sold_out_products_by_default
     *
     * @return void 반환값 없음
     */
    public function test_list_keeps_sold_out_products_without_the_filter(): void
    {
        $this->assertEqualsCanonicalizing(array_keys($this->products), $this->listedCases(''));
        $this->assertEqualsCanonicalizing(array_keys($this->products), $this->listedCases('?exclude_sold_out=0'));
    }

    /**
     * 조건을 주면 판매상태 품절·활성 옵션 재고 전부 0·비활성 옵션에만 재고 있는 상품이 빠진다.
     *
     * @scenario surface=public_list, exclude_sold_out=on
     *
     * @effects list_excludes_status_sold_out, list_excludes_all_active_options_empty, list_excludes_only_inactive_stock, list_keeps_partial_stock
     *
     * @return void 반환값 없음
     */
    public function test_list_excludes_sold_out_products_with_the_filter(): void
    {
        $this->assertSame(['partial_stock'], $this->listedCases('?exclude_sold_out=1'));
    }

    /**
     * 허용하지 않는 값은 422.
     *
     * @scenario surface=public_list, exclude_sold_out=invalid
     *
     * @effects list_rejects_invalid_exclude_sold_out
     *
     * @return void 반환값 없음
     */
    public function test_list_rejects_invalid_value(): void
    {
        $this->getJson(self::URL.'?exclude_sold_out=2')->assertStatus(422);
    }

    /**
     * 통합검색(상품코드 일치)도 같은 조건을 받아 결과와 건수에서 품절 상품을 뺀다.
     *
     * @scenario surface=search, exclude_sold_out=on
     *
     * @effects search_excludes_sold_out_products, search_count_excludes_sold_out_products
     *
     * @return void 반환값 없음
     */
    public function test_search_excludes_sold_out_products_with_the_filter(): void
    {
        $service = app(ProductService::class);

        $all = $service->searchByKeyword('SOLDOUT-', 'latest', null, 0, 10);
        $this->assertCount(4, $all->items());

        $filters = ['exclude_sold_out' => true];
        $filtered = $service->searchByKeyword('SOLDOUT-', 'latest', null, 0, 10, $filters);
        $this->assertSame(
            ['SOLDOUT-PARTIAL'],
            collect($filtered->items())->pluck('product_code')->all()
        );
        $this->assertSame(1, $service->countByKeyword('SOLDOUT-', null, $filters)->total);
        $this->assertSame(4, $service->countByKeyword('SOLDOUT-')->total);

        $cursorPage = $service->searchByKeywordWithCursor('SOLDOUT-', 'latest', null, 10, null, 1, $filters);
        if ($cursorPage !== null) {
            $this->assertSame(
                ['SOLDOUT-PARTIAL'],
                collect($cursorPage->items())->pluck('product_code')->all()
            );
        }
    }

    /**
     * 검색 리스너가 요청의 exclude_sold_out=1 만 조건으로 읽는다.
     *
     * @scenario surface=search, exclude_sold_out=request_param
     *
     * @effects search_listener_reads_request_param
     *
     * @return void 반환값 없음
     */
    public function test_search_listener_reads_exclude_sold_out_from_request(): void
    {
        $listener = app(SearchProductsListener::class);
        $context = fn (array $query) => [
            'q' => 'SOLDOUT-',
            'type' => 'products',
            'sort' => 'latest',
            'page' => 1,
            'per_page' => 10,
            'user' => null,
            'request' => Request::create('/api/search', 'GET', $query),
        ];

        $on = $listener->searchProducts([], $context(['exclude_sold_out' => '1']));
        $this->assertSame(['SOLDOUT-PARTIAL'], array_column($on['products']['items'], 'product_code'));
        $this->assertSame(1, $on['products']['total']);

        $off = $listener->searchProducts([], $context(['exclude_sold_out' => '2']));
        $this->assertCount(4, $off['products']['items']);
    }

    /**
     * 통합검색도 공개 목록과 같은 규칙(0 또는 1)으로 값을 검증해 잘못된 값·배열을 422 로 돌려보낸다.
     *
     * 규칙이 없으면 배열 값이 그대로 리스너까지 와서 문자열 변환 경고로 서버 오류(500)가 난다.
     *
     * @scenario surface=search, exclude_sold_out=invalid
     *
     * @effects search_rejects_invalid_exclude_sold_out
     *
     * @return void 반환값 없음
     */
    public function test_search_rejects_invalid_exclude_sold_out(): void
    {
        $this->getJson('/api/search?q=SOLDOUT-&type=products&exclude_sold_out=2')
            ->assertStatus(422)
            ->assertJsonValidationErrors('exclude_sold_out');
        $this->getJson('/api/search?q=SOLDOUT-&type=products&exclude_sold_out[]=1')
            ->assertStatus(422)
            ->assertJsonValidationErrors('exclude_sold_out');
        $this->getJson('/api/search?q=SOLDOUT-&type=products&exclude_sold_out=1')->assertOk();
    }

    /**
     * 판매상태와 옵션 재고를 지정해 전시 상품을 만듭니다.
     *
     * @param  string  $code  상품코드
     * @param  ProductSalesStatus  $status  판매상태
     * @param  array<int, array{0: int, 1: bool}>  $options  옵션별 [재고, 활성 여부]
     * @return Product 만든 상품
     */
    private function makeProduct(string $code, ProductSalesStatus $status, array $options): Product
    {
        $product = Product::factory()->onSale()->create([
            'product_code' => $code,
            'sales_status' => $status->value,
        ]);

        foreach ($options as [$stock, $active]) {
            ProductOption::factory()->create([
                'product_id' => $product->id,
                'stock_quantity' => $stock,
                'is_active' => $active,
            ]);
        }

        return $product;
    }

    /**
     * 목록 응답에 실린 상품을 경우 이름으로 돌려줍니다.
     *
     * @param  string  $query  쿼리 문자열
     * @return array<int, string> 응답에 있는 경우 이름
     */
    private function listedCases(string $query): array
    {
        $codes = array_column(
            $this->getJson(self::URL.$query)->assertOk()->json('data.data') ?? [],
            'product_code'
        );
        $byCode = [];
        foreach ($this->products as $case => $product) {
            $byCode[$product->product_code] = $case;
        }

        return array_values(array_filter(array_map(fn ($code) => $byCode[$code] ?? null, $codes)));
    }
}
