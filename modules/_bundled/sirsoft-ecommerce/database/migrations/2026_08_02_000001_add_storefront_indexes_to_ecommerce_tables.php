<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 쇼핑 진열·판매 집계가 쓰는 색인을 추가합니다.
 *
 * 공개 상품 목록은 `display_status = 'visible'` 로 좁힌 뒤 등록일 또는 판매가로 정렬한다.
 * 기존 색인은 `display_status` 단독과 `sales_status, display_status` 조합뿐이라, 전시 조건을
 * 만족하는 행을 고른 뒤 정렬을 다시 해야 했다. 정렬까지 덮는 색인을 만들면 그 단계가 사라진다.
 *
 * 판매량 집계는 `ecommerce_order_options` 를 `product_id` 로 묶어 `quantity` 를 더하고,
 * 인기 상품은 여기에 기간 조건이 붙는다. 기존 색인은 `product_id` 단독이라 묶는 것까지만
 * 도왔다. `(product_id, created_at, quantity)` 는 집계에 필요한 세 컬럼을 모두 담아 테이블
 * 본문을 읽지 않고 색인만으로 끝난다.
 *
 * 정렬 색인 마지막에 `id` 를 덧붙이는 이유는 전순서 보장이다. 등록일·판매가는 값이 겹칠 수
 * 있어, 동률 구간에서 순서가 흔들리면 인접 페이지가 같은 행을 중복 노출하고 다른 행을 빠뜨린다.
 *
 * 기존 사이트에는 마이그레이션이 재실행되지 않으므로 같은 색인을 업그레이드 스텝에서도 적용한다.
 */
return new class extends Migration
{
    /**
     * 대상 [색인명 => [테이블, 컬럼]]
     *
     * @var array<string, array{0: string, 1: array<int, string>}>
     */
    private const TARGETS = [
        'idx_products_display_created_id' => ['ecommerce_products', ['display_status', 'created_at', 'id']],
        'idx_products_display_price_id' => ['ecommerce_products', ['display_status', 'selling_price', 'id']],
        'idx_order_options_product_created_qty' => ['ecommerce_order_options', ['product_id', 'created_at', 'quantity']],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TARGETS as $indexName => [$table, $columns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = array_column(Schema::getIndexes($table), 'name');

            if (in_array($indexName, $existing, true)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
                $blueprint->index($columns, $indexName);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TARGETS as $indexName => [$table, $columns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = array_column(Schema::getIndexes($table), 'name');

            if (! in_array($indexName, $existing, true)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->dropIndex($indexName);
            });
        }
    }
};
