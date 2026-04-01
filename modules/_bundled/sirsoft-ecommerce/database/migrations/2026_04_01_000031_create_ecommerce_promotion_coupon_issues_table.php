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
        Schema::create('ecommerce_promotion_coupon_issues', function (Blueprint $table) {
            $table->id()->comment('발급 내역 ID');
            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('user_id');
            $table->string('coupon_code', 50)->nullable()->comment('쿠폰 코드 (다운로드 쿠폰 시)');
            $table->enum('status', ['available', 'used', 'expired', 'cancelled'])->default('available')->comment('상태: available(사용가능), used(사용완료), expired(만료), cancelled(취소)');
            $table->dateTime('issued_at')->comment('발급일시');
            $table->dateTime('expired_at')->nullable()->comment('만료일시');
            $table->dateTime('used_at')->nullable()->comment('사용일시');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->decimal('discount_amount', 15, 2)->nullable()->comment('실제 할인 금액');
            $table->timestamps();

            $table->index('coupon_id', 'idx_coupon_id');
            $table->index('user_id', 'idx_user_id');
            $table->index('status', 'idx_status');
            $table->index('issued_at', 'idx_issued_at');
            $table->index('coupon_code', 'idx_coupon_code');
            $table->foreign('coupon_id')->references('id')->on('ecommerce_promotion_coupons')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('ecommerce_orders')->nullOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_promotion_coupon_issues` COMMENT '쿠폰 발급 내역'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_promotion_coupon_issues')) {
            Schema::dropIfExists('ecommerce_promotion_coupon_issues');
        }
    }
};
