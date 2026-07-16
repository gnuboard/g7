<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 알림 템플릿 테이블 생성.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->foreignId('definition_id')->constrained('notification_definitions')->cascadeOnDelete()->comment('알림 정의 ID');
            $table->string('channel', 50)->comment('채널: mail, database, fcm');
            $table->text('subject')->nullable()->comment('다국어 제목 ({"ko": "...", "en": "..."})');
            $table->mediumText('body')->comment('다국어 본문 ({"ko": "...", "en": "..."})');
            $table->boolean('is_active')->default(true)->comment('해당 채널 활성 여부');
            $table->boolean('is_default')->default(true)->comment('시더 생성 여부');
            $table->text('user_overrides')->nullable()->comment('사용자가 수정한 필드명 목록');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete()->comment('수정자');
            $table->timestamps();

            $table->unique(['definition_id', 'channel']);
        });
    }

    /**
     * 알림 템플릿 테이블 삭제.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
