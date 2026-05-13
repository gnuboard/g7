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
        Schema::create('ecommerce_shipping_policy_country_settings', function (Blueprint $table) {
            $table->id()->comment('국가별 설정 ID');
            $table->unsignedBigInteger('shipping_policy_id')->comment('배송정책 ID');
            $table->string('country_code', 10)->comment('국가코드 (KR, US 등)');
            $table->string('shipping_method', 50)->comment('배송방법: parcel(택배), collect(착불), quick(퀵서비스), direct(직접배송), pickup(방문수령), other(기타)');
            $table->string('currency_code', 10)->default('KRW')->comment('기준통화 코드');
            $table->string('charge_policy', 50)->comment('부과정책: free(무료), fixed(고정), conditional_free(조건부무료), range_amount(구간별금액), range_quantity(구간별수량), range_weight(구간별무게), range_volume(구간별부피), range_volume_weight(구간별부피+무게), per_quantity(단위수량), per_weight(단위무게), per_volume(단위부피), per_volume_weight(단위부피+무게), per_amount(단위금액), api(계산API)');
            $table->decimal('base_fee', 12, 2)->default(0)->comment('기본 배송비');
            $table->decimal('free_threshold', 12, 2)->nullable()->comment('무료배송 기준금액 (conditional_free 시 사용)');
            $table->mediumText('ranges')->nullable()->comment('구간별/단위별 설정 JSON: {type, tiers: [{min, max, fee}], unit_value}');
            $table->string('api_endpoint', 255)->nullable()->comment('배송비 계산 API URL');
            $table->text('api_request_fields')->nullable()->comment('API 전송 필드 목록');
            $table->string('api_response_fee_field', 100)->nullable()->comment('API 응답에서 배송비 값을 추출할 필드명');
            $table->boolean('extra_fee_enabled')->default(false)->comment('추가배송비(도서산간) 사용여부');
            $table->mediumText('extra_fee_settings')->nullable()->comment('추가배송비 설정 JSON: [{zipcode, fee}]');
            $table->boolean('extra_fee_multiply')->default(false)->comment('추가배송비 수량비례 적용여부');
            $table->boolean('is_active')->default(true)->comment('사용여부');
            $table->timestamps();

            $table->unique(['shipping_policy_id', 'country_code'], 'uniq_policy_country');
            $table->index('country_code');
            $table->index('is_active');
            $table->index(['shipping_policy_id', 'is_active'], 'idx_policy_active');
            $table->foreign('shipping_policy_id', 'fk_cs_shipping_policy_id')->references('id')->on('ecommerce_shipping_policies')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_shipping_policy_country_settings` COMMENT '배송정책 국가별 설정'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_shipping_policy_country_settings')) {
            Schema::dropIfExists('ecommerce_shipping_policy_country_settings');
        }
    }
};
