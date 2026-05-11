<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 본인인증 메시지 정의 테이블 생성.
     *
     * 알림 시스템(notification_definitions)과 분리된 IDV 전용 메시지 템플릿 시스템.
     * (provider_id, scope_type, scope_value) 매트릭스를 1차 키로 갖는다.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('identity_message_definitions', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->string('provider_id', 64)->comment('IDV 프로바이더 ID (예: g7:core.mail, kcp, portone)');
            $table->string('scope_type', 32)
                ->comment('메시지 정의 스코프 (provider_default|purpose|policy) — App\\Enums\\IdentityMessageScopeType enum');
            $table->string('scope_value', 120)->default('')
                ->comment('범위 값: provider_default 빈 문자열 / purpose 키 / policy 키');
            $table->text('name')->comment('다국어 표시명 ({"ko":"...", "en":"..."})');
            $table->mediumText('description')->nullable()->comment('다국어 설명');
            $table->text('channels')->comment('활성 채널 (현재 ["mail"], 향후 sms 등 확장)');
            $table->text('variables')->nullable()
                ->comment('사용 가능 변수 메타데이터 ([{key, description}])');
            $table->string('extension_type', 20)->comment('확장 타입: core, module, plugin');
            $table->string('extension_identifier', 100)->comment('확장 식별자');
            $table->boolean('is_active')->default(true)->comment('활성 여부');
            $table->boolean('is_default')->default(true)->comment('시더 생성 여부');
            $table->text('user_overrides')->nullable()
                ->comment('운영자가 수정한 필드명 목록 (예: ["name","is_active"])');
            $table->timestamps();

            $table->unique(
                ['provider_id', 'scope_type', 'scope_value'],
                'idx_identity_message_def_provider_scope_unique'
            );
            $table->index(['provider_id', 'scope_type'], 'idx_identity_message_def_provider_scope');
            $table->index(['extension_type', 'extension_identifier'], 'idx_identity_message_def_extension');
        });
    }

    /**
     * 본인인증 메시지 정의 테이블 삭제.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('identity_message_definitions');
    }
};
