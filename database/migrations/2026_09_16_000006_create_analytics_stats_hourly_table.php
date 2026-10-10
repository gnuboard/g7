<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 방문자 통계 시간 단위 집계 테이블을 생성합니다.
     *
     * 시간 × 방문자 유형으로 1행입니다. hour_start 는 UTC 가 아니라 사이트 표준시의
     * 시각을 그대로 저장합니다 — 시간대별 이용 패턴을 HOUR() 로 묶을 때 사이트 기준과
     * 어긋나지 않게 하기 위함입니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('analytics_stats_hourly')) {
            return;
        }

        Schema::create('analytics_stats_hourly', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->dateTime('hour_start')->comment('집계 시간 시작 — 사이트 표준시의 시각을 그대로 저장한다 (UTC 아님)');
            $table->string('visitor_type', 10)->comment('방문자 유형 (guest, member, admin, bot)');
            $table->unsignedInteger('visits')->default(0)->comment('방문 수');
            $table->unsignedInteger('visitors')->default(0)->comment('순방문자 수');
            $table->unsignedInteger('pageviews')->default(0)->comment('페이지뷰 수');
            $table->unsignedInteger('actions')->default(0)->comment('전체 액션 수');
            $table->unsignedInteger('bounces')->default(0)->comment('이탈 방문 수');
            $table->unsignedInteger('server_only_visits')->default(0)->comment('서버 폴백으로만 잡힌 방문 수 — 비콘이 오지 않는 환경을 진단할 때 본다');
            $table->unsignedInteger('new_visitors')->nullable()->comment('신규 방문자 수 — 쿠키를 쓰는 모드에서만 센다');
            $table->unsignedInteger('returning_visitors')->nullable()->comment('재방문자 수 — 쿠키를 쓰는 모드에서만 센다');
            $table->unsignedBigInteger('total_time_seconds')->default(0)->comment('체류 시간 합계 (초)');
            $table->dateTime('archived_at')->comment('집계 수행 시각');

            $table->unique(['hour_start', 'visitor_type'], 'uq_analytics_stats_hourly');
        });
    }

    /**
     * 방문자 통계 시간 단위 집계 테이블을 삭제합니다.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_stats_hourly');
    }
};
