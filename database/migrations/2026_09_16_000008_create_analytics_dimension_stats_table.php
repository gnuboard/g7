<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 방문자 통계 차원별 집계 테이블을 생성합니다.
     *
     * 일 × 방문자 유형 × 차원(페이지·유입·브라우저·국가 등) × 라벨로 1행입니다.
     * 인기 페이지·유입 경로 같은 상위 목록은 전부 이 표 하나를 읽어 만듭니다.
     * 시간 단위를 두지 않는 이유는 행 수가 24배로 불어나기 때문입니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('analytics_dimension_stats')) {
            return;
        }

        Schema::create('analytics_dimension_stats', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->date('date')->comment('집계 일자 (사이트 표준시 기준)');
            $table->string('visitor_type', 10)->comment('방문자 유형 (guest, member, admin, bot)');
            $table->string('dimension', 310)->comment('차원 이름 (page_url, referrer_name, browser, country 등, 확장 차원은 ext:식별자:키 — 식별자 255자·키 50자까지 담는 폭)');
            $table->char('label_hash', 32)->comment('라벨 해시 — 긴 라벨 대신 이 값으로 같은 항목을 묶는다');
            $table->string('label', 512)->comment('라벨 원값');
            $table->unsignedBigInteger('action_name_id')->nullable()->comment('이름 사전 ID — 페이지 차원에서 원본 URL 을 되찾을 때 쓴다');
            $table->unsignedInteger('visits')->default(0)->comment('이 라벨이 나온 방문 수');
            $table->unsignedInteger('hits')->default(0)->comment('이 라벨이 나온 액션 수');
            $table->unsignedInteger('entries')->default(0)->comment('이 라벨로 시작한 방문 수');
            $table->unsignedInteger('exits')->default(0)->comment('이 라벨에서 끝난 방문 수');
            $table->unsignedInteger('bounces')->default(0)->comment('이 라벨에서 바로 떠난 방문 수');
            $table->unsignedBigInteger('total_time_seconds')->default(0)->comment('체류 시간 합계 (초)');
            $table->json('extra')->nullable()->comment('차원별 추가 값 (검색어의 결과 없음 건수, 목표의 금액 합 등)');
            $table->dateTime('archived_at')->comment('집계 수행 시각');

            $table->unique(['date', 'visitor_type', 'dimension', 'label_hash'], 'uq_analytics_dimension_stats');
            $table->index(['dimension', 'date'], 'idx_analytics_dimension_stats_dim');
        });
    }

    /**
     * 방문자 통계 차원별 집계 테이블을 삭제합니다.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_dimension_stats');
    }
};
