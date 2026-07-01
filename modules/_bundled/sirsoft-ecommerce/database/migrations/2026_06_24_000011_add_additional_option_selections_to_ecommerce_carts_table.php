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
        Schema::table('ecommerce_carts', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_carts', 'additional_option_selections')) {
                $table->text('additional_option_selections')
                    ->nullable()
                    ->after('product_option_id')
                    ->comment('선택된 추가옵션 (JSON: [{additional_option_id, value_id}])');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_carts')) {
            return;
        }

        Schema::table('ecommerce_carts', function (Blueprint $table) {
            if (Schema::hasColumn('ecommerce_carts', 'additional_option_selections')) {
                $table->dropColumn('additional_option_selections');
            }
        });
    }
};
