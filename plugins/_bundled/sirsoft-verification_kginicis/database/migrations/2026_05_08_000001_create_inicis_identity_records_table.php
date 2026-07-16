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
     * 사용자별 KG이니시스 본인확인 PII (Crypt 암호화) 1:1 보관 테이블.
     * 코어 identity_verification_logs 는 hash 만 보관하고, 평문은 본 테이블에만 저장한다.
     * 사용자 탈퇴/삭제 시 listener 가 명시 삭제 (CASCADE 미사용 — database-guide 규정).
     */
    public function up(): void
    {
        Schema::create('inicis_identity_records', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->comment('사용자 ID — UNIQUE 1:1. CASCADE 미설정 (탈퇴/삭제 시 listener 명시 삭제)');
            $table->uuid('latest_log_id')
                ->nullable()
                ->comment('현재 PII 가 발급된 challenge UUID — identity_verification_logs.id 참조 (감사 link)');
            $table->string('provider_dev_cd', 10)->nullable()->comment('통신사 코드 (이니시스 매뉴얼 providerDevCd)');
            $table->text('name_encrypted')->comment('실명 (Crypt::encrypt — 이니시스 userName 복호화 후 저장)');
            $table->text('phone_encrypted')->comment('휴대폰 (Crypt::encrypt — userPhone)');
            $table->text('birthday_encrypted')->comment('생년월일 YYYYMMDD (Crypt::encrypt — userBirthday)');
            $table->text('di_encrypted')->nullable()->comment('DI (Crypt::encrypt — userDi). 본인확인 reqSvcCd=03 전용');
            $table->string('di_hash', 64)->nullable()->comment('SHA256(userDi) — 동일인 검색용 인덱스');
            $table->text('ci_encrypted')->nullable()->comment('연계정보 CI 암호화 (Crypt::encrypt — userCi). 통신 3사 전 기관 공통 동일인 식별값');
            $table->string('ci_hash', 64)->nullable()->comment('연계정보 CI 해시 SHA256(userCi) — 동일인 검색용 인덱스');
            $table->text('ci2_encrypted')->nullable()->comment('연계정보 CI2 암호화 (Crypt::encrypt — userCi2). 본인확인 백업 CI');
            $table->string('ci2_hash', 64)->nullable()->comment('연계정보 CI2 해시 SHA256(userCi2) — 백업 동일인 검색');
            $table->char('gender', 1)->nullable()->comment('성별 M/F (이니시스 userGender)');
            $table->boolean('is_foreigner')->default(false)->comment('외국인 여부 (이니시스 isForeign — "0"/"1")');
            $table->boolean('is_adult')->default(false)->comment('성인 여부 (생년월일 기반 만 19세 이상 자동 계산)');
            $table->timestamp('verified_at')->comment('최초 본인확인 시각');
            $table->timestamp('re_verified_at')->nullable()->comment('재인증 시각 (재인증마다 갱신)');
            $table->timestamps();

            $table->index('di_hash', 'idx_inicis_records_di_hash');
            $table->index('ci_hash', 'idx_inicis_records_ci_hash');
            $table->index('ci2_hash', 'idx_inicis_records_ci2_hash');
            $table->index('is_adult', 'idx_inicis_records_is_adult');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('inicis_identity_records', function (Blueprint $table) {
                $table->comment('KG이니시스 본인확인 PII (Crypt 암호화, 사용자 1:1)');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * down() 은 migrate:rollback 시점에만 실행. 일반 plugin:uninstall 시 자동 실행 안 됨.
     * database-guide 규정상 down() 작성 의무.
     */
    public function down(): void
    {
        if (Schema::hasTable('inicis_identity_records')) {
            Schema::dropIfExists('inicis_identity_records');
        }
    }
};
