<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_1_1\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 살아있는 답변이 없는데 「답변완료」로 남은 문의를 미답변으로 정정합니다.
 *
 * 종전 결함(N4): 게시판 관리자가 답변 글을 직접 삭제해도 문의 피벗의
 * `is_answered` 가 해제되지 않아, 답변이 없는데 「답변완료」로 표기됐다.
 * 04 스텝의 고아 답변 정리로 답변이 사라진 문의도 이 스텝이 정합화한다
 * (실행 순서 = 파일명 prefix — 04 가 답변을 정리한 뒤 실행되어야 한다).
 *
 * 살아있는 피벗만 대상으로 한다 — 05 에서 소프트 삭제된 피벗은 화면에
 * 노출되지 않으므로 답변완료 플래그를 건드릴 필요가 없다.
 *
 * 멱등: is_answered=1 필터로 재실행 시 0건.
 * V-1 안전: DB/Schema Facade 만 사용 — 모듈 클래스를 참조하지 않는다.
 */
class UnmarkAnsweredInquiriesWithoutLiveReplies implements DataMigration
{
    private const POSTS_TABLE = 'board_posts';

    private const PIVOT_TABLE = 'ecommerce_product_inquiries';

    private const POST_MODEL = 'Modules\\Sirsoft\\Board\\Models\\Post';

    public function name(): string
    {
        return 'UnmarkAnsweredInquiriesWithoutLiveReplies';
    }

    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::POSTS_TABLE) || ! Schema::hasTable(self::PIVOT_TABLE)) {
            $context->logger->warning('[ecommerce:1.1.1] 게시글/문의 피벗 테이블 미존재 — 스킵');

            return;
        }

        $query = DB::table(self::PIVOT_TABLE.' as pivots')
            ->where('pivots.inquirable_type', self::POST_MODEL)
            ->where('pivots.is_answered', 1)
            ->whereNotExists(function ($sub) {
                // 살아있는 답변(자식 Post)이 하나라도 있으면 답변완료 유지
                $sub->selectRaw('1')
                    ->from(self::POSTS_TABLE.' as replies')
                    ->whereColumn('replies.parent_id', 'pivots.inquirable_id')
                    ->whereNull('replies.deleted_at');
            });

        if (Schema::hasColumn(self::PIVOT_TABLE, 'deleted_at')) {
            $query->whereNull('pivots.deleted_at');
        }

        $fixed = 0;

        $query->orderBy('pivots.id')
            ->select('pivots.id')
            ->chunkById(500, function ($rows) use (&$fixed) {
                $ids = $rows->pluck('id')->all();

                $fixed += DB::table(self::PIVOT_TABLE)
                    ->whereIn('id', $ids)
                    ->where('is_answered', 1)
                    ->update([
                        'is_answered' => 0,
                        'answered_at' => null,
                    ]);
            }, 'pivots.id', 'id');

        $context->logger->info("[ecommerce:1.1.1] 답변 없는 답변완료 문의 {$fixed}건 정정 완료");
    }
}
