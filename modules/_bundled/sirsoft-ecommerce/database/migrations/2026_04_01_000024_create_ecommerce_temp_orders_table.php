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
        Schema::create('ecommerce_temp_orders', function (Blueprint $table) {
            $table->id()->comment('임시주문 ID');
            $table->string('cart_key', 50)->nullable()->comment('비회원 장바구니 키');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->mediumText('items')->comment('장바구니 아이템 스냅샷 [{product_id, product_option_id, quantity}]');
            $table->mediumText('calculation_result')->comment('OrderCalculationResult JSON');
            $table->mediumText('calculation_input')->nullable()->comment('계산 입력 {promotions, use_points, shipping_address}');
            $table->timestamp('expires_at')->comment('만료일시 (기본 30분)');
            $table->timestamps();

            $table->index('cart_key', 'ecommerce_temp_orders_cart_key_index');
            $table->index('user_id', 'ecommerce_temp_orders_user_id_index');
            $table->index('expires_at', 'ecommerce_temp_orders_expires_at_index');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_temp_orders` COMMENT '임시 주문 (주문서 작성 단계)'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_temp_orders')) {
            Schema::dropIfExists('ecommerce_temp_orders');
        }
    }
};
