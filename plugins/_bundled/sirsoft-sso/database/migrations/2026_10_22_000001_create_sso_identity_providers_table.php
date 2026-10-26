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
     * 회사 IdP 1행. 프로토콜 설정(`config`)은 비밀이 아닌 값만, 비밀값(OIDC client_secret, SP 개인키, LDAP 바인드 비밀번호)은
     * `secrets` 에 encrypted:array 로 둔다. 정책 키 기본값은 계획된 운영 기본값이다(관리자 화면에서 IdP 마다 바꾼다).
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('sso_identity_providers')) {
            return;
        }

        Schema::create('sso_identity_providers', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->string('key', 50)->comment('IdP 키 — 영소문자로 시작하는 영소문자·숫자·밑줄 2~50자, mock_ 접두는 모의 IdP 예약');
            $table->string('protocol', 10)->comment('프로토콜 키 — oidc, saml, ldap 또는 확장이 등록한 키');
            $table->boolean('enabled')->default(false)->comment('사용 여부');
            $table->smallInteger('sort_order')->default(0)->comment('로그인 화면 버튼 순서 (작을수록 앞)');
            $table->json('label')->comment('표시 이름 — 로케일별 {ko, en}');
            $table->boolean('button_visible')->default(true)->comment('로그인 화면에 버튼을 보일지');
            $table->json('button_style')->nullable()->comment('버튼 색 {bg, fg, border} — null 이면 기본 색');
            $table->json('config')->nullable()->comment('프로토콜 설정 (비밀이 아닌 값만)');
            $table->text('secrets')->nullable()->comment('프로토콜 비밀값 (encrypted:array cast) — 응답에서 가린다');
            $table->json('owned_domains')->nullable()->comment('이 IdP 가 소유한 이메일 도메인 목록 (소문자 정규화)');
            $table->boolean('enforce_owned_domains')->default(false)->comment('소유 도메인 회원의 비밀번호 로그인을 막을지');
            $table->string('provisioning_mode', 15)->default('preregistered')->comment('처음 온 사람 처리 — jit(자동 생성), preregistered(사전 등록 회원만)');
            $table->json('link_methods')->nullable()->comment('기존 회원 연결 방식 사용 여부 {domain_auto, verify, admin}');
            $table->boolean('require_consent')->default(true)->comment('자동 생성 때 약관 동의를 받을지');
            $table->string('identity_policy', 5)->default('skip')->comment('자동 생성 때 본인인증 가입 정책 — skip(적용 안 함), apply(적용)');
            $table->json('attribute_map')->nullable()->comment('속성 동기화 규칙');
            $table->json('role_mapping')->nullable()->comment('그룹 → 역할 매핑 규칙');
            $table->string('role_sync_mode', 15)->default('managed_only')->comment('역할 회수 방식 — managed_only, authoritative, first_only');
            $table->string('group_source', 10)->default('assertion')->comment('그룹 출처 — assertion, scim, ldap');
            $table->string('two_factor_mode', 15)->default('skip_if_mfa')->comment('2단계 인증 생략 방식 — always_skip, skip_if_mfa, always');
            $table->string('logout_mode', 10)->default('local')->comment('로그아웃 범위 — local(G7 만), rp(G7 → IdP), both(양방향)');
            $table->boolean('idp_initiated')->default(false)->comment('IdP 가 시작한 로그인(IdP-initiated) 허용 여부');
            $table->boolean('allow_local_password')->default(false)->comment('연결 회원이 G7 비밀번호를 만들 수 있는지');
            $table->smallInteger('max_session_minutes')->nullable()->comment('이 IdP 로그인 세션 수명 상한(분) — null 이면 사이트 정책을 따른다');
            $table->json('deprovision')->nullable()->comment('퇴사 처리 방식 {scim, periodic, periodic_minutes, on_login, withdraw_after_days}');
            $table->char('scim_token_hash', 64)->nullable()->comment('SCIM Bearer 토큰 sha256 (원문은 발급 때 한 번만 보인다)');
            $table->timestamp('scim_token_issued_at')->nullable()->comment('SCIM 토큰 발급 시각');
            $table->timestamp('metadata_refreshed_at')->nullable()->comment('IdP 메타데이터(디스커버리) 마지막 갱신 시각');
            $table->timestamp('cert_expires_at')->nullable()->comment('IdP 서명 인증서 만료 시각');
            $table->timestamp('last_success_at')->nullable()->comment('마지막 로그인 성공 시각');
            $table->timestamp('last_error_at')->nullable()->comment('마지막 오류 시각');
            $table->string('last_error_code', 50)->nullable()->comment('마지막 오류 코드');
            $table->timestamps();

            $table->unique('key', 'sso_identity_providers_key_unique');
            $table->index(['enabled', 'sort_order'], 'sso_identity_providers_enabled_sort_index');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('sso_identity_providers', function (Blueprint $table) {
                $table->comment('SSO 회사 IdP');
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
        Schema::dropIfExists('sso_identity_providers');
    }
};
