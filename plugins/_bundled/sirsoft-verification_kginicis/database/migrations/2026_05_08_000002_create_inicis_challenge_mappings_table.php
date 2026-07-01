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
     * 이니시스 mTxId(가맹점 거래 ID) ↔ challenge_id(코어 IdentityVerificationLog UUID) 매핑 테이블.
     *
     * 이니시스 매뉴얼 STEP2 callback 이 challenge_id 를 echo 하지 않으므로, STEP3 응답에서
     * 회수한 mTxId 로 challenge 를 역조회할 인덱스가 필요하다. 코어 `identity_verification_logs`
     * 테이블에 컬럼을 추가하는 대신 본 plugin 의 별도 테이블로 분리하여 코어 미수정 원칙을 지킨다.
     */
    public function up(): void
    {
        Schema::create('inicis_challenge_mappings', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->string('mtxid', 20)->unique()->comment('이니시스 가맹점 거래 ID (18자) — UNIQUE 인덱스로 callback 시점 challenge 역조회');
            $table->uuid('challenge_id')->comment('코어 IdentityVerificationLog UUID (provider_id=inicis 행)');
            $table->timestamp('created_at')->useCurrent()->comment('생성 시각');

            $table->foreign('challenge_id')
                ->references('id')
                ->on('identity_verification_logs')
                ->cascadeOnDelete();

            $table->index('challenge_id', 'idx_inicis_mappings_challenge_id');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('inicis_challenge_mappings', function (Blueprint $table) {
                $table->comment('이니시스 mTxId ↔ challenge_id 매핑 (callback 역조회용)');
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
        if (Schema::hasTable('inicis_challenge_mappings')) {
            Schema::dropIfExists('inicis_challenge_mappings');
        }
    }
};
