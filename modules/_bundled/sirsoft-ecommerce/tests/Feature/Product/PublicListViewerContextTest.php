<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Product;

use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Ecommerce\Listeners\SearchProductsListener;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOption;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 공개 상품 목록 응답 보강(카드 단추용)과 관련·최근 본 상품 확장 지점 테스트
 *
 * 카드의 찜·장바구니 단추가 쓰는 값(찜 여부·활성 옵션 수·추가옵션 있음)을 목록 응답에 싣는다.
 * 값은 응답당 정해진 수의 쿼리로 붙이고, 상품 수에 따라 늘지 않는다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/public-list-viewer-context.yaml
 */
class PublicListViewerContextTest extends ModuleTestCase
{
    private const BASE = '/api/modules/sirsoft-ecommerce/products';

    private User $member;

    /**
     * 공개 상품 조회 권한과 회원을 준비합니다.
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

        $this->member = $this->createUser();
    }

    /**
     * 활성 옵션 수·추가옵션을 지정해 전시 상품을 만듭니다.
     *
     * @param  int  $activeOptions  활성 옵션 수
     * @param  int  $inactiveOptions  비활성 옵션 수
     * @param  bool  $withAdditionalOption  추가옵션 그룹을 붙일지
     * @return Product 만든 상품
     */
    private function makeProduct(int $activeOptions = 1, int $inactiveOptions = 0, bool $withAdditionalOption = false): Product
    {
        $product = Product::factory()->onSale()->create();
        ProductOption::factory()->count($activeOptions)->create(['product_id' => $product->id, 'is_active' => true, 'stock_quantity' => 5]);
        if ($inactiveOptions > 0) {
            ProductOption::factory()->count($inactiveOptions)->create(['product_id' => $product->id, 'is_active' => false]);
        }
        if ($withAdditionalOption) {
            ProductAdditionalOption::create([
                'product_id' => $product->id,
                'name' => ['ko' => '각인', 'en' => 'Engraving'],
                'is_required' => false,
                'sort_order' => 0,
            ]);
        }

        return $product;
    }

    /**
     * 목록 응답의 행을 상품 ID 로 찾습니다.
     *
     * @param  array<int, array<string, mixed>>  $rows  응답 행
     * @param  Product  $product  찾을 상품
     * @return array<string, mixed> 그 상품의 행
     */
    private function rowOf(array $rows, Product $product): array
    {
        return collect($rows)->firstWhere('id', $product->id) ?? [];
    }

    /**
     * 회원에게는 찜한 상품만 true, 활성 옵션 수와 추가옵션 유무가 실린다.
     *
     * @scenario endpoint=index, viewer=member
     *
     * @effects list_marks_wishlisted_products_for_member, list_counts_active_options_only, list_flags_additional_options
     *
     * @return void 반환값 없음
     */
    public function test_member_list_carries_wishlist_and_option_flags(): void
    {
        $wished = $this->makeProduct(2, 1);
        $plain = $this->makeProduct(1, 0, true);
        ProductWishlist::create(['user_id' => $this->member->id, 'product_id' => $wished->id]);
        Sanctum::actingAs($this->member);

        $rows = $this->getJson(self::BASE)->assertOk()->json('data.data');

        $this->assertTrue($this->rowOf($rows, $wished)['is_wishlisted']);
        $this->assertSame(2, $this->rowOf($rows, $wished)['options_count']);
        $this->assertFalse($this->rowOf($rows, $wished)['has_additional_options']);

        $this->assertFalse($this->rowOf($rows, $plain)['is_wishlisted']);
        $this->assertSame(1, $this->rowOf($rows, $plain)['options_count']);
        $this->assertTrue($this->rowOf($rows, $plain)['has_additional_options']);
    }

