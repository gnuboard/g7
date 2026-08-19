<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_1_1\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * 과거 문의 삭제로 홀로 남은 답변 글(고아 답변)을 소프트 삭제합니다.
 *
 * 종전 결함(#107): 문의 삭제 시 질문 글만 소프트 삭제되고 답변 글은 published 로
 * 잔존해, 직접 URL 로 비회원도 열람 가능한 고아 답변이 쌓였다. 대상은 문의 게시판의
 * `parent_id NOT NULL AND deleted_at IS NULL` 이면서 부모가 없거나(하드 삭제) 부모가
 * 삭제된 답변 글이다.
 *
 * 문의 게시판 판별은 두 축의 합집합이다 — 설정 파일의 board_slug 해석 ∪ 문의 피벗이
 * 실제로 가리키는 Post 의 board_id (설정 변경/유실 사이트 보강).
 *
 * trigger_type 은 변경하지 않는다 — 백필된 고아는 부모 복원 시 되살리지 않는
 * 보수적 선택이다(cascade 마킹이 없으면 복원 경로가 건드리지 않는다).
 *
 * 멱등: deleted_at IS NULL 필터로 재실행 시 0건.
 * V-1 안전: DB/Schema/File Facade 만 사용 — 모듈 클래스를 참조하지 않는다.
 */
class SoftDeleteOrphanInquiryReplyPosts implements DataMigration
{
    private const POSTS_TABLE = 'board_posts';

    private const BOARDS_TABLE = 'boards';

    private const PIVOT_TABLE = 'ecommerce_product_inquiries';

    private const POST_MODEL = 'Modules\\Sirsoft\\Board\\Models\\Post';

    public function name(): string
    {
        return 'SoftDeleteOrphanInquiryReplyPosts';
    }

    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::POSTS_TABLE) || ! Schema::hasTable(self::PIVOT_TABLE)) {
            $context->logger->warning('[ecommerce:1.1.1] 게시글/문의 피벗 테이블 미존재 — 스킵');

            return;
        }

        $boardIds = $this->resolveInquiryBoardIds($context);

        if ($boardIds === []) {
            $context->logger->info('[ecommerce:1.1.1] 문의 게시판을 판별할 수 없음 — 고아 답변 정리 스킵');

            return;
        }

        $now = now();
        $cleaned = 0;

        DB::table(self::POSTS_TABLE.' as replies')
            ->whereIn('replies.board_id', $boardIds)
            ->whereNotNull('replies.parent_id')
            ->whereNull('replies.deleted_at')
            ->where(function ($query) {
                // 부모가 없거나(하드 삭제) 부모가 소프트 삭제된 답변만 대상
                $query->whereNotExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from(self::POSTS_TABLE.' as parents')
                        ->whereColumn('parents.id', 'replies.parent_id')
                        ->whereNull('parents.deleted_at');
                });
            })
            ->orderBy('replies.id')
            ->select('replies.id')
            ->chunkById(500, function ($rows) use (&$cleaned, $now) {
                $ids = $rows->pluck('id')->all();

                $cleaned += DB::table(self::POSTS_TABLE)
                    ->whereIn('id', $ids)
                    ->whereNull('deleted_at')
                    ->update([
                        'status' => 'deleted',
                        'deleted_at' => $now,
                    ]);
            }, 'replies.id', 'id');

        $context->logger->info("[ecommerce:1.1.1] 고아 답변 글 {$cleaned}건 소프트 삭제 완료", [
            'board_ids' => $boardIds,
        ]);
    }

    /**
     * 문의 게시판 ID 집합을 판별합니다 (설정 board_slug ∪ 피벗 실데이터).
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return array<int> 게시판 ID 배열
     */
    private function resolveInquiryBoardIds(UpgradeContext $context): array
    {
        $boardIds = [];

        // ① 설정 파일의 board_slug → boards.id 해석
        $settingsPath = storage_path('app/modules/sirsoft-ecommerce/settings/inquiry.json');

        if (File::exists($settingsPath) && Schema::hasTable(self::BOARDS_TABLE)) {
            $settings = json_decode(File::get($settingsPath), true);
            $slug = is_array($settings) ? ($settings['board_slug'] ?? null) : null;

            if (is_string($slug) && $slug !== '') {
                $id = DB::table(self::BOARDS_TABLE)->where('slug', $slug)->value('id');

                if ($id !== null) {
                    $boardIds[] = (int) $id;
                }
            }
        }

        // ② 피벗이 실제로 가리키는 Post 의 board_id (설정 변경/유실 사이트 보강)
        $pivotBoardIds = DB::table(self::PIVOT_TABLE.' as pivots')
            ->join(self::POSTS_TABLE.' as posts', 'posts.id', '=', 'pivots.inquirable_id')
            ->where('pivots.inquirable_type', self::POST_MODEL)
            ->distinct()
            ->pluck('posts.board_id')
            ->all();

        $boardIds = array_values(array_unique(array_merge($boardIds, array_map('intval', $pivotBoardIds))));

        $context->logger->info('[ecommerce:1.1.1] 문의 게시판 판별 결과', ['board_ids' => $boardIds]);

        return $boardIds;
    }
}
