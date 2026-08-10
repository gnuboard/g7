<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftBoard\V1_0_3\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 게시판 설정 max_comment_depth 를 초과한 기존 댓글을 리포트합니다.
 *
 * 자동 이동/삭제는 하지 않습니다. 관리자가 나중에 max_comment_depth 를 낮춘 결과일 수도
 * 있어 데이터가 오염이라 단정할 수 없고, 자동 재부모화는 대화 맥락을 파괴하기 때문입니다.
 * 규모만 로그로 남겨 운영자가 판단할 근거를 제공합니다.
 *
 * idempotent: 읽기 전용. V-1 안전: Facades\DB/Schema 만 사용.
 */
class ReportCommentsExceedingMaxDepth implements DataMigration
{
    private const COMMENTS_TABLE = 'board_comments';

    private const BOARDS_TABLE = 'boards';

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'ReportCommentsExceedingMaxDepth';
    }

    /**
     * 설정 초과 depth 댓글 규모를 게시판별로 로그에 남깁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::COMMENTS_TABLE)
            || ! Schema::hasTable(self::BOARDS_TABLE)
            || ! Schema::hasColumn(self::BOARDS_TABLE, 'max_comment_depth')) {
            $context->logger->info('[board:1.0.3] 설정 초과 depth 리포트 — 대상 스키마 부재로 스킵');

            return;
        }

        // 별칭 조인 대신 게시판 단위 순회 — DB 프리픽스가 별칭에도 적용되어
        // raw 표현식의 별칭 참조가 깨지는 것을 회피한다 (게시판 수는 소규모).
        $boards = DB::table(self::BOARDS_TABLE)
            ->select('id', 'slug', 'max_comment_depth')
            ->orderBy('id')
            ->get();

        $reported = 0;

        foreach ($boards as $board) {
            $max = (int) $board->max_comment_depth;

            $query = DB::table(self::COMMENTS_TABLE)
                ->where('board_id', $board->id)
                ->where('depth', '>', $max);

            $overCount = (clone $query)->count();

            if ($overCount === 0) {
                continue;
            }

            $reported++;

            $context->logger->warning(sprintf(
                '[board:1.0.3] 게시판 %s(id=%d) 설정 max_comment_depth=%d 초과 댓글 %d 건 (최대 depth=%d) — 자동 이동하지 않음, 운영자 확인 필요',
                $board->slug,
                $board->id,
                $max,
                $overCount,
                (int) (clone $query)->max('depth')
            ));
        }

        if ($reported === 0) {
            $context->logger->info('[board:1.0.3] 게시판 설정을 초과한 depth 댓글 없음');
        }
    }
}
