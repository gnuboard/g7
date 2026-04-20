<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 사이트내 알림 테이블 생성 (Laravel 표준).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('UUID');
            $table->string('type')->comment('알림 클래스명');
            $table->string('notifiable_type')->comment('알림 수신 모델 타입');
            $table->unsignedBigInteger('notifiable_id')->comment('알림 수신 모델 ID');
            $table->index(['notifiable_type', 'notifiable_id']);
            $table->text('data')->comment('알림 데이터 (JSON)');
            $table->timestamp('read_at')->nullable()->comment('읽음 시각');
            $table->timestamps();
        });
    }

    /**
     * 사이트내 알림 테이블 삭제.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
