<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 옵션마다 과세 종류(과세·면세·영세)를 지정할 수 있게 한다 (#612).
     * null 은 「상품 기본값을 따름」이다 — 기존 옵션은 모두 null 로 남아 지금처럼 상품 값을 따른다.
     */
    public function up(): void
    {
        if (Schema::hasTable('ecommerce_product_options')
            && ! Schema::hasColumn('ecommerce_product_options', 'tax_status')) {
            Schema::table('ecommerce_product_options', function (Blueprint $table) {
                $table->string('tax_status', 20)->nullable()->after('selling_price')
                    ->comment('과세 종류: null(상품 따름)·taxable(과세)·tax_free(면세)·zero_rated(영세)');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_product_options')
            && Schema::hasColumn('ecommerce_product_options', 'tax_status')) {
            Schema::table('ecommerce_product_options', function (Blueprint $table) {
                $table->dropColumn('tax_status');
            });
        }
    }
};
