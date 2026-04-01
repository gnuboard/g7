<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 사용자 알림 설정
        Schema::create('board_user_notification_settings', function (Blueprint $table) {
            $table->id()->comment('알림 설정 ID');
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('notify_post_complete')->default(false)->comment('게시글 완료 알림');
            $table->boolean('notify_post_reply')->default(false)->comment('게시글 답변 알림');
            $table->boolean('notify_comment')->default(false)->comment('댓글 알림');
            $table->boolean('notify_reply_comment')->default(false)->comment('답글 알림');
            $table->timestamps();
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('board_user_notification_settings', function (Blueprint $table) {
                $table->comment('게시판 사용자 알림 설정');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('board_user_notification_settings')) {
            Schema::dropIfExists('board_user_notification_settings');
        }
    }
};
