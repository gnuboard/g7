<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ecommerce_order_addresses 테이블 인덱스 추가.
 *
 * - orderer_phone: 주문자 전화번호 검색
 * - recipient_phone: 수령자 전화번호 검색
 */
return new class extends Migration
{
    public function up(): void
    {
        $existingIndexes = array_column(Schema::getIndexes('ecommerce_order_addresses'), 'name');

        Schema::table('ecommerce_order_addresses', function (Blueprint $table) use ($existingIndexes) {
            $indexes = [
                'orderer_phone' => 'idx_ecommerce_order_addresses_orderer_phone',
                'recipient_phone' => 'idx_ecommerce_order_addresses_recipient_phone',
            ];

            foreach ($indexes as $column => $indexName) {
                if (! in_array($indexName, $existingIndexes)) {
                    $table->index($column, $indexName);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_order_addresses')) {
            return;
        }

        $existingIndexes = array_column(Schema::getIndexes('ecommerce_order_addresses'), 'name');

        Schema::table('ecommerce_order_addresses', function (Blueprint $table) use ($existingIndexes) {
            $indexes = [
                'idx_ecommerce_order_addresses_orderer_phone',
                'idx_ecommerce_order_addresses_recipient_phone',
            ];

            foreach ($indexes as $index) {
                if (in_array($index, $existingIndexes)) {
                    $table->dropIndex($index);
                }
            }
        });
    }
};
