<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 인덱스 이름
     */
    private const INDEX = 'idx_analytics_archive_runs_type_trigger_started';

    /**
     * 집계 실행 이력에 「종류·실행 주체·시작 시각」 인덱스를 더합니다.
     *
     * 통계 화면은 요청마다 「예약 실행이 마지막으로 언제 돌았는가」를 묻습니다(스케줄러 생존 판정).
     * 화면을 열 때 집계하면 실행 이력이 최대 분당 1건씩 늘어나므로, 이 질문이 표를 훑지 않고
     * 인덱스로 끝나야 합니다.
     *
     * `trigger` 값: `scheduled`(스케줄러 등록 실행 — `--scheduled`), `manual`(CLI 수동·관리자 버튼),
     * `on_view`(관리자가 통계 화면을 열 때 1분 간격으로 도는 집계).
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable('analytics_archive_runs')) {
            return;
        }

        Schema::table('analytics_archive_runs', function (Blueprint $table) {
            // 재실행 가드 — MySQL 은 DDL 을 되돌리지 않으므로 이미 있으면 건너뛴다.
            if (! Schema::hasIndex('analytics_archive_runs', 'idx_analytics_archive_runs_type_trigger_started')) {
                $table->index(['run_type', 'trigger', 'started_at'], 'idx_analytics_archive_runs_type_trigger_started');
            }
        });
    }

    /**
     * 인덱스를 제거합니다.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable('analytics_archive_runs') || ! Schema::hasIndex('analytics_archive_runs', self::INDEX)) {
            return;
        }

        Schema::table('analytics_archive_runs', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
