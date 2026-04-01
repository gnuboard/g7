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
        Schema::create('schedules', function (Blueprint $table) {
            $table->id()->comment('스케줄 ID');
            $table->string('name', 255)->comment('작업명');
            $table->text('description')->nullable()->comment('설명');
            $table->enum('type', ['artisan', 'shell', 'url'])->comment('작업 유형: artisan(Artisan 커맨드), shell(쉘 명령), url(URL 호출)');
            $table->text('command')->comment('명령어 또는 URL');
            $table->string('expression', 100)->comment('Cron 표현식');
            $table->enum('frequency', ['everyMinute', 'hourly', 'daily', 'weekly', 'monthly', 'custom'])->default('custom')->comment('실행 주기: everyMinute(매분), hourly(매시간), daily(매일), weekly(매주), monthly(매월), custom(사용자 정의)');
            $table->boolean('without_overlapping')->default(false)->comment('중복 실행 방지 여부: 0(허용), 1(방지)');
            $table->boolean('run_in_maintenance')->default(false)->comment('점검 모드 실행 여부: 0(비실행), 1(실행)');
            $table->unsignedInteger('timeout')->nullable()->comment('실행 제한 시간 (초)');
            $table->boolean('is_active')->default(true)->comment('활성화 여부: 0(비활성), 1(활성)');
            $table->enum('last_result', ['success', 'failed', 'running', 'never'])->default('never')->comment('마지막 실행 결과: success(성공), failed(실패), running(실행중), never(미실행)');
            $table->timestamp('last_run_at')->nullable()->comment('마지막 실행 시간');
            $table->timestamp('next_run_at')->nullable()->comment('다음 실행 예정 시간');
            $table->string('extension_type', 20)->nullable()->comment('확장 소유 타입: core(코어), module(모듈), plugin(플러그인), NULL(사용자 정의)');
            $table->string('extension_identifier', 255)->nullable()->comment('확장 식별자 (예: core, sirsoft-board, sirsoft-payment)');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('type', 'idx_type');
            $table->index('frequency', 'idx_frequency');
            $table->index('is_active', 'idx_is_active');
            $table->index('last_result', 'idx_last_result');
            $table->index('next_run_at', 'idx_next_run_at');
            $table->index(['extension_type', 'extension_identifier'], 'idx_schedules_extension');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('schedules', function (Blueprint $table) {
                $table->comment('스케줄 작업 관리');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
