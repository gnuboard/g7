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
     * KCP 거래(거래등록 ~ 결과조회) ↔ challenge_id(코어 IdentityVerificationLog UUID) 매핑 테이블.
     *
     * KCP 결과조회 API 는 거래등록 응답의 `reg_cert_key` 와 가맹점 주문번호 `ordr_idxx` 를
     * 요구하므로, 콜백 시점까지 두 값을 보관해야 한다. 코어 identity_verification_logs 에
     * 컬럼을 추가하는 대신 본 plugin 전용 테이블로 분리하여 코어 미수정 원칙을 지킨다.
     *
     * 거래키는 평문 인덱스를 두지 않는다 — 조회는 keyed-hash 컬럼으로만 수행하고,
     * 결과조회 요청에 필요한 평문은 Crypt 암호화 컬럼에 보관한다.
     */
    public function up(): void
    {
        Schema::create('nhnkcp_cert_transactions', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->string('ordr_idxx', 50)->unique()->comment('가맹점 주문번호 (우리가 생성, 유니크) — KCP 결과조회 요청 필수값');
            $table->string('reg_cert_key_hash', 64)->unique()->comment('거래키 keyed-hash (HMAC-SHA256) — 콜백 역조회 인덱스 (평문 인덱스 금지)');
            $table->text('reg_cert_key_encrypted')->comment('거래키 암호화 보관 (Crypt::encryptString) — KCP 결과조회 요청용');
            $table->uuid('challenge_id')->comment('코어 IdentityVerificationLog UUID (provider_id=nhnkcp 행)');
            $table->timestamp('consumed_at')->nullable()->comment('결과조회 1회 소비 시각 (재사용 차단)');
            $table->timestamp('created_at')->useCurrent()->comment('생성 시각');

            $table->foreign('challenge_id')
                ->references('id')
                ->on('identity_verification_logs')
                ->cascadeOnDelete();

            $table->index('challenge_id', 'idx_nhnkcp_tx_challenge_id');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('nhnkcp_cert_transactions', function (Blueprint $table) {
                $table->comment('NHN KCP 거래 ↔ challenge_id 매핑 (콜백 역조회 + 결과조회용)');
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
        if (Schema::hasTable('nhnkcp_cert_transactions')) {
            Schema::dropIfExists('nhnkcp_cert_transactions');
        }
    }
};
