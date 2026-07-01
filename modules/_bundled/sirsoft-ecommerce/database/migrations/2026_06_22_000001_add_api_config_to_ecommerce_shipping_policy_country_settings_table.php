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
            $table->text('api_config')->nullable()->after('api_response_fee_field')
                ->comment('계산 API 연동 상세 설정 JSON: {http_method(GET|POST), auth_type(none|bearer|custom_header), auth_token(암호화), auth_header_name, response_type(json|text), response_path, field_map{우리키:외부키}}');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('ecommerce_shipping_policy_country_settings', 'api_config')) {
            Schema::table('ecommerce_shipping_policy_country_settings', function (Blueprint $table) {
                $table->dropColumn('api_config');
            });
        }
    }
};
