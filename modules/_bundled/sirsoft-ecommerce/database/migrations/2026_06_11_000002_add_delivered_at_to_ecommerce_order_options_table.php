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
        if (Schema::hasColumn('ecommerce_order_options', 'delivered_at')) {
            return;
        }

        Schema::table('ecommerce_order_options', function (Blueprint $table) {
            // 배송완료 진입 시점 — confirmed_at 과 대칭. 지연 적립 기준(delivered_at + earn_delay_days)에 사용
            $table->timestamp('delivered_at')->nullable()->after('confirmed_at')->comment('배송완료 시점');
            $table->index('delivered_at', 'ecommerce_order_options_delivered_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('ecommerce_order_options', 'delivered_at')) {
            return;
        }

        Schema::table('ecommerce_order_options', function (Blueprint $table) {
            $table->dropIndex('ecommerce_order_options_delivered_at_index');
            $table->dropColumn('delivered_at');
        });
    }
};
