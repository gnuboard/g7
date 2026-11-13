<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 유튜브 등 제3자 영상 플랫폼 배너를 없앱니다 — 유튜브 배너는 이미지 배너로 바꾸고 유튜브 영상 ID 컬럼을 지웁니다.
     *
     * 유튜브 배너도 PC 이미지(대표 이미지)가 필수였으므로 이미지 배너로 바꾸면 화면에는 그 이미지가 그대로 보입니다.
     * 컬럼이 이미 없으면(다시 실행) 아무것도 하지 않습니다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (! Schema::hasColumn('hero_section_banners', 'youtube_id')) {
            return;
        }

        DB::table('hero_section_banners')->where('media_type', 'youtube')->update(['media_type' => 'image']);

        Schema::table('hero_section_banners', fn (Blueprint $table) => $table->dropColumn('youtube_id'));
    }

    /**
     * 유튜브 영상 ID 컬럼만 빈 값으로 되살립니다. 이미지로 바꾼 배너는 되돌리지 않습니다(원래 영상 ID 를 알 수 없다).
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        if (Schema::hasColumn('hero_section_banners', 'youtube_id')) {
            return;
        }

        Schema::table('hero_section_banners', function (Blueprint $table) {
            $table->string('youtube_id', 11)->nullable()->after('media_type')->comment('유튜브 영상 ID (media_type 이 youtube 일 때, 11자)');
        });
    }
};
