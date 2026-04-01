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
        Schema::create('ecommerce_order_refunds', function (Blueprint $table) {
            $table->id()->comment('환불 ID');
            $table->foreignId('order_id')->comment('주문 FK')
                ->constrained('ecommerce_orders')->cascadeOnDelete();
            $table->foreignId('order_cancel_id')->nullable()->comment('취소 FK (추후 polymorphic 전환 가능)')
                ->constrained('ecommerce_order_cancels')->nullOnDelete();
            $table->string('refund_number', 50)->unique()->comment('고유 환불번호');
            $table->string('refund_status', 30)->comment('환불 상태 (RefundStatusEnum: requested, approved, processing, on_hold, completed, rejected)');
            $table->string('refund_method', 30)->comment('환불 수단 (RefundMethodEnum: pg, bank, points)');
            $table->decimal('refund_amount', 12, 2)->default(0)->comment('PG/계좌 환불 금액');
            $table->decimal('refund_points_amount', 12, 2)->default(0)->comment('마일리지 환불액');
            $table->decimal('refund_shipping_amount', 12, 2)->default(0)->comment('배송비 환불/추가 차액');
            $table->mediumText('original_calculation_snapshot')->nullable()->comment('재계산 전 주문 금액 스냅샷');
            $table->mediumText('recalculated_snapshot')->nullable()->comment('재계산 후 주문 금액 스냅샷');
            $table->decimal('additional_payment_amount', 12, 2)->default(0)->comment('추가결제 필요 금액 (무료배송 미달 등)');
            $table->string('additional_payment_method', 30)->nullable()->comment('추가결제 수단 (pg, bank)');
            $table->boolean('is_additional_payment_completed')->default(false)->comment('추가결제 완료 여부');
            $table->timestamp('additional_paid_at')->nullable()->comment('추가결제 완료 시각');
            $table->string('refund_bank_holder', 100)->nullable()->comment('무통장 환불 예금주');
            $table->string('refund_bank_code', 10)->nullable()->comment('무통장 환불 은행코드');
            $table->string('refund_bank_account', 50)->nullable()->comment('무통장 환불 계좌번호');
            $table->string('pg_transaction_id', 100)->nullable()->comment('PG 환불 거래 ID');
            $table->string('pg_error_code', 50)->nullable()->comment('PG 오류 코드');
            $table->text('pg_error_message')->nullable()->comment('PG 오류 메시지');
            $table->timestamp('refunded_at')->nullable()->comment('환불 완료 시각');
            $table->foreignId('processed_by')->nullable()->comment('처리 관리자')
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('order_id', 'ecommerce_order_refunds_order_id_index');
            $table->index('order_cancel_id', 'ecommerce_order_refunds_cancel_id_index');
            $table->index('refund_status', 'ecommerce_order_refunds_refund_status_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_order_refunds` COMMENT '주문 환불 이력'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_order_refunds')) {
            Schema::dropIfExists('ecommerce_order_refunds');
        }
    }
};
