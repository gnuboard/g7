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
        Schema::create('ecommerce_shipping_types', function (Blueprint $table) {
            $table->id()->comment('배송유형 ID');
            $table->string('code', 50)->unique()->comment('고유 코드 (parcel, pickup 등)');
            $table->text('name')->comment('다국어 배송유형명 {"ko":"택배","en":"Parcel"}');
            $table->string('category', 20)->comment('카테고리: domestic(국내), international(해외), other(기타)');
            $table->boolean('is_active')->default(true)->comment('활성 여부: true(활성), false(비활성)');
            $table->integer('sort_order')->default(0)->comment('정렬 순서 (작을수록 먼저)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('생성자 ID');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('수정자 ID');
            $table->timestamps();

            $table->index('category', 'idx_shipping_types_category');
            $table->index('is_active', 'idx_shipping_types_is_active');
            $table->index(['is_active', 'category'], 'idx_shipping_types_active_category');
            $table->index('sort_order', 'idx_shipping_types_sort_order');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_shipping_types` COMMENT '배송유형 마스터'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_shipping_types')) {
            Schema::dropIfExists('ecommerce_shipping_types');
        }
    }
};
