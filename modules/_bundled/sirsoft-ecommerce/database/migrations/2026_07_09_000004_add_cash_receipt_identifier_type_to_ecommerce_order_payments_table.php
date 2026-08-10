<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * comment 문자열에 모듈 Enum 클래스를 참조하지 않는다 (모듈 오토로더 미등록 상태에서도 실행 가능해야 함).
 */
return new class extends Migration
{
    /**
     * 마이그레이션 실행
     *
     * 용도(cash_receipt_type)와 식별번호 종류는 서로 독립이다.
     * 휴대폰번호는 소득공제/지출증빙 양쪽에 사용 가능하므로 종류를 별도 컬럼으로 보관한다.
     */
    public function up(): void
    {
        Schema::table('ecommerce_order_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_order_payments', 'cash_receipt_identifier_type')) {
                $table->string('cash_receipt_identifier_type', 20)
                    ->nullable()
                    ->after('cash_receipt_type')
                    ->comment('현금영수증 식별번호 종류 (phone: 휴대폰번호, card: 현금영수증카드번호, business: 사업자등록번호)');
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
            if (Schema::hasColumn('ecommerce_order_payments', 'cash_receipt_identifier_type')) {
                $table->dropColumn('cash_receipt_identifier_type');
            }
        });
    }
};
