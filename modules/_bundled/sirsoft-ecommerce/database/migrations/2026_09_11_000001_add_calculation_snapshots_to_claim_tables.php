<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 반품·교환 신청서에 접수 시점 주문 금액 스냅샷 컬럼을 추가합니다.
 *
 * 신청서 상세의 "주문금액 비교(전/후)" 표는 접수 시점의 재계산 결과를 그린다. 취소
 * (`ecommerce_order_refunds` 의 취소 발 환불 행)와 환불신청서는 접수할 때 이미 두 스냅샷을
 * 저장하지만, 반품·교환은 배송비 스냅샷만 남겨 상세가 "전 vs 후" 를 세울 근거가 없었다 —
 * 상세가 그때그때 다시 계산하면 이후의 다른 클레임·쿠폰 변경이 섞여 접수 당시와 다른 표가 된다.
 *
 * 컬럼 이름·형(mediumText nullable)은 환불신청서와 같게 맞춘다 — 네 유형이 같은 자리에 같은
 * 이름으로 들어야 리소스가 유형을 가리지 않고 읽는다. 다통화 스냅샷은 저장하지 않고 읽을 때
 * `CalculationSnapshotPresenter` 가 주문의 동결 환율로 다시 만든다.
 *
 * 백필은 하지 않는다 — 이 컬럼이 비어 있는 구형 신청서는 상세가 "접수 시점 비교표가 없습니다"
 * 한 줄로 그린다.
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
                if (! Schema::hasColumn($table, 'original_calculation_snapshot')) {
                    $blueprint->mediumText('original_calculation_snapshot')->nullable()->after('refund_bank_holder')->comment('접수 시점 재계산 전 주문 금액 스냅샷');
                }

                if (! Schema::hasColumn($table, 'recalculated_snapshot')) {
                    $blueprint->mediumText('recalculated_snapshot')->nullable()->after('original_calculation_snapshot')->comment('접수 시점 재계산 후 주문 금액 스냅샷');
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
                foreach (['original_calculation_snapshot', 'recalculated_snapshot'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropColumn($column);
                    }
                }
            });
        }
    }
};
