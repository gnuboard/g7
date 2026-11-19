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
     * SSO 로그인 1회 = 1행. G7 토큰과 IdP 세션(SAML SessionIndex, OIDC sid)을 잇는다 — 로그아웃 때 IdP 로 보낼 세션을 찾고,
     * IdP 발 로그아웃 때 끝낼 G7 토큰을 찾는다. 회원 탈퇴, 삭제 때는 리스너가 명시 삭제한다(CASCADE 미사용).
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('sso_sessions')) {
            return;
        }

        Schema::create('sso_sessions', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->foreignId('idp_id')
                ->comment('IdP ID — CASCADE 미설정')
                ->constrained('sso_identity_providers');
            $table->foreignId('user_id')
                ->comment('회원 ID — CASCADE 미설정 (회원 탈퇴, 삭제 시 리스너가 명시 삭제)')
                ->constrained('users');
            $table->unsignedBigInteger('token_id')->nullable()->comment('G7 토큰 ID (personal_access_tokens.id) — 로그인 완료 때 채움, 2단계 인증 중 이탈이면 NULL');
            $table->string('external_session', 255)->nullable()->comment('IdP 세션 참조 (SAML SessionIndex, OIDC sid)');
            $table->string('name_id', 255)->nullable()->comment('IdP 쪽 회원 식별자 (SAML NameID, OIDC sub) — IdP 발 로그아웃 대조용');
            $table->timestamp('created_at')->nullable()->comment('SSO 로그인 시각');
            $table->timestamp('ended_at')->nullable()->comment('세션 종료 시각');
            $table->string('end_reason', 20)->nullable()->comment('종료 사유 — logout, idp_logout, deprovisioned, withdrawn');

            $table->index('token_id', 'sso_sessions_token_index');
            $table->index(['idp_id', 'external_session'], 'sso_sessions_idp_external_index');
            $table->index(['user_id', 'ended_at'], 'sso_sessions_user_ended_index');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('sso_sessions', function (Blueprint $table) {
                $table->comment('SSO 로그인 세션 (G7 토큰 ↔ IdP 세션)');
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
        Schema::dropIfExists('sso_sessions');
    }
};
