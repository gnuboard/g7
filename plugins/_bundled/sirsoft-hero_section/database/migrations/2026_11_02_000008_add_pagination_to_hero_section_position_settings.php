<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 위치별 슬라이더 설정에 페이징 모양을 추가합니다. 기존 행은 점(dots)으로 채워져 화면이 그대로다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (Schema::hasColumn('hero_section_position_settings', 'pagination')) {
            return;
        }

        Schema::table('hero_section_position_settings', function (Blueprint $table) {
            $table->string('pagination', 10)->default('dots')->after('header_overlay')->comment('페이징 모양: dots(점), progress(진행 막대 점), tabs(배너 이름 탭), fraction(번호)');
        });
    }

    /**
     * 페이징 모양 컬럼만 되돌립니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        if (Schema::hasColumn('hero_section_position_settings', 'pagination')) {
            Schema::table('hero_section_position_settings', fn (Blueprint $table) => $table->dropColumn('pagination'));
        }
    }
};
