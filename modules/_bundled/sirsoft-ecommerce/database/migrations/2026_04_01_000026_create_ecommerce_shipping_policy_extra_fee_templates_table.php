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
            $table->string('zipcode', 20)->comment('우편번호 (단일 또는 범위)');
            $table->decimal('fee', 12, 2)->default(0)->comment('추가 배송비');
            $table->string('region', 100)->nullable()->comment('지역명 (예: 제주도, 울릉도)');
            $table->text('description')->nullable()->comment('설명 (예: 도서산간 지역)');
            $table->boolean('is_active')->default(true)->comment('사용여부: true(사용), false(미사용)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('생성자 ID');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('수정자 ID');
            $table->timestamps();

            // 인덱스/유니크 이름을 명시한다. 테이블명이 길어 자동 생성명을 쓰면
            // DB prefix 가 4자 이상일 때 MySQL identifier 한도(64자)를 초과한다.
            $table->unique('zipcode', 'uniq_extra_fee_tpl_zipcode');
            $table->index('region');
            $table->index('is_active', 'idx_extra_fee_tpl_is_active');
            $table->index('fee');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `'.DB::getTablePrefix()."ecommerce_shipping_policy_extra_fee_templates` COMMENT '배송 추가비용 템플릿'");
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
