<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 알림 발송 이력 테이블 생성.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->string('channel', 50)->comment('채널: mail, database, fcm 등');
            $table->string('notification_type', 100)->comment('알림 타입: welcome, order_confirmed 등');
            $table->string('extension_type', 20)->default('core')->comment('확장 타입: core, module, plugin');
            $table->string('extension_identifier', 100)->default('core')->comment('확장 식별자');
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('수신자 회원 ID (회원인 경우)');
            $table->string('recipient_identifier', 255)->comment('수신자 식별자 (채널별: 이메일, 디바이스토큰, user_id 등)');
            $table->string('recipient_name', 255)->nullable()->comment('수신자 표시명 (발송 시점 스냅샷)');
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('발송자 회원 ID (null=시스템 자동)');
            $table->string('subject', 500)->nullable()->comment('렌더링된 제목');
            $table->longText('body')->nullable()->comment('렌더링된 본문');
            $table->string('status', 20)->default('sent')->comment('상태: sent, failed, skipped');
            $table->text('error_message')->nullable()->comment('에러 메시지');
            $table->string('source', 100)->nullable()->comment('발송 출처: notification, test_mail 등');
            $table->timestamp('sent_at')->useCurrent()->comment('발송 시각');
            $table->timestamps();

            $table->index('channel', 'idx_notification_logs_channel');
            $table->index('notification_type', 'idx_notification_logs_type');
            $table->index('status', 'idx_notification_logs_status');
            $table->index('sent_at', 'idx_notification_logs_sent_at');
            $table->index('recipient_user_id', 'idx_notification_logs_recipient_user');
            $table->index('recipient_identifier', 'idx_notification_logs_recipient_id');
            $table->index(['extension_type', 'extension_identifier'], 'idx_notification_logs_extension');
        });
    }

    /**
     * 알림 발송 이력 테이블 삭제.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
