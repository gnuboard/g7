<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        // 부분 실패 뒤 재실행이나 이미 만들어진 설치본에서 "Base table already exists" 로 멈추지 않게 한다.
        if (Schema::hasTable('ecommerce_order_return_options')) {
            return;
        }

        Schema::create('ecommerce_order_return_options', function (Blueprint $table) {
            $table->id()->comment('반품 옵션 ID');
            $table->foreignId('order_return_id')->comment('반품 FK')
                ->constrained('ecommerce_order_returns')->cascadeOnDelete();
            $table->foreignId('order_id')->comment('주문 FK')
                ->constrained('ecommerce_orders')->cascadeOnDelete();
            $table->foreignId('order_option_id')->comment('원 주문옵션 FK')
                ->constrained('ecommerce_order_options')->cascadeOnDelete();
            $table->string('option_status', 30)->comment('상품 단위 반품 상태 (ReturnStatusEnum — requested: 반품신청, payment_pending: 반품결제대기, paid: 반품결제완료, approved: 반품승인, on_hold: 반품보류, rejected: 반품반려, collection_ready: 반품회수준비중, collecting: 반품회수중, collected: 반품회수도착, completed: 반품완료, withdrawn: 반품철회)');
            $table->unsignedInteger('return_quantity')->comment('반품 신청 수량');
            $table->unsignedInteger('original_quantity')->comment('신청 전 원래 수량 (감사용)');
            $table->string('original_option_status', 30)->comment('신청 직전 원 주문옵션 상태 — 반려·철회 시 상품옵션 단위 복귀 기준 (OrderStatusEnum — pending_order: 주문대기, pending_payment: 결제대기, payment_complete: 결제완료, shipping_hold: 배송보류, preparing: 상품준비중, shipping_ready: 배송준비중, shipping: 배송중, delivered: 배송완료, confirmed: 구매확정, cancelled: 주문취소, exchange_in_progress: 교환진행중, return_in_progress: 반품진행중, exchanged: 교환완료, returned: 반품완료)');
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

            $table->comment('반품 신청 상품별 상세');
        });
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
