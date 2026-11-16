<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 내보내기 작업에 파일이 저장된 디스크 컬럼을 추가합니다.
 *
 * 내보내기 파일은 관리자가 고른 「비공개 첨부 저장소」 디스크(「기본」이면 `modules`)에 저장된다. 디스크가 행에
 * 남지 않으면 운영자가 저장소를 바꾼 뒤 그 전에 만든 파일을 받지도, 지우지도, 만료 정리하지도 못한다 — 서비스가
 * 지금 디스크로만 찾기 때문이다. 그래서 저장할 때의 디스크를 행에 남기고, 다운로드·삭제·정리는 그 디스크로 간다.
 *
 * 기존 행은 기본값 `modules` 로 채워진다. 이 컬럼 전에는 내보내기가 언제나 모듈 기본 디스크(`modules`)에 저장됐으므로
 * 백필이 따로 필요 없다(업그레이드 스텝 없음).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable('ecommerce_exports') || Schema::hasColumn('ecommerce_exports', 'disk')) {
            return;
        }

        Schema::table('ecommerce_exports', function (Blueprint $table) {
            $table->string('disk', 50)->default('modules')->after('file_path')
                ->comment('파일이 저장된 디스크 (filesystems.disks 키 — 저장할 때의 비공개 첨부 저장소, 이 컬럼 추가 전 행은 modules). 다운로드·삭제·만료 정리가 이 디스크로 간다');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_exports') && Schema::hasColumn('ecommerce_exports', 'disk')) {
            Schema::table('ecommerce_exports', function (Blueprint $table) {
                $table->dropColumn('disk');
            });
        }
    }
};
