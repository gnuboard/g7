<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ecommerce_mileage_transactions', function (Blueprint $table) {
            $table->id()->comment('거래 ID');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('회원 ID');
            $table->string('currency', 10)->default('KRW')->comment('통화 코드 (주문 기준통화 스냅샷)');
            $table->string('type', 30)->comment('거래 유형 (MileageTransactionTypeEnum)');
            $table->decimal('amount', 12, 2)->comment('거래 금액 (양수=적립, 음수=차감)');
            $table->decimal('remaining_amount', 12, 2)->default(0)->comment('잔여 금액 (적립건만 양수, FIFO 차감용)');
            $table->decimal('balance_after', 12, 2)->comment('거래 후 잔액 (감사 스냅샷, 베스트에포트)');
            $table->foreignId('order_id')->nullable()->constrained('ecommerce_orders')->nullOnDelete()->comment('관련 주문 ID');
            // 옵션 병합 시 하드 삭제(SoftDeletes 미사용)되므로 FK 미설정 — unsignedBigInteger + 인덱스만 유지
            $table->unsignedBigInteger('order_option_id')->nullable()->comment('관련 주문옵션 ID (병합 하드삭제 대응 — FK 미설정)');
            $table->unsignedBigInteger('order_cancel_id')->nullable()->comment('관련 주문취소 ID (복원 멱등 기준)');
            $table->unsignedBigInteger('source_transaction_id')->nullable()->comment('원본 적립건 ID (차감 시 FIFO 추적)');
            $table->unsignedBigInteger('granted_by')->nullable()->comment('부여 주체 (NULL=시스템, user ID=관리자)');
            $table->string('description', 500)->nullable()->comment('거래 설명');
            $table->text('memo')->nullable()->comment('관리자 메모');
            $table->timestamp('expires_at')->nullable()->comment('유효기간 만료 예정일 (적립건만)');
            $table->timestamp('expired_at')->nullable()->comment('실제 소멸 처리일');
            $table->json('metadata')->nullable()->comment('추가 정보 (부족 회수액 등)');
            $table->timestamps();

            // 자기 참조 외래키 (FIFO 소비 추적)
            $table->foreign('source_transaction_id')
                ->references('id')
                ->on('ecommerce_mileage_transactions')
                ->nullOnDelete();

            // 인덱스 — 인덱스명 약어 금지(전체 이름)
            // FOR UPDATE 락 범위를 한정하기 위한 선두 복합 인덱스 (next-key 락 전파 교차 데드락 방지)
            $table->index(['user_id', 'currency', 'expires_at'], 'ecommerce_mileage_transactions_user_currency_expires_index');
            $table->index(['user_id', 'currency'], 'ecommerce_mileage_transactions_user_currency_index');
            $table->index('expires_at', 'ecommerce_mileage_transactions_expires_index');
            $table->index(['type', 'created_at'], 'ecommerce_mileage_transactions_type_created_index');
            $table->index('order_option_id', 'ecommerce_mileage_transactions_order_option_index');
            $table->index('order_cancel_id', 'ecommerce_mileage_transactions_order_cancel_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_mileage_transactions');
    }
};
