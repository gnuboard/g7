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
        Schema::create('ecommerce_order_payments', function (Blueprint $table) {
            $table->id()->comment('결제 ID');
            $table->unsignedBigInteger('order_id');
            $table->string('payment_status', 30)->comment('결제 상태 (PaymentStatusEnum)');
            $table->string('pg_provider', 50)->comment('PG사 (tosspayments, inicis 등)');
            $table->string('embedded_pg_provider', 50)->nullable()->comment('간편결제 PG (kakaopay, naverpay 등)');
            $table->string('transaction_id', 100)->nullable()->comment('PG 거래 고유ID');
            $table->string('merchant_order_id', 100)->unique()->comment('가맹점 기준 주문번호');
            $table->string('payment_method', 30)->comment('결제수단 (PaymentMethodEnum)');
            $table->string('payment_device', 20)->nullable()->comment('결제 디바이스 (pc/mobile/app)');
            $table->decimal('paid_amount_local', 12, 2)->comment('결제금액 (현지 통화, PG 실제 결제액)');
            $table->decimal('paid_amount_base', 12, 2)->comment('결제금액 (기준 통화 KRW 환산)');
            $table->decimal('vat_amount', 12, 2)->default(0)->comment('부가세');
            $table->string('currency', 10)->default('KRW')->comment('결제 통화');
            $table->mediumText('currency_snapshot')->nullable()->comment('주문 시점 통화 스냅샷');
            $table->text('mc_paid_amount')->nullable()->comment('결제금액 다중 통화 {"KRW": 10000, "USD": 7.5}');
            $table->text('mc_cancelled_amount')->nullable()->comment('취소금액 다중 통화');
            $table->string('card_name', 50)->nullable()->comment('카드사명');
            $table->string('card_number_masked', 20)->nullable()->comment('마스킹된 카드번호 (1234-****-****-5678)');
            $table->string('card_approval_number', 30)->nullable()->comment('카드 승인번호');
            $table->integer('card_installment_months')->nullable()->comment('할부개월 (0: 일시불)');
            $table->boolean('is_interest_free')->default(false)->comment('무이자 여부 (1: 무이자, 0: 유이자)');
            $table->string('vbank_code', 10)->nullable()->comment('은행코드');
            $table->string('vbank_name', 50)->nullable()->comment('은행명');
            $table->string('vbank_number', 50)->nullable()->comment('계좌번호');
            $table->string('vbank_holder', 100)->nullable()->comment('예금주');
            $table->timestamp('vbank_due_at')->nullable()->comment('입금기한');
            $table->timestamp('vbank_issued_at')->nullable()->comment('가상계좌 발급일시');
            $table->string('dbank_code', 10)->nullable()->comment('무통장 은행코드');
            $table->string('dbank_name', 50)->nullable()->comment('무통장 은행명');
            $table->string('dbank_account', 50)->nullable()->comment('무통장 계좌번호');
            $table->string('dbank_holder', 100)->nullable()->comment('무통장 예금주');
            $table->string('depositor_name', 100)->nullable()->comment('입금자명');
            $table->timestamp('deposit_due_at')->nullable()->comment('입금기한');
            $table->boolean('is_escrow')->default(false)->comment('에스크로 여부 (1: 에스크로, 0: 일반)');
            $table->string('buyer_name', 100)->nullable()->comment('구매자명');
            $table->string('buyer_email', 255)->nullable()->comment('구매자 이메일');
            $table->string('buyer_phone', 20)->nullable()->comment('구매자 전화번호');
            $table->boolean('is_cash_receipt_requested')->default(false)->comment('현금영수증 요청여부 (1: 요청, 0: 미요청)');
            $table->boolean('is_cash_receipt_issued')->default(false)->comment('현금영수증 발급여부 (1: 발급, 0: 미발급)');
            $table->string('cash_receipt_type', 20)->nullable()->comment('현금영수증 유형 (income: 소득공제, expense: 지출증빙)');
            $table->string('cash_receipt_identifier', 50)->nullable()->comment('현금영수증 식별번호 (휴대폰/사업자번호)');
            $table->timestamp('cash_receipt_issued_at')->nullable()->comment('현금영수증 발급일시');
            $table->decimal('cancelled_amount', 12, 2)->default(0)->comment('취소금액');
            $table->decimal('cancelled_vat_amount', 12, 2)->default(0)->comment('취소 부가세');
            $table->text('cancel_reason')->nullable()->comment('취소사유');
            $table->mediumText('cancel_history')->nullable()->comment('취소 이력 (부분취소 이력)');
            $table->string('refund_bank_code', 10)->nullable()->comment('환불 은행코드');
            $table->string('refund_bank_name', 50)->nullable()->comment('환불 은행명');
            $table->string('refund_bank_account', 50)->nullable()->comment('환불 계좌번호');
            $table->string('refund_bank_holder', 100)->nullable()->comment('환불 예금주');
            $table->text('receipt_url')->nullable()->comment('영수증 URL');
            $table->string('payment_name', 255)->nullable()->comment('결제명 (상품명 요약)');
            $table->text('user_agent')->nullable()->comment('브라우저 정보');
            $table->mediumText('payment_meta')->nullable()->comment('기타 메타정보 (PG별 추가 정보)');
            $table->timestamp('payment_started_at')->nullable()->comment('결제 시작일시');
            $table->timestamp('paid_at')->nullable()->comment('결제 완료일시');
            $table->timestamp('cancelled_at')->nullable()->comment('취소 완료일시');
            $table->timestamps();

            $table->index('order_id', 'ecommerce_order_payments_order_id_index');
            $table->index('payment_status', 'ecommerce_order_payments_payment_status_index');
            $table->index('payment_method', 'ecommerce_order_payments_payment_method_index');
            $table->index('paid_at', 'ecommerce_order_payments_paid_at_index');
            $table->foreign('order_id')->references('id')->on('ecommerce_orders')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_order_payments` COMMENT '주문 결제 정보'");
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_order_payments')) {
            Schema::dropIfExists('ecommerce_order_payments');
        }
    }
};
