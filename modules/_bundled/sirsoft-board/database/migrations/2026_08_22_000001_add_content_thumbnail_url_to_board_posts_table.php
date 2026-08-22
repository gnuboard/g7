<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * board_posts 에 본문 첫 내부 이미지 URL 캐시 컬럼 추가 (공개 이슈 #22)
     *
     * 목록 쿼리는 content 를 SELECT 하지 않으므로(컬럼 프루닝) 조회 시점 본문 파싱이
     * 불가하다 — 저장 시점(모델 saving 이벤트)에 추출해 이 컬럼에 캐시한다.
     * 기존 행 백필은 Upgrade_1_1_0 업그레이드 스텝이 수행한다 (마이그레이션은 스키마만).
     */
    public function up(): void
    {
        Schema::table('board_posts', function (Blueprint $table) {
            if (! Schema::hasColumn('board_posts', 'content_thumbnail_url')) {
                $table->string('content_thumbnail_url', 1000)
                    ->nullable()
                    ->after('content_mode')
                    ->comment('본문 첫 내부 이미지 URL 캐시 — 이미지 첨부가 없을 때 목록 썸네일 폴백');
            }
        });
    }

    /**
     * 마이그레이션 롤백
     */
    public function down(): void
    {
        if (Schema::hasTable('board_posts')) {
            $columns = Schema::getColumnListing('board_posts');

            Schema::table('board_posts', function (Blueprint $table) use ($columns) {
                if (in_array('content_thumbnail_url', $columns)) {
                    $table->dropColumn('content_thumbnail_url');
                }
            });
        }
    }
};
