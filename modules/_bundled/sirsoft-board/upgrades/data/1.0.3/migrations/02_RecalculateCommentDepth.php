<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftBoard\V1_0_3\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 댓글 depth 를 루트부터 BFS 로 재계산합니다.
 *
 * depth 를 5 로 고정 클램프하던 시기에 6단계 이후 답글이 모두 depth 5 로 저장되어,
 * 화면상 들여쓰기가 무너지고 부모 depth + 1 규칙이 깨진 행이 남아 있습니다.
 *
 * 최상위 댓글(parent_id IS NULL)을 depth 0 으로 두고 자식 세대마다 +1 을 부여합니다.
 * 콘텐츠는 건드리지 않으며 depth 컬럼만 갱신하므로 결정론적입니다.
 *
 * idempotent: 이미 정합한 행은 갱신 대상에서 제외되어 재실행해도 결과가 같습니다.
 * 순환 참조가 남아 있어도 방문 ID 집합으로 유한 종료하며, 루트에서 도달하지 못한
 * 행(고아 부모 / 순환 컴포넌트)은 값을 보존한 채 경고 로그로 남깁니다.
 * V-1 안전: Facades\DB/Schema 만 사용.
 */
class RecalculateCommentDepth implements DataMigration
{
    private const TABLE = 'board_comments';

    /** @var int 세대 순회 상한 (순환 참조 방어) */
    private const MAX_LEVELS = 1000;

