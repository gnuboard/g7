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
        Schema::create('ecommerce_product_additional_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->text('name')->comment('옵션명 (다국어 JSON)');
            $table->boolean('is_required')->default(false)->comment('필수 여부');
            $table->unsignedInteger('sort_order')->default(0)->comment('정렬 순서');
            $table->timestamps();

            $table->index(['product_id', 'sort_order'], 'ec_prod_add_opts_pid_sort_idx');
            $table->foreign('product_id')->references('id')->on('ecommerce_products')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_product_additional_options` COMMENT '상품 추가옵션 정보'");
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_product_additional_options')) {
            Schema::dropIfExists('ecommerce_product_additional_options');
        }
    }
};
