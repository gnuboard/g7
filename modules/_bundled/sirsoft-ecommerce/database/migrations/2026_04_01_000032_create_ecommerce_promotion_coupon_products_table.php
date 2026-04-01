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
        Schema::create('ecommerce_promotion_coupon_products', function (Blueprint $table) {
            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('product_id');
            $table->enum('type', ['include', 'exclude'])->default('include')->comment('적용유형: include(포함), exclude(제외)');

            $table->primary(['coupon_id', 'product_id', 'type']);
            $table->index('type', 'idx_type');
            $table->foreign('coupon_id')->references('id')->on('ecommerce_promotion_coupons')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('ecommerce_products')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_promotion_coupon_products` COMMENT '쿠폰 적용 상품'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_promotion_coupon_products')) {
            Schema::dropIfExists('ecommerce_promotion_coupon_products');
        }
    }
};
