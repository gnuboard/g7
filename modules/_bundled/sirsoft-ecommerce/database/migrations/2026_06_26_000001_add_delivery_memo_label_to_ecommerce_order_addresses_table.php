<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 마이그레이션을 실행합니다.
     */
    public function up(): void
    {
        Schema::table('ecommerce_order_addresses', function (Blueprint $table) {
            $table->text('delivery_memo_label')
                ->nullable()
                ->after('delivery_memo')
                ->comment('배송 메모 표시 라벨 (프리셋 키의 주문시점 스냅샷, custom은 원문)');
        });
    }

    /**
     * 마이그레이션을 롤백합니다.
     */
    public function down(): void
    {
        if (Schema::hasColumn('ecommerce_order_addresses', 'delivery_memo_label')) {
            Schema::table('ecommerce_order_addresses', function (Blueprint $table) {
                $table->dropColumn('delivery_memo_label');
            });
        }
    }
};
