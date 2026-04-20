<?php

use App\Search\Engines\DatabaseFulltextEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * board_posts / board_comments / board_attachments 인덱스 전체 정비
 *
 * 전체 53개 쿼리 패턴 전수조사 + EXPLAIN 검증을 거쳐
 * 복합 인덱스 추가 · 통합 · 중복/미사용 인덱스 제거를 일괄 적용합니다.
 *
 * ## board_posts
 *
 * 추가 (10개):
 *  - idx_board_posts_board_parent         (board_id, parent_id)                                    답글 트리 조회, getAdjacentPosts index_merge
 *  - idx_board_posts_board_category       (board_id, category, created_at)                         카테고리 필터+정렬
 *  - idx_board_posts_board_view_count     (board_id, view_count)                                   인기글 조회수 정렬, getPopularPosts USE INDEX
 *  - idx_board_posts_user_activity        (user_id, deleted_at, board_id, created_at)              마이페이지 COUNT 커버링
 *  - idx_board_posts_user_created         (user_id, deleted_at, created_at)                        마이페이지 SELECT + ORDER BY created_at (Filesort 제거)
 *  - idx_board_posts_user_board_stats     (user_id, board_id, comments_count, view_count)          사용자 활동 통계 SUM 커버링
 *  - idx_board_posts_list_count           (board_id, is_notice, parent_id, deleted_at, created_at) 목록 페이지네이션 커버링 + 정렬
 *  - idx_board_posts_adjacent             (board_id, status, is_notice, parent_id, deleted_at, created_at, id) 이전/다음글 커버링 인덱스
 *  - idx_board_posts_user_status          (user_id, status)                                        사용자 프로필 공개 통계
 *  - ft_board_posts_title_content         FULLTEXT(title, content)                                 제목+본문 FULLTEXT 검색
 *
 * 제거 (11개 — 중복·미사용):
 *  - g7_board_posts_category_index        (category)                              → idx_board_posts_board_category에 포함
 *  - g7_board_posts_view_count_index      (view_count)                            → idx_board_posts_board_view_count에 포함
 *  - g7_board_posts_parent_id_index       (parent_id)                             → idx_board_posts_board_parent 좌측 프리픽스
 *  - g7_board_posts_user_id_index         (user_id)                               → idx_board_posts_user_activity 좌측 프리픽스
 *  - idx_board_notice                     (board_id, is_notice)                   → idx_board_posts_list_count 좌측 프리픽스
 *  - idx_board_status                     (board_id, status)                      → idx_board_posts_board_status_created 좌측 프리픽스
 *  - idx_user_views                       (user_id, view_count)                   → 사용 쿼리 없음
 *  - idx_user_created                     (user_id, created_at)                   → idx_board_posts_user_activity로 통합
 *  - idx_board_posts_user_deleted         (user_id, deleted_at)                   → idx_board_posts_user_activity로 통합
 *  - idx_board_posts_recent_lookup        (parent_id, status, deleted_at, created_at) → board_id 누락으로 풀스캔 유발, idx_board_posts_board_status_created가 대체
 *  - idx_board_posts_user_board_views     (user_id, board_id, view_count)         → idx_board_posts_user_board_stats로 대체
 *
 * ## board_comments
 *
 * 추가 (5개):
 *  - idx_board_comments_board_parent           (board_id, parent_id)              답글 조회, softDeleteByBoardId, boardCmtCntSync
 *  - idx_board_comments_post_created           (board_id, post_id, created_at)    댓글 목록 정렬 + softDeleteByPostId (idx_board_post 대체)
 *  - idx_board_comments_user_board             (user_id, board_id)                마이페이지 활동 통계
 *  - idx_board_comments_post_deleted_created   (board_id, post_id, deleted_at, created_at) 댓글 목록 deleted_at 필터링 인덱스 레벨 처리
 *  - idx_board_comments_user_status            (user_id, status)                  사용자 프로필 공개 통계
 *
 * 제거 (4개 — 중복):
 *  - idx_board_post                       (board_id, post_id)    → idx_board_comments_post_created 좌측 프리픽스
 *  - idx_board_status                     (board_id, status)     → 사용 쿼리 없음
 *  - idx_board_created                    (board_id, created_at) → 사용 쿼리 없음
 *  - g7_board_comments_user_id_index      (user_id)              → idx_user_post 좌측 프리픽스
 *
 * ## board_attachments
 *
 * 추가 (1개):
 *  - idx_board_attachments_post_id        (post_id)              썸네일 eager loading WHERE post_id IN (...)
 */
