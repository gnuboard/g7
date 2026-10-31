<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 운영자가 환불수단을 직접 지정했는지 기록한다 (P-D3). 신청 시점에 PG 플러그인이 꺼져 있어 카드 주문이
     * 「계좌 환불」 로 저장되면, 플러그인을 다시 켜도 실행이 PG 를 부르지 않고 끝났다. 운영자가 고른 수단이
     * 아니면 실행 시점에 수단을 다시 판정한다. 기존 행은 false(다시 판정 대상)로 둔다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (! Schema::hasTable('ecommerce_order_refunds') || Schema::hasColumn('ecommerce_order_refunds', 'refund_method_overridden')) {
            return;
        }

        Schema::table('ecommerce_order_refunds', function (Blueprint $table) {
            $table->boolean('refund_method_overridden')->default(false)->after('refund_method')
                ->comment('운영자가 환불수단을 직접 지정했는지 (false 면 실행 시 수단을 다시 판정 — P-D3)');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_order_refunds') && Schema::hasColumn('ecommerce_order_refunds', 'refund_method_overridden')) {
            Schema::table('ecommerce_order_refunds', function (Blueprint $table) {
                $table->dropColumn('refund_method_overridden');
            });
        }
    }
};
