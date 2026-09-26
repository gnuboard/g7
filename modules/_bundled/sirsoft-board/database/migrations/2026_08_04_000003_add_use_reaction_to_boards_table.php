<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * boards 테이블에 반응 사용 여부 컬럼 추가
     *
     * use_comment/use_report와 동일 패턴 — 게시판별 반응 기능 on/off.
     */
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            if (! Schema::hasColumn('boards', 'use_reaction')) {
                $table->boolean('use_reaction')
                    ->default(true)
                    ->after('use_report')
                    ->comment('반응(추천/비추천) 사용 여부');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('boards')) {
            $columns = Schema::getColumnListing('boards');

            Schema::table('boards', function (Blueprint $table) use ($columns) {
                if (in_array('use_reaction', $columns)) {
                    $table->dropColumn('use_reaction');
                }
            });
        }
    }
};
