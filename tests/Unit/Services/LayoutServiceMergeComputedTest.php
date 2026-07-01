<?php

namespace Tests\Unit\Services;

use App\Services\LayoutService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * LayoutServiceMergeComputedTest — mergeComputed + buildComputedSourceMap 단위
 *
 * computed 는 shallow merge(자식 우선 — 같은 키 자식 식 유효). withSourceMeta 시 `__computedSource`
 * 키 출처 맵(부모=base / 자식=route, override 포함)이 정확하고, computed 키와 충돌하지 않음을 검증.
 */
class LayoutServiceMergeComputedTest extends TestCase
{
    private LayoutService $service;

    private ReflectionMethod $merge;

    private ReflectionMethod $sourceMap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LayoutService::class);
        $this->merge = new ReflectionMethod(LayoutService::class, 'mergeComputed');
        $this->merge->setAccessible(true);
        $this->sourceMap = new ReflectionMethod(LayoutService::class, 'buildComputedSourceMap');
        $this->sourceMap->setAccessible(true);
    }

    public function test_shallow_merge_child_wins_same_key(): void
    {
        $parent = ['isReadOnly' => '{{ a }}'];
        $child = ['isReadOnly' => '{{ b }}'];
        $merged = $this->merge->invoke($this->service, $parent, $child);
        $this->assertSame('{{ b }}', $merged['isReadOnly']);
    }

    public function test_parent_only_child_only_both(): void
    {
        $this->assertSame(['x' => '1'], $this->merge->invoke($this->service, ['x' => '1'], []));
        $this->assertSame(['y' => '2'], $this->merge->invoke($this->service, [], ['y' => '2']));
        $merged = $this->merge->invoke($this->service, ['x' => '1'], ['y' => '2']);
        $this->assertSame(['x' => '1', 'y' => '2'], $merged);
    }

    public function test_source_map_marks_base_and_route(): void
    {
        $parent = ['common' => '{{ a }}'];
        $child = ['own' => '{{ b }}'];
        $map = $this->sourceMap->invoke($this->service, $parent, $child);
        $this->assertSame('base', $map['common']);
        $this->assertSame('route', $map['own']);
    }

    public function test_source_map_overridden_key_is_route_override(): void
    {
        // 자식이 부모 키를 덮으면 그 키 출처는 'route-override'(부모+자식 동시 선언 — 덮음).
        // 순수 자식 키('route')와 구분되어야 ComputedForm 이 〔이 페이지에서 덮음〕
        // 승격 배지 + 공통값 되돌리기를 그릴 수 있다.
        $parent = ['isReadOnly' => '{{ a }}'];
        $child = ['isReadOnly' => '{{ b }}'];
        $map = $this->sourceMap->invoke($this->service, $parent, $child);
        $this->assertSame('route-override', $map['isReadOnly']);
    }

    public function test_source_map_three_kinds_distinct(): void
    {
        // 부모만(base) / 자식만(route) / 부모+자식 동시(route-override) 3종이 구분된다.
        $parent = ['common' => '{{ a }}', 'shared' => '{{ p }}'];
        $child = ['own' => '{{ b }}', 'shared' => '{{ c }}'];
        $map = $this->sourceMap->invoke($this->service, $parent, $child);
        $this->assertSame('base', $map['common'], '부모만 = base');
        $this->assertSame('route', $map['own'], '자식만 = route');
        $this->assertSame('route-override', $map['shared'], '부모+자식 동시 = route-override');
    }

    public function test_source_map_does_not_collide_with_computed_keys(): void
    {
        // 출처 맵은 별개 배열 — computed 키 값(식)과 섞이지 않는다.
        $map = $this->sourceMap->invoke($this->service, ['a' => '{{ x }}'], ['b' => '{{ y }}']);
        $this->assertArrayNotHasKey('__computedSource', $map);
        $this->assertSame(['a' => 'base', 'b' => 'route'], $map);
    }

    public function test_multi_level_inheritance_nearest_layer_wins(): void
    {
        // ④ 다단 상속(조부모→부모→자식) — buildComputedSourceMap 은 2-레이어 단위지만
        // loadAndMergeLayout 은 부모를 재귀 병합한 "부모 병합본"을 parent 로 넘긴다.
        // 따라서 조부모 키는 부모 병합본에 잔존해 자식 입장에선 모두 'base'(부모 레이어),
        // 자식 자기 선언 키만 'route'. 같은 키를 자식이 덮으면 자식(가장 가까운) 우선.
        $grandparentMerged = $this->merge->invoke($this->service, ['fromGrandparent' => '{{ g }}'], ['fromParent' => '{{ p }}']);
        $this->assertSame(['fromGrandparent' => '{{ g }}', 'fromParent' => '{{ p }}'], $grandparentMerged);

        // 부모 병합본(조부모+부모) + 자식 → 자식 식 병합(자식 우선).
        $merged = $this->merge->invoke($this->service, $grandparentMerged, ['own' => '{{ c }}', 'fromParent' => '{{ override }}']);
        $this->assertSame('{{ override }}', $merged['fromParent'], '가장 가까운 레이어(자식)가 같은 키를 덮는다');
        $this->assertSame('{{ g }}', $merged['fromGrandparent'], '미덮은 상위 키는 보존');

        // 출처 맵 — 부모 병합본 키는 base, 자식 선언/덮은 키는 route.
        $map = $this->sourceMap->invoke($this->service, $grandparentMerged, ['own' => '{{ c }}', 'fromParent' => '{{ override }}']);
        $this->assertSame('base', $map['fromGrandparent']);
        $this->assertSame('route', $map['own']);
        $this->assertSame('route-override', $map['fromParent'], '자식이 덮은 키 출처 = route-override');
    }

    public function test_with_source_meta_false_omits_computed_source_map(): void
    {
        // ⑥ 운영 경로(sourceMeta=null) — mergeLayouts 결과에 __computedSource 미부착(응답 형식 종전 동일).
        $parent = ['computed' => ['common' => '{{ a }}']];
        $child = ['computed' => ['own' => '{{ b }}'], 'layout_name' => 'child'];

        $opMerged = $this->service->mergeLayouts($parent, $child, null);
        $this->assertArrayNotHasKey('__computedSource', $opMerged, '운영 경로엔 출처 맵 미부착');
        $this->assertSame(['common' => '{{ a }}', 'own' => '{{ b }}'], $opMerged['computed']);

        // 편집 경로(sourceMeta 비-null) — 출처 맵 부착.
        $editMerged = $this->service->mergeLayouts($parent, $child, ['kind' => 'base', 'layout' => 'parent']);
        $this->assertArrayHasKey('__computedSource', $editMerged, '편집 경로엔 출처 맵 부착');
        $this->assertSame('base', $editMerged['__computedSource']['common']);
        $this->assertSame('route', $editMerged['__computedSource']['own']);
    }
}
