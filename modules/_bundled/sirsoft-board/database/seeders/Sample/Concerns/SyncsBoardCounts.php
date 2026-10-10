<?php

namespace Modules\Sirsoft\Board\Database\Seeders\Sample\Concerns;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 게시판 샘플 시더 공용 — 비정규화 카운트 컬럼을 실데이터로 다시 맞춘다
 *
 * 샘플 시더는 모델 이벤트(BoardCommentsCountSyncListener / PostCountSyncListener / 반응·스크랩 서비스)를
 * 우회하는 raw INSERT·UPDATE 라 캐시 컬럼이 갱신되지 않는다. 시더가 끝날 때 이 트레이트로
 * 실제 행 수와 일치시킨다 (소프트 삭제 제외).
 *
 * 특정 DB 문법(MySQL 의 UPDATE … JOIN 등)에 기대지 않도록 쿼리 빌더로 묶음 집계를 읽은 뒤,
 * 같은 값을 가질 행끼리 모아 whereIn 으로 갱신한다(대상은 샘플 게시판으로 한정).
 */
trait SyncsBoardCounts
{
    /**
     * 게시글·댓글·게시판의 개수 캐시 컬럼을 실데이터와 맞춥니다.
     *
     * 대상:
     *  - posts.comments_count    = 그 게시글의 미삭제 댓글 수
     *  - posts.replies_count     = 그 게시글의 미삭제 답변글 수
     *  - posts.attachments_count = 그 게시글의 미삭제 첨부 수
     *  - posts.scrap_count       = 그 게시글의 스크랩 수
     *  - comments.replies_count  = 그 댓글의 미삭제 자식 댓글 수
     *  - boards.posts_count      = 그 게시판의 미삭제 게시글 수 (답글 포함)
     *  - boards.comments_count   = 그 게시판의 미삭제 댓글 수
     *
     * @param  array<int, int>  $boardIds  대상 게시판 ID (샘플 게시판만 — 운영 게시판은 건드리지 않는다)
     * @return void 반환값 없음
     */
    protected function syncBoardCounts(array $boardIds): void
    {
        $boardIds = array_values(array_unique(array_map('intval', $boardIds)));

        if ($boardIds === []) {
            return;
        }

        $postIds = DB::table('board_posts')->whereIn('board_id', $boardIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $commentIds = DB::table('board_comments')->whereIn('board_id', $boardIds)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->applyCounts('board_posts', 'comments_count', $postIds, $this->groupedCount(
            DB::table('board_comments')->whereIn('board_id', $boardIds)->whereNull('deleted_at'), 'post_id'
        ));
        $this->applyCounts('board_posts', 'replies_count', $postIds, $this->groupedCount(
            DB::table('board_posts')->whereIn('board_id', $boardIds)->whereNotNull('parent_id')->whereNull('deleted_at'), 'parent_id'
        ));
        $this->applyCounts('board_posts', 'attachments_count', $postIds, $this->groupedCount(
            DB::table('board_attachments')->whereIn('board_id', $boardIds)->whereNotNull('post_id')->whereNull('deleted_at'), 'post_id'
        ));
        $this->applyCounts('board_posts', 'scrap_count', $postIds, $this->groupedCount(
            DB::table('board_post_scraps')->whereIn('post_id', $postIds === [] ? [0] : $postIds), 'post_id'
        ));
        $this->applyCounts('board_comments', 'replies_count', $commentIds, $this->groupedCount(
            DB::table('board_comments')->whereIn('board_id', $boardIds)->whereNotNull('parent_id')->whereNull('deleted_at'), 'parent_id'
        ));
        $this->applyCounts('boards', 'posts_count', $boardIds, $this->groupedCount(
            DB::table('board_posts')->whereIn('board_id', $boardIds)->whereNull('deleted_at'), 'board_id'
        ));
        $this->applyCounts('boards', 'comments_count', $boardIds, $this->groupedCount(
            DB::table('board_comments')->whereIn('board_id', $boardIds)->whereNull('deleted_at'), 'board_id'
        ));
    }

    /**
     * 게시글·댓글의 반응 카운트(reaction_counts)를 반응 이력과 맞춥니다.
     *
     * 형식은 운영 경로(ReactionRepository::recalculateReactionCounts)와 같다 —
     * `{"반응 유형 ID": 건수}` 객체, 반응이 없는 대상은 손대지 않는다.
     *
     * @param  array<int, int>  $boardIds  대상 게시판 ID
     * @return void 반환값 없음
     */
    protected function syncReactionCounts(array $boardIds): void
    {
        $boardIds = array_values(array_unique(array_map('intval', $boardIds)));

        if ($boardIds === []) {
            return;
        }

        $rows = DB::table('board_reactions')
            ->whereIn('board_id', $boardIds)
            ->selectRaw('target_type, target_id, reaction_type_id, COUNT(*) AS total')
            ->groupBy('target_type', 'target_id', 'reaction_type_id')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->target_type][(int) $row->target_id][(string) $row->reaction_type_id] = (int) $row->total;
        }

        $tables = ['post' => 'board_posts', 'comment' => 'board_comments'];

        foreach ($counts as $targetType => $targets) {
            $table = $tables[$targetType] ?? null;
            if ($table === null) {
                continue;
            }

            foreach ($targets as $targetId => $byType) {
                // 숫자 키를 객체로 직렬화한다 (리스트로 바뀌면 화면이 유형 ID 로 찾지 못한다)
                DB::table($table)->where('id', $targetId)->update([
                    'reaction_counts' => json_encode((object) $byType),
                ]);
            }
        }
    }

    /**
     * 키 칸별 행 수를 셉니다.
     *
     * @param  Builder  $query  조건이 걸린 쿼리
     * @param  string  $key  묶을 칸
     * @return array<int, int> 키 값 → 행 수
     */
    private function groupedCount($query, string $key): array
    {
        return $query->selectRaw("{$key} AS k, COUNT(*) AS n")
            ->groupBy($key)
            ->pluck('n', 'k')
            ->mapWithKeys(fn ($n, $k) => [(int) $k => (int) $n])
            ->all();
    }

    /**
     * 대상 행의 카운트 칸을 집계값(없으면 0)으로 갱신합니다 — 같은 값끼리 묶어 whereIn 한 번씩.
     *
     * @param  string  $table  테이블명
     * @param  string  $column  카운트 칸
     * @param  array<int, int>  $ids  대상 행 ID
     * @param  array<int, int>  $counts  행 ID → 집계값
     * @return void 반환값 없음
     */
    private function applyCounts(string $table, string $column, array $ids, array $counts): void
    {
        $byValue = [];
        foreach ($ids as $id) {
            $byValue[$counts[$id] ?? 0][] = $id;
        }

        foreach ($byValue as $value => $group) {
            foreach (array_chunk($group, 1000) as $chunk) {
                DB::table($table)->whereIn('id', $chunk)->update([$column => $value]);
            }
        }
    }
}
