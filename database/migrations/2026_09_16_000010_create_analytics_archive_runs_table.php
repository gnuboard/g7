<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 방문자 통계 배치 실행 이력 테이블을 생성합니다.
     *
     * 집계·정리·익명화가 언제 돌았고 얼마나 처리했는지를 남깁니다. 배치가 조용히
     * 실패하면 화면 숫자가 그대로 멈추므로, 운영자가 진단 화면에서 이 표를 보고
     * 「마지막 집계가 몇 시간 전인지」를 판단할 수 있어야 합니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('analytics_archive_runs')) {
            return;
        }

        Schema::create('analytics_archive_runs', function (Blueprint $table) {
            $table->id()->comment('ID');
            // 길이는 가장 긴 값인 extension_purge(15자)에 맞춘다 — 12자로 두면 그 값이
            // 저장되지 않아 확장 제거 정리가 통째로 실패한다.
            $table->string('run_type', 20)->comment('실행 종류 (hour, day, reaggregate, anonymize, purge, extension_purge)');
            $table->dateTime('period_start')->nullable()->comment('대상 기간 시작');
            $table->dateTime('period_end')->nullable()->comment('대상 기간 끝');
            $table->string('trigger', 10)->comment('실행 주체 (scheduled, manual)');
            $table->string('status', 10)->comment('상태 (running, success, failed, skipped)');
            $table->unsignedInteger('processed_visits')->default(0)->comment('처리한 방문 수');
            $table->unsignedInteger('processed_actions')->default(0)->comment('처리한 액션 수');
            $table->unsignedInteger('chunks')->default(0)->comment('처리한 청크 수');
            $table->unsignedTinyInteger('progress_percent')->default(0)->comment('진행률 (%) — 수동 실행 화면이 이 값을 폴링한다');
            $table->dateTime('started_at')->comment('시작 시각');
            $table->dateTime('finished_at')->nullable()->comment('종료 시각');
            $table->text('error')->nullable()->comment('실패 사유');
            $table->unsignedBigInteger('triggered_by')->nullable()->comment('수동 실행한 관리자 ID');
            $table->timestamp('created_at')->useCurrent()->comment('생성 시각');

            $table->index(['run_type', 'period_start'], 'idx_analytics_archive_runs_type');
            $table->index(['status', 'started_at'], 'idx_analytics_archive_runs_status');
        });
    }

    /**
     * 방문자 통계 배치 실행 이력 테이블을 삭제합니다.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_archive_runs');
    }
};
