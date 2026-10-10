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
        // 재실행 안전: 컬럼·인덱스를 각각 확인한다. 컬럼이 있다고 통째로 건너뛰면, 컬럼만 만들고
        // 인덱스 단계에서 멈춘 설치본은 인덱스가 영영 생기지 않는다.
        if (! Schema::hasColumn('ecommerce_order_options', 'delivered_at')) {
            Schema::table('ecommerce_order_options', function (Blueprint $table) {
                // 배송완료 진입 시점 — confirmed_at 과 대칭. 지연 적립 기준(delivered_at + earn_delay_days)에 사용
                $table->timestamp('delivered_at')->nullable()->after('confirmed_at')->comment('배송완료 시점');
            });
        }

        if (! Schema::hasIndex('ecommerce_order_options', 'ecommerce_order_options_delivered_at_index')) {
            Schema::table('ecommerce_order_options', function (Blueprint $table) {
                $table->index('delivered_at', 'ecommerce_order_options_delivered_at_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasIndex('ecommerce_order_options', 'ecommerce_order_options_delivered_at_index')) {
            Schema::table('ecommerce_order_options', function (Blueprint $table) {
                $table->dropIndex('ecommerce_order_options_delivered_at_index');
            });
        }

        if (Schema::hasColumn('ecommerce_order_options', 'delivered_at')) {
            Schema::table('ecommerce_order_options', function (Blueprint $table) {
                $table->dropColumn('delivered_at');
            });
        }
    }
};
