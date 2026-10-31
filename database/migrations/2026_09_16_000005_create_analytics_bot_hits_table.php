<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 방문자 통계 봇 요청 테이블을 생성합니다.
     *
     * 봇은 사람 방문과 섞으면 통계가 망가지므로 원장을 따로 둡니다. 어떤 봇이 언제
     * 무엇을 가져갔는지와 그때 SEO 캐시가 쓰였는지를 함께 남깁니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('analytics_bot_hits')) {
            return;
        }

        Schema::create('analytics_bot_hits', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->string('bot_name', 100)->comment('봇 이름 — 판별하지 못하면 User-Agent 앞부분을 쓴다');
            $table->string('user_agent', 500)->comment('User-Agent 문자열');
            $table->string('url', 1024)->comment('요청 URL');
            $table->char('url_hash', 32)->comment('URL 해시 — 집계에서 같은 URL 을 묶는 데 쓴다');
            $table->binary('ip', 16)->nullable()->comment('요청 IP 이진값 — 사람 방문과 같은 마스킹 규칙을 적용한다');
            $table->string('cache_status', 10)->comment('SEO 캐시 상태 (hit, miss, bypass, none)');
            $table->dateTime('occurred_at')->comment('발생 시각 (UTC)');
            $table->timestamp('created_at')->useCurrent()->comment('생성 시각');

            $table->index('occurred_at', 'idx_analytics_bot_hits_occurred');
            $table->index(['bot_name', 'occurred_at'], 'idx_analytics_bot_hits_name');
        });
    }

    /**
     * 방문자 통계 봇 요청 테이블을 삭제합니다.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_bot_hits');
    }
};
