<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 교환으로 받은 상품을 반품·환불할 때 돌려줄 「상위 옵션 교환 차액」을 클레임 결제 건별로 기록한다 (S2 · Q9).
     * 주문 재계산은 원 행 단가만 돌려주고, 교환 때 추가결제한 차액은 별도 장부에 남아 돌아오지 않았다.
     * 기존 행은 NULL 로 둔다(돌려줄 차액 없음).
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (! Schema::hasTable('ecommerce_order_refunds') || Schema::hasColumn('ecommerce_order_refunds', 'exchange_difference_refunds')) {
            return;
        }

        Schema::table('ecommerce_order_refunds', function (Blueprint $table) {
            $table->json('exchange_difference_refunds')->nullable()->after('refund_shipping_amount')
                ->comment('교환 차액 환급 몫 [{claim_payment_id, amount, points_amount}] — 낸 수단 그대로 돌려준다 (S2)');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_order_refunds') && Schema::hasColumn('ecommerce_order_refunds', 'exchange_difference_refunds')) {
            Schema::table('ecommerce_order_refunds', function (Blueprint $table) {
                $table->dropColumn('exchange_difference_refunds');
            });
        }
    }
};
