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
            if (! Schema::hasColumn('ecommerce_orders', 'cancelled_at')) {
                // 취소일시 — 전체취소·부분취소 모두 최초 취소 발생 시각을 기록한다.
                // 다른 *_at 컬럼(paid_at/confirmed_at)과 일관되게 native 컬럼으로 두어 쿼리·정렬이 가능하다.
                // (종전 order_meta.cancelled_at accessor 는 부분취소에서 미기록 + 쿼리 불가 문제가 있었다.)
                $table->timestamp('cancelled_at')
                    ->nullable()
                    ->after('confirmed_at')
                    ->comment('취소일시 (전체/부분취소 최초 발생 시각)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ecommerce_orders', function (Blueprint $table) {
            if (Schema::hasColumn('ecommerce_orders', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
        });
    }
};
