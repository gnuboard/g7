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
     * 회원과 IdP 신원의 연결 1행. 연결 키는 (IdP, IdP 안의 고유 식별자)이고 이메일은 키가 아니다. subject 는 대소문자를 구분해야
     * 하므로 utf8mb4_bin 으로 비교한다 — 기본 collation 이면 대소문자만 다른 두 신원이 한 회원에 묶인다.
     * 회원 탈퇴, 삭제 때는 리스너가 명시 삭제한다(CASCADE 미사용).
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('sso_accounts')) {
            return;
        }

        Schema::create('sso_accounts', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->foreignId('idp_id')
                ->comment('IdP ID — CASCADE 미설정')
                ->constrained('sso_identity_providers');
            $table->foreignId('user_id')
                ->comment('회원 ID — CASCADE 미설정 (회원 탈퇴, 삭제 시 리스너가 명시 삭제)')
                ->constrained('users');
            $table->string('subject', 191)->collation('utf8mb4_bin')->comment('IdP 안의 고유 식별자 — 대소문자 구분(utf8mb4_bin)');
            $table->string('subject_format', 100)->nullable()->comment('식별자 형식 (SAML NameID Format)');
            $table->string('email', 255)->nullable()->comment('IdP 가 준 이메일 — 연결 키가 아니다');
            $table->string('display_name', 255)->nullable()->comment('IdP 가 준 표시 이름');
            $table->string('linked_via', 10)->comment('연결 방식 — jit, domain, verify, admin, csv, scim');
            $table->string('scim_external_id', 191)->nullable()->collation('utf8mb4_bin')->comment('SCIM externalId — 대소문자 구분(utf8mb4_bin)');
            $table->boolean('active')->default(true)->comment('활성 여부 — 퇴사 처리되면 false');
            $table->timestamp('deprovisioned_at')->nullable()->comment('퇴사 처리 시각');
            $table->string('deprovision_reason', 30)->nullable()->comment('퇴사 처리 출처 — scim, periodic, login, admin');
            $table->timestamp('blocked_user_at')->nullable()->comment('이 연결 때문에 회원을 차단한 시각 — 해제는 회원 차단 시각이 이 값과 같을 때만');
            $table->text('refresh_token')->nullable()->comment('IdP 갱신 토큰 (encrypted cast) — 주기 점검을 켠 OIDC IdP 에서만 저장');
            $table->timestamp('linked_at')->nullable()->comment('연결 시각');
            $table->timestamp('last_login_at')->nullable()->comment('이 연결로 마지막 로그인한 시각');
            $table->timestamps();

            $table->unique(['idp_id', 'subject'], 'sso_accounts_idp_subject_unique');
            $table->unique(['idp_id', 'user_id'], 'sso_accounts_idp_user_unique');
            $table->index(['idp_id', 'scim_external_id'], 'sso_accounts_idp_scim_external_index');
            $table->index('user_id', 'sso_accounts_user_index');
            $table->index('deprovisioned_at', 'sso_accounts_deprovisioned_index');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('sso_accounts', function (Blueprint $table) {
                $table->comment('회원-회사 IdP 신원 연결');
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
        Schema::dropIfExists('sso_accounts');
    }
};
