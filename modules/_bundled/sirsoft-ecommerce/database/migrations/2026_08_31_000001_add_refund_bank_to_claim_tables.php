<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 반품·교환 신청서에 환불 계좌 컬럼을 추가합니다.
 *
 * 계좌 환불 주문에서 구매자는 신청서마다 환불받을 계좌를 적는다 — 주문할 때 남긴 계좌를
 * 채워서 열되 그 자리에서 고칠 수 있다. 고친 값을 신청서가 자기 것으로 들지 않으면
 * 어느 계좌로 신청했는지가 남지 않아, 운영자가 이체할 때 읽을 근거가 주문 당시 값뿐이다.
 *
 * 환불신청서(`ecommerce_order_refunds`)는 같은 3컬럼을 이미 갖고 있어 이 마이그레이션의
 * 대상이 아니다 — 세 유형이 같은 자리에 같은 이름으로 들게 맞춘다.
 *
 * 실제 송금이 읽는 자리는 `ecommerce_order_payments.refund_bank_*` 다(PG 환불 API 의
 * refundReceiveAccount · 무통장 수동 이체 대상). 그 컬럼은 환불을 **실행하는 시점**에
 * 그 환불 레코드의 계좌로 갱신되며, 신청 접수만으로는 바뀌지 않는다.
 */
return new class extends Migration
{
    /**
     * 대상 테이블 목록
     *
     * @var array<int, string>
     */
    private array $tables = [
        'ecommerce_order_returns',
        'ecommerce_order_exchanges',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'refund_bank_code')) {
                    $blueprint->string('refund_bank_code', 10)->nullable()->comment('환불 은행코드 (계좌 환불 주문의 신청 시점 입력값)');
                }

                if (! Schema::hasColumn($table, 'refund_bank_account')) {
                    $blueprint->string('refund_bank_account', 50)->nullable()->comment('환불 계좌번호 (계좌 환불 주문의 신청 시점 입력값)');
                }

                if (! Schema::hasColumn($table, 'refund_bank_holder')) {
                    $blueprint->string('refund_bank_holder', 100)->nullable()->comment('환불 예금주 (계좌 환불 주문의 신청 시점 입력값)');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                foreach (['refund_bank_code', 'refund_bank_account', 'refund_bank_holder'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropColumn($column);
                    }
                }
            });
        }
    }
};
