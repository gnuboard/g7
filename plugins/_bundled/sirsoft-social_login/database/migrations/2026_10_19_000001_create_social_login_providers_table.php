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
     * 제공자 설정 1행 = 제공자 하나. 내장 4종(kakao·naver·google·facebook)은 설치 때 시딩하고,
     * 관리자가 일반 OIDC 제공자를 더 추가한다. 비밀값(client_secret·admin_key)은 Eloquent encrypted
     * 캐스트로만 저장한다 — 평문 저장 금지.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('social_login_providers', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->string('key', 50)->unique()->comment('제공자 키 — 내장은 kakao·naver·google·facebook, 커스텀은 영소문자 시작 영숫자·밑줄 2~50자(mock_ 접두 예약)');
            $table->string('driver', 20)->comment('드라이버 — kakao·naver·google·facebook·oidc');
            $table->string('type', 10)->comment('행 종류 — builtin(내장, 삭제 불가)·custom(관리자 추가)');
            $table->boolean('enabled')->default(false)->comment('로그인 화면 노출 여부');
            $table->smallInteger('sort_order')->default(0)->comment('버튼 표시 순서(작을수록 앞)');
            $table->json('label')->comment('표시 이름 다국어 {ko,en}');
            $table->string('client_id', 255)->nullable()->comment('제공자 앱 클라이언트 ID');
            $table->text('client_secret')->nullable()->comment('제공자 앱 클라이언트 비밀값 (encrypted cast)');
            $table->text('admin_key')->nullable()->comment('카카오 어드민 키 — 연결 해제·웹훅 검증용 (encrypted cast)');
            $table->json('scopes')->nullable()->comment('요청 범위 목록. 비우면 드라이버 기본값');
            $table->string('discovery_url', 500)->nullable()->comment('OIDC discovery 주소 (커스텀 OIDC)');
            $table->string('authorize_url', 500)->nullable()->comment('인가 주소 (discovery 가 없을 때 직접 입력)');
            $table->string('token_url', 500)->nullable()->comment('토큰 주소');
            $table->string('userinfo_url', 500)->nullable()->comment('사용자 정보 주소');
            $table->string('jwks_url', 500)->nullable()->comment('서명 키 목록(JWKS) 주소');
            $table->string('issuer', 255)->nullable()->comment('id_token 발급자(iss) 기대값');
            $table->string('graph_api_version', 10)->nullable()->comment('페이스북 Graph API 버전 (예 v26.0)');
            $table->json('button_style')->nullable()->comment('커스텀 제공자 버튼 색 {bg,fg,border}');
            $table->boolean('signup_allowed')->default(true)->comment('이 제공자로 새 회원 가입 허용 여부');
            $table->string('email_missing_policy', 20)->default('ask')->comment('이메일 미제공 시 정책 — ask·deny·placeholder');
            $table->string('email_trust', 10)->default('flag')->comment('제공자 이메일 신뢰 — flag(검증 표시 따름)·trust·verify');
            $table->json('extra')->nullable()->comment('드라이버별 추가 설정 (endpoint_override 등)');
            $table->dateTime('last_success_at')->nullable()->comment('마지막 성공 시각 (진단 화면)');
            $table->dateTime('last_error_at')->nullable()->comment('마지막 오류 시각 (진단 화면)');
            $table->string('last_error_code', 50)->nullable()->comment('마지막 오류 코드 (진단 화면)');
            $table->timestamps();

            $table->index(['enabled', 'sort_order'], 'social_login_providers_enabled_sort_index');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('social_login_providers', function (Blueprint $table) {
                $table->comment('소셜 로그인 제공자 설정');
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
        Schema::dropIfExists('social_login_providers');
    }
};
