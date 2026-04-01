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
        Schema::create('ecommerce_brands', function (Blueprint $table) {
            $table->id()->comment('브랜드 ID');
            $table->text('name')->comment('브랜드명 (다국어 JSON: {ko: "삼성", en: "Samsung"})');
            $table->string('slug', 200)->unique()->comment('슬러그 (URL 식별자)');
            $table->string('website', 500)->nullable()->comment('브랜드 웹사이트 URL');
            $table->unsignedInteger('sort_order')->default(0)->comment('정렬 순서');
            $table->boolean('is_active')->default(true)->comment('활성화 (true: 활성, false: 비활성)');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('sort_order');
            $table->index(['is_active', 'sort_order']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_brands` COMMENT '브랜드 정보'");
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_brands')) {
            Schema::dropIfExists('ecommerce_brands');
        }
    }
};
