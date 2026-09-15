<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 관리자 스크랩 관리 목록용 인덱스를 추가한다.
     *
     * 기존 인덱스는 (user_id, created_at)·post_id·FK board_id 뿐이라, 회원을 특정하지 않고
     * created_at 으로 정렬하는 관리자 목록은 인덱스를 타지 못하고 전체 스캔 + filesort 가 된다.
     * 지연 조인의 inner 단계가 정렬 컬럼 인덱스에 기대므로, 인덱스가 없으면 그 최적화가
     * 통째로 무효가 된다.
     *
     * - idx_scrap_created_at (created_at, id): 전체 목록의 기본 정렬 (id 는 동률 시 전순서)
     * - idx_scrap_board_created (board_id, created_at): 게시판 필터 + 정렬 조합
     *
     * 기설치본에도 그대로 적용되도록 hasIndex 가드를 둔다 (module:update 재실행 안전).
     */
    public function up(): void
    {
        if (! Schema::hasTable('board_post_scraps')) {
            return;
        }

        Schema::table('board_post_scraps', function (Blueprint $table) {
            if (! Schema::hasIndex('board_post_scraps', 'idx_scrap_created_at')) {
                $table->index(['created_at', 'id'], 'idx_scrap_created_at');
            }

            if (! Schema::hasIndex('board_post_scraps', 'idx_scrap_board_created')) {
                $table->index(['board_id', 'created_at'], 'idx_scrap_board_created');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('board_post_scraps')) {
            return;
        }

        Schema::table('board_post_scraps', function (Blueprint $table) {
            if (Schema::hasIndex('board_post_scraps', 'idx_scrap_created_at')) {
                $table->dropIndex('idx_scrap_created_at');
            }

            if (Schema::hasIndex('board_post_scraps', 'idx_scrap_board_created')) {
                $table->dropIndex('idx_scrap_board_created');
            }
        });
    }
};
