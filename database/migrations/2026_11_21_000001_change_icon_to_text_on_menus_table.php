<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * menus.icon 을 TEXT 로 넓힙니다 (7.2.1, #381).
 *
 * 관리자 메뉴 아이콘이 FA 클래스 문자열 외에 이미지·인라인 SVG 코드(최대 32KB)를 구조형 JSON 으로 담는다.
 * 데이터 변환은 없다 — 기존 문자열은 그대로 FA 클래스로 해석된다.
 */
return new class extends Migration
{
    /**
     * icon 컬럼을 TEXT 로 바꿉니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable('menus') || ! Schema::hasColumn('menus', 'icon')) {
            return;
        }

        Schema::table('menus', function (Blueprint $table) {
            $table->text('icon')->nullable()->comment('메뉴 아이콘 — FA 클래스 문자열 또는 구조형 JSON(image·svg)')->change();
        });
    }

    /**
     * icon 컬럼을 VARCHAR(255) 로 되돌립니다 — 255자를 넘는 값이 있으면 데이터 손실을 막기 위해 거부합니다.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable('menus') || ! Schema::hasColumn('menus', 'icon')) {
            return;
        }

        // 길이 판정은 PHP 에서 한다 — DB 전용 함수(CHAR_LENGTH 등)에 기대지 않는다
        $overflow = 0;
        DB::table('menus')->whereNotNull('icon')->select(['id', 'icon'])->orderBy('id')
            ->chunkById(500, function ($rows) use (&$overflow): void {
                foreach ($rows as $row) {
                    if (mb_strlen((string) $row->icon) > 255) {
                        $overflow++;
                    }
                }
            });

        if ($overflow > 0) {
            throw new RuntimeException("menus.icon 을 VARCHAR(255) 로 되돌릴 수 없습니다 — 255자를 넘는 아이콘 값 {$overflow}건(이미지·SVG 아이콘)을 먼저 바꾸세요.");
        }

        Schema::table('menus', function (Blueprint $table) {
            $table->string('icon')->nullable()->comment('메뉴 아이콘')->change();
        });
    }
};
