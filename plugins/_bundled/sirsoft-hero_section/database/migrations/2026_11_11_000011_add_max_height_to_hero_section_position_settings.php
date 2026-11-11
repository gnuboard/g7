<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 위치별 슬라이더 설정에 기기별 최대 높이(px)를 추가합니다. 기존 행은 비워(null) 두어 화면이 그대로다 — 비우면 비율대로 그리고,
     * 화면 전체 폭은 지금처럼 640px 에서 멈춘다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (Schema::hasColumn('hero_section_position_settings', 'max_height_pc')) {
            return;
        }

        Schema::table('hero_section_position_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_height_pc')->nullable()->after('pagination')->comment('PC 최대 높이(px). null 이면 비율대로(화면 전체 폭은 640px 에서 멈춤)');
            $table->unsignedSmallInteger('max_height_mobile')->nullable()->after('max_height_pc')->comment('휴대폰 최대 높이(px). null 이면 비율대로(화면 전체 폭은 640px 에서 멈춤)');
        });
    }

    /**
     * 최대 높이 컬럼만 되돌립니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        if (Schema::hasColumn('hero_section_position_settings', 'max_height_pc')) {
            Schema::table('hero_section_position_settings', fn (Blueprint $table) => $table->dropColumn(['max_height_pc', 'max_height_mobile']));
        }
    }
};
