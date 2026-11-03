<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 이미지 위 글 상자 컬럼을 더합니다. 기존 팝업은 null(글 상자 없음)입니다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (! Schema::hasTable('popups') || Schema::hasColumn('popups', 'overlays')) {
            return;
        }

        Schema::table('popups', function (Blueprint $table) {
            $table->json('overlays')->nullable()->after('link_new_window')
                ->comment('이미지 위 글 상자 (JSON: pc·mobile 배열, 위치·폭·글자 크기는 이미지 기준 %, 글만 다국어 / mobile null 이면 PC 배치 사용)');
        });
    }

    /**
     * 이미지 위 글 상자 컬럼을 지웁니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        if (! Schema::hasTable('popups') || ! Schema::hasColumn('popups', 'overlays')) {
            return;
        }

        Schema::table('popups', function (Blueprint $table) {
            $table->dropColumn('overlays');
        });
    }
};
