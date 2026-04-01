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
        Schema::create('ecommerce_shipping_policies', function (Blueprint $table) {
            $table->id()->comment('배송정책 ID');
            $table->text('name')->comment('배송정책명 (다국어 JSON: {ko: "...", en: "..."})');
            $table->boolean('is_active')->default(true)->comment('사용여부: true(사용), false(미사용)');
            $table->boolean('is_default')->default(false)->comment('기본 배송정책 여부');
            $table->integer('sort_order')->default(0)->comment('정렬순서 (낮을수록 먼저)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('생성자 ID');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('수정자 ID');
            $table->timestamps();

            $table->index('is_active');
            $table->index('sort_order');
            $table->index('created_at');
            $table->index(['is_active', 'sort_order']);
            $table->index('is_default');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_shipping_policies` COMMENT '배송 정책 정보'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_shipping_policies')) {
            Schema::dropIfExists('ecommerce_shipping_policies');
        }
    }
};
