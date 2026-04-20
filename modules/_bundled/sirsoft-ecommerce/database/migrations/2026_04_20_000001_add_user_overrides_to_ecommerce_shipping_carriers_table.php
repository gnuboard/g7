<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ecommerce_shipping_carriers 테이블에 user_overrides 컬럼 추가.
     *
     * 사용자가 수정한 필드명 목록을 저장하여 시더 재실행 시 보존합니다.
     * (ShippingCarrierSeeder 를 GenericEntitySyncHelper 패턴으로 전환하면서 도입)
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable('ecommerce_shipping_carriers') || Schema::hasColumn('ecommerce_shipping_carriers', 'user_overrides')) {
            return;
        }

        Schema::table('ecommerce_shipping_carriers', function (Blueprint $table) {
            $table->text('user_overrides')->nullable()
                ->comment('유저가 수정한 필드명 목록 (예: ["name", "tracking_url", "sort_order"])');
        });
    }

    /**
     * user_overrides 컬럼 삭제.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_shipping_carriers') || ! Schema::hasColumn('ecommerce_shipping_carriers', 'user_overrides')) {
            return;
        }

        Schema::table('ecommerce_shipping_carriers', function (Blueprint $table) {
            $table->dropColumn('user_overrides');
        });
    }
};
