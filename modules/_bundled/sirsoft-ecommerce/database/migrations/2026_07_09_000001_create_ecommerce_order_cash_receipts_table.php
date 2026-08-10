<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * comment 문자열에 모듈 Enum 클래스를 참조하지 않는다.
 * `php artisan migrate` 는 모듈 오토로더가 등록되지 않은 상태에서도 실행될 수 있어
 * (신규 설치, 모듈 비활성 상태 등) Enum 클래스 해석에 실패한다.
 */
return new class extends Migration
{
    /**
     * 마이그레이션 실행
     */
    public function up(): void
    {
        if (Schema::hasTable('ecommerce_order_cash_receipts')) {
            return;
        }

        Schema::create('ecommerce_order_cash_receipts', function (Blueprint $table) {
            $table->id()->comment('현금영수증 이력 ID');
            $table->foreignId('order_id')
                ->comment('주문 ID')
                ->constrained('ecommerce_orders')
                ->onDelete('cascade');
            $table->unsignedBigInteger('order_payment_id')->nullable()->comment('결제 ID');
            $table->string('provider', 50)->comment('발급 프로바이더 (tosspayments, kginicis 등)');
            $table->string('receipt_key', 200)->nullable()->comment('프로바이더 영수증 키');
            $table->string('transaction_type', 20)
                ->comment('거래 유형 (issue: 발급, cancel: 취소)');
            $table->string('receipt_type', 20)
                ->comment('영수증 유형 (income: 소득공제, expense: 지출증빙)');
            $table->decimal('amount', 12, 2)->comment('발급/취소 금액');
            $table->decimal('tax_free_amount', 12, 2)->default(0)->comment('면세 금액');
            $table->string('identifier_masked', 50)->nullable()->comment('식별번호 (뒤 4자리 외 마스킹)');
            $table->text('receipt_url')->nullable()->comment('영수증 조회 URL');
            $table->string('issue_number', 20)->nullable()->comment('발급번호');
            $table->string('issue_status', 20)
                ->comment('발급 상태 (IN_PROGRESS: 처리중, COMPLETED: 완료, FAILED: 실패)');
            $table->string('error_code', 100)->nullable()->comment('실패 시 프로바이더 에러코드');
            $table->text('error_message')->nullable()->comment('실패 시 에러 메시지');
            $table->timestamp('issued_at')->nullable()->comment('발급일시');
            $table->json('raw_response')->nullable()->comment('프로바이더 원응답 (민감키 마스킹 후 저장)');
            $table->timestamps();

            $table->index(['order_id', 'transaction_type'], 'ecommerce_order_cash_receipts_order_type_index');
            $table->index('receipt_key', 'ecommerce_order_cash_receipts_receipt_key_index');
            $table->index('order_payment_id', 'ecommerce_order_cash_receipts_payment_id_index');
        });
    }

    /**
     * 마이그레이션 롤백
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_order_cash_receipts');
    }
};
