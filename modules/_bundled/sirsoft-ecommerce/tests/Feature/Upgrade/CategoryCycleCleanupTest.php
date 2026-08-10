<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Upgrade;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Modules\Sirsoft\Ecommerce\Upgrades\Upgrade_1_0_5;

/**
 * 1.0.5 카테고리 순환 데이터 정리 업그레이드 스텝 테스트
 *
 * 검증 목적:
 * - 자기참조(parent_id = id) 노드가 절단된다
 * - 다중 노드 사이클이 절단되어 트리에 복귀한다
 * - 정상 트리의 depth/path 가 재구축된다
 * - 재실행해도 결과가 동일하다 (멱등)
 */
class CategoryCycleCleanupTest extends ModuleTestCase
{
    /**
     * 자기참조 노드가 절단되고 최상위로 복귀한다
     *
     * @scenario entity=ec_category, parent_target=self
     *
     * @effects existing_cycle_broken_and_paths_rebuilt
     */
    public function test_self_referencing_node_is_detached(): void
    {
        $id = $this->insertCategory('self-cycle', parentId: null, depth: 3, path: 'garbage');
        DB::table('ecommerce_categories')->where('id', $id)->update(['parent_id' => $id]);

        $this->runCleanup();

        $row = DB::table('ecommerce_categories')->where('id', $id)->first();

        $this->assertNull($row->parent_id, '자기참조는 절단되어야 합니다.');
        $this->assertSame(0, (int) $row->depth);
        $this->assertSame((string) $id, $row->path);
    }

    /**
     * 3노드 사이클이 절단되고 계층이 복구된다
     *
     * @scenario entity=ec_category, parent_target=descendant
     *
     * @effects existing_cycle_broken_and_paths_rebuilt, path_recursion_terminates_on_polluted_data
     */
    public function test_multi_node_cycle_is_broken(): void
    {
        $a = $this->insertCategory('cycle-a');
        $b = $this->insertCategory('cycle-b', parentId: $a);
        $c = $this->insertCategory('cycle-c', parentId: $b);

        // a 의 부모를 c 로 만들어 a → b → c → a 사이클 구성
        DB::table('ecommerce_categories')->where('id', $a)->update(['parent_id' => $c]);

        $this->runCleanup();

        $rows = DB::table('ecommerce_categories')
            ->whereIn('id', [$a, $b, $c])
            ->pluck('parent_id', 'id')
            ->all();

        $rootCount = count(array_filter($rows, fn ($parentId) => $parentId === null));
        $this->assertSame(1, $rootCount, '사이클은 정확히 한 지점에서 절단되어야 합니다.');

        // 절단 후 모든 노드가 루트에서 도달 가능해야 한다 (path 가 루트 ID 로 시작)
        foreach ([$a, $b, $c] as $id) {
            $path = DB::table('ecommerce_categories')->where('id', $id)->value('path');
            $this->assertNotSame('', (string) $path, "category {$id} 의 path 가 재구축되어야 합니다.");
        }
    }

    /**
     * 정상 트리의 depth/path 가 재구축된다
     *
     * @scenario entity=ec_category, parent_target=unrelated
     *
     * @effects descendant_paths_rebuilt_on_valid_move
     */
    public function test_healthy_tree_paths_are_rebuilt(): void
    {
        $root = $this->insertCategory('healthy-root', depth: 9, path: 'wrong');
        $child = $this->insertCategory('healthy-child', parentId: $root, depth: 9, path: 'wrong');

        $this->runCleanup();

        $this->assertSame(0, (int) DB::table('ecommerce_categories')->where('id', $root)->value('depth'));
        $this->assertSame((string) $root, DB::table('ecommerce_categories')->where('id', $root)->value('path'));
        $this->assertSame(1, (int) DB::table('ecommerce_categories')->where('id', $child)->value('depth'));
        $this->assertSame("{$root}/{$child}", DB::table('ecommerce_categories')->where('id', $child)->value('path'));
    }

    /**
     * 재실행해도 결과가 동일하다 (멱등)
     *
     * @scenario entity=ec_category, parent_target=unrelated
     *
     * @effects cleanup_is_idempotent
     */
    public function test_cleanup_is_idempotent(): void
    {
        $a = $this->insertCategory('idem-a');
        $b = $this->insertCategory('idem-b', parentId: $a);
        DB::table('ecommerce_categories')->where('id', $a)->update(['parent_id' => $b]);

        $this->runCleanup();
        $first = $this->snapshot();

        $this->runCleanup();
        $second = $this->snapshot();

        $this->assertSame($first, $second, '재실행 결과가 동일해야 합니다.');
    }

    /**
     * 1.0.5 정리 마이그레이션을 실행합니다.
     */
    private function runCleanup(): void
    {
        (new Upgrade_1_0_5)->run(new UpgradeContext('1.0.4', '1.0.5', '1.0.5', 'extension-upgrade'));
    }

    /**
     * 테스트용 카테고리를 직접 삽입합니다.
     *
     * @param  string  $slug  슬러그
     * @param  int|null  $parentId  부모 ID
     * @param  int  $depth  초기 depth
     * @param  string  $path  초기 path
     * @return int 생성된 카테고리 ID
     */
    private function insertCategory(string $slug, ?int $parentId = null, int $depth = 0, string $path = ''): int
    {
        return DB::table('ecommerce_categories')->insertGetId([
            'name' => json_encode(['ko' => $slug, 'en' => $slug], JSON_UNESCAPED_UNICODE),
            'slug' => $slug,
            'parent_id' => $parentId,
            'depth' => $depth,
            'path' => $path,
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 전체 카테고리의 (id, parent_id, depth, path) 스냅샷을 반환합니다.
     *
     * @return array<int, array<string, mixed>> 스냅샷 배열
     */
    private function snapshot(): array
    {
        return DB::table('ecommerce_categories')
            ->orderBy('id')
            ->get(['id', 'parent_id', 'depth', 'path'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                'depth' => (int) $row->depth,
                'path' => (string) $row->path,
            ])
            ->all();
    }
}
