<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * boards 테이블에 답글 삭제 정책 컬럼을 추가합니다.
 *
 * 답글이 달린 글을 삭제할 때의 동작을 게시판별로 선택합니다.
 *  - 'cascade' (기본값): 답글도 함께 소프트 삭제하고, 복원 시 연쇄분만 되살립니다.
 *  - 'block': 살아 있는 답글이 있는 동안 원글 삭제를 차단합니다.
 *
 * NOT NULL + default 라 ALTER 시 기존 행이 자동으로 'cascade' 로 채워집니다 —
 * 지금까지 성공하던 삭제(신고 조치·관리자 삭제)가 갑자기 차단되지 않습니다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('boards') || Schema::hasColumn('boards', 'reply_delete_policy')) {
            return;
        }

        Schema::table('boards', function (Blueprint $table) {
            $table->string('reply_delete_policy', 20)
                ->default('cascade')
                ->comment('답글 삭제 정책 (block: 답글 있으면 삭제 차단, cascade: 함께 소프트 삭제)')
                ->after('max_reply_depth');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('boards') || ! Schema::hasColumn('boards', 'reply_delete_policy')) {
            return;
        }

        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn('reply_delete_policy');
        });
    }
};
