<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 상품/옵션 가격 컬럼을 정수에서 소수(decimal)로 전환합니다.
     *
     * 기본통화가 KRW 가 아닌 통화(USD 등 소수 통화)일 때 3.3 같은 소수 가격이
     * 정수 컬럼에 막혀 저장되지 않던 문제를 해소합니다. 주문/쿠폰/마일리지 테이블이
     * 이미 사용 중인 decimal(x,2) 스케일과 동일하게 맞춥니다. 기존 정수 값은
     * 값 변경 없이 소수부 0 으로 보존됩니다(3882 → 3882.00).
     */
    public function up(): void
    {
        if (Schema::hasTable('ecommerce_products')) {
            Schema::table('ecommerce_products', function (Blueprint $table) {
                // unsigned 유지(상품 가격은 음수 불가)
                $table->decimal('list_price', 15, 2)->unsigned()->default(0)->comment('정가 (기본통화 기준)')->change();
                $table->decimal('selling_price', 15, 2)->unsigned()->default(0)->comment('판매가 (기본통화 기준)')->change();
            });
        }

        if (Schema::hasTable('ecommerce_product_options')) {
            Schema::table('ecommerce_product_options', function (Blueprint $table) {
                // 옵션 가격은 nullable(null 이면 상품 가격 사용), price_adjustment 는 +/- 가능(signed)
                $table->decimal('list_price', 15, 2)->nullable()->comment('정가 (null이면 상품 정가 사용)')->change();
                $table->decimal('selling_price', 15, 2)->nullable()->comment('판매가 (null이면 상품 판매가 사용)')->change();
                $table->decimal('price_adjustment', 15, 2)->default(0)->comment('가격 조정액 (+/-)')->change();
            });
        }
    }

    /**
     * 가격 컬럼을 다시 정수로 되돌립니다.
     *
     * 소수부가 있던 값은 정수 변환 시 절사됩니다(소수 통화 데이터 손실 가능).
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_products')) {
            Schema::table('ecommerce_products', function (Blueprint $table) {
                $table->unsignedBigInteger('list_price')->default(0)->comment('정가 (원)')->change();
                $table->unsignedBigInteger('selling_price')->default(0)->comment('판매가 (원)')->change();
            });
        }

        if (Schema::hasTable('ecommerce_product_options')) {
            Schema::table('ecommerce_product_options', function (Blueprint $table) {
                $table->bigInteger('list_price')->nullable()->comment('정가 (null이면 상품 정가 사용)')->change();
                $table->bigInteger('selling_price')->nullable()->comment('판매가 (null이면 상품 판매가 사용)')->change();
                $table->integer('price_adjustment')->default(0)->comment('가격 조정액 (+/-)')->change();
            });
        }
    }
};
