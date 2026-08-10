<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 게시글 목록의 조회수 정렬 색인을 추가합니다.
 *
 * 조회수 정렬은 화면에서 실제로 도달 가능한 경로다 — 게시판 설정의 기본 정렬
 * (`order_by = 'view_count'`)과 목록 URL(`?sort_by=view_count`) 둘 다 있고, 저장소
 * 정렬 화이트리스트에도 들어 있다. 그런데 목록 술어(`board_id` + `is_notice=0`
 * + `parent_id IS NULL` + `deleted_at IS NULL`)를 등치 사슬로 덮으면서 `view_count`
 * 로 끝나는 색인이 없어, 뒤쪽 페이지가 테이블 전체를 훑고 정렬했다.
 *
 * 필요한 색인은 계측 프로파일 선언(filters / order / soft_delete)에서 도출한 것이며,
 * `ListIndexCoverageTest` 가 같은 규칙으로 전 목록을 검사한다.
 *
 * 기존 `idx_board_posts_board_view_count (board_id, view_count)` 는 지우지 않는다 —
 * 인기글 조회(`getPopularPosts`)가 쓰는 색인이며, 이 목록 색인은 앞쪽에 등치 컬럼이
 * 더 붙어 있어 `(board_id, view_count)` 만 필요한 그 쿼리를 대신하지 못한다.
 * (`2026_04_17_000004` 의 주석이 `USE INDEX` 힌트를 언급하나 현재 코드에 힌트는 없다.)
 */
return new class extends Migration
{
    private const TABLE = 'board_posts';

    private const INDEX_NAME = 'idx_board_posts_list_views';

    /** @var array<int, string> */
    private const INDEX_COLUMNS = ['board_id', 'is_notice', 'parent_id', 'deleted_at', 'view_count', 'id'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $existing = array_column(Schema::getIndexes(self::TABLE), 'name');

        Schema::table(self::TABLE, function (Blueprint $table) use ($existing) {
            if (! in_array(self::INDEX_NAME, $existing, true)) {
                $table->index(self::INDEX_COLUMNS, self::INDEX_NAME);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $existing = array_column(Schema::getIndexes(self::TABLE), 'name');

        Schema::table(self::TABLE, function (Blueprint $table) use ($existing) {
            if (in_array(self::INDEX_NAME, $existing, true)) {
                $table->dropIndex(self::INDEX_NAME);
            }
        });
    }
};
