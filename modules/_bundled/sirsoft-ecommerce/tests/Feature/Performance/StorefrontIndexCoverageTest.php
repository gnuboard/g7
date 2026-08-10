<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Performance;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 쇼핑 진열·판매 집계 색인 회귀 테스트 (#519 F5)
 *
 * 색인은 "만들었다" 가 아니라 "쿼리가 그것을 고른다" 가 목표다. 마이그레이션만 확인하면
 * 컬럼 순서가 어긋나 옵티마이저가 무시하는 색인을 정상으로 판정한다.
 *
 * MySQL 계열이 아니면 EXPLAIN 출력 형식이 달라 판정할 수 없으므로 건너뛴다.
 *
 * @scenario storefront-index-coverage
 *
 * @effects display_sort_index_exists,
 *          sales_aggregate_index_exists,
 *          product_list_uses_display_sort_index
 */
class StorefrontIndexCoverageTest extends ModuleTestCase
{
    /**
     * MySQL 계열이 아니면 건너뜁니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('EXPLAIN 판정은 MySQL 계열에서만 수행합니다.');
        }
    }

    /**
     * 전시 조건 + 정렬을 덮는 색인이 존재하는지 확인
     *
     * @effects display_sort_index_exists
     */
    #[Test]
    public function display_sort_indexes_exist(): void
    {
        $names = array_column(Schema::getIndexes('ecommerce_products'), 'name');

        $this->assertContains('idx_products_display_created_id', $names, '등록일 정렬을 덮는 색인이 없다');
        $this->assertContains('idx_products_display_price_id', $names, '판매가 정렬을 덮는 색인이 없다');
    }

    /**
     * 판매 집계용 커버링 색인이 존재하는지 확인
     *
     * @effects sales_aggregate_index_exists
     */
    #[Test]
    public function sales_aggregate_index_exists(): void
    {
        $names = array_column(Schema::getIndexes('ecommerce_order_options'), 'name');

        $this->assertContains(
            'idx_order_options_product_created_qty',
            $names,
            '판매 집계가 테이블 본문을 읽지 않도록 하는 색인이 없다'
        );
    }

    /**
     * 색인 컬럼 순서가 조건·정렬 순서와 일치하는지 확인
     *
     * 컬럼 순서가 어긋나면 색인은 존재해도 정렬에 쓰이지 않는다.
     *
     * @effects product_list_uses_display_sort_index
     */
    #[Test]
    public function index_column_order_matches_query_shape(): void
    {
        $this->assertIndexColumns(
            'ecommerce_products',
            'idx_products_display_created_id',
            ['display_status', 'created_at', 'id']
        );

        $this->assertIndexColumns(
            'ecommerce_products',
            'idx_products_display_price_id',
            ['display_status', 'selling_price', 'id']
        );

        $this->assertIndexColumns(
            'ecommerce_order_options',
            'idx_order_options_product_created_qty',
            ['product_id', 'created_at', 'quantity']
        );
    }

    /**
     * 등록일 정렬 목록이 실제로 이 색인을 고르는지 EXPLAIN 으로 확인
     *
     * @effects product_list_uses_display_sort_index
     */
    #[Test]
    public function query_plan_uses_the_display_sort_index(): void
    {
        $this->seedProductsForPlanning();

        $query = DB::table('ecommerce_products')
            ->where('display_status', 'visible')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(10);

        $this->assertQueryPlanUsesIndex($query, 'idx_products_display_created_id');
    }

    /**
     * 판매가 정렬 목록이 실제로 이 색인을 고르는지 EXPLAIN 으로 확인
     *
     * @effects product_list_uses_price_sort_index
     */
    #[Test]
    public function query_plan_uses_the_price_sort_index(): void
    {
        $this->seedProductsForPlanning();

        $query = DB::table('ecommerce_products')
            ->where('display_status', 'visible')
            ->orderBy('selling_price', 'asc')
            ->orderBy('id', 'asc')
            ->limit(10);

        $this->assertQueryPlanUsesIndex($query, 'idx_products_display_price_id');
    }

    /**
     * 판매 집계 파생 테이블이 실제로 이 색인을 고르는지 EXPLAIN 으로 확인
     *
     * 이 색인은 `(product_id, created_at, quantity)` 커버링이라, 집계가 원본 행을 읽지
     * 않고 색인만으로 끝나야 한다.
     *
     * @effects sales_aggregate_uses_covering_index
     */
    #[Test]
    public function query_plan_uses_the_sales_aggregate_index(): void
    {
        $this->seedOrderOptionsForPlanning();

        $query = DB::table('ecommerce_order_options')
            ->select('product_id')
            ->selectRaw('SUM(quantity) as total_sold')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('product_id');

        $this->assertQueryPlanUsesIndex($query, 'idx_order_options_product_created_qty');
    }

    /**
     * 옵티마이저가 실제로 그 색인을 **선택**했는지 단언합니다.
     *
     * `possible_keys` 는 "쓸 수 있었다" 일 뿐이다. 후보에만 올라 있고 실제로는 전체
     * 스캔을 골라도 통과해 버리므로, 판정은 실행계획이 확정한 `key` 로만 한다.
     *
     * @param  Builder  $query  판정 대상 쿼리
     * @param  string  $indexName  선택되어야 하는 색인명
     */
    private function assertQueryPlanUsesIndex($query, string $indexName): void
    {
        $plan = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings());

        $this->assertNotEmpty($plan, 'EXPLAIN 결과가 비어 있다');

        $row = (array) $plan[0];
        $chosen = (string) ($row['key'] ?? '');

        $this->assertStringContainsString(
            $indexName,
            $chosen,
            "옵티마이저가 {$indexName} 을 고르지 않았다 (선택: ".($chosen !== '' ? $chosen : '없음(전체 스캔)').
            ', 후보: '.($row['possible_keys'] ?? '없음').') — 컬럼 순서나 조건 형태가 어긋났다'
        );
    }

    /**
     * 실행계획이 색인을 고를 만큼 상품 행을 채웁니다.
     *
     * 행이 몇 건뿐이면 전체 스캔이 정상 선택이라 `key` 단언이 성립하지 않는다.
     */
    private function seedProductsForPlanning(): void
    {
        $rows = [];

        for ($i = 0; $i < 300; $i++) {
            $rows[] = [
                'product_code' => 'PLAN-'.$i,
                'name' => json_encode(['ko' => '계획 '.$i], JSON_UNESCAPED_UNICODE),
                'selling_price' => 1000 + $i,
                'stock_quantity' => 10,
                'sales_status' => 'on_sale',
                'display_status' => $i % 10 === 0 ? 'hidden' : 'visible',
                'created_at' => now()->subMinutes($i),
                'updated_at' => now(),
            ];
        }

        DB::table('ecommerce_products')->insert($rows);
        DB::statement('ANALYZE TABLE '.DB::getTablePrefix().'ecommerce_products');
    }

    /**
     * 실행계획이 색인을 고를 만큼 주문 옵션 행을 채웁니다.
     *
     * order_id / product_id 에 외래키가 걸려 있어 실제 부모 행이 먼저 있어야 한다.
     */
    private function seedOrderOptionsForPlanning(): void
    {
        // 컬럼이 많고 필수 항목이 대부분이라 팩토리로 한 건을 만들어 형태를 얻은 뒤,
        // 색인 판정에 필요한 축(product_id / created_at / quantity)만 바꿔 복제한다.
        $seed = OrderOption::factory()->create();
        $template = $seed->getAttributes();
        unset($template['id']);

        $productIds = DB::table('ecommerce_products')->limit(30)->pluck('id')->all();

        if (empty($productIds)) {
            $this->seedProductsForPlanning();
            $productIds = DB::table('ecommerce_products')->limit(30)->pluck('id')->all();
        }

        $rows = [];

        for ($i = 0; $i < 300; $i++) {
            $rows[] = array_merge($template, [
                // 상품 FK 는 실제 존재하는 행이어야 한다
                'product_id' => $productIds[$i % count($productIds)],
                'quantity' => ($i % 5) + 1,
                'created_at' => now()->subDays($i % 40),
                'updated_at' => now(),
            ]);
        }

        DB::table('ecommerce_order_options')->insert($rows);
        DB::statement('ANALYZE TABLE '.DB::getTablePrefix().'ecommerce_order_options');
    }

    /**
     * 색인의 컬럼 구성을 단언합니다.
     *
     * @param  string  $table  테이블명 (프리픽스 제외)
     * @param  string  $indexName  색인명
     * @param  array<int, string>  $expected  기대 컬럼 순서
     */
    private function assertIndexColumns(string $table, string $indexName, array $expected): void
    {
        $index = collect(Schema::getIndexes($table))->firstWhere('name', $indexName);

        $this->assertNotNull($index, "색인이 없다: {$indexName}");
        $this->assertSame(
            $expected,
            array_values($index['columns']),
            "색인 컬럼 순서가 조건·정렬 순서와 다르다: {$indexName}"
        );
    }
}
