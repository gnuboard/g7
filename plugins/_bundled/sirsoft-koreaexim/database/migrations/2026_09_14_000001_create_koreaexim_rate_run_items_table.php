<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('koreaexim_rate_run_items')) {
            return;
        }
        Schema::create('koreaexim_rate_run_items', function (Blueprint $table) {
            $table->id()->comment('통화별 갱신 ID');
            $table->foreignId('run_id')->comment('갱신 실행 ID')->constrained('koreaexim_rate_runs')->cascadeOnDelete();
            $table->string('currency_code', 3)->comment('통화 코드');
            $table->string('api_currency_unit', 16)->nullable()->comment('API 호가 통화 표기');
            $table->unsignedSmallInteger('unit_divisor')->default(1)->comment('API 호가 단위 분모');
            $table->decimal('source_rate', 18, 6)->nullable()->comment('API 원본 환율');
            $table->decimal('previous_rate', 20, 8)->nullable()->comment('갱신 전 환율');
            $table->decimal('new_rate', 20, 8)->nullable()->comment('갱신 후 환율');
            $table->string('status', 20)->comment('통화 처리 결과');
            $table->json('currency_snapshot')->comment('갱신 당시 통화 설정');
            $table->json('api_fields')->nullable()->comment('API 원본 통화 정보');
            $table->index('run_id', 'idx_koreaexim_rate_run_items_run_id');
            $table->index('currency_code', 'idx_koreaexim_rate_run_items_currency_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('koreaexim_rate_run_items');
    }
};
