<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 비즈뿌리오 알림 템플릿 테이블 (#597).
 *
 * 알림 1건(notification_definitions.type)당 1행. 알림톡 템플릿의 카카오 등록
 * 페이로드(content)·승인 스냅샷(approved_content)·검수 상태와, 대체 SMS/SMS 단독
 * 본문(sms_body)을 함께 저장한다. 발송 시 DB 가 유일한 판정 근거다(실시간 조회 폐지).
 *
 * sms_body 는 다국어 맵이다 — 알림톡 content 는 카카오가 승인한 원문 그대로만 발송할 수
 * 있어 단일 언어 1벌이지만, SMS 에는 그 제약이 없으므로 코어 알림 템플릿과 동일하게
 * 수신자 로케일별 본문을 유지한다(개편 전 동작 보존).
 *
 * bizppurio_notification_bindings 를 대체한다 — 공개 릴리즈 이력이 없어 마이그레이션
 * 자체를 교체했다(업그레이드 스텝 불요, 개발 환경은 플러그인 재설치 필요).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('bizppurio_templates');

        Schema::create('bizppurio_templates', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('템플릿 PK');
            $table->string('notification_type', 100)->comment('코어 notification_definitions.type (알림 1건당 1행)');
            $table->boolean('alimtalk_enabled')->default(false)->comment('알림톡 발송 사용 여부');
            $table->string('template_code', 30)->nullable()->comment('자체 채번한 카카오 템플릿 코드 (등록 전 null)');
            $table->string('sender_key', 100)->nullable()->comment('신청 당시 발신프로필 키 스냅샷');
            $table->json('content')->nullable()->comment('카카오 등록 페이로드 (templateName/templateMessageType/templateEmphasizeType/templateContent/buttons 등 kapi add 필드 그대로 — 유형별 컬럼 분해 없이 JSON 단일 컬럼)');
            $table->json('approved_content')->nullable()->comment('승인 확정 시점 content 스냅샷 (발송 SSoT — 승인 취소돼도 유지)');
            $table->string('status', 20)->default('draft')->comment('검수 상태: draft/requested/approved/rejected/stopped/blocked/dormant (BizppurioTemplateStatus enum)');
            $table->json('inspection_detail')->nullable()->comment('kapi detail 의 comments 배열 스냅샷 (반려 사유 원문)');
            $table->timestamp('requested_at')->nullable()->comment('검수 신청 시각');
            $table->timestamp('approved_at')->nullable()->comment('승인 확정 시각');
            $table->timestamp('last_synced_at')->nullable()->comment('마지막 카카오 상태 동기화 시각');
            $table->boolean('fallback_sms_enabled')->default(false)->comment('알림톡 실패 시 SMS 대체발송 여부 (resend 위임)');
            $table->json('sms_body')->nullable()->comment('대체 SMS 겸 SMS 단독 본문의 다국어 맵 ({"ko": "...", "en": "..."}, #{var} 치환) — 수신자 로케일로 렌더');
            $table->boolean('sms_only')->default(false)->comment('알림톡 없이 SMS 만 발송하는 알림 여부 (표시·알림톡 게이트용)');
            $table->boolean('is_active')->default(true)->comment('활성 여부');
            $table->timestamps();

            $table->unique('notification_type', 'bizppurio_template_type_unique');
            $table->index('status', 'bizppurio_template_status_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('bizppurio_templates', function (Blueprint $table) {
                $table->comment('비즈뿌리오 알림 템플릿 (알림톡 등록·검수 상태 + SMS 본문)');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bizppurio_templates');
    }
};
