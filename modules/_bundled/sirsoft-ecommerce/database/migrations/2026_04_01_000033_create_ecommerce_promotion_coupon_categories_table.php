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
        Schema::create('ecommerce_promotion_coupon_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('category_id');
            $table->enum('type', ['include', 'exclude'])->default('include')->comment('적용유형: include(포함), exclude(제외)');

            $table->primary(['coupon_id', 'category_id', 'type']);
            $table->index('type', 'idx_type');
            $table->foreign('coupon_id')->references('id')->on('ecommerce_promotion_coupons')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('ecommerce_categories')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_promotion_coupon_categories` COMMENT '쿠폰 적용 카테고리'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_promotion_coupon_categories')) {
            Schema::dropIfExists('ecommerce_promotion_coupon_categories');
        }
    }
};
