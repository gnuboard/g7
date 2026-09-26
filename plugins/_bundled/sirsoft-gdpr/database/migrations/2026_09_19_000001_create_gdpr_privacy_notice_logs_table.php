<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 개인정보 통지 접수와 발송 결과를 추가 전용으로 보관합니다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (Schema::hasTable('gdpr_privacy_notice_logs')) {
            return;
        }

        Schema::create('gdpr_privacy_notice_logs', function (Blueprint $table) {
            $table->id()->comment('통지 이력 ID');
            $table->foreignId('user_id')->nullable()->comment('수신 회원 ID, 완전 삭제 시 익명화')->constrained('users')->nullOnDelete();
            $table->uuid('attempt_id')->comment('통지 요청 식별자');
            $table->string('status', 16)->comment('상태: queued 접수, sent 발송, failed 실패');
            $table->json('channels')->comment('요청 또는 결과 채널 목록');
            $table->boolean('is_test')->default(false)->comment('시험 발송 여부: 0 정기, 1 시험');
            $table->timestamp('sent_at')->nullable()->comment('발송 완료 시각');
            $table->json('snapshot')->comment('통지 내용 요약, 직접 식별정보 제외');
            $table->timestamp('created_at')->useCurrent()->comment('이벤트 기록 시각');
            $table->index(['user_id', 'is_test', 'created_at'], 'gdpr_notice_user_date_idx');
            $table->index(['attempt_id', 'status'], 'gdpr_notice_attempt_status_idx');
        });
    }

    /**
     * 통지 이력 테이블을 제거합니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        Schema::dropIfExists('gdpr_privacy_notice_logs');
    }
};
