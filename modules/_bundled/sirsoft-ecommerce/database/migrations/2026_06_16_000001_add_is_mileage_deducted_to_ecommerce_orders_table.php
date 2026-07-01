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
        Schema::table('ecommerce_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_orders', 'is_mileage_deducted')) {
                // 마일리지 실차감 여부 — 차감 시점(결제완료/주문접수)이 도래해 실제 FIFO 차감이 일어났는지 기록.
                // 복원(취소/결제실패) 시 이 플래그가 true 인 주문만 복원하여, 차감된 적 없는 주문에서
                // 유령 적립이 발생하는 것을 차단한다 (재고의 is_stock_deducted 와 동형).
                $table->boolean('is_mileage_deducted')
                    ->default(false)
                    ->after('total_points_used_amount')
                    ->comment('마일리지 실차감 여부 (1: 차감됨, 0: 미차감 — 복원 가드 기준)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ecommerce_orders', function (Blueprint $table) {
            if (Schema::hasColumn('ecommerce_orders', 'is_mileage_deducted')) {
                $table->dropColumn('is_mileage_deducted');
            }
        });
    }
};
