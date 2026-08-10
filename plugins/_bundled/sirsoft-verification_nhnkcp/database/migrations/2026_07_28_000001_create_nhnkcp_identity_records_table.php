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
     * 사용자별 NHN KCP 본인확인 PII (Crypt 암호화) 1:1 보관 테이블.
     * 코어 identity_verification_logs 는 hash 만 보관하고, 평문은 본 테이블에만 저장한다.
     * 사용자 탈퇴/삭제 시 listener 가 명시 삭제 (CASCADE 미사용 — database-guide 규정).
     */
    public function up(): void
    {
        Schema::create('nhnkcp_identity_records', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->foreignId('user_id')
                ->unique()
                ->comment('사용자 ID — UNIQUE 1:1. CASCADE 미설정 (탈퇴/삭제 시 listener 명시 삭제)')
                ->constrained('users');
            $table->uuid('latest_log_id')
                ->nullable()
                ->comment('현재 PII 가 발급된 challenge UUID — identity_verification_logs.id 참조 (감사 link)');
            $table->string('comm_id', 10)->nullable()->comment('통신사 코드 (KCP comm_id)');
            $table->text('name_encrypted')->nullable()->comment('실명 (Crypt::encryptString — KCP user_name)');
            $table->text('phone_encrypted')->nullable()->comment('휴대폰 (Crypt::encryptString — KCP phone_no)');
            $table->text('birthday_encrypted')->nullable()->comment('생년월일 YYYYMMDD (Crypt::encryptString — KCP birth_day)');
            $table->text('di_encrypted')->nullable()->comment('DI 암호화 (Crypt::encryptString — KCP DI). 사이트코드 종속 동일인 식별값');
            $table->string('di_hash', 64)->nullable()->comment('DI keyed-hash (HMAC-SHA256) — 동일인 검색용 인덱스');
            $table->text('ci_encrypted')->nullable()->comment('CI 암호화 (Crypt::encryptString — KCP CI). 전 기관 공통 동일인 식별값');
            $table->string('ci_hash', 64)->nullable()->comment('CI keyed-hash (HMAC-SHA256) — 동일인 검색용 인덱스');
            $table->char('gender', 1)->nullable()->comment('성별 M/F (KCP sex_code 01→M, 02→F)');
            $table->boolean('is_foreigner')->default(false)->comment('외국인 여부 (KCP local_code 02 → true)');
            $table->boolean('is_adult')->default(false)->comment('성인 여부 (생년월일 기반 만 19세 이상 자동 계산)');
            $table->timestamp('verified_at')->comment('최초 본인확인 시각');
            $table->timestamp('re_verified_at')->nullable()->comment('재인증 시각 (재인증마다 갱신)');
            $table->timestamps();

            $table->index('di_hash', 'idx_nhnkcp_records_di_hash');
            $table->index('ci_hash', 'idx_nhnkcp_records_ci_hash');
            $table->index('is_adult', 'idx_nhnkcp_records_is_adult');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('nhnkcp_identity_records', function (Blueprint $table) {
                $table->comment('NHN KCP 본인확인 PII (Crypt 암호화, 사용자 1:1)');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * down() 은 migrate:rollback 시점에만 실행. 일반 plugin:uninstall 시 자동 실행 안 됨.
     * database-guide 규정상 down() 작성 의무 + 테이블 존재 확인 후 삭제.
     */
    public function down(): void
    {
        if (Schema::hasTable('nhnkcp_identity_records')) {
            Schema::dropIfExists('nhnkcp_identity_records');
        }
    }
};
