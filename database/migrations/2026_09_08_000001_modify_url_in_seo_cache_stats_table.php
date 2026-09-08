<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SEO 캐시 통계의 url 컬럼을 캐시 키 URL 길이에 맞춥니다.
     *
     * 캐시 키 URL 은 경로 + 정규화 쿼리(기본 최대 512바이트)라 255자를 넘을 수 있는데,
     * 컬럼이 255자면 그 기록이 엄격 모드에서 실패하고 통계 서비스는 그 예외를 삼킨다 —
     * 긴 주소의 봇 요청은 통계에서 빠지고 요청마다 실패하는 INSERT 만 남는다.
     * 768 은 utf8mb4 인덱스 키 상한(3072바이트)에 맞춘 값이다 — `idx_seo_cache_stats_url`
     * 이 이 컬럼에 걸려 있다.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('seo_cache_stats', function (Blueprint $table) {
            $table->string('url', 768)->comment('요청 URL (경로 + 정규화 쿼리)')->change();
        });
    }

    /**
     * url 컬럼을 원래 길이(255)로 되돌립니다.
     *
     * 되돌리기 전에 255자를 넘는 행을 지운다 — 엄격 모드에서는 잘리는 값이 하나라도 있으면
     * ALTER 자체가 실패한다. 통계 행은 파생 데이터라 손실이 복구 대상이 아니다.
     *
     * @return void
     */
    public function down(): void
    {
        DB::table('seo_cache_stats')->whereRaw('CHAR_LENGTH(url) > 255')->delete();

        Schema::table('seo_cache_stats', function (Blueprint $table) {
            $table->string('url', 255)->comment('요청 URL')->change();
        });
    }
};
