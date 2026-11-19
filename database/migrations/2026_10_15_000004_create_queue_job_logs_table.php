<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 큐 작업 처리 기록 표를 만듭니다 (#17).
 *
 * 워커가 처리를 마친 작업(성공·실패)을 한 행씩 남긴다. 대기(jobs)·실패(failed_jobs) 표는 처리가 끝나면
 * 행이 사라지거나 실패만 남으므로 「성공했다」는 사실을 볼 곳이 없다. 재시도 대기(release)는 처리가 끝난 것이
 * 아니라 기록하지 않는다. 보관 기간은 실패 작업과 같은 환경설정 1개(drivers.queue_record_retention_days)를
 * 따르고 매일 정리 명령(queue:prune-records)이 지운다. 예약 작업 Job 이면 그 스케줄을 가리킨다.
 */
return new class extends Migration
{
    /**
     * 마이그레이션을 실행합니다.
     */
    public function up(): void
    {
        if (Schema::hasTable('queue_job_logs')) {
            return;
        }

        Schema::create('queue_job_logs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->nullable()->comment('작업 UUID (payload uuid — 실패 작업 failed_jobs.uuid 와 같은 값)');
            $table->string('connection', 50)->comment('큐 연결 이름');
            $table->string('queue', 100)->comment('큐 이름');
            $table->string('job_class', 255)->comment('작업 클래스 (payload data.commandName, 없으면 job)');
            $table->string('display_name', 255)->comment('표시 이름 (payload displayName)');
            $table->enum('status', ['processed', 'failed'])->comment('처리 결과 (processed=성공, failed=실패)');
            $table->unsignedSmallInteger('attempts')->default(1)->comment('처리 시도 횟수');
            $table->timestamp('started_at', 3)->nullable()->comment('처리 시작 시각 (밀리초)');
            $table->timestamp('finished_at', 3)->nullable()->comment('처리 종료 시각 (밀리초)');
            $table->unsignedInteger('duration_ms')->nullable()->comment('처리 소요 시간 (밀리초)');
            $table->string('exception_class', 255)->nullable()->comment('실패 예외 클래스');
            $table->text('exception_message')->nullable()->comment('실패 예외 메시지 (2000자에서 자름)');
            $table->foreignId('schedule_id')->nullable()->comment('예약 작업 ID (예약 작업 Job 일 때)')
                ->constrained('schedules')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('기록 시각');

            $table->index('uuid', 'idx_queue_job_logs_uuid');
            $table->index(['status', 'created_at'], 'idx_queue_job_logs_status_created');
            $table->index(['job_class', 'created_at'], 'idx_queue_job_logs_job_class_created');
            $table->index('created_at', 'idx_queue_job_logs_created_at');
        });
    }

    /**
     * 마이그레이션을 되돌립니다.
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_job_logs');
    }
};
