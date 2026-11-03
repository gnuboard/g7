<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 배너 머리글(제목 위 짧은 한 줄, 다국어)·보이기·색과 위치 설정의 메뉴 겹치기를 추가합니다.
     * 기존 배너는 머리글이 비어 있어 화면이 그대로이고, 기존 위치는 메뉴 겹치기가 꺼져 있다.
     * 문구 위치·폭·등장 효과·바탕 밝기는 기존 `presentation` JSON 에 들어가므로 컬럼을 늘리지 않는다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (! Schema::hasColumn('hero_section_banners', 'eyebrow')) {
            Schema::table('hero_section_banners', function (Blueprint $table) {
                $table->text('eyebrow')->nullable()->after('title')->comment('머리글 (제목 위 짧은 한 줄, 다국어 JSON, 비면 그리지 않음)');
            });
        }

        if (! Schema::hasColumn('hero_section_banners', 'eyebrow_visible')) {
            Schema::table('hero_section_banners', function (Blueprint $table) {
                $table->boolean('eyebrow_visible')->default(true)->after('image_alt')->comment('머리글 표시 여부');
            });
        }

        if (! Schema::hasColumn('hero_section_banners', 'eyebrow_color')) {
            Schema::table('hero_section_banners', function (Blueprint $table) {
                $table->string('eyebrow_color', 7)->default('#FFFFFF')->after('eyebrow_visible')->comment('머리글 색 (#RRGGBB)');
            });
        }

        if (! Schema::hasColumn('hero_section_position_settings', 'header_overlay')) {
            Schema::table('hero_section_position_settings', function (Blueprint $table) {
                $table->boolean('header_overlay')->default(false)->after('width')->comment('메뉴 겹치기: 화면 전체 폭일 때 사이트 상단 메뉴를 배너 위에 투명하게 겹친다');
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
        if (Schema::hasColumn('hero_section_position_settings', 'header_overlay')) {
            Schema::table('hero_section_position_settings', fn (Blueprint $table) => $table->dropColumn('header_overlay'));
        }

        foreach (['eyebrow_color', 'eyebrow_visible', 'eyebrow'] as $column) {
            if (Schema::hasColumn('hero_section_banners', $column)) {
                Schema::table('hero_section_banners', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
