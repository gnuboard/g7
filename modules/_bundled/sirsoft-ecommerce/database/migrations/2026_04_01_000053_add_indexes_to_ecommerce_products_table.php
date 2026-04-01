<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ecommerce_products 테이블 인덱스 추가.
 *
 * - selling_price: 가격 범위 필터
 * - list_price: 가격 범위 필터
 * - stock_quantity: 재고 범위 필터
 * - shipping_policy_id: 배송정책별 상품 조회
 * - barcode: 바코드 검색
 * - tax_status: 과세/면세 필터
 * - updated_at: 날짜 필터/정렬
 */
return new class extends Migration
{
    public function up(): void
    {
        $existingIndexes = array_column(Schema::getIndexes('ecommerce_products'), 'name');

        Schema::table('ecommerce_products', function (Blueprint $table) use ($existingIndexes) {
            $indexes = [
                'selling_price' => 'idx_ecommerce_products_selling_price',
                'list_price' => 'idx_ecommerce_products_list_price',
                'stock_quantity' => 'idx_ecommerce_products_stock_quantity',
                'shipping_policy_id' => 'idx_ecommerce_products_shipping_policy',
                'barcode' => 'idx_ecommerce_products_barcode',
                'tax_status' => 'idx_ecommerce_products_tax_status',
                'updated_at' => 'idx_ecommerce_products_updated_at',
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
        if (! Schema::hasTable('ecommerce_products')) {
            return;
        }

        $existingIndexes = array_column(Schema::getIndexes('ecommerce_products'), 'name');

        Schema::table('ecommerce_products', function (Blueprint $table) use ($existingIndexes) {
            $indexes = [
                'idx_ecommerce_products_selling_price',
                'idx_ecommerce_products_list_price',
                'idx_ecommerce_products_stock_quantity',
                'idx_ecommerce_products_shipping_policy',
                'idx_ecommerce_products_barcode',
                'idx_ecommerce_products_tax_status',
                'idx_ecommerce_products_updated_at',
            ];

            foreach ($indexes as $index) {
                if (in_array($index, $existingIndexes)) {
                    $table->dropIndex($index);
                }
            }
        });
    }
};
