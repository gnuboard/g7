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
        Schema::create('ecommerce_product_common_infos', function (Blueprint $table) {
            $table->id()->comment('공통정보 ID');
            $table->text('name')->comment('공통정보명 (다국어 JSON: {ko: "", en: ""})');
            $table->mediumText('content')->nullable()->comment('안내 내용 (다국어 JSON: {ko: "", en: ""})');
            $table->enum('content_mode', ['text', 'html'])->default('text')->comment('내용 모드 (text: 텍스트, html: HTML)');
            $table->boolean('is_default')->default(false)->comment('기본 설정 여부 (1: 기본, 0: 일반)');
            $table->boolean('is_active')->default(true)->comment('사용 여부 (1: 사용, 0: 미사용)');
            $table->unsignedInteger('sort_order')->default(0)->comment('정렬 순서');
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'ec_prod_common_info_active_sort_idx');
            $table->index('is_default', 'ec_prod_common_info_default_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_product_common_infos` COMMENT '상품 공통정보'");
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_product_common_infos')) {
            Schema::dropIfExists('ecommerce_product_common_infos');
        }
    }
};
