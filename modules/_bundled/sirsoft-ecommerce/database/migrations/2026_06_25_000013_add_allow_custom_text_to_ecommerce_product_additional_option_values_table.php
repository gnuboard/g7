<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ecommerce_product_additional_option_values', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_product_additional_option_values', 'allow_custom_text')) {
                $table->boolean('allow_custom_text')
                    ->default(false)
                    ->after('is_active')
                    ->comment('직접입력 허용 여부 (1: 허용 — 유저가 자유 텍스트 입력 필수, 0: 미허용)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_product_additional_option_values')) {
            return;
        }

        Schema::table('ecommerce_product_additional_option_values', function (Blueprint $table) {
            if (Schema::hasColumn('ecommerce_product_additional_option_values', 'allow_custom_text')) {
                $table->dropColumn('allow_custom_text');
            }
        });
    }
};
