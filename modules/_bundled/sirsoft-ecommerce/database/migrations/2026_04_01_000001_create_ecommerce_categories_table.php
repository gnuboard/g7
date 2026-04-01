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
        Schema::create('ecommerce_categories', function (Blueprint $table) {
            $table->id()->comment('카테고리 ID');
            $table->text('name')->comment('카테고리명 (다국어 JSON: {ko: "...", en: "..."})');
            $table->mediumText('description')->nullable()->comment('카테고리 설명 (다국어 JSON: {ko: "...", en: "..."})');
            $table->unsignedBigInteger('parent_id')->nullable()->comment('부모 카테고리 ID');
            $table->string('path', 500)->comment('Materialized Path: 1/5/23');
            $table->unsignedInteger('depth')->default(0)->comment('계층 깊이');
            $table->unsignedInteger('sort_order')->default(0)->comment('정렬 순서');
            $table->boolean('is_active')->default(true)->comment('활성 여부: true(활성), false(비활성)');
            $table->string('slug', 200)->nullable()->comment('URL 슬러그');
            $table->string('meta_title', 200)->nullable()->comment('SEO 제목');
            $table->text('meta_description')->nullable()->comment('SEO 설명');
            $table->timestamps();

            $table->index('parent_id');
            $table->index('path');
            $table->index('depth');
            $table->index('sort_order');
            $table->index('is_active');
            $table->index('slug');
            $table->index(['parent_id', 'sort_order']);
            $table->foreign('parent_id')->references('id')->on('ecommerce_categories')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_categories` COMMENT '상품 카테고리 정보'");
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_categories')) {
            Schema::dropIfExists('ecommerce_categories');
        }
    }
};
