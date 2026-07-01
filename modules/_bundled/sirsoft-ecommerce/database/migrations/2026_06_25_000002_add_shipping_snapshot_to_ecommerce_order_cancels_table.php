<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 취소/환불 이력에 배송지(국가·우편번호) + 취소 대상 정책 스냅샷을 독립 보존 (B5, MP08 후속).
     * 기존 items_snapshot 은 취소 옵션/수량만 담아 도서산간·국가별 배송정책 재판단 맥락이 없었다.
     * 주문 주소(ecommerce_order_addresses)는 사후 변경/삭제될 수 있으므로 취소 "시점"의 배송국가·
     * 우편번호·정책을 취소 레코드에 복사해 환불 정책 판단을 이력 독립적으로 복원할 수 있게 한다.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ecommerce_order_cancels')) {
            return;
        }

        if (Schema::hasColumn('ecommerce_order_cancels', 'shipping_snapshot')) {
            return;
        }

        Schema::table('ecommerce_order_cancels', function (Blueprint $table) {
            $table->json('shipping_snapshot')
                ->nullable()
                ->after('items_snapshot')
                ->comment('취소 시점 배송지(국가/우편번호) + 취소 대상 배송정책 스냅샷 (환불 정책 재판단 복원용)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_order_cancels')) {
            return;
        }

        if (! Schema::hasColumn('ecommerce_order_cancels', 'shipping_snapshot')) {
            return;
        }

        Schema::table('ecommerce_order_cancels', function (Blueprint $table) {
            $table->dropColumn('shipping_snapshot');
        });
    }
};
