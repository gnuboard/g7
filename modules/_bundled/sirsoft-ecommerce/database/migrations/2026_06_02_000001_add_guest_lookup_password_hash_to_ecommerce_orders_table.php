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
        Schema::table('ecommerce_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_orders', 'guest_lookup_password_hash')) {
                $table->string('guest_lookup_password_hash', 255)
                    ->nullable()
                    ->after('order_number')
                    ->comment('비회원 주문 조회 비밀번호 해시 (회원 주문은 null)');
            }
        });
    }

    /**
     * 마이그레이션을 롤백합니다.
     */
    public function down(): void
    {
        if (Schema::hasColumn('ecommerce_orders', 'guest_lookup_password_hash')) {
            Schema::table('ecommerce_orders', function (Blueprint $table) {
                $table->dropColumn('guest_lookup_password_hash');
            });
        }
    }
};
