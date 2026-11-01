<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 주문상품 행마다 주문 시점에 확정된 과세 종류(옵션 지정 ?? 상품 기본값)를 기록한다 (#612).
     * 품목 배지·클레임 재계산이 상품·옵션 스냅샷을 다시 해석하지 않고 이 값을 읽는다.
     * 기존 행은 null 로 두고 업그레이드 스텝(1.3.3)이 상품 스냅샷 값으로 채운다.
     */
    public function up(): void
    {
        if (Schema::hasTable('ecommerce_order_options')
            && ! Schema::hasColumn('ecommerce_order_options', 'tax_status')) {
            Schema::table('ecommerce_order_options', function (Blueprint $table) {
                $table->string('tax_status', 20)->nullable()->after('subtotal_tax_free_amount')
                    ->comment('주문 시점 확정 과세 종류: taxable(과세)·tax_free(면세)·zero_rated(영세), null 은 업그레이드 백필 전 기존 행');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_order_options')
            && Schema::hasColumn('ecommerce_order_options', 'tax_status')) {
            Schema::table('ecommerce_order_options', function (Blueprint $table) {
                $table->dropColumn('tax_status');
            });
        }
    }
};
