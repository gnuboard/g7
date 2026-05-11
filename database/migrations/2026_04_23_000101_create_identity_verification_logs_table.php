<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * identity_verification_logs 테이블을 신설합니다.
 *
 * Challenge 생명주기(requested/sent/verified/failed/expired/cancelled/policy_violation_logged)
 * 감사 로그. activity_logs 는 사용자 관점 서사, 이 테이블은 challenge 기술 이벤트를 기록합니다.
 * 기본 보관주기 180일 (관리자가 logs.purge 권한으로 즉시 삭제 가능).
 *
 * @since 7.0.0-beta.4
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verification_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('Challenge UUID');
            $table->string('provider_id', 64)->index()->comment('프로바이더 식별자 (예: g7:core.mail, kcp)');
            $table->string('purpose', 32)->index()->comment('인증 목적 (signup|password_reset|self_update|sensitive_action|*module-defined*) — 코어 4종은 App\\Enums\\IdentityVerificationPurpose enum, 모듈/플러그인은 declaredPurposes 레지스트리');
            $table->string('channel', 16)->comment('인증 채널 (email|sms|ipin|...) — 코어 enum=App\\Enums\\IdentityVerificationChannel, 모듈/플러그인 provider 가 추가 채널 등록 가능');

            $table->foreignId('user_id')
                ->nullable()
                ->index()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('사용자 탈퇴 시 NULL 로 유지 — 감사 이력 보존 (CASCADE 금지 규정 준수)');

            $table->string('target_hash', 64)->index()->comment('SHA256(email|phone) — PII 원본 저장 회피');
            $table->string('status', 32)->index()->comment('인증 challenge 생명주기 상태 (requested|sent|processing|verified|failed|expired|cancelled|policy_violation_logged) — App\\Enums\\IdentityVerificationStatus enum');
            $table->string('render_hint', 32)->nullable()->comment('프론트 렌더 힌트 (text_code|link|external_redirect)');

            $table->unsignedSmallInteger('attempts')->default(0)->comment('시도 횟수');
            $table->unsignedSmallInteger('max_attempts')->default(5)->comment('허용 최대 시도 횟수');

            $table->ipAddress('ip_address')->nullable()->comment('요청 IP');
            $table->string('user_agent', 512)->nullable()->comment('요청 User-Agent');

            // 인증 경로 — 이슈 #297 요구사항 8.3
            $table->string('origin_type', 20)
                ->nullable()
                ->index()
                ->comment('인증 트리거 출처 유형 (route|hook|policy|middleware|api|custom|system) — App\\Enums\\IdentityOriginType enum');

            $table->string('origin_identifier', 255)
                ->nullable()
                ->index()
                ->comment('실제 경로/훅명/정책키 (예: PUT /api/me/password, core.user.before_update)');

            $table->string('origin_policy_key', 120)
                ->nullable()
                ->index()
                ->comment('정책이 트리거한 경우 identity_policies.key 참조');

            $table->json('properties')->nullable()->comment('요청 페이로드 요약');
            $table->json('metadata')->nullable()->comment('프로바이더 내부 데이터 (코드 해시 등)');

            $table->string('verification_token', 128)
                ->nullable()
                ->unique()
                ->comment('Mode B verify 성공 시 발급되는 서명 토큰 (purpose+target_hash 바인딩)');

            $table->timestamp('expires_at')->nullable()->index()->comment('Challenge 만료 시각');
            $table->timestamp('verified_at')->nullable()->comment('검증 완료 시각');
            $table->timestamp('consumed_at')->nullable()->comment('Mode B verification_token 이 register 에서 소비된 시각');
            $table->timestamps();

            $table->index(['provider_id', 'status', 'created_at'], 'idx_idv_logs_provider_status_created');
            $table->index(['origin_type', 'origin_identifier'], 'idx_idv_logs_origin');
            $table->index(['purpose', 'user_id', 'verified_at'], 'idx_idv_logs_purpose_user_verified');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verification_logs');
    }
};
