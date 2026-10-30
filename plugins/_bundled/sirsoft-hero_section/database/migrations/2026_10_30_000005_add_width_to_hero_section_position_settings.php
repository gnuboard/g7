<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 위치별 슬라이더 설정에 표시 폭을 추가합니다. 기존 행은 본문 폭(content)으로 채워져 화면이 그대로다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (Schema::hasColumn('hero_section_position_settings', 'width')) {
            return;
        }

        Schema::table('hero_section_position_settings', function (Blueprint $table) {
            $table->string('width', 10)->default('content')->after('effect')->comment('표시 폭: content(본문 폭), full(화면 전체)');
        });
    }

    /**
     * 표시 폭 컬럼만 되돌립니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        if (Schema::hasColumn('hero_section_position_settings', 'width')) {
            Schema::table('hero_section_position_settings', fn (Blueprint $table) => $table->dropColumn('width'));
        }
    }
};
