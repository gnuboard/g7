<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 마이그레이션 실행
     *
     * 재발급(전체취소 → 전액 재발급)에는 최초 발급 시의 원본 식별번호가 필요하다.
     * 이력 테이블에는 마스킹 값만 남기고, 원본은 이 컬럼에 APP_KEY 기반 AES-256 으로만 보관한다.
     * 구매확정 시점에 폐기된다(개인정보 최소보유).
     */
    public function up(): void
    {
        Schema::table('ecommerce_order_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_order_payments', 'cash_receipt_identifier_encrypted')) {
                $table->text('cash_receipt_identifier_encrypted')
                    ->nullable()
                    ->after('cash_receipt_identifier')
                    ->comment('현금영수증 식별번호 암호문 (재발급용, 구매확정 시 폐기)');
            }
        });
    }

    /**
     * 마이그레이션 롤백
     */
    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_order_payments')) {
            return;
        }

        Schema::table('ecommerce_order_payments', function (Blueprint $table) {
            if (Schema::hasColumn('ecommerce_order_payments', 'cash_receipt_identifier_encrypted')) {
                $table->dropColumn('cash_receipt_identifier_encrypted');
            }
        });
    }
};
