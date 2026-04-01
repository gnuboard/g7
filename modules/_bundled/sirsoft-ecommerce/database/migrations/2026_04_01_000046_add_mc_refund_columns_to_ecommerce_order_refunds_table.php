<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ecommerce_order_refunds 테이블에 다통화(mc_*) 환불금액 컬럼 추가
 *
 * 주문 시점의 환율 스냅샷 기반으로 변환된 다통화 환불금액을 저장합니다.
 */
return new class extends Migration
{
    /**
     * 마이그레이션을 실행합니다.
     */
    public function up(): void
    {
        Schema::table('ecommerce_order_refunds', function (Blueprint $table) {
            $table->text('mc_refund_amount')->nullable()->after('refund_shipping_amount')
                ->comment('다통화 PG 환불금액 (통화코드 → 금액)');
            $table->text('mc_refund_points_amount')->nullable()->after('mc_refund_amount')
                ->comment('다통화 마일리지 환불금액 (통화코드 → 금액)');
            $table->text('mc_refund_shipping_amount')->nullable()->after('mc_refund_points_amount')
                ->comment('다통화 배송비 환불금액 (통화코드 → 금액)');
        });
    }

    /**
     * 마이그레이션을 되돌립니다.
     */
    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_order_refunds')) {
            return;
        }

        Schema::table('ecommerce_order_refunds', function (Blueprint $table) {
            $columns = ['mc_refund_amount', 'mc_refund_points_amount', 'mc_refund_shipping_amount'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('ecommerce_order_refunds', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
