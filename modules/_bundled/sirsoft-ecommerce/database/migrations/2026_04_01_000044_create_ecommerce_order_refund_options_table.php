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
        Schema::create('ecommerce_order_refund_options', function (Blueprint $table) {
            $table->id()->comment('환불 옵션 ID');
            $table->foreignId('order_refund_id')->comment('환불 FK')
                ->constrained('ecommerce_order_refunds')->cascadeOnDelete();
            $table->foreignId('order_id')->comment('주문 FK')
                ->constrained('ecommerce_orders')->cascadeOnDelete();
            $table->foreignId('order_option_id')->comment('주문옵션 FK')
                ->constrained('ecommerce_order_options')->cascadeOnDelete();
            $table->string('option_status', 30)->comment('옵션 환불 상태 (RefundOptionStatusEnum: requested, approved, processing, on_hold, completed, rejected)');
            $table->unsignedInteger('quantity')->comment('환불 수량');
            $table->decimal('unit_price', 12, 2)->comment('환불 시점 단가');
            $table->decimal('subtotal_amount', 12, 2)->comment('수량 × 단가');
            $table->decimal('discount_amount', 12, 2)->default(0)->comment('할인 차감분');
            $table->decimal('shipping_amount', 12, 2)->default(0)->comment('해당 옵션 배송비 차액');
            $table->decimal('refund_amount', 12, 2)->comment('해당 옵션 최종 환불액');
            $table->timestamp('completed_at')->nullable()->comment('완료 시각');
            $table->foreignId('processed_by')->nullable()->comment('처리 관리자')
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('order_refund_id', 'ecommerce_order_refund_opts_refund_id_index');
            $table->index('order_id', 'ecommerce_order_refund_opts_order_id_index');
            $table->index('order_option_id', 'ecommerce_order_refund_opts_option_id_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_order_refund_options` COMMENT '주문 환불 옵션별 상세'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_order_refund_options')) {
            Schema::dropIfExists('ecommerce_order_refund_options');
        }
    }
};
