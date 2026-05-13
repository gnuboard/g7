<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 본인인증 메시지 템플릿 테이블 생성.
     *
     * 정의(identity_message_definitions) 1건 × 채널 N개 구조.
     * 현재 mail 채널만 사용. 향후 sms 등 확장 시 row 추가만으로 가능.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('identity_message_templates', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->foreignId('definition_id')
                ->constrained('identity_message_definitions')
                ->cascadeOnDelete()
                ->comment('메시지 정의 ID (FK)');
            $table->string('channel', 20)->comment('메시지 템플릿 채널 (mail 현재 / sms 등 향후) — IdentityVerificationChannel 과는 별개의 도메인 분류');
            $table->text('subject')->nullable()
                ->comment('다국어 제목 ({"ko":"...", "en":"..."}) — mail 채널에서만 의미');
            $table->mediumText('body')->comment('다국어 본문 ({"ko":"...", "en":"..."})');
            $table->boolean('is_active')->default(true)->comment('해당 채널 활성 여부');
            $table->boolean('is_default')->default(true)->comment('시더 생성 여부 (운영자 편집 시 false)');
            $table->text('user_overrides')->nullable()
                ->comment('운영자가 수정한 필드명 목록 (예: ["subject","body","is_active"])');
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('수정자 (사용자 삭제 시 NULL)');
            $table->timestamps();

            $table->unique(['definition_id', 'channel'], 'idx_identity_message_tpl_def_channel_unique');
        });
    }

    /**
     * 본인인증 메시지 템플릿 테이블 삭제.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('identity_message_templates');
    }
};
