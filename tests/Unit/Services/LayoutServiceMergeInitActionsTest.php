<?php

namespace Tests\Unit\Services;

use App\Services\LayoutService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * LayoutServiceMergeInitActionsTest — mergeInitActions 단위
 *
 * 부모/자식 init_actions 병합 + 출처 메타(`__source`) 부착 정합을 검증한다. 운영 렌더
 * (sourceMeta=null) 는 단순 array_merge(부착 0), 편집 모드(sourceMeta!=null) 는 각 항목에
 * `__source:{kind, layout}` 부착(부모=base / 자식=route). 반사로 private 메서드 직접 검증.
 */
class LayoutServiceMergeInitActionsTest extends TestCase
{
    private LayoutService $service;

    private ReflectionMethod $merge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LayoutService::class);
        $this->merge = new ReflectionMethod(LayoutService::class, 'mergeInitActions');
        $this->merge->setAccessible(true);
    }

    /**
     * private mergeInitActions 호출 헬퍼.
     *
     * @param  array  $parent  부모 init_actions
     * @param  array  $child  자식 init_actions
     * @param  array|null  $sourceMeta  부모 출처 메타(null=운영)
     * @param  string|null  $childLayout  자식 레이아웃명
     * @return array 병합 결과
     */
    private function invokeMerge(array $parent, array $child, ?array $sourceMeta = null, ?string $childLayout = null): array
    {
        return $this->merge->invoke($this->service, $parent, $child, $sourceMeta, $childLayout);
    }

    public function test_parent_only_returns_parent(): void
    {
        $parent = [['handler' => 'initTheme']];
        $this->assertSame($parent, $this->invokeMerge($parent, []));
    }

    public function test_child_only_returns_child(): void
    {
        $child = [['handler' => 'toast']];
        $this->assertSame($child, $this->invokeMerge([], $child));
    }

    public function test_parent_first_child_last_order(): void
    {
        $parent = [['handler' => 'initTheme'], ['handler' => 'initCartKey']];
        $child = [['handler' => 'toast']];
        $merged = $this->invokeMerge($parent, $child);
        $this->assertCount(3, $merged);
        $this->assertSame('initTheme', $merged[0]['handler']);
        $this->assertSame('initCartKey', $merged[1]['handler']);
        $this->assertSame('toast', $merged[2]['handler']);
    }

    public function test_source_meta_attaches_base_and_route(): void
    {
        $parent = [['handler' => 'initTheme']];
        $child = [['handler' => 'toast']];
        $merged = $this->invokeMerge($parent, $child, ['kind' => 'base', 'layout' => '_user_base'], 'home');

        $this->assertSame(['kind' => 'base', 'layout' => '_user_base'], $merged[0]['__source']);
        $this->assertSame(['kind' => 'route', 'layout' => 'home'], $merged[1]['__source']);
    }

    public function test_operational_render_has_no_source_meta(): void
    {
        $parent = [['handler' => 'initTheme']];
        $child = [['handler' => 'toast']];
        $merged = $this->invokeMerge($parent, $child); // sourceMeta=null

        $this->assertArrayNotHasKey('__source', $merged[0]);
        $this->assertArrayNotHasKey('__source', $merged[1]);
    }

    public function test_three_level_source_layout_is_nearest(): void
    {
        // 조부모→부모 병합 결과를 다시 자식과 병합(가장 가까운 선언 레이어가 출처).
        $grandparentMerged = $this->invokeMerge(
            [['handler' => 'initTheme']],
            [['handler' => 'initParent']],
            ['kind' => 'base', 'layout' => '_root_base'],
            '_user_base',
        );
        // 이제 grandparentMerged 를 부모로, 자식과 병합(편집 모드 — 부모 전체 base).
        $merged = $this->invokeMerge(
            $grandparentMerged,
            [['handler' => 'toast']],
            ['kind' => 'base', 'layout' => '_user_base'],
            'home',
        );
        // 부모 항목 전부 base/_user_base 로 재스탬프(가장 가까운 부모 레이어), 자식만 route/home.
        $this->assertSame(['kind' => 'base', 'layout' => '_user_base'], $merged[0]['__source']);
        $this->assertSame(['kind' => 'base', 'layout' => '_user_base'], $merged[1]['__source']);
        $this->assertSame(['kind' => 'route', 'layout' => 'home'], $merged[2]['__source']);
    }

    public function test_duplicate_handler_both_preserved(): void
    {
        $parent = [['handler' => 'toast']];
        $child = [['handler' => 'toast']];
        $merged = $this->invokeMerge($parent, $child);
        $this->assertCount(2, $merged); // 중복 제거 안 함(실행 2회).
    }

    public function test_empty_parent_with_child(): void
    {
        $merged = $this->invokeMerge([], [['handler' => 'toast']], ['kind' => 'base', 'layout' => '_base'], 'home');
        $this->assertCount(1, $merged);
        $this->assertSame(['kind' => 'route', 'layout' => 'home'], $merged[0]['__source']);
    }

    public function test_parent_with_empty_child(): void
    {
        $merged = $this->invokeMerge([['handler' => 'initTheme']], [], ['kind' => 'base', 'layout' => '_base'], 'home');
        $this->assertCount(1, $merged);
        $this->assertSame(['kind' => 'base', 'layout' => '_base'], $merged[0]['__source']);
    }
}