return new class extends Migration
{
    /**
     * 인덱스를 추가하고 중복·미사용 인덱스를 제거합니다.
     *
     * @return void
     */
    public function up(): void
    {
        // ── board_posts: 인덱스 추가 ──────────────────────────────────────────
        Schema::table('board_posts', function (Blueprint $table) {
            if (! $this->hasIndex('board_posts', 'idx_board_posts_board_parent')) {
                // 답글 트리 조회 (loadAllDescendantReplies), getAdjacentPosts index_merge
                $table->index(['board_id', 'parent_id'], 'idx_board_posts_board_parent');
            }

            if (! $this->hasIndex('board_posts', 'idx_board_posts_board_category')) {
                // 카테고리 필터 + created_at 정렬
                $table->index(['board_id', 'category', 'created_at'], 'idx_board_posts_board_category');
            }

            if (! $this->hasIndex('board_posts', 'idx_board_posts_board_view_count')) {
                // 인기글 조회수 정렬 (getPopularPosts USE INDEX)
                $table->index(['board_id', 'view_count'], 'idx_board_posts_board_view_count');
            }

            if (! $this->hasIndex('board_posts', 'idx_board_posts_user_activity')) {
                // 마이페이지 COUNT 커버링: WHERE user_id + deleted_at IS NULL + board_id NOT IN
                $table->index(['user_id', 'deleted_at', 'board_id', 'created_at'], 'idx_board_posts_user_activity');
            }

            if (! $this->hasIndex('board_posts', 'idx_board_posts_user_created')) {
                // 마이페이지 SELECT + ORDER BY created_at: Filesort 제거
                $table->index(['user_id', 'deleted_at', 'created_at'], 'idx_board_posts_user_created');
            }

            if (! $this->hasIndex('board_posts', 'idx_board_posts_user_board_stats')) {
                // 사용자 활동 통계: SUM(comments_count) + SUM(view_count) 커버링 인덱스
                $table->index(['user_id', 'board_id', 'comments_count', 'view_count'], 'idx_board_posts_user_board_stats');
            }

            if (! $this->hasIndex('board_posts', 'idx_board_posts_list_count')) {
                // 목록 페이지네이션 커버링 + 정렬 인덱스
                $table->index(
                    ['board_id', 'is_notice', 'parent_id', 'deleted_at', 'created_at'],
                    'idx_board_posts_list_count'
                );
            }

            if (! $this->hasIndex('board_posts', 'idx_board_posts_adjacent')) {
                // 이전/다음글 쿼리 커버링 인덱스
                // 등치 조건 5개 (board_id, status, is_notice, parent_id, deleted_at) + 정렬 (created_at, id)
                $table->index(
                    ['board_id', 'status', 'is_notice', 'parent_id', 'deleted_at', 'created_at', 'id'],
                    'idx_board_posts_adjacent'
                );
            }

            if (! $this->hasIndex('board_posts', 'idx_board_posts_user_status')) {
                // 사용자 프로필 공개 통계 — user_id + status 필터링
                $table->index(['user_id', 'status'], 'idx_board_posts_user_status');
            }
        });

        // ── board_posts: FULLTEXT 인덱스 추가 ────────────────────────────────
        $indexes = array_column(Schema::getIndexes('board_posts'), 'name');
        if (! in_array('ft_board_posts_title_content', $indexes)) {
            DatabaseFulltextEngine::addFulltextIndex('board_posts', 'ft_board_posts_title_content', ['title', 'content']);
        }

        // ── board_posts: 중복·미사용 인덱스 제거 (11개) ───────────────────────
        Schema::table('board_posts', function (Blueprint $table) {
            // (category) 단일 → idx_board_posts_board_category에 포함
            if ($this->hasIndex('board_posts', 'g7_board_posts_category_index')) {
                $table->dropIndex('g7_board_posts_category_index');
            }
            // (view_count) 단일 → idx_board_posts_board_view_count에 포함
            if ($this->hasIndex('board_posts', 'g7_board_posts_view_count_index')) {
                $table->dropIndex('g7_board_posts_view_count_index');
            }
            // (parent_id) 단일 → idx_board_posts_board_parent 좌측 프리픽스
            if ($this->hasIndex('board_posts', 'g7_board_posts_parent_id_index')) {
                $table->dropIndex('g7_board_posts_parent_id_index');
            }
            // (user_id) 단일 → idx_board_posts_user_activity 좌측 프리픽스
            if ($this->hasIndex('board_posts', 'g7_board_posts_user_id_index')) {
                $table->dropIndex('g7_board_posts_user_id_index');
            }
            // (board_id, is_notice) → idx_board_posts_list_count 좌측 프리픽스
            if ($this->hasIndex('board_posts', 'idx_board_notice')) {
                $table->dropIndex('idx_board_notice');
            }
            // (board_id, status) → idx_board_posts_board_status_created 좌측 프리픽스
            if ($this->hasIndex('board_posts', 'idx_board_status')) {
                $table->dropIndex('idx_board_status');
            }
            // (user_id, view_count) → 사용 쿼리 없음
            if ($this->hasIndex('board_posts', 'idx_user_views')) {
                $table->dropIndex('idx_user_views');
            }
            // (user_id, created_at) → idx_board_posts_user_activity로 통합
            if ($this->hasIndex('board_posts', 'idx_user_created')) {
                $table->dropIndex('idx_user_created');
            }
            // (user_id, deleted_at) → idx_board_posts_user_activity로 통합
            if ($this->hasIndex('board_posts', 'idx_board_posts_user_deleted')) {
                $table->dropIndex('idx_board_posts_user_deleted');
            }
            // (parent_id, status, deleted_at, created_at) → board_id 누락으로 풀스캔 유발, idx_board_posts_board_status_created가 대체
            if ($this->hasIndex('board_posts', 'idx_board_posts_recent_lookup')) {
                $table->dropIndex('idx_board_posts_recent_lookup');
            }
            // (user_id, board_id, view_count) → idx_board_posts_user_board_stats로 대체
            if ($this->hasIndex('board_posts', 'idx_board_posts_user_board_views')) {
                $table->dropIndex('idx_board_posts_user_board_views');
            }
        });

        // ── board_comments: 인덱스 추가 (5개) ────────────────────────────────
        Schema::table('board_comments', function (Blueprint $table) {
            if (! $this->hasIndex('board_comments', 'idx_board_comments_board_parent')) {
                // 답글 조회, softDeleteByBoardId, boardCmtCntSync (board_id + parent_id)
                $table->index(['board_id', 'parent_id'], 'idx_board_comments_board_parent');
            }

            if (! $this->hasIndex('board_comments', 'idx_board_comments_post_created')) {
                // 댓글 목록 정렬 + softDeleteByPostId (idx_board_post 대체)
                $table->index(['board_id', 'post_id', 'created_at'], 'idx_board_comments_post_created');
            }

            if (! $this->hasIndex('board_comments', 'idx_board_comments_user_board')) {
                // 마이페이지 활동 통계 (getUserActivityStats)
                $table->index(['user_id', 'board_id'], 'idx_board_comments_user_board');
            }

            if (! $this->hasIndex('board_comments', 'idx_board_comments_post_deleted_created')) {
                // 댓글 목록 조회 시 deleted_at 필터링 인덱스 레벨 처리
                $table->index(
                    ['board_id', 'post_id', 'deleted_at', 'created_at'],
                    'idx_board_comments_post_deleted_created'
                );
            }

            if (! $this->hasIndex('board_comments', 'idx_board_comments_user_status')) {
                // 사용자 프로필 공개 통계 — user_id + status 필터링
                $table->index(['user_id', 'status'], 'idx_board_comments_user_status');
            }
        });

        // ── board_comments: 중복 인덱스 제거 (4개) ───────────────────────────
        Schema::table('board_comments', function (Blueprint $table) {
            // (board_id, post_id) → idx_board_comments_post_created 좌측 프리픽스
            if ($this->hasIndex('board_comments', 'idx_board_post')) {
                $table->dropIndex('idx_board_post');
            }
            // (board_id, status) → 사용 쿼리 없음
            if ($this->hasIndex('board_comments', 'idx_board_status')) {
                $table->dropIndex('idx_board_status');
            }
            // (board_id, created_at) → 사용 쿼리 없음
            if ($this->hasIndex('board_comments', 'idx_board_created')) {
                $table->dropIndex('idx_board_created');
            }
            // (user_id) 단일 → idx_user_post (user_id, post_id) 좌측 프리픽스
            if ($this->hasIndex('board_comments', 'g7_board_comments_user_id_index')) {
                $table->dropIndex('g7_board_comments_user_id_index');
            }
        });

        // ── board_attachments: 인덱스 추가 (1개) ─────────────────────────────
        Schema::table('board_attachments', function (Blueprint $table) {
            if (! $this->hasIndex('board_attachments', 'idx_board_attachments_post_id')) {
                // 썸네일 eager loading: WHERE post_id IN (...) AND mime_type LIKE 'image/%'
                $table->index('post_id', 'idx_board_attachments_post_id');
            }
        });
    }

    /**
     * 인덱스를 원상 복구합니다.
     *
     * @return void
     */
    public function down(): void
    {
        // ── board_posts: 추가한 인덱스 제거 ─────────────────────────────────
        Schema::table('board_posts', function (Blueprint $table) {
            foreach ([
                'idx_board_posts_board_parent',
                'idx_board_posts_board_category',
                'idx_board_posts_board_view_count',
                'idx_board_posts_user_activity',
                'idx_board_posts_user_created',
                'idx_board_posts_user_board_stats',
                'idx_board_posts_list_count',
                'idx_board_posts_adjacent',
                'idx_board_posts_user_status',
            ] as $index) {
                if ($this->hasIndex('board_posts', $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        // ── board_posts: FULLTEXT 인덱스 제거 ────────────────────────────────
        $indexes = array_column(Schema::getIndexes('board_posts'), 'name');
        if (in_array('ft_board_posts_title_content', $indexes)) {
            Schema::table('board_posts', function (Blueprint $table) {
                $table->dropIndex('ft_board_posts_title_content');
            });
        }

        // ── board_posts: 제거했던 인덱스 복원 ────────────────────────────────
        Schema::table('board_posts', function (Blueprint $table) {
            if (! $this->hasIndex('board_posts', 'g7_board_posts_category_index')) {
                $table->index('category', 'g7_board_posts_category_index');
            }
            if (! $this->hasIndex('board_posts', 'g7_board_posts_view_count_index')) {
                $table->index('view_count', 'g7_board_posts_view_count_index');
            }
            if (! $this->hasIndex('board_posts', 'g7_board_posts_parent_id_index')) {
                $table->index('parent_id', 'g7_board_posts_parent_id_index');
            }
            if (! $this->hasIndex('board_posts', 'g7_board_posts_user_id_index')) {
                $table->index('user_id', 'g7_board_posts_user_id_index');
            }
            if (! $this->hasIndex('board_posts', 'idx_board_notice')) {
                $table->index(['board_id', 'is_notice'], 'idx_board_notice');
            }
            if (! $this->hasIndex('board_posts', 'idx_board_status')) {
                $table->index(['board_id', 'status'], 'idx_board_status');
            }
            // idx_user_views — 사용 쿼리 없음이므로 down()에서 복원하지 않음
            if (! $this->hasIndex('board_posts', 'idx_user_created')) {
                $table->index(['user_id', 'created_at'], 'idx_user_created');
            }
            if (! $this->hasIndex('board_posts', 'idx_board_posts_user_deleted')) {
                $table->index(['user_id', 'deleted_at'], 'idx_board_posts_user_deleted');
            }
            if (! $this->hasIndex('board_posts', 'idx_board_posts_recent_lookup')) {
                $table->index(['parent_id', 'status', 'deleted_at', 'created_at'], 'idx_board_posts_recent_lookup');
            }
            if (! $this->hasIndex('board_posts', 'idx_board_posts_user_board_views')) {
                $table->index(['user_id', 'board_id', 'view_count'], 'idx_board_posts_user_board_views');
            }
        });

        // ── board_comments: 추가한 인덱스 제거 ──────────────────────────────
        Schema::table('board_comments', function (Blueprint $table) {
            foreach ([
                'idx_board_comments_board_parent',
                'idx_board_comments_post_created',
                'idx_board_comments_user_board',
                'idx_board_comments_post_deleted_created',
                'idx_board_comments_user_status',
            ] as $index) {
                if ($this->hasIndex('board_comments', $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        // ── board_comments: 제거했던 인덱스 복원 ─────────────────────────────
        Schema::table('board_comments', function (Blueprint $table) {
            if (! $this->hasIndex('board_comments', 'idx_board_post')) {
                $table->index(['board_id', 'post_id'], 'idx_board_post');
            }
            if (! $this->hasIndex('board_comments', 'idx_board_status')) {
                $table->index(['board_id', 'status'], 'idx_board_status');
            }
            if (! $this->hasIndex('board_comments', 'idx_board_created')) {
                $table->index(['board_id', 'created_at'], 'idx_board_created');
            }
            if (! $this->hasIndex('board_comments', 'g7_board_comments_user_id_index')) {
                $table->index('user_id', 'g7_board_comments_user_id_index');
            }
        });

        // ── board_attachments: 추가한 인덱스 제거 ────────────────────────────
        Schema::table('board_attachments', function (Blueprint $table) {
            if ($this->hasIndex('board_attachments', 'idx_board_attachments_post_id')) {
                $table->dropIndex('idx_board_attachments_post_id');
            }
        });
    }

    /**
     * 인덱스 존재 여부를 확인합니다.
     *
     * @param  string  $table  테이블명
     * @param  string  $indexName  인덱스명
     * @return bool
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = Schema::getIndexes($table);

        return collect($indexes)->contains(fn ($index) => $index['name'] === $indexName);
    }
};
