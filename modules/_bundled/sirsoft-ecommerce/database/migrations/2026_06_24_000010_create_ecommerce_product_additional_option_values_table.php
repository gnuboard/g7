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
        Schema::create('ecommerce_product_additional_option_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('additional_option_id')->comment('추가옵션 그룹 ID');
            $table->text('name')->comment('선택지명 (다국어 JSON)');
            $table->bigInteger('price_adjustment')->default(0)->comment('추가금 (KRW 기준, 0 이상)');
            $table->text('mc_price_adjustment')->nullable()->comment('다중통화 추가금 (통화코드별 JSON)');
            $table->boolean('is_default')->default(false)->comment('기본 선택지 여부 (1: 기본, 0: 일반)');
            $table->boolean('is_active')->default(true)->comment('활성 여부 (1: 활성, 0: 비활성)');
            $table->unsignedInteger('sort_order')->default(0)->comment('정렬 순서');
            $table->timestamps();

            $table->index(['additional_option_id', 'sort_order'], 'ec_prod_add_opt_val_aoid_sort_idx');
            $table->foreign('additional_option_id', 'ec_prod_add_opt_val_aoid_fk')
                ->references('id')
                ->on('ecommerce_product_additional_options')
                ->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `'.DB::getTablePrefix()."ecommerce_product_additional_option_values` COMMENT '상품 추가옵션 선택지'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_product_additional_option_values');
    }
};
