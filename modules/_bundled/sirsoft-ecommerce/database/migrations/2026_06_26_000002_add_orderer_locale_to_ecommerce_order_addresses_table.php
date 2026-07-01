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
        if (Schema::hasColumn('ecommerce_order_addresses', 'orderer_locale')) {
            return;
        }

        Schema::table('ecommerce_order_addresses', function (Blueprint $table) {
            $table->string('orderer_locale', 10)
                ->nullable()
                ->after('orderer_email')
                ->comment('주문자 선호 언어 (주문 시점 화면 언어 스냅샷, 비회원 알림 발송 언어 결정용)');
        });
    }

    /**
     * 마이그레이션을 롤백합니다.
     */
    public function down(): void
    {
        if (Schema::hasColumn('ecommerce_order_addresses', 'orderer_locale')) {
            Schema::table('ecommerce_order_addresses', function (Blueprint $table) {
                $table->dropColumn('orderer_locale');
            });
        }
    }
};
