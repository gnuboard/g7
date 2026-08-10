<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_0_5\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 카테고리 순환 참조를 절단하고 depth/path 를 전면 재구축합니다.
 *
 * 순환 참조를 막는 검증이 없던 시기에 자기참조(parent_id = id) 또는 다중 노드 사이클이
 * 저장될 수 있었습니다. 사이클이 남아 있으면 카테고리 수정·순서 변경이 path 재계산 재귀에서
 * 종료되지 않아 요청이 실패합니다.
 *
 * 처리 순서:
 * 1. 루트에서 도달 가능한 노드를 수집하며 depth/path 재계산
 * 2. 루트에서 도달하지 못한 노드(= 사이클 구성원)의 parent_id 를 NULL 로 절단 후 다시 재계산
 *
 * 카테고리는 삭제하지 않으며 계층만 복구합니다.
 *
 * idempotent: 사이클이 없고 path 가 정합하면 no-op. V-1 안전: Facades\DB/Schema 만 사용.
 */
class BreakCategoryCyclesAndRebuildPaths implements DataMigration
{
    private const TABLE = 'ecommerce_categories';

    /** @var int 세대 순회 상한 (방어적 종료 조건) */
    private const MAX_LEVELS = 1000;

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'BreakCategoryCyclesAndRebuildPaths';
    }

    /**
     * 순환을 절단하고 계층 컬럼을 재구축합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE)
            || ! Schema::hasColumn(self::TABLE, 'parent_id')
            || ! Schema::hasColumn(self::TABLE, 'path')
            || ! Schema::hasColumn(self::TABLE, 'depth')) {
            $context->logger->info('[ecommerce:1.0.5] 카테고리 계층 재구축 — 대상 스키마 부재로 스킵');

            return;
        }

        $rows = DB::table(self::TABLE)
            ->orderBy('id')
            ->get(['id', 'parent_id', 'depth', 'path']);

        if ($rows->isEmpty()) {
            $context->logger->info('[ecommerce:1.0.5] 카테고리 없음 — 계층 재구축 스킵');

            return;
        }

        $nodes = [];
        foreach ($rows as $row) {
            $nodes[(int) $row->id] = [
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                'depth' => (int) $row->depth,
                'path' => (string) $row->path,
            ];
        }

        $detached = $this->breakCycles($nodes, $context);
        $updated = $this->rebuild($nodes, $context);

        $context->logger->info(sprintf(
            '[ecommerce:1.0.5] 카테고리 계층 재구축 완료 — 순환 절단 %d 건, depth/path 갱신 %d 건',
            $detached,
            $updated
        ));
    }

    /**
     * 루트에서 도달하지 못하는 노드(순환 구성원)의 부모 연결을 끊습니다.
     *
     * @param  array<int, array{parent_id: int|null, depth: int, path: string}>  $nodes  노드 맵 (참조 전달, 갱신됨)
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return int 절단된 노드 수
     */
    private function breakCycles(array &$nodes, UpgradeContext $context): int
    {
        $detached = 0;

        // 존재하지 않는 부모를 가리키는 행도 고아이므로 함께 절단 대상에 포함된다.
        while (($orphan = $this->findUnreachableNode($nodes)) !== null) {
            $context->logger->warning(sprintf(
                '[ecommerce:1.0.5] 스냅샷 순환 절단 category_id=%d parent_id=%s depth=%d path=%s',
                $orphan,
                var_export($nodes[$orphan]['parent_id'], true),
                $nodes[$orphan]['depth'],
                $nodes[$orphan]['path']
            ));

            DB::table(self::TABLE)->where('id', $orphan)->update(['parent_id' => null]);
            $nodes[$orphan]['parent_id'] = null;
            $detached++;

            if ($detached >= self::MAX_LEVELS) {
                $context->logger->warning('[ecommerce:1.0.5] 순환 절단이 상한에 도달 — 수동 확인 필요');

                break;
            }
        }

        return $detached;
    }

    /**
     * 루트에서 도달할 수 없는 노드 하나를 찾습니다.
     *
     * @param  array<int, array{parent_id: int|null, depth: int, path: string}>  $nodes  노드 맵
     * @return int|null 도달 불가 노드 ID (없으면 null)
     */
    private function findUnreachableNode(array $nodes): ?int
    {
        $reachable = $this->reachableIds($nodes);

        foreach (array_keys($nodes) as $id) {
            if (! isset($reachable[$id])) {
                return $id;
            }
        }

        return null;
    }

    /**
     * 루트(parent_id = null)에서 도달 가능한 노드 ID 집합을 반환합니다.
     *
     * @param  array<int, array{parent_id: int|null, depth: int, path: string}>  $nodes  노드 맵
     * @return array<int, bool> 도달 가능 ID 집합
     */
    private function reachableIds(array $nodes): array
    {
        $childrenOf = [];
        $reachable = [];
        $queue = [];

        foreach ($nodes as $id => $node) {
            if ($node['parent_id'] === null) {
                $reachable[$id] = true;
                $queue[] = $id;

                continue;
            }

            $childrenOf[$node['parent_id']][] = $id;
        }

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($childrenOf[$current] ?? [] as $childId) {
                if (isset($reachable[$childId])) {
                    continue;
                }
                $reachable[$childId] = true;
                $queue[] = $childId;
            }
        }

        return $reachable;
    }

    /**
     * 루트부터 depth/path 를 재계산해 저장합니다.
     *
     * @param  array<int, array{parent_id: int|null, depth: int, path: string}>  $nodes  노드 맵
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return int 갱신된 행 수
     */
    private function rebuild(array $nodes, UpgradeContext $context): int
    {
        $childrenOf = [];
        $queue = [];

        foreach ($nodes as $id => $node) {
            if ($node['parent_id'] === null) {
                $queue[] = [$id, 0, (string) $id];

                continue;
            }

            $childrenOf[$node['parent_id']][] = $id;
        }

        $updated = 0;

        while ($queue !== []) {
            [$id, $depth, $path] = array_shift($queue);

            if ($nodes[$id]['depth'] !== $depth || $nodes[$id]['path'] !== $path) {
                $context->logger->info(sprintf(
                    '[ecommerce:1.0.5] 스냅샷 category_id=%d depth %d→%d path %s→%s',
                    $id,
                    $nodes[$id]['depth'],
                    $depth,
                    $nodes[$id]['path'],
                    $path
                ));

                DB::table(self::TABLE)
                    ->where('id', $id)
                    ->update(['depth' => $depth, 'path' => $path]);

                $updated++;
            }

            foreach ($childrenOf[$id] ?? [] as $childId) {
                $queue[] = [$childId, $depth + 1, $path.'/'.$childId];
            }
        }

        return $updated;
    }
}
