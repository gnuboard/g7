<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ecommerce_shipping_carriers', function (Blueprint $table) {
            $table->id()->comment('배송사 ID');
            $table->string('code', 50)->unique()->comment('고유 코드 (cj, fedex 등)');
            $table->text('name')->comment('다국어 배송사명 {"ko":"CJ대한통운","en":"CJ Logistics"}');
            $table->string('type', 20)->comment('배송사 유형: domestic(국내), international(해외)');
            $table->string('tracking_url', 500)->nullable()->comment('배송 추적 URL 템플릿 ({tracking_number} 치환)');
            $table->boolean('is_active')->default(true)->comment('활성 여부: true(활성), false(비활성)');
            $table->integer('sort_order')->default(0)->comment('정렬 순서 (작을수록 먼저)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('생성자 ID');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('수정자 ID');
            $table->timestamps();

            $table->index('type', 'idx_carriers_type');
            $table->index('is_active', 'idx_carriers_is_active');
            $table->index(['is_active', 'type'], 'idx_carriers_active_type');
            $table->index('sort_order', 'idx_carriers_sort_order');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_shipping_carriers` COMMENT '배송사 마스터'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_shipping_carriers')) {
            Schema::dropIfExists('ecommerce_shipping_carriers');
        }
    }
};
