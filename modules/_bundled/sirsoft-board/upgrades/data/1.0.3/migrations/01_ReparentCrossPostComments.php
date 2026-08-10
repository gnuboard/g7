<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftBoard\V1_0_3\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 다른 게시글의 댓글을 부모로 가진 고아 댓글의 부모 연결을 해제합니다.
 *
 * 부모 댓글의 소속 게시글을 검증하지 않던 시기에, 게시글 A 의 댓글을 게시글 B 댓글의
 * parent_id 로 지정하면 그대로 저장되었습니다. 이렇게 만들어진 댓글은 어느 게시글의
 * 목록에도 나타나지 않는 고아 상태가 됩니다.
 *
 * 본문 손실을 피하기 위해 삭제하지 않고 parent_id 를 NULL 로 되돌려 최상위 댓글로
 * 승격시킵니다. depth 는 후속 마이그레이션(02_RecalculateCommentDepth)이 재계산합니다.
 *
 * idempotent: 교차 부모가 없으면 no-op. V-1 안전: Facades\DB/Schema 만 사용.
 */
class ReparentCrossPostComments implements DataMigration
{
    private const TABLE = 'board_comments';

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'ReparentCrossPostComments';
    }

    /**
     * 교차 게시글 부모를 가진 댓글의 부모 연결을 해제합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE)
            || ! Schema::hasColumn(self::TABLE, 'parent_id')
            || ! Schema::hasColumn(self::TABLE, 'post_id')) {
            $context->logger->info('[board:1.0.3] 댓글 부모 정리 — 대상 스키마 부재로 스킵');

            return;
        }

        $orphans = DB::table(self::TABLE.' as c')
            ->join(self::TABLE.' as p', 'c.parent_id', '=', 'p.id')
            ->whereColumn('c.post_id', '<>', 'p.post_id')
            ->select('c.id', 'c.post_id', 'c.parent_id', 'c.depth', 'p.post_id as parent_post_id')
            ->orderBy('c.id')
            ->get();

        if ($orphans->isEmpty()) {
            $context->logger->info('[board:1.0.3] 교차 게시글 부모 댓글 없음 — 정리 스킵');

            return;
        }

        // 변경 전 스냅샷 기록 (복구 근거)
        foreach ($orphans as $row) {
            $context->logger->info(sprintf(
                '[board:1.0.3] 스냅샷 comment_id=%d post_id=%d parent_id=%d parent_post_id=%d depth=%d',
                $row->id,
                $row->post_id,
                $row->parent_id,
                $row->parent_post_id,
                (int) $row->depth
            ));
        }

        $ids = $orphans->pluck('id')->all();

        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table(self::TABLE)
                ->whereIn('id', $chunk)
                ->update(['parent_id' => null, 'depth' => 0]);
        }

        $context->logger->info(sprintf(
            '[board:1.0.3] 교차 게시글 부모 댓글 %d 건을 최상위 댓글로 승격 완료',
            count($ids)
        ));
    }
}
