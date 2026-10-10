<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 클레임 추가결제 테이블을 생성합니다.
 *
 * 교환/반품의 클레임 배송비·차액을 별도 결제 건으로 수납한다. payment_number 는
 * SequenceType::CLAIM_PAYMENT(접두사 CP)로 채번되며 PG merchant_order_id 로도 쓰인다.
 * payment_number / transaction_id 의 UNIQUE 가 재시도 멱등의 축이다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 부분 실패 뒤 재실행이나 이미 만들어진 설치본에서 "Base table already exists" 로 멈추지 않게 한다.
        if (Schema::hasTable('ecommerce_claim_payments')) {
            return;
        }

        Schema::create('ecommerce_claim_payments', function (Blueprint $table) {
            $table->id()->comment('클레임 결제 ID');
            $table->string('payable_type', 20)->comment('결제 대상 유형 (ClaimTypeEnum — exchange: 교환, return: 반품)');
            $table->unsignedBigInteger('payable_id')->comment('결제 대상 ID');
            $table->foreignId('order_id')->comment('주문 FK')
                ->constrained('ecommerce_orders')->cascadeOnDelete();
            $table->string('payment_number', 50)->unique()->comment('고유 결제번호 (SequenceType::CLAIM_PAYMENT, 접두사 CP = PG merchant_order_id)');
            $table->string('payment_method', 30)->comment('결제 수단 (PaymentMethodEnum — card: 신용카드, vbank: 가상계좌, dbank: 무통장입금, bank: 계좌이체, phone: 휴대폰결제, point: 마일리지 결제, deposit: 예치금결제, free: 무료)');
            $table->string('pg_provider', 50)->nullable()->comment('PG 제공자 식별자 (마일리지 결제면 NULL)');
            $table->string('payment_status', 30)->comment('결제 상태 (ClaimPaymentStatusEnum — pending: 결제대기, paid: 결제완료, failed: 결제실패, cancelled: 결제취소, refunded: 환급완료)');
            $table->decimal('amount', 12, 2)->default(0)->comment('PG 결제 금액');
            $table->decimal('points_amount', 12, 2)->default(0)->comment('마일리지 결제 금액');
            $table->string('currency_code', 10)->default('KRW')->comment('결제 통화 코드');
            $table->text('mc_amount')->nullable()->comment('다통화 결제 금액 (통화코드 → 금액)');
            $table->string('transaction_id', 100)->nullable()->unique()->comment('PG 거래 ID (멱등 축)');
            $table->string('pg_error_code', 50)->nullable()->comment('PG 오류 코드');
            $table->text('pg_error_message')->nullable()->comment('PG 오류 메시지');
            $table->mediumText('raw_response')->nullable()->comment('PG 원본 응답 JSON');
            $table->timestamp('paid_at')->nullable()->comment('결제 완료 시각');
            // 재발송지 수정(D14)으로 청구액이 줄면 이미 받은 결제에서 차액만 돌려준다 — 부분 환급은 상태가 아니라 누적액으로 기록한다.
            $table->decimal('refunded_amount', 12, 2)->default(0)->comment('부분 환급 누적액 (차액 환불 — 전액 환급은 payment_status=refunded)');
            $table->timestamp('refunded_at')->nullable()->comment('마지막 환급 시각');
            $table->timestamps();

            $table->index(['payable_type', 'payable_id'], 'ecommerce_claim_payments_payable_index');
            $table->index('order_id', 'ecommerce_claim_payments_order_id_index');
            // 상태 단독 인덱스는 고아 결제 대기 건 정리 배치가 선두로 쓴다
            // (`ClaimPaymentRepository::listPendingOlderThan()` — 상태 등치 + created_at 범위).
            // 나머지 상태 조회는 전부 payable_* 또는 order_id 가 선두라 이 인덱스를 타지 않는다.
            $table->index('payment_status', 'ecommerce_claim_payments_status_index');

            $table->comment('클레임 추가결제');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_claim_payments')) {
            Schema::dropIfExists('ecommerce_claim_payments');
        }
    }
};