    /**
     * 비회원에게는 찜 여부가 false 로 실리고 찜 표를 조회하지 않는다.
     *
     * @scenario endpoint=index, viewer=guest
     *
     * @effects list_wishlist_false_for_guest, guest_list_never_queries_wishlist
     *
     * @return void 반환값 없음
     */
    public function test_guest_list_has_false_wishlist_without_wishlist_query(): void
    {
        $product = $this->makeProduct();
        $wishlistTable = (new ProductWishlist)->getTable();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $this->getJson(self::BASE)->assertOk()->json('data.data');
        $wishlistQueries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], $wishlistTable));
        DB::disableQueryLog();

        $this->assertFalse($this->rowOf($rows, $product)['is_wishlisted']);
        $this->assertCount(0, $wishlistQueries);
    }

    /**
     * 인기·신상품·최근 본·관련 상품 응답에도 같은 값이 실린다.
     *
     * @scenario endpoint=popular_new_recent_related, viewer=member
     *
     * @effects other_public_lists_carry_the_same_flags
     *
     * @return void 반환값 없음
     */
    public function test_other_public_lists_carry_the_same_flags(): void
    {
        $product = $this->makeProduct(2, 0, true);
        ProductWishlist::create(['user_id' => $this->member->id, 'product_id' => $product->id]);
        Sanctum::actingAs($this->member);

        foreach ([self::BASE.'/popular', self::BASE.'/new', self::BASE.'/recent?ids='.$product->id] as $url) {
            $row = $this->rowOf($this->getJson($url)->assertOk()->json('data'), $product);
            $this->assertTrue($row['is_wishlisted'] ?? null, $url);
            $this->assertSame(2, $row['options_count'] ?? null, $url);
            $this->assertTrue($row['has_additional_options'] ?? null, $url);
        }
    }

    /**
     * 상품이 늘어도 목록 쿼리 수가 같다 (찜·옵션 수·추가옵션 집계가 행마다 묻지 않는다).
     *
     * @scenario endpoint=index, viewer=member_query_count
     *
     * @effects list_viewer_context_adds_no_per_row_query
     *
     * @return void 반환값 없음
     */
    public function test_viewer_context_adds_no_per_row_queries(): void
    {
        Sanctum::actingAs($this->member);

        $count = function (int $add): int {
            for ($i = 0; $i < $add; $i++) {
                $product = $this->makeProduct(2, 0, true);
                ProductWishlist::create(['user_id' => $this->member->id, 'product_id' => $product->id]);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::BASE)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $count(1);
        $withTwo = $count(1);
        $withSix = $count(4);

        $this->assertSame($withTwo, $withSix, '상품이 늘어도 목록 쿼리 수는 같아야 한다');
    }

    /**
     * 관련 상품 응답에도 같은 값이 실린다 — 결과 필터 훅이 끼워 넣은 상품까지 포함해서다(값은 훅 뒤에 붙인다).
     *
     * @scenario endpoint=related, viewer=member
     *
     * @effects related_list_carries_flags_after_result_hook
     *
     * @return void 반환값 없음
     */
    public function test_related_list_carries_flags_for_products_added_by_the_result_hook(): void
    {
        $base = $this->makeProduct();
        $injected = $this->makeProduct(2, 0, true);
        ProductWishlist::create(['user_id' => $this->member->id, 'product_id' => $injected->id]);
        Sanctum::actingAs($this->member);

        HookManager::addFilter(
            'sirsoft-ecommerce.product.filter_related_list_result',
            fn ($products) => $products->push($injected->fresh())->unique('id')->values()
        );

        $rows = $this->getJson(self::BASE.'/'.$base->product_code.'/related')->assertOk()->json('data');
        $row = $this->rowOf($rows, $injected);

        $this->assertTrue($row['is_wishlisted'] ?? null);
        $this->assertSame(2, $row['options_count'] ?? null);
        $this->assertTrue($row['has_additional_options'] ?? null);
    }

    /**
     * 상품 목록의 판매순·후기순 정렬과 인기·신상품·최근 본·관련 상품도 상품이 늘어도 쿼리 수가 같다.
     *
     * @scenario endpoint=popular_new_recent_related, viewer=member_query_count
     *
     * @effects other_lists_add_no_per_row_query
     *
     * @return void 반환값 없음
     */
    public function test_other_public_lists_add_no_per_row_queries(): void
    {
        Sanctum::actingAs($this->member);
        $ids = [];
        $add = function (int $count) use (&$ids): void {
            for ($i = 0; $i < $count; $i++) {
                $product = $this->makeProduct(2, 0, true);
                ProductWishlist::create(['user_id' => $this->member->id, 'product_id' => $product->id]);
                $ids[] = $product->id;
            }
        };
        $measure = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($url)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        // 관련 상품은 선정 기준(분류·캐시)과 무관하게, 결과 훅이 만든 상품 전부를 돌려주게 해 붙이는 값의 쿼리 수만 잰다
        $base = Product::factory()->onSale()->create();
        HookManager::addFilter(
            'sirsoft-ecommerce.product.filter_related_list_result',
            // 화살표 함수는 만들 때 값을 붙잡는다 — 늘어나는 상품 목록을 보려면 참조로 받는다
            // 실제 관련 상품 경로와 같은 저장소 조회(목록 응답이 읽는 관계를 미리 읽음)로 돌려준다
            function () use (&$ids) {
                return app(ProductRepositoryInterface::class)->findByIds($ids);
            }
        );
        $relatedCount = fn (): int => count($this->getJson(self::BASE.'/'.$base->product_code.'/related')->assertOk()->json('data') ?? []);
        $urls = fn (): array => [
            'index_sales' => self::BASE.'?sort=sales',
            'index_reviews' => self::BASE.'?sort=reviews',
            'popular' => self::BASE.'/popular',
            'new' => self::BASE.'/new',
            'recent' => self::BASE.'/recent?ids='.implode(',', $ids),
            'related' => self::BASE.'/'.$base->product_code.'/related',
        ];

        $add(2);
        // 첫 호출은 권한·설정 캐시를 데운다 — 재지 않는다
        foreach ($urls() as $url) {
            $measure($url);
        }
        $few = array_map($measure, $urls());
        $add(4);
        $many = array_map($measure, $urls());

        $this->assertSame($few, $many, '상품이 늘어도 목록별 쿼리 수는 같아야 한다');
        // 관련 상품이 실제로 늘어난 채 쟀는지(빈 목록을 두 번 잰 것이 아님)
        $this->assertSame(6, $relatedCount());

        HookManager::clearFilter('sirsoft-ecommerce.product.filter_related_list_result');
    }

    /**
     * 관리자 상품 목록에는 보는 사람 값(찜 여부·추가옵션 유무)이 실리지 않는다 — 공개 목록에서만 붙인다.
     *
     * @scenario endpoint=admin_index, viewer=admin
     *
     * @effects admin_list_has_no_viewer_context_keys
     *
     * @return void 반환값 없음
     */
    public function test_admin_list_has_no_viewer_context_keys(): void
    {
        $product = $this->makeProduct(2, 0, true);
        $admin = $this->createAdminUser(['sirsoft-ecommerce.products.read']);

        $rows = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products')
            ->assertOk()
            ->json('data.data');
        $row = $this->rowOf($rows, $product);

        $this->assertNotSame([], $row);
        $this->assertArrayNotHasKey('is_wishlisted', $row);
        $this->assertArrayNotHasKey('has_additional_options', $row);
    }

    /**
     * 관련 상품·최근 본 상품 조회가 before·결과 필터·after 훅을 발화하고, 결과 필터가 응답을 바꾼다.
     *
     * @scenario endpoint=popular_new_recent_related, viewer=extension_hooks
     *
     * @effects related_list_fires_three_hooks, recent_list_fires_three_hooks, result_filter_changes_response, related_validation_rules_are_filterable
     *
     * @return void 반환값 없음
     */
    public function test_related_and_recent_lists_expose_extension_hooks(): void
    {
        $base = $this->makeProduct();
        $extra = $this->makeProduct();
        $fired = [];
        $args = [];

        // 인자를 읽을 수 있는 모양으로 적는다 — 상품은 번호, 컬렉션은 번호 목록
        $describe = fn ($value) => match (true) {
            $value instanceof Product => 'product:'.$value->id,
            $value instanceof Collection => 'ids:'.$value->pluck('id')->sort()->implode(','),
            is_array($value) => 'ids:'.collect($value)->map(fn ($v) => (int) $v)->sort()->implode(','),
            default => get_debug_type($value),
        };

        foreach (['related', 'recent'] as $kind) {
            HookManager::addAction("sirsoft-ecommerce.product.before_{$kind}_list", function (...$given) use (&$fired, &$args, $kind, $describe) {
                $fired[] = "before_{$kind}";
                $args["before_{$kind}"] = array_map($describe, $given);
            });
            HookManager::addAction("sirsoft-ecommerce.product.after_{$kind}_list", function (...$given) use (&$fired, &$args, $kind, $describe) {
                $fired[] = "after_{$kind}";
                $args["after_{$kind}"] = array_map($describe, $given);
            });
            HookManager::addFilter("sirsoft-ecommerce.product.filter_{$kind}_list_result", function ($products, ...$rest) use (&$fired, &$args, $kind, $extra, $describe) {
                $fired[] = "filter_{$kind}";
                $args["filter_{$kind}"] = array_map($describe, [$products, ...$rest]);

                return $products->filter(fn ($p) => $p->id !== $extra->id)->values();
            });
        }

        $recent = $this->getJson(self::BASE.'/recent?ids='.$base->id.','.$extra->id)->assertOk()->json('data');
        $this->assertSame([$base->id], array_column($recent, 'id'));

        $this->getJson(self::BASE.'/'.$base->product_code.'/related')->assertOk();

        $this->assertSame(
            ['before_recent', 'filter_recent', 'after_recent', 'before_related', 'filter_related', 'after_related'],
            $fired
        );

        // 기대 인자 — 최근 본: 요청 번호 / (결과, 요청 번호) / (훅이 바꾼 결과, 요청 번호),
        // 관련: 기준 상품 / (결과, 기준 상품) / (훅이 바꾼 결과, 기준 상품)
        $requested = 'ids:'.collect([$base->id, $extra->id])->sort()->implode(',');
        $this->assertSame([$requested], $args['before_recent']);
        $this->assertSame(['ids:'.collect([$base->id, $extra->id])->sort()->implode(','), $requested], $args['filter_recent']);
        $this->assertSame(['ids:'.$base->id, $requested], $args['after_recent']);
        $this->assertSame(['product:'.$base->id], $args['before_related']);
        $this->assertSame('product:'.$base->id, $args['filter_related'][1] ?? null);
        $this->assertStringStartsWith('ids:', $args['filter_related'][0]);
        $this->assertSame('product:'.$base->id, $args['after_related'][1] ?? null);
        // after 는 결과 필터가 바꾼 뒤의 결과를 받는다(뺀 상품이 없다)
        $this->assertNotContains((string) $extra->id, explode(',', substr($args['after_related'][0], strlen('ids:'))));

        HookManager::addFilter(
            'sirsoft-ecommerce.product.related_validation_rules',
            fn (array $rules) => $rules + ['brand' => ['nullable', 'integer']]
        );
        $this->getJson(self::BASE.'/'.$base->product_code.'/related?brand=abc')->assertStatus(422);
    }

    /**
     * 통합검색 상품 결과에도 카드가 읽는 값(대표 분류·옵션 수·찜·추가옵션)이 실린다.
     *
     * @scenario endpoint=search, viewer=guest
     *
     * @effects search_result_carries_card_fields
     *
     * @return void 반환값 없음
     */
    public function test_search_result_carries_card_fields(): void
    {
        $product = $this->makeProduct(2, 0, true);
        $product->update(['product_code' => 'CARDFIELD-001']);

        $result = app(SearchProductsListener::class)->searchProducts([], [
            'q' => 'CARDFIELD-',
            'type' => 'products',
            'sort' => 'latest',
            'page' => 1,
            'per_page' => 10,
            'user' => null,
            'request' => Request::create('/api/search', 'GET'),
        ]);

        $row = $result['products']['items'][0];
        $this->assertSame(2, $row['options_count']);
        $this->assertTrue($row['has_additional_options']);
        $this->assertFalse($row['is_wishlisted']);
        $this->assertArrayHasKey('has_options', $row);
        $this->assertArrayHasKey('min_purchase_qty', $row);
        $this->assertArrayHasKey('primary_category', $row);
    }
}
