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
        Schema::create('ecommerce_product_labels', function (Blueprint $table) {
            $table->id();
            $table->text('name')->comment('라벨명 (다국어 JSON)');
            $table->string('color', 20)->nullable()->comment('색상 코드 (예: #FF5733)');
            $table->boolean('is_active')->default(true)->comment('활성화 여부');
            $table->unsignedInteger('sort_order')->default(0)->comment('정렬 순서');
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'ec_prod_labels_active_sort_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_product_labels` COMMENT '상품 라벨 정보'");
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_product_labels')) {
            Schema::dropIfExists('ecommerce_product_labels');
        }
    }
};
