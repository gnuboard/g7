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
        Schema::create('ecommerce_product_label_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('label_id');
            $table->string('custom_color', 7)->nullable()->comment('상품별 커스텀 색상 (null이면 시스템 라벨 색상 사용)');
            $table->text('custom_name')->nullable()->comment('상품별 커스텀 다국어 라벨명 (null이면 시스템 라벨명 사용)');
            $table->date('start_date')->nullable()->comment('시작일');
            $table->date('end_date')->nullable()->comment('종료일');
            $table->timestamps();

            $table->index(['product_id', 'label_id'], 'ec_prod_label_assign_pid_lid_idx');
            $table->index(['start_date', 'end_date'], 'ec_prod_label_assign_dates_idx');
            $table->foreign('product_id')->references('id')->on('ecommerce_products')->cascadeOnDelete();
            $table->foreign('label_id')->references('id')->on('ecommerce_product_labels')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_product_label_assignments` COMMENT '상품-라벨 연결 정보'");
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_product_label_assignments')) {
            Schema::dropIfExists('ecommerce_product_label_assignments');
        }
    }
};
