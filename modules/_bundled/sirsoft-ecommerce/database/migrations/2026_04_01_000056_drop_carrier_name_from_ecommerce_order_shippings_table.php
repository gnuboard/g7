<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * order_shippings 테이블에서 carrier_name 컬럼 삭제
 *
 * 택배사명은 carrier_id 관계를 통해 사용자 로케일에 맞게 동적으로 조회합니다.
 */
return new class extends Migration
{
    /**
     * 마이그레이션 실행
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasColumn('ecommerce_order_shippings', 'carrier_name')) {
            Schema::table('ecommerce_order_shippings', function (Blueprint $table) {
                $table->dropColumn('carrier_name');
            });
        }
    }

    /**
     * 마이그레이션 롤백
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasColumn('ecommerce_order_shippings', 'carrier_name')) {
            Schema::table('ecommerce_order_shippings', function (Blueprint $table) {
                $table->string('carrier_name', 100)->nullable()->comment('택배사명 (주문 시점 스냅샷)')->after('carrier_id');
            });
        }
    }
};
