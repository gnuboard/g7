<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * boards 테이블에 댓글 반응 사용 여부 컬럼 추가
     *
     * use_reaction(게시글 반응)의 하위 토글이다. use_reaction 이 꺼져 있으면 이 값과 무관하게
     * 댓글 반응도 동작하지 않는다(서비스 가드가 두 값을 모두 본다).
     * 기본값 true — 반응을 쓰는 게시판은 댓글에도 반응이 열려 있는 것이 기대 동작이다.
     *
     * 2026_08_04_000003(use_reaction) 패턴 재사용.
     */
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            if (! Schema::hasColumn('boards', 'use_comment_reaction')) {
                $table->boolean('use_comment_reaction')
                    ->default(true)
                    ->after('use_reaction')
                    ->comment('댓글 반응 사용 여부 (use_reaction 하위 토글)');
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
                if (in_array('use_comment_reaction', $columns)) {
                    $table->dropColumn('use_comment_reaction');
                }
            });
        }
    }
};
