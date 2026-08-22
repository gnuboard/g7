<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 반품 신청 상품(옵션별 상세) 테이블을 생성합니다.
 *
 * 교환 신청 상품에서 신규 옵션 컬럼을 제거하고 소계(subtotal_amount)를 둔다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ecommerce_order_return_options', function (Blueprint $table) {
            $table->id()->comment('반품 옵션 ID');
            $table->foreignId('order_return_id')->comment('반품 FK')
                ->constrained('ecommerce_order_returns')->cascadeOnDelete();
            $table->foreignId('order_id')->comment('주문 FK')
                ->constrained('ecommerce_orders')->cascadeOnDelete();
            $table->foreignId('order_option_id')->comment('원 주문옵션 FK')
                ->constrained('ecommerce_order_options')->cascadeOnDelete();
            $table->string('option_status', 30)->comment('상품 단위 반품 상태 (ReturnStatusEnum)');
            $table->unsignedInteger('return_quantity')->comment('반품 신청 수량');
            $table->unsignedInteger('original_quantity')->comment('신청 전 원래 수량 (감사용)');
            $table->string('original_option_status', 30)->comment('신청 직전 원 주문옵션 상태 (반려·철회 시 상품옵션 단위 복귀 기준, OrderStatusEnum)');
            $table->decimal('unit_price', 12, 2)->comment('신청 시점 단가');
            $table->decimal('subtotal_amount', 12, 2)->comment('반품 소계 (return_quantity × unit_price)');
            $table->timestamp('completed_at')->nullable()->comment('상품 단위 완료 시각');
            $table->foreignId('processed_by')->nullable()->comment('처리 관리자')
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('order_return_id', 'ecommerce_order_rt_opts_return_id_index');
            $table->index('order_id', 'ecommerce_order_rt_opts_order_id_index');
            $table->index('order_option_id', 'ecommerce_order_rt_opts_option_id_index');
            $table->index('option_status', 'ecommerce_order_rt_opts_status_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `'.DB::getTablePrefix()."ecommerce_order_return_options` COMMENT '반품 신청 상품별 상세'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_order_return_options')) {
            Schema::dropIfExists('ecommerce_order_return_options');
        }
    }
};
