<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 교환·반품·환불 신청서에 환불 우선순위 컬럼을 추가합니다.
 *
 * 구매자는 신청할 때 환불을 PG 결제금부터 받을지(`pg_first`) 마일리지부터 받을지(`points_first`)
 * 고른다. 접수 계산은 그 값을 썼지만 신청서가 그 값을 들지 않아, 응답(`ClaimResource::refund_priority`)은
 * 언제나 null 이었고 수정 화면은 환불액 구성으로 추측했다 — PG 잔여가 모자라 마일리지가 섞인
 * PG 우선 신청서가 수정 화면에서 마일리지 우선으로 뒤집혔다.
 *
 * 기존 행은 백필하지 않고 null 로 둔다. 접수 계산의 입력이었던 우선순위는 어디에도 남아 있지 않다 —
 * 동결 스냅샷(`original_calculation_snapshot` · `recalculated_snapshot`)은 주문 금액 축만 담고,
 * 환불액 구성으로 되짚으면 화면이 하던 추측을 저장값으로 굳히게 된다. null 인 신청서는 화면이
 * 종전 추측으로 떨어진다.
 *
 * 컬럼 추가만 하므로 업그레이드 스텝(데이터 전환)은 필요 없다 — 마이그레이션은 모듈 업데이트가 실행한다.
 */
return new class extends Migration
{
    /**
     * 대상 테이블 목록
     *
     * @var array<int, string>
     */
    private array $tables = [
        'ecommerce_order_exchanges',
        'ecommerce_order_returns',
        'ecommerce_order_refunds',
    ];

    /**
     * Run the migrations.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'refund_priority')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('refund_priority', 20)
                    ->nullable()
                    ->comment('접수 시 환불 우선순위 (pg_first: PG 결제금부터, points_first: 마일리지부터 / NULL: 기록 이전 신청서)');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'refund_priority')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('refund_priority');
            });
        }
    }
};
