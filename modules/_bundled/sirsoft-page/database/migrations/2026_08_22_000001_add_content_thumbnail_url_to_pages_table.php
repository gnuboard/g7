<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * pages 에 본문 첫 내부 이미지 URL 캐시 컬럼 추가 (공개 이슈 #22 동종)
     *
     * 페이지는 og:image 를 공급할 경로가 전무했다(본문·첨부·수동 어느 경로로도 불가).
     * 저장 시점(모델 saving 이벤트)에 본문(다국어 JSON) 첫 내부 이미지를 추출해 캐시하고,
     * 공개 Resource 가 방출해 페이지 표시 레이아웃의 og:image 가 소비한다.
     * 기존 행 백필은 Upgrade_1_1_0 업그레이드 스텝이 수행한다.
     */
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            if (! Schema::hasColumn('pages', 'content_thumbnail_url')) {
                $table->string('content_thumbnail_url', 1000)
                    ->nullable()
                    ->after('content_mode')
                    ->comment('본문 첫 내부 이미지 URL 캐시 — 페이지 og:image 폴백');
            }
        });
    }

    /**
     * 마이그레이션 롤백
     */
    public function down(): void
    {
        if (Schema::hasTable('pages')) {
            $columns = Schema::getColumnListing('pages');

            Schema::table('pages', function (Blueprint $table) use ($columns) {
                if (in_array('content_thumbnail_url', $columns)) {
                    $table->dropColumn('content_thumbnail_url');
                }
            });
        }
    }
};
