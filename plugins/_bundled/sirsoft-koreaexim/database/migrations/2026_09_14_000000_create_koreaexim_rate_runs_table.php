<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('koreaexim_rate_runs')) {
            return;
        }
        Schema::create('koreaexim_rate_runs', function (Blueprint $table) {
            $table->id()->comment('갱신 실행 ID');
            $table->string('trigger', 20)->comment('실행 계기');
            $table->string('status', 24)->comment('갱신 결과');
            $table->string('rate_type', 20)->comment('환율 종류');
            $table->string('base_currency', 3)->comment('기본통화');
            $table->date('search_date')->comment('조회 기준일 한국시간');
            $table->unsignedInteger('base_unit')->comment('기본통화 환산 단위');
            $table->unsignedSmallInteger('request_count')->default(0)->comment('API 호출 수');
            $table->unsignedSmallInteger('applied_count')->default(0)->comment('적용 통화 수');
            $table->unsignedSmallInteger('unchanged_count')->default(0)->comment('무변경 통화 수');
            $table->unsignedSmallInteger('skipped_count')->default(0)->comment('건너뜀 통화 수');
            $table->text('message')->nullable()->comment('비밀값을 제외한 결과 요약');
            $table->json('raw_response')->nullable()->comment('성공 API 원본 환율');
            $table->foreignId('source_run_id')->nullable()->comment('재적용 원본 실행 ID')->constrained('koreaexim_rate_runs')->nullOnDelete();
            $table->foreignId('triggered_by')->nullable()->comment('수동 실행 사용자 ID')->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->comment('실행 시작 시각');
            $table->timestamp('finished_at')->nullable()->comment('실행 종료 시각');
            $table->timestamp('created_at')->nullable()->comment('생성 시각');
            $table->timestamp('updated_at')->nullable()->comment('수정 시각');
            $table->index('search_date', 'idx_koreaexim_rate_runs_search_date');
            $table->index('created_at', 'idx_koreaexim_rate_runs_created_at');
            $table->index('status', 'idx_koreaexim_rate_runs_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('koreaexim_rate_runs');
    }
};
