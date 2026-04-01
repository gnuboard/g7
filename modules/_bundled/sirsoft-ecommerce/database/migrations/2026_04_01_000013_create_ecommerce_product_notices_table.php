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
        Schema::create('ecommerce_product_notices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->mediumText('values')->nullable()->comment('고시항목 값 JSON');
            $table->timestamps();

            $table->unique('product_id');
            $table->foreign('product_id')->references('id')->on('ecommerce_products')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_product_notices` COMMENT '상품정보제공고시'");
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_product_notices')) {
            Schema::dropIfExists('ecommerce_product_notices');
        }
    }
};
