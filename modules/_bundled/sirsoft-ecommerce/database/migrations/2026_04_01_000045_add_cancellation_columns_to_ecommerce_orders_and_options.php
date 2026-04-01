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
            $table->unsignedInteger('cancellation_count')->default(0)
                ->after('total_earned_points_amount')
                ->comment('취소 횟수');
        });

        Schema::table('ecommerce_order_options', function (Blueprint $table) {
            $table->unsignedInteger('cancelled_quantity')->default(0)
                ->after('quantity')
                ->comment('취소된 수량 (누적)');
            $table->string('cancel_reason', 30)->nullable()
                ->after('cancelled_quantity')
                ->comment('취소 사유 코드 (ecommerce_claim_reasons.code 참조)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_orders') && Schema::hasColumn('ecommerce_orders', 'cancellation_count')) {
            Schema::table('ecommerce_orders', function (Blueprint $table) {
                $table->dropColumn('cancellation_count');
            });
        }

        if (Schema::hasTable('ecommerce_order_options')) {
            Schema::table('ecommerce_order_options', function (Blueprint $table) {
                if (Schema::hasColumn('ecommerce_order_options', 'cancelled_quantity')) {
                    $table->dropColumn('cancelled_quantity');
                }
                if (Schema::hasColumn('ecommerce_order_options', 'cancel_reason')) {
                    $table->dropColumn('cancel_reason');
                }
            });
        }
    }
};
