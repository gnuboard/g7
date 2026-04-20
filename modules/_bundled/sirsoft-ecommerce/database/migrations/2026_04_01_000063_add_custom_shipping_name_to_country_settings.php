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
        Schema::table('ecommerce_shipping_policy_country_settings', function (Blueprint $table) {
            $table->text('custom_shipping_name')->nullable()
                ->after('shipping_method')
                ->comment('배송방법이 custom일 때 사용자 입력 배송방법명 (다국어 JSON)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_shipping_policy_country_settings')) {
            if (Schema::hasColumn('ecommerce_shipping_policy_country_settings', 'custom_shipping_name')) {
                Schema::table('ecommerce_shipping_policy_country_settings', function (Blueprint $table) {
                    $table->dropColumn('custom_shipping_name');
                });
            }
        }
    }
};
