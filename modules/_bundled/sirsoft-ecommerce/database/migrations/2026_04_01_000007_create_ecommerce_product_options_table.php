<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ecommerce_product_options', function (Blueprint $table) {
            $table->id()->comment('옵션 ID');
            $table->unsignedBigInteger('product_id')->comment('상품 ID');
            $table->string('option_code', 100)->comment('옵션 코드 (조합 해시)');
            $table->text('option_values')->comment('옵션 값 조합: {색상: "빨강", 사이즈: "L"}');
            $table->text('option_name')->nullable()->comment('옵션 조합명 (다국어): {"ko": "빨강/L", "en": "Red/L"}');
            $table->integer('price_adjustment')->default(0)->comment('가격 조정액 (+/-)');
            $table->bigInteger('list_price')->nullable()->comment('정가 (null이면 상품 정가 사용)');
            $table->bigInteger('selling_price')->nullable()->comment('판매가 (null이면 상품 판매가 사용)');
            $table->string('currency_code', 10)->default('KRW')->comment('통화 코드 (저장 시 기본통화 기준)');
            $table->integer('stock_quantity')->default(0)->comment('옵션별 재고');
            $table->unsignedInteger('safe_stock_quantity')->default(0)->comment('안전재고');
            $table->decimal('weight', 10, 2)->nullable()->comment('무게 (g)');
            $table->decimal('volume', 10, 2)->nullable()->comment('부피 (cm³)');
            $table->decimal('mileage_value', 10, 2)->nullable()->comment('마일리지 값');
            $table->string('mileage_type', 10)->nullable()->comment('마일리지 타입: fixed(정액), percent(정률)');
            $table->boolean('is_default')->default(false)->comment('기본 옵션 여부');
            $table->boolean('is_active')->default(true)->comment('활성 여부');
            $table->string('sku', 100)->nullable()->comment('옵션별 SKU');
            $table->unsignedInteger('sort_order')->default(0)->comment('정렬 순서');
            $table->timestamps();

            $table->unique(['product_id', 'option_code']);
            $table->foreign('product_id')->references('id')->on('ecommerce_products')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_product_options` COMMENT '상품 옵션 정보'");
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_product_options')) {
            Schema::dropIfExists('ecommerce_product_options');
        }
    }
};
