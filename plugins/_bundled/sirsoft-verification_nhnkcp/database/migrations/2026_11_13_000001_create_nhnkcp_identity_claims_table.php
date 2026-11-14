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
     * 동일인(DI/CI 해시) 선점표 — 한 해시는 한 회원만 쥔다(1.1.4, KVE-2026-3192).
     * 같은 본인확인 결과로 동시에 두 계정이 가입하면 records 표가 비어 있는 경합 창에서 둘 다 통과했다.
     * 가입 트랜잭션 안에서 UNIQUE(hash_type, hash) 로 선점해, 진 쪽 가입은 롤백된다.
     * 사용자 탈퇴/삭제 시 listener 가 명시 삭제한다 (CASCADE 미사용 — records 관례).
     */
    public function up(): void
    {
        if (Schema::hasTable('nhnkcp_identity_claims')) {
            return;
        }

        Schema::create('nhnkcp_identity_claims', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->string('hash_type', 8)->comment('식별값 종류 — di 또는 ci');
            $table->char('hash', 64)->comment('식별값 keyed-hash (HMAC-SHA256, records 의 di_hash/ci_hash 와 같은 값)');
            $table->unsignedBigInteger('user_id')->comment('선점한 사용자 ID — CASCADE 미설정 (탈퇴/삭제 시 listener 명시 삭제)');
            $table->timestamp('created_at')->nullable()->comment('선점 시각');

            $table->unique(['hash_type', 'hash'], 'uniq_nhnkcp_claims_type_hash');
            $table->index('user_id', 'idx_nhnkcp_claims_user_id');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('nhnkcp_identity_claims', function (Blueprint $table) {
                $table->comment('NHN KCP 동일인 식별값 선점표 (해시 1건 = 회원 1명)');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * down() 은 migrate:rollback 시점에만 실행. 일반 plugin:uninstall 시 자동 실행 안 됨.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhnkcp_identity_claims');
    }
};
