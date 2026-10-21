<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SEO 캐시 통계의 locale 칸을 언어팩 언어 코드 길이(20자)에 맞춥니다.
     *
     * 다섯 글자보다 긴 언어(`zh-Hant-TW`)의 통계 기록이 엄격 모드에서 실패하고 통계 서비스가
     * 그 예외를 삼켜 조용히 빠졌다(#148). 20 은 `language_packs.locale` 폭이다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        Schema::table('seo_cache_stats', function (Blueprint $table) {
            $table->string('locale', 20)->comment('로케일')->change();
        });
    }

    /**
     * locale 칸을 원래 길이(5)로 되돌립니다.
     *
     * 되돌리기 전에 다섯 글자를 넘는 행을 지운다 — 엄격 모드에서는 잘리는 값이 하나라도 있으면
     * ALTER 자체가 실패한다. 통계 행은 파생 데이터라 손실이 복구 대상이 아니다. 길이 판정은 DB 종속 함수
     * 대신 PHP 에서 한다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        DB::table('seo_cache_stats')->select(['id', 'locale'])->orderBy('id')->chunkById(500, function ($rows) {
            $ids = $rows->filter(fn ($row) => mb_strlen((string) $row->locale) > 5)->pluck('id')->all();
            if ($ids !== []) {
                DB::table('seo_cache_stats')->whereIn('id', $ids)->delete();
            }
        });

        Schema::table('seo_cache_stats', function (Blueprint $table) {
            $table->string('locale', 5)->comment('로케일')->change();
        });
    }
};
