<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * identity_policies 테이블을 신설합니다.
 *
 * 운영자/플러그인이 "어느 지점에 어떤 purpose 의 IDV 를 어떤 TTL 로 강제할지" 선언형으로 등록하는 저장소.
 * 알림 시스템의 notification_definitions 동형 패턴 — config/core.php.identity_policies 블록 +
 * IdentityPolicySyncHelper + {벤더}IdentityPolicySeeder 3단 구조로 동기화됩니다.
 *
 * @since 7.0.0-beta.4
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_policies', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique()->comment('정책 식별자 (예: core.profile.password_change)');
            $table->string('scope', 16)->index()->comment('정책 적용 범위 (route|hook|custom) — App\\Enums\\IdentityPolicyScope enum');
            $table->string('target', 255)->comment('라우트명/URI 패턴, 훅 이름, 또는 custom key');
            $table->string('purpose', 64)->comment('IDV 목적 (signup|password_reset|self_update|sensitive_action|*module-defined*) — 코어 4종은 App\\Enums\\IdentityVerificationPurpose enum');
            $table->string('provider_id', 64)->nullable()->comment('null 이면 purpose 기본 provider 사용');
            $table->unsignedInteger('grace_minutes')->default(0)->comment('최근 N 분 이내 동일 purpose verified 재사용 허용');
            $table->boolean('enabled')->default(true)->index()->comment('활성화 여부');
            $table->unsignedSmallInteger('priority')->default(100)->comment('매칭 우선순위 (DESC)');
            $table->json('conditions')->nullable()->comment('역할/HTTP 메서드/파라미터 매칭 조건 JSON');
            $table->string('source_type', 20)->default('core')->comment('정책 출처 (core|module|plugin|admin) — App\\Enums\\IdentityPolicySourceType enum');
            $table->string('source_identifier', 100)->default('core')->comment('출처 식별자 (예: sirsoft-ecommerce)');
            $table->string('applies_to', 16)->default('both')->comment('대상 사용자 스코프 (self|admin|both) — App\\Enums\\IdentityPolicyAppliesTo enum');
            $table->string('fail_mode', 16)->default('block')->comment('정책 실패 시 동작 (block=HTTP 428 / log_only=감사 로그만) — App\\Enums\\IdentityPolicyFailMode enum');
            $table->json('user_overrides')->nullable()->comment('운영자가 S1d UI 로 수정한 필드 목록 (Seeder 재실행 시 보존)');
            $table->timestamps();

            $table->index(['scope', 'target', 'enabled'], 'idx_idp_scope_target_enabled');
            $table->index(['source_type', 'source_identifier'], 'idx_idp_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_policies');
    }
};
