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
        Schema::create('ecommerce_search_presets', function (Blueprint $table) {
            $table->id()->comment('프리셋 ID');
            $table->unsignedBigInteger('user_id')->comment('사용자 ID');
            $table->string('target_screen', 50)->comment('대상 화면: products, orders, customers 등');
            $table->string('preset_name', 100)->comment('프리셋 이름');
            $table->mediumText('conditions')->comment('검색 조건 JSON');
            $table->unsignedInteger('sort_order')->default(0)->comment('정렬 순서');
            $table->boolean('is_default')->default(false)->comment('기본 프리셋 여부');
            $table->timestamps();

            $table->unique(['user_id', 'target_screen', 'preset_name'], 'ecommerce_search_presets_unique');
            $table->index(['user_id', 'target_screen'], 'ecommerce_search_presets_user_screen');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_search_presets` COMMENT '검색 프리셋 정보'");
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_search_presets')) {
            Schema::dropIfExists('ecommerce_search_presets');
        }
    }
};
