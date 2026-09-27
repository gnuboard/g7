<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 방문자 통계 일 단위 집계 테이블을 생성합니다.
     *
     * 일 × 방문자 유형으로 1행이며 주·월·년 숫자는 모두 이 표를 더해서 만듭니다.
     * 원장은 보존기간이 지나면 지워지지만 이 표는 영구 보존이라 「전체 방문자」를
     * 몇 년이 지나도 말할 수 있습니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('analytics_stats_daily')) {
            return;
        }

        Schema::create('analytics_stats_daily', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->date('date')->comment('집계 일자 (사이트 표준시 기준)');
            $table->string('visitor_type', 10)->comment('방문자 유형 (guest, member, admin, bot)');
            $table->unsignedInteger('visits')->default(0)->comment('방문 수');
            $table->unsignedInteger('visitors')->default(0)->comment('순방문자 수');
            $table->unsignedInteger('pageviews')->default(0)->comment('페이지뷰 수');
            $table->unsignedInteger('actions')->default(0)->comment('전체 액션 수');
            $table->unsignedInteger('bounces')->default(0)->comment('이탈 방문 수');
            $table->unsignedInteger('server_only_visits')->default(0)->comment('서버 폴백으로만 잡힌 방문 수');
            $table->unsignedInteger('new_visitors')->nullable()->comment('신규 방문자 수 — 쿠키를 쓰는 모드에서만 센다');
            $table->unsignedInteger('returning_visitors')->nullable()->comment('재방문자 수 — 쿠키를 쓰는 모드에서만 센다');
            $table->unsignedBigInteger('total_time_seconds')->default(0)->comment('체류 시간 합계 (초)');
            $table->boolean('is_final')->default(false)->comment('확정 여부 — 오늘치는 계속 다시 계산하므로 false 로 둔다');
            $table->dateTime('archived_at')->comment('집계 수행 시각');

            $table->unique(['date', 'visitor_type'], 'uq_analytics_stats_daily');
            $table->index('date', 'idx_analytics_stats_daily_date');
        });
    }

    /**
     * 방문자 통계 일 단위 집계 테이블을 삭제합니다.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_stats_daily');
    }
};
