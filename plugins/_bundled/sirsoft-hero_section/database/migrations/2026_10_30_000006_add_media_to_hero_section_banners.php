<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 배너 미디어 종류(이미지·유튜브·영상 파일)와 유튜브 영상 ID, 이미지 행의 파일 종류(이미지·영상)를 추가합니다.
     * 기존 배너는 이미지(image), 기존 파일 행은 이미지(image)로 채워져 화면이 그대로다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (! Schema::hasColumn('hero_section_banners', 'media_type')) {
            Schema::table('hero_section_banners', function (Blueprint $table) {
                $table->string('media_type', 10)->default('image')->after('position')->comment('미디어 종류: image 이미지, youtube 유튜브, video 영상 파일');
            });
        }

        if (! Schema::hasColumn('hero_section_banners', 'youtube_id')) {
            Schema::table('hero_section_banners', function (Blueprint $table) {
                $table->string('youtube_id', 11)->nullable()->after('media_type')->comment('유튜브 영상 ID (media_type 이 youtube 일 때, 11자)');
            });
        }

        if (! Schema::hasColumn('hero_section_banner_images', 'kind')) {
            Schema::table('hero_section_banner_images', function (Blueprint $table) {
                $table->string('kind', 10)->default('image')->after('device')->comment('파일 종류: image 이미지(대표 이미지), video 영상');
            });
        }
    }

    /**
     * 추가한 컬럼만 되돌립니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        if (Schema::hasColumn('hero_section_banner_images', 'kind')) {
            Schema::table('hero_section_banner_images', fn (Blueprint $table) => $table->dropColumn('kind'));
        }

        foreach (['youtube_id', 'media_type'] as $column) {
            if (Schema::hasColumn('hero_section_banners', $column)) {
                Schema::table('hero_section_banners', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
