<?php

namespace Tests\Unit\Services;

use App\Services\LayoutService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * LayoutServiceMergeInitLocalTest — mergeShallow(initLocal/initGlobal/initIsolated) 단위
 *
 *
 * `initLocal`/`initGlobal`/`initIsolated` 는 shallow merge(자식 우선 — 같은 키 자식 값,
 * 중첩 객체 키는 통째 교체). 반사로 private mergeShallow 직접 검증.
 */
class LayoutServiceMergeInitLocalTest extends TestCase
{
    private LayoutService $service;

    private ReflectionMethod $merge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LayoutService::class);
        $this->merge = new ReflectionMethod(LayoutService::class, 'mergeShallow');
        $this->merge->setAccessible(true);
    }

    /**
     * @param  array  $parent  부모 객체
     * @param  array  $child  자식 객체
     * @return array 병합 결과
     */
    private function invokeMerge(array $parent, array $child): array
    {
        return $this->merge->invoke($this->service, $parent, $child);
    }

    public function test_shallow_merge_child_wins_same_key(): void
    {
        $merged = $this->invokeMerge(['perPage' => 20], ['perPage' => 50]);
        $this->assertSame(50, $merged['perPage']);
    }

    public function test_parent_only_child_only_both(): void
    {
        $this->assertSame(['a' => 1], $this->invokeMerge(['a' => 1], []));
        $this->assertSame(['b' => 2], $this->invokeMerge([], ['b' => 2]));
        $this->assertSame(['a' => 1, 'b' => 2], $this->invokeMerge(['a' => 1], ['b' => 2]));
    }

    public function test_nested_object_key_replaced_wholesale_not_deep(): void
    {
        // shallow — 중첩 객체 키는 통째 교체(deep merge 아님).
        $parent = ['filter' => ['status' => 'active', 'page' => 1]];
        $child = ['filter' => ['status' => 'archived']];
        $merged = $this->invokeMerge($parent, $child);
        // 자식 filter 통째 교체 — parent.filter.page 사라짐(shallow 확인).
        $this->assertSame(['status' => 'archived'], $merged['filter']);
    }

    public function test_init_global_init_isolated_same_behavior(): void
    {
        // initGlobal/initIsolated 도 동일 mergeShallow 경로.
        $merged = $this->invokeMerge(['theme' => 'light'], ['theme' => 'dark', 'lang' => 'ko']);
        $this->assertSame(['theme' => 'dark', 'lang' => 'ko'], $merged);
    }

    public function test_legacy_state_and_init_local_normalized_on_merge(): void
    {
        // ⑤ legacy state(부모) + initLocal(자식) 혼재 — mergeLayouts 가 `initLocal ?? state` 로
        // 정규화 후 shallow merge(LayoutService.php:146-148), 결과는 항상 `initLocal` 키.
        $parent = ['state' => ['perPage' => 20, 'sort' => 'asc'], 'layout_name' => 'parent'];
        $child = ['initLocal' => ['perPage' => 50], 'layout_name' => 'child'];

        $merged = $this->service->mergeLayouts($parent, $child, null);

        // 부모 state 가 initLocal 로 취급돼 병합(자식 perPage 우선, 부모 sort 보존).
        $this->assertArrayHasKey('initLocal', $merged);
        $this->assertArrayNotHasKey('state', $merged, '저장은 항상 initLocal 로 정규화(state 키 미출력)');
        $this->assertSame(50, $merged['initLocal']['perPage']);
        $this->assertSame('asc', $merged['initLocal']['sort']);
    }

    public function test_with_source_meta_merged_superset_of_child_original(): void
    {
        // ⑥⑦ 편집 모드 출처 도출 — 병합본(merged.initLocal)은 자식 자기선언분(child.initLocal,
        // = __editor.original 의 SSoT)의 상위집합(merged ⊇ original). 차집합 = 부모 상속분.
        // (실제 __editor.original 영속·strip 은 InitialStateInheritedSaveTest 가 DB 라운드트립으로
        //  잠근다 — 본 단위는 병합 결과로 출처 비교가 도출 가능함을 확인.)
        $parent = ['initLocal' => ['fromParent' => 1, 'shared' => 'parent'], 'layout_name' => 'parent'];
        $childOriginal = ['fromChild' => 2, 'shared' => 'child'];
        $child = ['initLocal' => $childOriginal, 'layout_name' => 'child'];

        $merged = $this->service->mergeLayouts($parent, $child, ['kind' => 'base', 'layout' => 'parent']);
        $mergedLocal = $merged['initLocal'];

        // merged ⊇ original(자식 자기선언분 전부 병합본에 존재, 덮은 키는 자식 값).
        foreach ($childOriginal as $k => $v) {
            $this->assertArrayHasKey($k, $mergedLocal);
            $this->assertSame($v, $mergedLocal[$k], "자식 선언 키 {$k} 는 자식 값 유지");
        }
        // 차집합(merged − original) = 부모 상속분.
        $inheritedKeys = array_diff(array_keys($mergedLocal), array_keys($childOriginal));
        $this->assertSame(['fromParent'], array_values($inheritedKeys), '병합본 − 자기선언분 = 부모 상속 키');
    }
}
