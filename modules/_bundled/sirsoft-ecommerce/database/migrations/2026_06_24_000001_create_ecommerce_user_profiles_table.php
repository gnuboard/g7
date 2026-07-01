<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 유저별 결제 통화 영속 저장 (A3). 코어 users 테이블을 건드리지 않고 커머스 모듈이
     * user_id FK 로 통화 설정을 보관한다(ecommerce_user_addresses 선례). 모듈 미설치 시
     * 테이블 자체가 부재해 "유저별 통화 = 커머스 책임" 설치 게이트를 구조적으로 충족(A5).
     */
    public function up(): void
    {
        if (Schema::hasTable('ecommerce_user_profiles')) {
            return;
        }

        Schema::create('ecommerce_user_profiles', function (Blueprint $table) {
            $table->id()->comment('이커머스 사용자 프로필 ID');
            $table->unsignedBigInteger('user_id')->comment('사용자 ID (코어 users FK)');
            $table->string('preferred_currency', 3)->nullable()->comment('선호 결제 통화 코드 (ISO 4217, 미설정 시 default_currency 폴백)');
            $table->timestamps();

            $table->unique('user_id', 'ecommerce_user_profiles_user_id_unique');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `'.DB::getTablePrefix()."ecommerce_user_profiles` COMMENT '이커머스 사용자별 프로필 (결제 통화 등)'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_user_profiles')) {
            Schema::dropIfExists('ecommerce_user_profiles');
        }
    }
};
