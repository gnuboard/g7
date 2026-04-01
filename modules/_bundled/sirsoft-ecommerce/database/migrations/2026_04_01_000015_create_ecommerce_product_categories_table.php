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
        Schema::create('ecommerce_product_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->comment('상품 ID');
            $table->unsignedBigInteger('category_id')->comment('카테고리 ID');
            $table->boolean('is_primary')->default(false)->comment('대표 카테고리 여부');

            $table->primary(['product_id', 'category_id']);
            $table->index('category_id');
            $table->index('is_primary');
            $table->foreign('product_id')->references('id')->on('ecommerce_products')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('ecommerce_categories')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_product_categories` COMMENT '상품-카테고리 연결'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_product_categories')) {
            Schema::dropIfExists('ecommerce_product_categories');
        }
    }
};
