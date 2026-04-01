<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SEO 캐시 통계 테이블을 생성합니다.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('seo_cache_stats', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->string('url')->comment('요청 URL');
            $table->string('locale', 5)->comment('로케일');
            $table->string('layout_name')->nullable()->comment('사용된 레이아웃명');
            $table->string('module_identifier')->nullable()->comment('레이아웃 소유 모듈 식별자');
            $table->string('type')->comment('유형 (hit 또는 miss)');
            $table->integer('response_time_ms')->nullable()->comment('렌더링 소요 시간 (ms)');
            $table->timestamp('created_at')->useCurrent()->comment('생성 시각');

            $table->index('url', 'idx_seo_cache_stats_url');
            $table->index('type', 'idx_seo_cache_stats_type');
            $table->index('created_at', 'idx_seo_cache_stats_created_at');
        });
    }

    /**
     * SEO 캐시 통계 테이블을 삭제합니다.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_cache_stats');
    }
};
