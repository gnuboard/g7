<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 마이그레이션 실행
     */
    public function up(): void
    {
        Schema::table('ecommerce_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_orders', 'total_cash_equivalent_amount')) {
                $table->decimal('total_cash_equivalent_amount', 12, 2)
                    ->default(0)
                    ->after('total_due_amount')
                    ->comment('주문 전체 현금성 결제 금액 (현금영수증 발급 대상)');
            }

            if (! Schema::hasColumn('ecommerce_orders', 'mc_total_cash_equivalent_amount')) {
                $table->text('mc_total_cash_equivalent_amount')
                    ->nullable()
                    ->comment('현금성 결제 금액 다중 통화');
            }
        });

        Schema::table('ecommerce_order_options', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_order_options', 'subtotal_cash_equivalent_amount')) {
                $table->decimal('subtotal_cash_equivalent_amount', 12, 2)
                    ->default(0)
                    ->comment('옵션별 현금성 금액 안분액');
            }

            if (! Schema::hasColumn('ecommerce_order_options', 'mc_subtotal_cash_equivalent_amount')) {
                $table->text('mc_subtotal_cash_equivalent_amount')
                    ->nullable()
                    ->comment('옵션별 현금성 금액 다중 통화');
            }
        });
    }

    /**
     * 마이그레이션 롤백
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_orders')) {
            Schema::table('ecommerce_orders', function (Blueprint $table) {
                foreach (['total_cash_equivalent_amount', 'mc_total_cash_equivalent_amount'] as $column) {
                    if (Schema::hasColumn('ecommerce_orders', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('ecommerce_order_options')) {
            Schema::table('ecommerce_order_options', function (Blueprint $table) {
                foreach (['subtotal_cash_equivalent_amount', 'mc_subtotal_cash_equivalent_amount'] as $column) {
                    if (Schema::hasColumn('ecommerce_order_options', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
