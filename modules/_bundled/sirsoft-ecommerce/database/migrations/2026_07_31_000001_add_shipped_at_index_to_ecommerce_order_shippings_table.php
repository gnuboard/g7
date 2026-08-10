<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 주문 목록 "발송일" 정렬용 복합 인덱스 추가.
 *
 * 주문 목록은 배송 테이블의 `shipped_at` 을 상관 서브쿼리로 읽어 정렬한다
 * (`SortsByRelatedColumn`). 주문 1건마다 서브쿼리가 한 번씩 실행되므로
 * `(order_id, shipped_at)` 복합 인덱스가 없으면 주문 수만큼 배송 테이블을 스캔한다.
 *
 * 기존 단일 인덱스 `order_id` 만으로는 정렬 컬럼이 인덱스에 없어 매번 행을 읽고
 * 정렬해야 한다. 선행 컬럼이 같으므로 단일 인덱스는 이 복합 인덱스로 대체된다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ecommerce_order_shippings')) {
            return;
        }

        $existingIndexes = array_column(Schema::getIndexes('ecommerce_order_shippings'), 'name');

        Schema::table('ecommerce_order_shippings', function (Blueprint $table) use ($existingIndexes) {
            if (! in_array('ecommerce_order_shippings_order_id_shipped_at_index', $existingIndexes)) {
                $table->index(['order_id', 'shipped_at'], 'ecommerce_order_shippings_order_id_shipped_at_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_order_shippings')) {
            return;
        }

        $existingIndexes = array_column(Schema::getIndexes('ecommerce_order_shippings'), 'name');

        Schema::table('ecommerce_order_shippings', function (Blueprint $table) use ($existingIndexes) {
            if (in_array('ecommerce_order_shippings_order_id_shipped_at_index', $existingIndexes)) {
                $table->dropIndex('ecommerce_order_shippings_order_id_shipped_at_index');
            }
        });
    }
};
