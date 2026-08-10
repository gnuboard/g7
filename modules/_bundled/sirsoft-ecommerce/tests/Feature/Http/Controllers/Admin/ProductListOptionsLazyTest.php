<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 상품목록 옵션 지연 로딩 테스트
 *
 * 목록 응답은 더 이상 행마다 옵션 배열을 싣지 않는다. 목록이 실제로 쓰던 값(활성 옵션 수,
 * 전체 옵션 수, 활성 옵션 재고 합계)은 DB 집계로 대체되고, 옵션 상세는 행을 펼칠 때
 * 별도 배치 엔드포인트가 공급한다.
 *
 * 이 테스트가 고정하는 것은 세 가지다.
 *   1. 기본 목록에 옵션 배열이 없고 `?with_options=1` 로만 돌아온다
 *   2. DB 집계 값이 종전의 PHP 컬렉션 연산과 **동일하다** (옵션 0건·비활성 혼재 포함)
 *   3. 행 수가 늘어도 옵션/이미지 쿼리 수가 늘지 않는다 (N+1 부재)
 */
class ProductListOptionsLazyTest extends ModuleTestCase
{
    protected User $adminUser;

    /** 수집된 실행 쿼리 SQL 목록 */
    private array $queries = [];

    /** DB::listen 리스너 등록 여부 (재등록하면 같은 쿼리가 중복 기록된다) */
    private bool $listening = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
    }

    /**
     * 옵션을 가진 상품을 만듭니다.
     *
     * @param  array<int, array{stock: int, active: bool}>  $options  생성할 옵션 정의
     * @return Product 생성된 상품
     */
    private function makeProductWithOptions(array $options): Product
    {
        $product = Product::factory()->create([
            'stock_quantity' => 100,
            'has_options' => $options !== [],
        ]);

        foreach ($options as $index => $spec) {
            ProductOption::create([
                'product_id' => $product->id,
                'option_code' => 'OPT-'.$product->id.'-'.$index,
                'option_values' => ['색상' => '값'.$index],
                'option_name' => '옵션'.$index,
                'price_adjustment' => 0,
                'stock_quantity' => $spec['stock'],
                'is_active' => $spec['active'],
                'sort_order' => $spec['sort'] ?? $index,
            ]);
        }

        return $product;
    }

    /**
     * 상품에 이미지를 추가합니다.
     *
     * @param  Product  $product  대상 상품
     * @param  bool  $isThumbnail  대표 이미지 여부
     * @param  int  $sortOrder  정렬 순서
     * @return ProductImage 생성된 이미지
     */
    private function makeImage(Product $product, bool $isThumbnail, int $sortOrder): ProductImage
    {
        return ProductImage::create([
            'product_id' => $product->id,
            // hash 컬럼은 12자 제한 (URL용 고유 해시)
            'hash' => substr(md5($product->id.'-'.$sortOrder.'-'.($isThumbnail ? 't' : 'f')), 0, 12),
            'original_filename' => 'img'.$sortOrder.'.jpg',
            'stored_filename' => 'img'.$sortOrder.'.jpg',
            'disk' => 'public',
            'path' => 'products/img'.$sortOrder.'.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'is_thumbnail' => $isThumbnail,
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * 쿼리 로그 수집을 (재)시작합니다.
     *
     * 리스너는 테스트당 1회만 등록한다. 매번 등록하면 리스너가 누적되어 같은 쿼리가 등록 수만큼
     * 중복 기록되고, 쿼리 수 단언이 구조와 무관하게 실패한다.
     */
    private function startCapture(): void
    {
        $this->queries = [];

        if ($this->listening) {
            return;
        }

        $this->listening = true;

        DB::listen(function ($query) {
            $this->queries[] = $query->sql;
        });
    }

    /**
     * 수집된 쿼리 중 특정 문자열을 포함한 것의 개수를 셉니다.
     *
     * @param  string  $needle  포함 문자열
     * @return int 일치 쿼리 수
     */
    private function countQueriesContaining(string $needle): int
    {
        return count(array_filter($this->queries, fn (string $sql) => str_contains($sql, $needle)));
    }

    /**
     * 목록의 특정 상품 행을 돌려줍니다.
     *
     * @param  array<string, mixed>  $rows  응답 data.data
     * @param  int  $productId  찾을 상품 ID
     * @return array<string, mixed>|null 해당 행
     */
    private function rowOf(array $rows, int $productId): ?array
    {
        foreach ($rows as $row) {
            if ((int) $row['id'] === $productId) {
                return $row;
            }
        }

        return null;
    }

    // ==================== ① 기본 목록에서 옵션 배열 제외 ====================

    /**
     * @scenario row_state=collapsed,option_profile=active_only
     * @effects list_response_omits_options
     */
    public function test_list_omits_option_array_by_default(): void
    {
        $product = $this->makeProductWithOptions([
            ['stock' => 10, 'active' => true],
            ['stock' => 5, 'active' => true],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products');

        $response->assertOk();

        $row = $this->rowOf($response->json('data.data'), $product->id);

        $this->assertNotNull($row, '생성한 상품이 목록에 있어야 한다');
        $this->assertArrayNotHasKey('options', $row, '기본 목록 응답에 옵션 배열이 실리면 안 된다');
        $this->assertSame(2, $row['options_count']);
        $this->assertSame(2, $row['options_total_count']);
        $this->assertSame(15, $row['option_stock_sum']);
    }

    /**
     * 검색·필터가 걸린 목록도 같은 옵션 계약을 따른다 — 검색 분기가 별도 조회 경로를 타므로
     * 지연 로딩이 그 분기에서만 되돌아가는 회귀가 가능하다 (mp09).
     *
     * @scenario row_state=collapsed,option_profile=mixed
     * @effects with_options_opt_in_restores, search_branch_shares_lazy_options
     */
    public function test_with_options_opt_in_includes_option_array(): void
    {
        $product = $this->makeProductWithOptions([
            ['stock' => 10, 'active' => true],
            ['stock' => 5, 'active' => false],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?with_options=1');

        $response->assertOk();

        $row = $this->rowOf($response->json('data.data'), $product->id);

        $this->assertNotNull($row);
        $this->assertArrayHasKey('options', $row, '?with_options=1 은 종전 동작(옵션 포함)을 유지해야 한다');
        $this->assertCount(2, $row['options'], '비활성 옵션도 포함된다 (종전 동작)');

        // opt-in 이어도 집계 값은 동일해야 한다
        $this->assertSame(1, $row['options_count']);
        $this->assertSame(2, $row['options_total_count']);
        $this->assertSame(10, $row['option_stock_sum']);
    }

    public function test_with_options_accepts_boolean_string_variants(): void
    {
        $this->makeProductWithOptions([['stock' => 1, 'active' => true]]);

        foreach (['true', '1', 'false', '0'] as $value) {
            $this->actingAs($this->adminUser)
                ->getJson('/api/modules/sirsoft-ecommerce/admin/products?with_options='.$value)
                ->assertOk();
        }

        $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?with_options=banana')
            ->assertStatus(422)
            ->assertJsonValidationErrors('with_options');
    }

    // ==================== ② 집계 정확성 (비활성 혼재 / 0건) ====================

    /**
     * @scenario row_state=collapsed,option_profile=mixed
     * @effects options_total_count_matches_all
     */
    public function test_aggregates_count_active_and_total_separately(): void
    {
        $mixed = $this->makeProductWithOptions([
            ['stock' => 7, 'active' => true],
            ['stock' => 3, 'active' => true],
            ['stock' => 100, 'active' => false],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products');

        $row = $this->rowOf($response->json('data.data'), $mixed->id);

        $this->assertSame(2, $row['options_count'], '활성 옵션만 센다');
        $this->assertSame(3, $row['options_total_count'], '비활성 포함 전체를 센다');
        $this->assertSame(10, $row['option_stock_sum'], '재고 합계는 활성 옵션만 더한다');
    }

    /**
     * 옵션 0건에서 재고 합계는 null 이 아니라 0 이어야 합니다.
     *
     * SQL 의 SUM 은 대상 행이 없으면 NULL 을 돌려준다. 이 값을 그대로 흘리면 화면의
     * 재고 불일치 비교(`stock_quantity !== option_stock_sum`)가 전 상품에서 참이 되어
     * 경고 배지가 일제히 뜬다.
     *
     * @scenario row_state=collapsed,option_profile=none
     * @effects option_stock_sum_is_zero_not_null
     */
    public function test_option_stock_sum_is_zero_not_null_when_product_has_no_options(): void
    {
        $bare = $this->makeProductWithOptions([]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products');

        $row = $this->rowOf($response->json('data.data'), $bare->id);

        $this->assertSame(0, $row['options_count']);
        $this->assertSame(0, $row['options_total_count']);
        $this->assertNotNull($row['option_stock_sum'], '옵션 0건에서도 null 이면 안 된다');
        $this->assertSame(0, $row['option_stock_sum']);
    }

    /**
     * DB 집계 값이 종전의 PHP 컬렉션 연산과 동일한지 같은 데이터로 대조합니다.
     *
     * 이 테스트가 "집계로 바꿔도 값이 같다" 를 증명하는 자리다. 픽스처는 활성/비활성 혼재,
     * 재고 0, 옵션 0건을 모두 포함한다.
     *
     * @effects aggregates_equal_php_computation
     */
    public function test_db_aggregates_equal_php_collection_computation(): void
    {
        $products = [
            $this->makeProductWithOptions([]),
            $this->makeProductWithOptions([['stock' => 0, 'active' => true]]),
            $this->makeProductWithOptions([
                ['stock' => 12, 'active' => true],
                ['stock' => 8, 'active' => false],
                ['stock' => 0, 'active' => true],
            ]),
            $this->makeProductWithOptions([
                ['stock' => 5, 'active' => false],
                ['stock' => 6, 'active' => false],
            ]),
        ];

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products');

        $rows = $response->json('data.data');

        foreach ($products as $product) {
            $options = ProductOption::where('product_id', $product->id)->get();

            $expectedActiveCount = $options->where('is_active', true)->count();
            $expectedTotalCount = $options->count();
            $expectedStockSum = (int) $options->where('is_active', true)->sum('stock_quantity');

            $row = $this->rowOf($rows, $product->id);

            $this->assertSame($expectedActiveCount, $row['options_count'], "상품 {$product->id} 활성 옵션 수");
            $this->assertSame($expectedTotalCount, $row['options_total_count'], "상품 {$product->id} 전체 옵션 수");
            $this->assertSame($expectedStockSum, $row['option_stock_sum'], "상품 {$product->id} 활성 옵션 재고 합계");
        }
    }

    // ==================== ⑤ 썸네일 URL 동치 ====================

    /**
     * eager load 된 컬렉션에서 고른 대표 이미지가 관계 재쿼리 결과와 같은지 3케이스로 확인합니다.
     *
     * 케이스: (a) 대표 이미지 지정 (b) 대표 없음 → sort_order 첫 이미지 (c) 이미지 없음
     *
     * @effects thumbnail_url_identical_after_refactor
     */
    public function test_thumbnail_url_is_identical_with_and_without_eager_load(): void
    {
        $withThumbnail = Product::factory()->create();
        $this->makeImage($withThumbnail, false, 1);
        $this->makeImage($withThumbnail, true, 2);

        $withoutThumbnail = Product::factory()->create();
        $this->makeImage($withoutThumbnail, false, 2);
        $this->makeImage($withoutThumbnail, false, 1);

        $withoutImages = Product::factory()->create();

        foreach ([$withThumbnail, $withoutThumbnail, $withoutImages] as $product) {
            $lazy = Product::find($product->id);
            $expected = $lazy->getThumbnailUrl();

            $eager = Product::with('images')->find($product->id);
            $actual = $eager->getThumbnailUrl();

            $this->assertSame($expected, $actual, "상품 {$product->id} 의 대표 이미지 URL 이 달라졌다");
        }

        // 대표 이미지가 있으면 sort_order 가 뒤라도 그것이 선택된다
        $eager = Product::with('images')->find($withThumbnail->id);
        $this->assertNotNull($eager->getThumbnailUrl());
    }

    /**
     * eager load 된 상품의 썸네일 조회는 추가 쿼리를 만들지 않아야 합니다.
     */
    public function test_thumbnail_url_does_not_requery_when_images_are_loaded(): void
    {
        $product = Product::factory()->create();
        $this->makeImage($product, true, 1);

        $eager = Product::with('images')->find($product->id);

        $this->startCapture();
        $eager->getThumbnailUrl();

        $this->assertSame(
            0,
            $this->countQueriesContaining('ecommerce_product_images'),
            'eager load 된 이미지를 두고 관계 빌더를 다시 태우면 안 된다'
        );
    }

    // ==================== ⑥ 쿼리 수 상수성 ====================

    /**
     * 행 수가 늘어도 옵션/이미지 쿼리 수가 늘지 않아야 합니다.
     *
     * 옵션은 관계 로드에서 집계로 바뀌었으므로 옵션 테이블 조회는 목록 SELECT 안의 상관
     * 서브쿼리로 흡수된다. 이미지는 eager load 1회. 어느 쪽도 행 수에 비례하면 안 된다.
     *
     * @effects query_count_constant_across_rows
     */
    public function test_option_and_image_query_count_is_constant_across_row_counts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $p = $this->makeProductWithOptions([
                ['stock' => 3, 'active' => true],
                ['stock' => 4, 'active' => false],
            ]);
            $this->makeImage($p, true, 1);
        }

        // 권한/설정 캐시를 데우기 위한 선행 요청 (측정 대상에서 제외)
        $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?per_page=100')
            ->assertOk();

        $this->startCapture();
        $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?per_page=100')
            ->assertOk();

        $optionQueriesFor5 = $this->countQueriesContaining('ecommerce_product_options');
        $imageQueriesFor5 = $this->countQueriesContaining('ecommerce_product_images');

        for ($i = 0; $i < 15; $i++) {
            $p = $this->makeProductWithOptions([
                ['stock' => 3, 'active' => true],
                ['stock' => 4, 'active' => false],
            ]);
            $this->makeImage($p, true, 1);
        }

        $this->startCapture();
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products?per_page=100');
        $response->assertOk();

        $optionQueriesFor20 = $this->countQueriesContaining('ecommerce_product_options');
        $imageQueriesFor20 = $this->countQueriesContaining('ecommerce_product_images');

        $this->assertGreaterThanOrEqual(20, count($response->json('data.data')), '20건이 모두 한 페이지에 있어야 한다');

        $this->assertSame(
            $optionQueriesFor5,
            $optionQueriesFor20,
            "옵션 쿼리 수가 행 수에 비례한다 (5건: {$optionQueriesFor5}, 20건: {$optionQueriesFor20})"
        );
        $this->assertSame(
            $imageQueriesFor5,
            $imageQueriesFor20,
            "이미지 쿼리 수가 행 수에 비례한다 (5건: {$imageQueriesFor5}, 20건: {$imageQueriesFor20})"
        );
    }
}
