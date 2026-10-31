<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 방문자 통계 적재 실패 로그 테이블을 생성합니다.
     *
     * 수집은 사이트 동작을 막지 않으려고 예외를 전부 삼키고 응답을 그대로 돌려줍니다.
     * 그래서 실패가 아무 흔적도 남기지 않으면 운영자는 방문이 왜 비는지 알 길이 없습니다.
     * 이 표가 그 유일한 단서입니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('analytics_collect_failures')) {
            return;
        }

        Schema::create('analytics_collect_failures', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->string('reason', 40)->comment('실패 사유 (validation, max_actions, db_error, listener_exception, unknown_goal, unknown_dimension)');
            $table->string('message', 500)->comment('실패 메시지');
            $table->string('url', 1024)->nullable()->comment('요청 URL');
            $table->binary('ip', 16)->nullable()->comment('요청 IP 이진값 — 방문 원장과 같은 마스킹 규칙을 적용한다');
            $table->string('user_agent', 500)->nullable()->comment('User-Agent 문자열');
            $table->json('payload_excerpt')->nullable()->comment('요청 본문 발췌 — 개인정보로 볼 수 있는 필드는 빼고 담는다');
            $table->dateTime('occurred_at')->comment('발생 시각 (UTC)');
            $table->timestamp('created_at')->useCurrent()->comment('생성 시각');

            $table->index('occurred_at', 'idx_analytics_collect_failures_occurred');
            $table->index('reason', 'idx_analytics_collect_failures_reason');
        });
    }

    /**
     * 방문자 통계 적재 실패 로그 테이블을 삭제합니다.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_collect_failures');
    }
};
