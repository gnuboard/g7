<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 게시판별 스크랩 기능 on/off 컬럼 추가.
     * use_comment/use_report 등과 동일한 게시판 설정 패턴.
     */
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            if (! Schema::hasColumn('boards', 'use_scrap')) {
                $table->boolean('use_scrap')
                    ->default(true)
                    ->after('use_report')
                    ->comment('게시글 스크랩(북마크) 기능 사용 (1: 사용, 0: 미사용)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('boards') && Schema::hasColumn('boards', 'use_scrap')) {
            Schema::table('boards', function (Blueprint $table) {
                $table->dropColumn('use_scrap');
            });
        }
    }
};
