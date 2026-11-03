<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 기존 배너에도 표시 설정과 수정 충돌 확인용 세대를 추가합니다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (! Schema::hasColumn('hero_section_banners', 'presentation')) {
            Schema::table('hero_section_banners', function (Blueprint $table) {
                $table->json('presentation')->nullable()->comment('PC·모바일 초점 좌표(0~100)와 문구 배경');
            });
        }
        if (! Schema::hasColumn('hero_section_banners', 'lock_version')) {
            Schema::table('hero_section_banners', function (Blueprint $table) {
                $table->unsignedInteger('lock_version')->default(0)->comment('동시 수정 충돌 확인용 세대 번호');
            });
        }
    }

    /**
     * 추가 컬럼만 되돌립니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        foreach (['presentation', 'lock_version'] as $column) {
            if (Schema::hasColumn('hero_section_banners', $column)) {
                Schema::table('hero_section_banners', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
