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
        Schema::create('ecommerce_product_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action_type', 50)->comment('액션 타입: created(등록), updated(수정), status_changed(상태변경), deleted(삭제)');
            $table->text('action_detail')->nullable()->comment('상세 내용');
            $table->string('ip_address', 45)->nullable()->comment('IP 주소');
            $table->text('user_agent')->nullable()->comment('User Agent');
            $table->timestamp('created_at')->useCurrent()->comment('생성일시');

            $table->index(['product_id', 'created_at'], 'ec_prod_logs_pid_created_idx');
            $table->index('action_type', 'ec_prod_logs_action_type_idx');
            $table->foreign('product_id')->references('id')->on('ecommerce_products')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_product_logs` COMMENT '상품 변경 이력'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_product_logs')) {
            Schema::dropIfExists('ecommerce_product_logs');
        }
    }
};
