<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_1_1\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 질문 글이 삭제됐는데 살아남은 문의 피벗을 소프트 삭제합니다.
 *
 * 종전 결함(N3): 게시판 직권 삭제 시 피벗 정리 리스너가 큐 비동기로 등록되어,
 * 큐 워커 미가동 환경에서는 피벗 정리가 조용히 누락됐다. 그 잔재 — 질문 Post 는
 * 소프트 삭제(또는 하드 삭제로 부재)인데 피벗은 살아있는 행 — 를 정리한다.
 *
 * 04 스텝(고아 답변 정리)이 먼저 실행된 뒤 수행되어야 한다 (실행 순서 = 파일명 prefix).
 *
 * 멱등: 피벗 deleted_at IS NULL 필터로 재실행 시 0건.
 * V-1 안전: DB/Schema Facade 만 사용 — 모듈 클래스를 참조하지 않는다.
 */
class SoftDeleteInquiryPivotsForTrashedQuestionPosts implements DataMigration
{
    private const POSTS_TABLE = 'board_posts';

    private const PIVOT_TABLE = 'ecommerce_product_inquiries';

    private const POST_MODEL = 'Modules\\Sirsoft\\Board\\Models\\Post';

    public function name(): string
    {
        return 'SoftDeleteInquiryPivotsForTrashedQuestionPosts';
    }

    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::POSTS_TABLE) || ! Schema::hasTable(self::PIVOT_TABLE)) {
            $context->logger->warning('[ecommerce:1.1.1] 게시글/문의 피벗 테이블 미존재 — 스킵');

            return;
        }

        if (! Schema::hasColumn(self::PIVOT_TABLE, 'deleted_at')) {
            // 피벗 SoftDeletes 컬럼은 정규 마이그레이션이 추가한다 — 아직 없으면 스킵
            $context->logger->warning('[ecommerce:1.1.1] 피벗 deleted_at 컬럼 미존재 — 스킵 (마이그레이션 선행 필요)');

            return;
        }

        $now = now();
        $cleaned = 0;

        DB::table(self::PIVOT_TABLE.' as pivots')
            ->where('pivots.inquirable_type', self::POST_MODEL)
            ->whereNull('pivots.deleted_at')
            ->whereNotExists(function ($sub) {
                // 질문 Post 가 살아있는 피벗만 생존 허용 — 부재(하드 삭제)/소프트 삭제 모두 정리 대상
                $sub->selectRaw('1')
                    ->from(self::POSTS_TABLE.' as posts')
                    ->whereColumn('posts.id', 'pivots.inquirable_id')
                    ->whereNull('posts.deleted_at');
            })
            ->orderBy('pivots.id')
            ->select('pivots.id')
            ->chunkById(500, function ($rows) use (&$cleaned, $now) {
                $ids = $rows->pluck('id')->all();

                $cleaned += DB::table(self::PIVOT_TABLE)
                    ->whereIn('id', $ids)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now]);
            }, 'pivots.id', 'id');

        $context->logger->info("[ecommerce:1.1.1] 질문 글 부재 문의 피벗 {$cleaned}건 소프트 삭제 완료");
    }
}