    /** @var int whereIn 청크 크기 */
    private const CHUNK = 500;

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'RecalculateCommentDepth';
    }

    /**
     * 댓글 depth 를 루트부터 재계산합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE)
            || ! Schema::hasColumn(self::TABLE, 'parent_id')
            || ! Schema::hasColumn(self::TABLE, 'depth')) {
            $context->logger->info('[board:1.0.3] depth 재계산 — 대상 스키마 부재로 스킵');

            return;
        }

        $updated = $this->assignRootDepth($context);

        $visited = [];
        $parentIds = DB::table(self::TABLE)->whereNull('parent_id')->pluck('id')->all();
        foreach ($parentIds as $id) {
            $visited[(int) $id] = true;
        }

        $level = 0;

        while ($parentIds !== [] && $level < self::MAX_LEVELS) {
            $level++;
            $childIds = $this->childIdsOf($parentIds, $visited);

            if ($childIds === []) {
                break;
            }

            $updated += $this->assignDepth($childIds, $level, $context);
            $parentIds = $childIds;
        }

        if ($level >= self::MAX_LEVELS) {
            $context->logger->warning(sprintf(
                '[board:1.0.3] depth 재계산이 세대 상한(%d)에 도달 — 순환 참조 잔존 가능. 수동 확인 필요',
                self::MAX_LEVELS
            ));
        }

        $this->logOrphanParents($context);
        $this->logUnreachableComments($context, $visited);

        $context->logger->info(sprintf(
            '[board:1.0.3] depth 재계산 완료 — %d 건 갱신 (최대 세대 %d)',
            $updated,
            $level
        ));
    }

    /**
     * 최상위 댓글의 depth 를 0 으로 맞춥니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return int 갱신된 행 수
     */
    private function assignRootDepth(UpgradeContext $context): int
    {
        $stale = DB::table(self::TABLE)
            ->whereNull('parent_id')
            ->where('depth', '<>', 0)
            ->pluck('id')
            ->all();

        if ($stale === []) {
            return 0;
        }

        $this->logSnapshot($context, 0, $stale);

        return DB::table(self::TABLE)
            ->whereNull('parent_id')
            ->where('depth', '<>', 0)
            ->update(['depth' => 0]);
    }

    /**
     * 주어진 부모 ID 목록의 자식 ID 를 조회합니다 (이미 방문한 ID 는 제외).
     *
     * @param  array<int, int>  $parentIds  부모 댓글 ID 목록
     * @param  array<int, bool>  $visited  방문 ID 집합 (참조 전달, 갱신됨)
     * @return array<int, int> 자식 댓글 ID 목록
     */
    private function childIdsOf(array $parentIds, array &$visited): array
    {
        $children = [];

        foreach (array_chunk($parentIds, self::CHUNK) as $chunk) {
            $ids = DB::table(self::TABLE)
                ->whereIn('parent_id', $chunk)
                ->pluck('id')
                ->all();

            foreach ($ids as $id) {
                $id = (int) $id;
                if (isset($visited[$id])) {
                    continue;
                }
                $visited[$id] = true;
                $children[] = $id;
            }
        }

        return $children;
    }

    /**
     * 주어진 댓글들의 depth 를 지정 값으로 갱신합니다.
     *
     * @param  array<int, int>  $ids  댓글 ID 목록
     * @param  int  $depth  설정할 depth
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return int 갱신된 행 수
     */
    private function assignDepth(array $ids, int $depth, UpgradeContext $context): int
    {
        $updated = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $stale = DB::table(self::TABLE)
                ->whereIn('id', $chunk)
                ->where('depth', '<>', $depth)
                ->pluck('id')
                ->all();

            if ($stale === []) {
                continue;
            }

            $this->logSnapshot($context, $depth, $stale);

            $updated += DB::table(self::TABLE)
                ->whereIn('id', $stale)
                ->update(['depth' => $depth]);
        }

        return $updated;
    }

    /**
     * 부모 행이 존재하지 않는 고아 댓글을 경고 로그로 남깁니다.
     *
     * BFS 는 `parent_id IS NULL` 인 루트에서만 출발하므로, 부모가 이미 삭제된 댓글은
     * 어느 세대에도 도달하지 못해 depth 가 옛 값 그대로 남는다. 값을 임의로 고치거나
     * 행을 지우면 데이터 손실이므로 건드리지 않되, 정정되지 않았다는 사실은 남긴다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function logOrphanParents(UpgradeContext $context): void
    {
        $orphanIds = DB::table(self::TABLE.' as c')
            ->whereNotNull('c.parent_id')
            ->whereNotExists(function ($query) {
                $query->select('p.id')
                    ->from(self::TABLE.' as p')
                    ->whereColumn('p.id', 'c.parent_id');
            })
            ->pluck('c.id')
            ->all();

        if ($orphanIds === []) {
            return;
        }

        $context->logger->warning(sprintf(
            '[board:1.0.3] 부모 행이 없는 댓글 %d 건 — depth 재계산에서 스킵(값 보존). 대상 id: %s',
            count($orphanIds),
            json_encode(array_map('intval', $orphanIds), JSON_UNESCAPED_UNICODE)
        ));
    }

    /**
     * 루트에서 도달하지 못한 댓글(순환 참조 컴포넌트)을 경고 로그로 남깁니다.
     *
     * 부모 행이 존재하는데도 BFS 가 방문하지 못했다면 그 부모 역시 도달 불가라는 뜻이고,
     * 거슬러 올라가면 루트가 없는 순환(A→B→A)에 닿는다. 이런 행은 세대 상한에 걸리지도
     * (BFS 가 진입 자체를 못 함) 고아 판정에 걸리지도(부모 행은 실재함) 않아,
     * 경고가 없으면 정정되지 않은 사실이 조용히 묻힌다.
     *
     * 값은 건드리지 않는다 — 순환의 어느 지점을 끊을지는 운영자 판단 영역이다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @param  array<int, bool>  $visited  BFS 가 방문한 댓글 ID 집합
     */
    private function logUnreachableComments(UpgradeContext $context, array $visited): void
    {
        $cycleIds = [];
        $lastId = 0;

        while (true) {
            $ids = DB::table(self::TABLE.' as c')
                ->whereNotNull('c.parent_id')
                ->where('c.id', '>', $lastId)
                ->whereExists(function ($query) {
                    $query->select('p.id')
                        ->from(self::TABLE.' as p')
                        ->whereColumn('p.id', 'c.parent_id');
                })
                ->orderBy('c.id')
                ->limit(self::CHUNK)
                ->pluck('c.id')
                ->all();

            if ($ids === []) {
                break;
            }

            foreach ($ids as $id) {
                $lastId = (int) $id;

                if (! isset($visited[$lastId])) {
                    $cycleIds[] = $lastId;
                }
            }
        }

        if ($cycleIds === []) {
            return;
        }

        $context->logger->warning(sprintf(
            '[board:1.0.3] 루트에서 도달하지 못한 댓글 %d 건 — 순환 참조로 보이며 depth 재계산에서 스킵(값 보존). 대상 id: %s',
            count($cycleIds),
            json_encode($cycleIds, JSON_UNESCAPED_UNICODE)
        ));
    }

    /**
     * 갱신 대상의 변경 전 상태를 로그로 남깁니다 (복구 근거).
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @param  int  $newDepth  새로 부여할 depth
     * @param  array<int, int>  $ids  갱신 대상 댓글 ID 목록
     */
    private function logSnapshot(UpgradeContext $context, int $newDepth, array $ids): void
    {
        $before = DB::table(self::TABLE)
            ->whereIn('id', $ids)
            ->pluck('depth', 'id')
            ->map(fn ($depth) => (int) $depth)
            ->all();

        $context->logger->info(sprintf(
            '[board:1.0.3] 스냅샷 depth→%d %d 건 — 변경 전 %s',
            $newDepth,
            count($ids),
            json_encode($before, JSON_UNESCAPED_UNICODE)
        ));
    }
}
