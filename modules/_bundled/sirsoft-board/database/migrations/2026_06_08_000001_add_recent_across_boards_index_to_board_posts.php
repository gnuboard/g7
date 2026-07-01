<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 대시보드 최신글 카드의 "전체 게시판 가로지르기" 쿼리 인덱스 보강.
     *
     * BoardDashboardService::getRecentPosts() → PostRepository::getRecentAcrossBoards()
     * 가 사용하는 다음 패턴은 기존 인덱스가 모두 board_id 또는 user_id 를 첫 컬럼으로
     * 두고 있어 활용 불가:
     *
     *   WHERE deleted_at IS NULL AND parent_id IS NULL
     *   ORDER BY created_at DESC LIMIT N
     *
     * 1M 글 환경 실측에서 filesort 동반 약 3.7초 소요. 본 인덱스로 정렬 비용 제거.
     */
    public function up(): void
    {
        Schema::table('board_posts', function (Blueprint $table) {
            $table->index(
                ['deleted_at', 'parent_id', 'created_at'],
                'idx_board_posts_recent_across_boards'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_posts', function (Blueprint $table) {
            $table->dropIndex('idx_board_posts_recent_across_boards');
        });
    }
};
