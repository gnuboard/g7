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
        Schema::create('ecommerce_order_cancel_options', function (Blueprint $table) {
            $table->id()->comment('취소 옵션 ID');
            $table->foreignId('order_cancel_id')->comment('취소 FK')
                ->constrained('ecommerce_order_cancels')->cascadeOnDelete();
            $table->foreignId('order_id')->comment('주문 FK')
                ->constrained('ecommerce_orders')->cascadeOnDelete();
            $table->foreignId('order_option_id')->comment('주문옵션 FK')
                ->constrained('ecommerce_order_options')->cascadeOnDelete();
            $table->string('option_status', 30)->comment('옵션 취소 상태 (CancelOptionStatusEnum: requested, completed)');
            $table->unsignedInteger('cancel_quantity')->comment('취소 수량');
            $table->unsignedInteger('original_quantity')->comment('취소 전 원래 수량 (감사용)');
            $table->decimal('unit_price', 12, 2)->comment('취소 시점 단가');
            $table->decimal('subtotal_amount', 12, 2)->comment('cancel_quantity × unit_price');
            $table->timestamp('completed_at')->nullable()->comment('완료 시각');
            $table->foreignId('processed_by')->nullable()->comment('처리 관리자')
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('order_cancel_id', 'ecommerce_order_cancel_opts_cancel_id_index');
            $table->index('order_id', 'ecommerce_order_cancel_opts_order_id_index');
            $table->index('order_option_id', 'ecommerce_order_cancel_opts_option_id_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_order_cancel_options` COMMENT '주문 취소 옵션별 상세'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_order_cancel_options')) {
            Schema::dropIfExists('ecommerce_order_cancel_options');
        }
    }
};
