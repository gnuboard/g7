<?php

namespace Modules\Sirsoft\Board\Database\Seeders\Sample\Concerns;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 게시판 샘플 시더 공용 — 글·댓글의 블라인드·삭제 조치를 운영 경로와 같은 모양으로 남긴다
 *
 * 운영 경로(PostService·CommentService·ReportService)는 알림·활동 기록·훅을 발화하므로 시더는
 * 직접 UPDATE 하되, 남는 행의 모양은 같아야 한다:
 *  - 블라인드: status=blinded, trigger_type=조치 주체, action_logs 에 blind 1건
 *  - 글 삭제: status=deleted·deleted_at, action_logs 에 delete 1건, 그 아래 댓글·첨부·자손 답글은
 *    trigger_type='cascade' 와 같은 deleted_at (이미 지워진 것은 그대로)
 *  - 댓글 삭제: status=deleted·deleted_at, action_logs 에 delete 1건 (자식 댓글은 남는다)
 * 조치 이력의 시각은 `Y-m-d H:i:s` 문자열이다(B-22).
 */
trait ModeratesSampleContent
{
    /**
     * 조치 이력 1건을 만듭니다 (PostService::buildActionLog 와 같은 모양).
     *
     * @param  string  $action  조치 (blind, delete)
     * @param  string|null  $reason  사유
     * @param  Carbon  $at  조치 시각
     * @param  int|null  $actorId  처리자 ID
     * @param  string|null  $actorName  처리자 이름
     * @return array<string, mixed> 조치 이력
     */
    protected function sampleActionLog(string $action, ?string $reason, Carbon $at, ?int $actorId, ?string $actorName): array
    {
        return [
            'action' => $action,
            'reason' => $reason,
            'admin_id' => $actorId,
            'admin_name' => $actorName ?? 'Unknown',
            'ip_address' => '127.0.0.1',
            'created_at' => $at->toDateTimeString(),
        ];
    }

    /**
     * 글 또는 댓글을 블라인드합니다 (이미 삭제된 대상은 건드리지 않는다).
     *
     * @param  string  $table  board_posts 또는 board_comments
     * @param  int  $id  대상 ID
     * @param  string  $triggerType  조치 주체 (report, auto_hide, admin)
     * @param  array<string, mixed>  $actionLog  조치 이력
     * @return bool 블라인드했으면 true
     */
    protected function blindSampleTarget(string $table, int $id, string $triggerType, array $actionLog): bool
    {
        $row = DB::table($table)->where('id', $id)->first(['id', 'status', 'deleted_at', 'action_logs']);

        if (! $row || $row->deleted_at !== null || $row->status === 'deleted') {
            return false;
        }

        DB::table($table)->where('id', $id)->update([
            'status' => 'blinded',
            'trigger_type' => $triggerType,
            'action_logs' => json_encode($this->appendActionLog($row->action_logs, $actionLog)),
        ]);

        return true;
    }

    /**
     * 글을 삭제 상태로 만들고 그 아래 댓글·첨부·자손 답글을 연쇄 소프트 삭제합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $postId  글 ID
     * @param  Carbon  $deletedAt  삭제 시각
     * @param  string  $triggerType  조치 주체 (admin, report 등)
     * @param  array<string, mixed>  $actionLog  조치 이력
     * @return void 반환값 없음
     */
    protected function softDeleteSamplePostCascade(int $boardId, int $postId, Carbon $deletedAt, string $triggerType, array $actionLog): void
    {
        $descendantIds = $this->collectSampleDescendantPostIds($boardId, $postId);
        $existingLogs = DB::table('board_posts')->where('id', $postId)->value('action_logs');

        DB::table('board_posts')->where('id', $postId)->update([
            'status' => 'deleted',
            'trigger_type' => $triggerType,
            'action_logs' => json_encode($this->appendActionLog($existingLogs, $actionLog)),
            'deleted_at' => $deletedAt,
            'updated_at' => $deletedAt,
        ]);

        if ($descendantIds !== []) {
            DB::table('board_posts')->whereIn('id', $descendantIds)->whereNull('deleted_at')->update([
                'status' => 'deleted',
                'trigger_type' => 'cascade',
                'deleted_at' => $deletedAt,
            ]);
        }

        $postIds = array_merge([$postId], $descendantIds);

        // 댓글·첨부는 운영 경로처럼 상태는 두고 trigger_type·deleted_at 만 남긴다
        DB::table('board_comments')->where('board_id', $boardId)->whereIn('post_id', $postIds)->whereNull('deleted_at')->update([
            'trigger_type' => 'cascade',
            'deleted_at' => $deletedAt,
        ]);
        DB::table('board_attachments')->where('board_id', $boardId)->whereIn('post_id', $postIds)->whereNull('deleted_at')->update([
            'trigger_type' => 'cascade',
            'deleted_at' => $deletedAt,
        ]);
    }

    /**
     * 댓글을 삭제 상태로 만듭니다 (자식 댓글은 운영 경로처럼 남는다).
     *
     * @param  int  $commentId  댓글 ID
     * @param  Carbon  $deletedAt  삭제 시각
     * @param  string  $triggerType  조치 주체 (user, report 등)
     * @param  array<string, mixed>  $actionLog  조치 이력
     * @return void 반환값 없음
     */
    protected function softDeleteSampleComment(int $commentId, Carbon $deletedAt, string $triggerType, array $actionLog): void
    {
        $existingLogs = DB::table('board_comments')->where('id', $commentId)->value('action_logs');

        DB::table('board_comments')->where('id', $commentId)->update([
            'status' => 'deleted',
            'trigger_type' => $triggerType,
            'action_logs' => json_encode($this->appendActionLog($existingLogs, $actionLog)),
            'deleted_at' => $deletedAt,
            'updated_at' => $deletedAt,
        ]);
    }

    /**
     * 글의 자손 답글 ID 를 모두 모읍니다 (레벨 순회 + 방문 가드).
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $postId  원글 ID
     * @return array<int, int> 자손 답글 ID
     */
    protected function collectSampleDescendantPostIds(int $boardId, int $postId): array
    {
        $descendantIds = [];
        $frontier = [$postId];

        while ($frontier !== []) {
            $children = DB::table('board_posts')
                ->where('board_id', $boardId)
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $frontier = array_values(array_diff($children, $descendantIds, [$postId]));
            $descendantIds = array_merge($descendantIds, $frontier);
        }

        return $descendantIds;
    }

    /**
     * 저장된 action_logs(JSON 문자열 또는 null)에 이력 1건을 덧붙입니다.
     *
     * @param  string|null  $existing  기존 action_logs 컬럼 값
     * @param  array<string, mixed>  $entry  추가할 이력
     * @return array<int, array<string, mixed>> 갱신된 이력 목록
     */
    private function appendActionLog(?string $existing, array $entry): array
    {
        $logs = $existing ? json_decode($existing, true) : [];
        $logs = is_array($logs) ? $logs : [];
        $logs[] = $entry;

        return $logs;
    }
}
