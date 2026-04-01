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
        Schema::create('ecommerce_carts', function (Blueprint $table) {
            $table->id()->comment('장바구니 ID');
            $table->string('cart_key', 50)->nullable()->comment('비회원 장바구니 키 (ck_ 접두사 + 32자)');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_option_id');
            $table->unsignedInteger('quantity')->default(1)->comment('수량');
            $table->timestamps();

            $table->index('cart_key', 'ecommerce_carts_cart_key_index');
            $table->index('user_id', 'ecommerce_carts_user_id_index');
            $table->index('product_id', 'ecommerce_carts_product_id_index');
            $table->index('product_option_id', 'ecommerce_carts_product_option_id_index');
            $table->index(['user_id', 'product_option_id'], 'ecommerce_carts_user_option_index');
            $table->index(['cart_key', 'product_option_id'], 'ecommerce_carts_cart_key_option_index');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('ecommerce_products')->cascadeOnDelete();
            $table->foreign('product_option_id')->references('id')->on('ecommerce_product_options')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_carts` COMMENT '장바구니'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_carts')) {
            Schema::dropIfExists('ecommerce_carts');
        }
    }
};
