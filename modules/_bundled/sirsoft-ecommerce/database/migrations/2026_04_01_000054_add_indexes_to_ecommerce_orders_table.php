<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ecommerce_orders 테이블 인덱스 추가.
 *
 * - total_amount: 금액 범위 필터
 * - order_device: PC/모바일/앱 필터
 * - confirmed_at: 구매확정일 필터
 * - [user_id, ordered_at]: 사용자 주문 이력 조회
 */
return new class extends Migration
{
    public function up(): void
    {
        $existingIndexes = array_column(Schema::getIndexes('ecommerce_orders'), 'name');

        Schema::table('ecommerce_orders', function (Blueprint $table) use ($existingIndexes) {
            $indexes = [
                'total_amount' => 'idx_ecommerce_orders_total_amount',
                'order_device' => 'idx_ecommerce_orders_order_device',
                'confirmed_at' => 'idx_ecommerce_orders_confirmed_at',
            ];

            foreach ($indexes as $column => $indexName) {
                if (! in_array($indexName, $existingIndexes)) {
                    $table->index($column, $indexName);
                }
            }

            // 복합 인덱스
            if (! in_array('idx_ecommerce_orders_user_ordered', $existingIndexes)) {
                $table->index(['user_id', 'ordered_at'], 'idx_ecommerce_orders_user_ordered');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_orders')) {
            return;
        }

        $existingIndexes = array_column(Schema::getIndexes('ecommerce_orders'), 'name');

        Schema::table('ecommerce_orders', function (Blueprint $table) use ($existingIndexes) {
            $indexes = [
                'idx_ecommerce_orders_total_amount',
                'idx_ecommerce_orders_order_device',
                'idx_ecommerce_orders_confirmed_at',
                'idx_ecommerce_orders_user_ordered',
            ];

            foreach ($indexes as $index) {
                if (in_array($index, $existingIndexes)) {
                    $table->dropIndex($index);
                }
            }
        });
    }
};
