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
        Schema::create('ecommerce_shipping_policy_extra_fee_templates', function (Blueprint $table) {
            $table->id()->comment('추가배송비 템플릿 ID');
            $table->string('zipcode', 20)->unique()->comment('우편번호 (단일 또는 범위)');
            $table->decimal('fee', 12, 2)->default(0)->comment('추가 배송비');
            $table->string('region', 100)->nullable()->comment('지역명 (예: 제주도, 울릉도)');
            $table->text('description')->nullable()->comment('설명 (예: 도서산간 지역)');
            $table->boolean('is_active')->default(true)->comment('사용여부: true(사용), false(미사용)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('생성자 ID');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('수정자 ID');
            $table->timestamps();

            $table->index('region');
            $table->index('is_active');
            $table->index('fee');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_shipping_policy_extra_fee_templates` COMMENT '배송 추가비용 템플릿'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_shipping_policy_extra_fee_templates')) {
            Schema::dropIfExists('ecommerce_shipping_policy_extra_fee_templates');
        }
    }
};
