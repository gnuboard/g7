<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 주문의 영세 금액을 따로 기록한다 (#612). 영세는 과세표준(total_tax_amount)에 포함되고
     * 부가세는 0 이라, 결제사·현금영수증에 면세로 넘기거나 공급가액을 구할 때 이 몫을 빼내야 한다.
     * 기존 주문은 0 이 맞다 — 영세를 지정할 수단이 없었다.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ecommerce_orders')) {
            return;
        }

        Schema::table('ecommerce_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_orders', 'total_zero_rated_amount')) {
                $table->decimal('total_zero_rated_amount', 12, 2)->default(0)->after('total_vat_amount')
                    ->comment('총 영세금액 (총 과세금액에 포함, 부가세 0)');
            }
            if (! Schema::hasColumn('ecommerce_orders', 'mc_total_zero_rated_amount')) {
                $table->text('mc_total_zero_rated_amount')->nullable()->after('mc_total_tax_free_amount')
                    ->comment('다중 통화 총 영세금액 (JSON)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_orders')) {
            return;
        }

        foreach (['mc_total_zero_rated_amount', 'total_zero_rated_amount'] as $column) {
            if (Schema::hasColumn('ecommerce_orders', $column)) {
                Schema::table('ecommerce_orders', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
