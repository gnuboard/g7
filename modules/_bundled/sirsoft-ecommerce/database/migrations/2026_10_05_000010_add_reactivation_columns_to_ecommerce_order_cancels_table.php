<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 취소신청서마다 「이 취소로 빠진 상품을 나중에 다시 판매 상태로 되살렸는가」를 기록한다 (Q29 재활성 배지).
     * 재활성 이력(order_meta.reactivation_reclaim)은 주문 단위라 취소신청서마다 판정할 수 없다 — 취소 상품은 분할
     * 전 원 행을 가리키고 병합이 참조를 옮긴다. 그래서 행이 되살아나는 순간에 귀속한 수량과 시각을 남긴다.
     * 기존 행은 기본값(0·null)으로 둔다 — 과거 재활성은 귀속 근거가 없어 배지 없음.
     */
    public function up(): void
    {
        if (Schema::hasTable('ecommerce_order_cancels')) {
            Schema::table('ecommerce_order_cancels', function (Blueprint $table) {
                if (! Schema::hasColumn('ecommerce_order_cancels', 'reactivated_quantity')) {
                    $table->unsignedInteger('reactivated_quantity')->default(0)->after('cancelled_at')
                        ->comment('이 취소로 빠진 수량 중 재활성으로 되돌아간 수량 합 (Q29)');
                }
                if (! Schema::hasColumn('ecommerce_order_cancels', 'reactivated_at')) {
                    $table->timestamp('reactivated_at')->nullable()->after('reactivated_quantity')
                        ->comment('가장 최근에 이 취소의 상품이 재활성된 시각 (Q29 배지)');
                }
            });
        }

        if (Schema::hasTable('ecommerce_order_cancel_options')
            && ! Schema::hasColumn('ecommerce_order_cancel_options', 'reactivated_quantity')) {
            Schema::table('ecommerce_order_cancel_options', function (Blueprint $table) {
                $table->unsignedInteger('reactivated_quantity')->default(0)->after('cancel_quantity')
                    ->comment('cancel_quantity 중 재활성으로 되돌아간 수량 (귀속 상한 판정용, Q29)');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_order_cancels')) {
            foreach (['reactivated_at', 'reactivated_quantity'] as $column) {
                if (Schema::hasColumn('ecommerce_order_cancels', $column)) {
                    Schema::table('ecommerce_order_cancels', function (Blueprint $table) use ($column) {
                        $table->dropColumn($column);
                    });
                }
            }
        }

        if (Schema::hasTable('ecommerce_order_cancel_options')
            && Schema::hasColumn('ecommerce_order_cancel_options', 'reactivated_quantity')) {
            Schema::table('ecommerce_order_cancel_options', function (Blueprint $table) {
                $table->dropColumn('reactivated_quantity');
            });
        }
    }
};
