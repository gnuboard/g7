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
     * 사용자별 TOTP 자격증명 1:1 보관 테이블.
     * 시크릿과 백업 코드는 이 테이블에만 존재하며, 코어 identity_verification_logs 는
     * challenge 상태만 들고 평문을 갖지 않는다. 사용자 탈퇴/삭제 시 listener 가 명시
     * 삭제한다 (CASCADE 미사용 — database-guide 규정, nhnkcp 선례 동형).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('totp_credentials', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->foreignId('user_id')
                ->unique()
                ->comment('사용자 ID — UNIQUE 1:1. CASCADE 미설정 (탈퇴/삭제 시 listener 명시 삭제)')
                ->constrained('users');
            $table->text('secret')->comment('TOTP 시크릿 Base32 (Eloquent encrypted cast — 평문 저장 금지)');
            $table->timestamp('confirmed_at')->nullable()->comment('등록 확정 시각. null 이면 발급만 하고 첫 코드 확인을 마치지 않은 미확정 상태');
            $table->unsignedBigInteger('last_used_timestep')->nullable()->comment('마지막으로 통과한 timestep — 같거나 이전 코드의 재사용(리플레이) 차단용');
            $table->json('backup_codes')->nullable()->comment('백업 코드 해시 배열 (bcrypt, 10개). 1회용이라 사용 시 해당 항목 제거');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('totp_credentials', function (Blueprint $table) {
                $table->comment('인증 앱(TOTP) 자격증명 — 사용자 1:1');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * down() 은 migrate:rollback 시점에만 실행. 일반 plugin:uninstall 시 자동 실행 안 됨.
     * database-guide 규정상 down() 작성 의무 + 테이블 존재 확인 후 삭제.
     *
     * @return void
     */
    public function down(): void
    {
        if (Schema::hasTable('totp_credentials')) {
            Schema::dropIfExists('totp_credentials');
        }
    }
};
