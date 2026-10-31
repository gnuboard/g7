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
     * SSO 로그인 시도 이력. 이력이라 IdP, 회원 FK 를 두지 않는다(IdP 나 회원이 지워져도 기록은 보존 기간까지 남는다).
     * 보존 기간이 지난 행은 정리 배치가 지운다.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('sso_login_attempts')) {
            return;
        }

        Schema::create('sso_login_attempts', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->unsignedBigInteger('idp_id')->nullable()->comment('IdP ID (FK 없음 — 이력)');
            $table->unsignedBigInteger('user_id')->nullable()->comment('회원 ID (FK 없음 — 이력)');
            $table->string('intent', 10)->comment('의도 — login, link, reauth, test');
            $table->string('guard', 5)->comment('로그인 경로 — user, admin');
            $table->string('outcome', 20)->comment('결과 — handoff, pending, link_pending, linked, reauth, test, failed, denied');
            $table->string('reason_code', 50)->nullable()->comment('실패 사유 코드');
            $table->string('ip_address', 45)->nullable()->comment('요청 IP');
            $table->string('user_agent', 500)->nullable()->comment('요청 브라우저 정보');
            $table->timestamp('created_at')->nullable()->comment('기록 시각');

            $table->index(['idp_id', 'created_at'], 'sso_login_attempts_idp_created_index');
            $table->index(['outcome', 'created_at'], 'sso_login_attempts_outcome_created_index');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('sso_login_attempts', function (Blueprint $table) {
                $table->comment('SSO 로그인 시도 이력');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('sso_login_attempts');
    }
};
