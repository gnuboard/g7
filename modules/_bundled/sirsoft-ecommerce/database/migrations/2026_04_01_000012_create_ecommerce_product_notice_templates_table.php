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
        Schema::create('ecommerce_product_notice_templates', function (Blueprint $table) {
            $table->id();
            $table->text('name')->comment('템플릿명 (다국어)');
            $table->string('category', 100)->nullable()->comment('품목 카테고리');
            $table->mediumText('fields')->comment('필드 정의 JSON');
            $table->boolean('is_active')->default(true)->comment('활성화 여부');
            $table->unsignedInteger('sort_order')->default(0)->comment('정렬 순서');
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'ec_prod_notice_tpl_active_sort_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_product_notice_templates` COMMENT '상품정보제공고시 템플릿'");
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_product_notice_templates')) {
            Schema::dropIfExists('ecommerce_product_notice_templates');
        }
    }
};
