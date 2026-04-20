<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 모듈/플러그인 테이블에 vendor_mode 컬럼 추가.
     *
     * 마지막 설치 시 사용된 vendor 모드(auto/composer/bundled)를 기록하여,
     * 업데이트 시 동일 모드를 상속할 수 있도록 합니다.
     */
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            if (! Schema::hasColumn('modules', 'vendor_mode')) {
                $table->string('vendor_mode', 16)
                    ->default('auto')
                    ->after('status')
                    ->comment('Vendor 설치 모드 (auto|composer|bundled)');
            }
        });

        Schema::table('plugins', function (Blueprint $table) {
            if (! Schema::hasColumn('plugins', 'vendor_mode')) {
                $table->string('vendor_mode', 16)
                    ->default('auto')
                    ->after('status')
                    ->comment('Vendor 설치 모드 (auto|composer|bundled)');
            }
        });
    }

    /**
     * 마이그레이션 롤백.
     */
    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            if (Schema::hasColumn('modules', 'vendor_mode')) {
                $table->dropColumn('vendor_mode');
            }
        });

        Schema::table('plugins', function (Blueprint $table) {
            if (Schema::hasColumn('plugins', 'vendor_mode')) {
                $table->dropColumn('vendor_mode');
            }
        });
    }
};
