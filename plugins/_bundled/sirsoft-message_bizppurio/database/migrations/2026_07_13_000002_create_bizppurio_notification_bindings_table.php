<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 비즈뿌리오 이벤트↔알림톡 템플릿 연결(설정) 테이블.
 *
 * "어느 알림에 어느 알림톡 템플릿을 쓸지 + 대체발송 여부"를 저장한다.
 * 알림 설정 알림톡 탭 편집 모달(계획서 §6-2)이 우리 API 로 저장한다.
 * 변수 매핑 컬럼은 없다 — 변수명 동일 규칙으로 발송 시 자동 치환한다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('bizppurio_notification_bindings');

        Schema::create('bizppurio_notification_bindings', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('연결 설정 PK');
            $table->string('notification_type', 100)->comment('코어 notification_definitions.type (연결 대상 알림)');
            $table->string('channel', 20)->default('alimtalk')->comment('채널 (1차 alimtalk 고정)');
            $table->string('template_code', 50)->comment('연결한 카카오 알림톡 템플릿 코드');
            $table->string('template_name', 255)->comment('템플릿 이름 (사람 식별 + 고아 감지용 스냅샷)');
            $table->boolean('fallback_sms_enabled')->default(false)->comment('개별 대체발송 ON/OFF (실패 시 SMS/LMS 대체)');
            $table->boolean('is_active')->default(true)->comment('연동 활성 여부');
            $table->timestamps();

            $table->unique(['notification_type', 'channel'], 'bizppurio_binding_type_channel_unique');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('bizppurio_notification_bindings', function (Blueprint $table) {
                $table->comment('비즈뿌리오 이벤트↔알림톡 템플릿 연결 설정');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bizppurio_notification_bindings');
    }
};
