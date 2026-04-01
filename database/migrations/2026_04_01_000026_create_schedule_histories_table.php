<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schedule_histories', function (Blueprint $table) {
            $table->id()->comment('이력 ID');
            $table->foreignId('schedule_id')->constrained('schedules')->cascadeOnDelete();
            $table->timestamp('started_at')->comment('실행 시작 시간');
            $table->timestamp('ended_at')->nullable()->comment('실행 종료 시간');
            $table->unsignedInteger('duration')->nullable()->comment('실행 시간 (초)');
            $table->enum('status', ['success', 'failed', 'running'])->default('running')->comment('실행 상태: success(성공), failed(실패), running(실행중)');
            $table->tinyInteger('exit_code')->nullable()->comment('종료 코드 (0: 성공)');
            $table->unsignedInteger('memory_usage')->nullable()->comment('메모리 사용량 (bytes)');
            $table->longText('output')->nullable()->comment('표준 출력');
            $table->longText('error_output')->nullable()->comment('에러 출력');
            $table->enum('trigger_type', ['scheduled', 'manual'])->default('scheduled')->comment('트리거 유형: scheduled(예약 실행), manual(수동 실행)');
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('started_at', 'idx_started_at');
            $table->index('status', 'idx_status');
            $table->index('trigger_type', 'idx_trigger_type');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('schedule_histories', function (Blueprint $table) {
                $table->comment('스케줄 실행 이력');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_histories');
    }
};
