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
        if (Schema::hasColumn('ecommerce_order_options', 'mc_subtotal_earned_points_amount')) {
            return;
        }

        Schema::table('ecommerce_order_options', function (Blueprint $table) {
            // 사용액(mc_subtotal_points_used_amount)은 존재하나 적립액 다중통화 컬럼이 부재한 비대칭 해소 (표시용)
            $table->text('mc_subtotal_earned_points_amount')->nullable()->after('mc_subtotal_points_used_amount')->comment('적립 예정 포인트 다중 통화');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('ecommerce_order_options', 'mc_subtotal_earned_points_amount')) {
            return;
        }

        Schema::table('ecommerce_order_options', function (Blueprint $table) {
            $table->dropColumn('mc_subtotal_earned_points_amount');
        });
    }
};
