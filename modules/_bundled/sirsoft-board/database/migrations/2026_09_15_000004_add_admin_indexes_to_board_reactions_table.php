<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 관리자 반응 관리 목록용 인덱스를 추가한다.
     *
     * 기존 인덱스는 unique(user_id,target_type,target_id)·(target_type,target_id)·
     * reaction_type_id·board_id 뿐이라, 회원을 특정하지 않고 created_at 으로 정렬하는
     * 관리자 목록은 인덱스를 타지 못하고 전체 스캔 + filesort 가 된다.
     * 지연 조인의 inner 단계가 정렬 컬럼 인덱스에 기대므로, 인덱스가 없으면 그 최적화가
     * 통째로 무효가 된다 (2026_09_14_000001 스크랩 인덱스와 동형).
     *
     * - idx_reaction_created_at (created_at, id): 전체 목록의 기본 정렬 (id 는 동률 시 전순서)
     * - idx_reaction_board_created (board_id, created_at): 게시판 필터 + 정렬 조합
     * - idx_reaction_type_created (reaction_type_id, created_at): 반응 유형 필터 + 정렬 조합
     *
     * 기존 idx_reaction_type 은 FK(reaction_type_id) 백킹 인덱스이므로 유지한다.
     * 기설치본에도 그대로 적용되도록 hasIndex 가드를 둔다 (module:update 재실행 안전).
     */
    public function up(): void
    {
        if (! Schema::hasTable('board_reactions')) {
            return;
        }

        Schema::table('board_reactions', function (Blueprint $table) {
            if (! Schema::hasIndex('board_reactions', 'idx_reaction_created_at')) {
                $table->index(['created_at', 'id'], 'idx_reaction_created_at');
            }

            if (! Schema::hasIndex('board_reactions', 'idx_reaction_board_created')) {
                $table->index(['board_id', 'created_at'], 'idx_reaction_board_created');
            }

            if (! Schema::hasIndex('board_reactions', 'idx_reaction_type_created')) {
                $table->index(['reaction_type_id', 'created_at'], 'idx_reaction_type_created');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('board_reactions')) {
            return;
        }

        Schema::table('board_reactions', function (Blueprint $table) {
            if (Schema::hasIndex('board_reactions', 'idx_reaction_created_at')) {
                $table->dropIndex('idx_reaction_created_at');
            }

            if (Schema::hasIndex('board_reactions', 'idx_reaction_board_created')) {
                $table->dropIndex('idx_reaction_board_created');
            }

            if (Schema::hasIndex('board_reactions', 'idx_reaction_type_created')) {
                $table->dropIndex('idx_reaction_type_created');
            }
        });
    }
};
