<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 방문자 통계 액션 원장 테이블을 생성합니다.
     *
     * 방문 안에서 일어난 행동(페이지뷰·다운로드·외부링크·검색·전환) 1건이 1행입니다.
     * visit_id 에 외래키를 두지 않고 삭제는 서비스가 액션 → 방문 순으로 명시 수행합니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('analytics_actions')) {
            return;
        }

        Schema::create('analytics_actions', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->unsignedBigInteger('visit_id')->comment('방문 ID — 외래키를 두지 않고 서비스가 삭제 순서를 책임진다');
            $table->char('visitor_key', 32)->comment('방문자 키 사본 — 방문을 조인하지 않고도 방문자 기준으로 지울 수 있게 복제한다');
            $table->string('action_type', 12)->comment('액션 종류 (pageview, download, outlink, site_search, event, goal)');
            $table->unsignedBigInteger('action_name_id')->comment('이름 사전 ID (URL·파일명·검색어)');
            $table->unsignedBigInteger('page_title_id')->nullable()->comment('페이지 제목 사전 ID');
            $table->dateTime('occurred_at')->comment('발생 시각 (UTC)');
            $table->unsignedInteger('time_spent_seconds')->nullable()->comment('이 액션에 머문 시간 (초) — 다음 액션이 도착할 때 확정하고 마지막 액션은 0으로 둔다');
            $table->unsignedInteger('search_result_count')->nullable()->comment('사이트 내부 검색 결과 건수');
            $table->string('search_tab', 50)->nullable()->comment('사이트 내부 검색 탭');
            $table->json('custom_dimensions')->nullable()->comment('확장이 넣은 커스텀 차원 값');
            $table->timestamp('created_at')->useCurrent()->comment('생성 시각');

            $table->index(['visit_id', 'occurred_at'], 'idx_analytics_actions_visit');
            $table->index(['action_name_id', 'occurred_at'], 'idx_analytics_actions_name');
            $table->index('occurred_at', 'idx_analytics_actions_occurred');
            $table->index(['action_type', 'occurred_at'], 'idx_analytics_actions_type');
        });
    }

    /**
     * 방문자 통계 액션 원장 테이블을 삭제합니다.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_actions');
    }
};
