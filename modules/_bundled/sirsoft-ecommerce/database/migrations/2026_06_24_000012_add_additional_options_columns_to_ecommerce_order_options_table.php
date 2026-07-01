<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ecommerce_order_options', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_order_options', 'additional_options_snapshot')) {
                $table->mediumText('additional_options_snapshot')
                    ->nullable()
                    ->after('option_snapshot')
                    ->comment('추가옵션 스냅샷 (주문 시점 동결 JSON)');
            }
            if (! Schema::hasColumn('ecommerce_order_options', 'additional_options_total')) {
                $table->decimal('additional_options_total', 12, 2)
                    ->default(0)
                    ->after('unit_price')
                    ->comment('옵션 1단위당 추가옵션 합계 (KRW 기준)');
            }
            if (! Schema::hasColumn('ecommerce_order_options', 'mc_additional_options_total')) {
                $table->text('mc_additional_options_total')
                    ->nullable()
                    ->after('mc_unit_price')
                    ->comment('다중통화 추가옵션 단위 합계 (통화코드별 JSON)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_order_options')) {
            return;
        }

        Schema::table('ecommerce_order_options', function (Blueprint $table) {
            foreach (['mc_additional_options_total', 'additional_options_total', 'additional_options_snapshot'] as $column) {
                if (Schema::hasColumn('ecommerce_order_options', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
