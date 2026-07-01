<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 유저별 선호 배송국가(preferred_shipping_country) 영속 저장 (MP08 후속). 유저가 자신의
     * 결제 통화를 별도로 설정하듯(preferred_currency 선례) 배송국가도 영속 저장한다. 코어 users
     * 테이블을 건드리지 않고 커머스 모듈의 ecommerce_user_profiles 에 컬럼을 추가한다. 모듈 미설치
     * 시 테이블 자체가 부재해 "유저별 배송국가 = 커머스 책임" 설치 게이트를 구조적으로 충족한다.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ecommerce_user_profiles')) {
            return;
        }

        if (Schema::hasColumn('ecommerce_user_profiles', 'preferred_shipping_country')) {
            return;
        }

        Schema::table('ecommerce_user_profiles', function (Blueprint $table) {
            $table->string('preferred_shipping_country', 2)
                ->nullable()
                ->after('preferred_currency')
                ->comment('선호 배송국가 코드 (ISO 3166-1 alpha-2, 미설정 시 GeoIP→default_country 폴백)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_user_profiles')) {
            return;
        }

        if (! Schema::hasColumn('ecommerce_user_profiles', 'preferred_shipping_country')) {
            return;
        }

        Schema::table('ecommerce_user_profiles', function (Blueprint $table) {
            $table->dropColumn('preferred_shipping_country');
        });
    }
};
