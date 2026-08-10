<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 주문 시점 마일리지 사용 정책 스냅샷을 주문에 보존 (E1).
     * 통화·프로모션·배송정책은 이미 주문 시점 스냅샷을 남기는데 마일리지 사용 정책
     * (최소 사용액/사용 단위/최대 한도)만 빠져 있었다. 관리자가 이후 한도를 변경하면
     * 과거 주문이 어떤 정책 아래 성립했는지 재현할 수 없어 정산·감사·분쟁 대응이 불가하다.
     * 기존 주문은 당시 설정을 알 수 없으므로 백필하지 않고 null 로 둔다(정책 미상).
     */
    public function up(): void
    {
        if (! Schema::hasTable('ecommerce_orders')) {
            return;
        }

        if (Schema::hasColumn('ecommerce_orders', 'mileage_policy_snapshot')) {
            return;
        }

        Schema::table('ecommerce_orders', function (Blueprint $table) {
            $table->mediumText('mileage_policy_snapshot')
                ->nullable()
                ->after('shipping_policy_applied_snapshot')
                ->comment('주문 시점 마일리지 사용 정책 스냅샷 (최소 사용액/사용 단위/한도 재현용)');
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

        if (! Schema::hasColumn('ecommerce_orders', 'mileage_policy_snapshot')) {
            return;
        }

        Schema::table('ecommerce_orders', function (Blueprint $table) {
            $table->dropColumn('mileage_policy_snapshot');
        });
    }
};
