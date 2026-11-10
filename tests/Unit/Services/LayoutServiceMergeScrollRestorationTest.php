<?php

namespace Tests\Unit\Services;

use App\Services\LayoutService;
use Tests\TestCase;

/**
 * LayoutServiceMergeScrollRestorationTest — meta.scroll_restoration 베이스→화면 깊은 병합
 *
 * 베이스 레이아웃 meta.scroll_restoration 은 편집기의 「템플릿 기본값」이고 화면 레이아웃은 적은 키만
 * 덮어쓴다(부분 덮어쓰기). 얕은 병합이면 화면이 키 하나만 적어도 베이스의 나머지 키(하위 snapshot 포함)가
 * 통째로 사라져 엔진 기본값으로 돌아간다. containers 는 목록이라 자식 값이 통째로 바꾼다(인덱스 섞임 금지).
 */
class LayoutServiceMergeScrollRestorationTest extends TestCase
{
    private LayoutService $layoutService;

    /**
     * 테스트 초기화 - LayoutService 인스턴스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->layoutService = app(LayoutService::class);
    }

    /**
     * 화면이 mode 만 적으면 베이스의 나머지 키·snapshot 이 보존된다.
     *
     * @effects scroll_restoration_meta_saved_and_deep_merged_base_to_route
     *
     * @return void 반환값 없음
     */
    public function test_child_overrides_only_written_keys(): void
    {
        $parent = $this->buildLayout([
            'mode' => 'restore',
            'wait' => 'data',
            'timeout_ms' => 8000,
            'containers' => ['#admin-main'],
            'snapshot' => ['enabled' => true, 'ttl_seconds' => 300, 'item_key' => 'uuid'],
        ]);
        $child = $this->buildLayout(['mode' => 'top']);

        $result = $this->layoutService->mergeLayouts($parent, $child);
        $scroll = $result['meta']['scroll_restoration'];

        $this->assertSame('top', $scroll['mode']);
        $this->assertSame('data', $scroll['wait']);
        $this->assertSame(8000, $scroll['timeout_ms']);
        $this->assertSame(['#admin-main'], $scroll['containers']);
        $this->assertSame(['enabled' => true, 'ttl_seconds' => 300, 'item_key' => 'uuid'], $scroll['snapshot']);
    }

    /**
     * 하위 snapshot 도 키 단위로 병합된다.
     *
     * @effects scroll_restoration_meta_saved_and_deep_merged_base_to_route
     *
     * @return void 반환값 없음
     */
    public function test_snapshot_is_deep_merged(): void
    {
        $parent = $this->buildLayout([
            'snapshot' => ['enabled' => true, 'ttl_seconds' => 300, 'revalidate' => true],
        ]);
        $child = $this->buildLayout([
            'snapshot' => ['ttl_seconds' => 60],
        ]);

        $result = $this->layoutService->mergeLayouts($parent, $child);
        $snapshot = $result['meta']['scroll_restoration']['snapshot'];

        $this->assertSame(60, $snapshot['ttl_seconds']);
        $this->assertTrue($snapshot['enabled']);
        $this->assertTrue($snapshot['revalidate']);
    }

    /**
     * containers 는 목록이라 자식 값이 통째로 바꾼다 (인덱스별로 섞지 않는다).
     *
     * @effects scroll_restoration_meta_saved_and_deep_merged_base_to_route
     *
     * @return void 반환값 없음
     */
    public function test_containers_replaced_wholesale(): void
    {
        $parent = $this->buildLayout(['containers' => ['#a', '#b', '#c']]);
        $child = $this->buildLayout(['containers' => ['#z']]);

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertSame(['#z'], $result['meta']['scroll_restoration']['containers']);
    }

    /**
     * snapshot.state 도 목록이라 자식 값이 통째로 바꾸고, 자식이 안 적으면 부모 값을 물려받는다.
     *
     * @effects scroll_restoration_meta_saved_and_deep_merged_base_to_route
     *
     * @return void 반환값 없음
     */
    public function test_snapshot_state_replaced_wholesale(): void
    {
        $parent = $this->buildLayout([
            'snapshot' => ['ttl_seconds' => 300, 'state' => ['_global.infiniteScroll', '_local.page']],
        ]);

        $replaced = $this->layoutService->mergeLayouts(
            $parent,
            $this->buildLayout(['snapshot' => ['state' => ['_local.cursor']]])
        );
        $this->assertSame(['_local.cursor'], $replaced['meta']['scroll_restoration']['snapshot']['state']);
        $this->assertSame(300, $replaced['meta']['scroll_restoration']['snapshot']['ttl_seconds']);

        $inherited = $this->layoutService->mergeLayouts(
            $parent,
            $this->buildLayout(['snapshot' => ['revalidate' => false]])
        );
        $this->assertSame(['_global.infiniteScroll', '_local.page'], $inherited['meta']['scroll_restoration']['snapshot']['state']);
    }

    /**
     * 부모만·자식만 있으면 그 값이 그대로 쓰인다.
     *
     * @effects scroll_restoration_meta_saved_and_deep_merged_base_to_route
     *
     * @return void 반환값 없음
     */
    public function test_one_side_only_is_used_as_is(): void
    {
        $parentOnly = $this->layoutService->mergeLayouts(
            $this->buildLayout(['mode' => 'top']),
            $this->buildLayout()
        );
        $this->assertSame(['mode' => 'top'], $parentOnly['meta']['scroll_restoration']);

        $childOnly = $this->layoutService->mergeLayouts(
            $this->buildLayout(),
            $this->buildLayout(['on_reload' => false])
        );
        $this->assertSame(['on_reload' => false], $childOnly['meta']['scroll_restoration']);
    }

    /**
     * 3단계 상속에서 각 단계의 키가 누적된다.
     *
     * @effects scroll_restoration_meta_saved_and_deep_merged_base_to_route
     *
     * @return void 반환값 없음
     */
    public function test_three_level_inheritance_accumulates(): void
    {
        $base = $this->buildLayout(['mode' => 'restore', 'snapshot' => ['enabled' => false]]);
        $base['components'] = [['component' => 'Div', 'slot' => 'content']];

        $mid = $this->buildLayout(['wait' => 'data']);
        unset($mid['components']);
        $mid['slots'] = ['content' => ['component' => 'Div', 'slot' => 'inner']];

        $leaf = $this->buildLayout(['snapshot' => ['item_key' => 'code']]);
        unset($leaf['components']);
        $leaf['slots'] = ['inner' => ['component' => 'Div']];

        $result = $this->layoutService->mergeLayouts(
            $this->layoutService->mergeLayouts($base, $mid),
            $leaf
        );
        $scroll = $result['meta']['scroll_restoration'];

        $this->assertSame('restore', $scroll['mode']);
        $this->assertSame('data', $scroll['wait']);
        $this->assertFalse($scroll['snapshot']['enabled']);
        $this->assertSame('code', $scroll['snapshot']['item_key']);
    }

    /**
     * 테스트용 레이아웃 배열을 빌드합니다.
     *
     * @param  array|null  $scroll  scroll_restoration 설정 (null 이면 키 없음)
     * @return array 레이아웃 배열
     */
    private function buildLayout(?array $scroll = null): array
    {
        $meta = [];
        if ($scroll !== null) {
            $meta['scroll_restoration'] = $scroll;
        }

        return [
            'meta' => $meta,
            'data_sources' => [],
            'components' => [],
        ];
    }
}
