<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mail_send_logs', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->string('extension_type', 20)->default('core')->comment('확장 타입 (core, module, plugin)');
            $table->string('extension_identifier', 100)->default('core')->comment('확장 식별자');
            $table->string('sender_email', 255)->nullable()->comment('발송자 이메일');
            $table->string('sender_name', 255)->nullable()->comment('발송자 이름');
            $table->string('recipient_email', 255)->comment('수신자 이메일');
            $table->string('recipient_name', 255)->nullable()->comment('수신자 이름');
            $table->string('subject', 500)->nullable()->comment('이메일 제목');
            $table->longText('body')->nullable()->comment('이메일 본문 (HTML)');
            $table->string('template_type', 100)->nullable()->comment('템플릿 유형 (welcome, new_comment 등)');
            $table->string('source', 100)->nullable()->comment('발송 출처 (notification, test_mail 등)');
            $table->string('status', 20)->default('sent')->comment('발송 상태 (sent, failed)');
            $table->text('error_message')->nullable()->comment('실패 시 에러 메시지');
            $table->timestamp('sent_at')->useCurrent()->comment('발송 시각');
            $table->timestamps();

            $table->index('recipient_email', 'idx_mail_send_logs_recipient');
            $table->index('template_type', 'idx_mail_send_logs_template');
            $table->index('sent_at', 'idx_mail_send_logs_sent_at');
            $table->index(['extension_type', 'extension_identifier'], 'idx_mail_send_logs_extension');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_send_logs');
    }
};
