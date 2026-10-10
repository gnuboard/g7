<?php

namespace Tests\Unit\Support;

use App\Support\LayoutEmptyObjects;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * 레이아웃 JSON 빈 객체 보존 보조 테스트
 *
 * 값 자리(initLocal 류 값, params·fallback 아래)의 빈 객체는 stdClass 로 남아 응답에서 `{}` 가 되고,
 * 병합 코드가 배열로 다루는 섹션·구조 키는 배열로 남아야 한다(7.1.0 실측 O-K3-4).
 */
class LayoutEmptyObjectsTest extends TestCase
{
    /**
     * 상태 초기값 섹션 안의 빈 객체는 보존하고 빈 배열은 배열로 둔다.
     *
     * @scenario position=init_state_value
     *
     * @effects init_state_empty_object_preserved, empty_list_stays_list
     *
     * @return void 반환값 없음
     */
    public function test_preserves_empty_object_in_init_state_values(): void
    {
        $layout = LayoutEmptyObjects::decode(
            '{"initLocal":{"expandedNotifications":{},"selectedIds":[],"nested":{"inner":{}}},"initGlobal":{"g":{}},"initIsolated":{"i":{}},"state":{"s":{}}}',
            []
        );

        $this->assertIsArray($layout['initLocal']);
        $this->assertInstanceOf(stdClass::class, $layout['initLocal']['expandedNotifications']);
        $this->assertSame([], $layout['initLocal']['selectedIds']);
        $this->assertInstanceOf(stdClass::class, $layout['initLocal']['nested']['inner']);
        $this->assertInstanceOf(stdClass::class, $layout['initGlobal']['g']);
        $this->assertInstanceOf(stdClass::class, $layout['initIsolated']['i']);
        $this->assertInstanceOf(stdClass::class, $layout['state']['s']);
        $this->assertSame(
            '{"expandedNotifications":{},"selectedIds":[],"nested":{"inner":{}}}',
            json_encode($layout['initLocal'])
        );
    }

    /**
     * params·fallback 아래 빈 객체는 보존하고, 키 자신의 빈 값과 구조 키는 배열로 둔다.
     *
     * @scenario position=params_fallback_value
     *
     * @effects action_params_empty_object_preserved, fallback_empty_object_preserved, structural_keys_stay_arrays
     *
     * @return void 반환값 없음
     */
    public function test_preserves_empty_object_below_params_and_fallback_only(): void
    {
        $layout = LayoutEmptyObjects::decode(
            '{"data_sources":[{"id":"a","params":{},"fallback":{"data":{"targets":{}}}}],'
            .'"components":[{"id":"c","props":{},"actions":[{"handler":"navigate","params":{"path":"/x","query":{}}},{"handler":"setState","params":{}}]}]}',
            []
        );

        $this->assertSame([], $layout['data_sources'][0]['params'], 'params 자신은 배열이어야 한다');
        $this->assertInstanceOf(stdClass::class, $layout['data_sources'][0]['fallback']['data']['targets']);
        $this->assertSame([], $layout['components'][0]['props'], 'props 는 구조 키라 배열이어야 한다');
        $this->assertInstanceOf(stdClass::class, $layout['components'][0]['actions'][0]['params']['query']);
        $this->assertSame([], $layout['components'][0]['actions'][1]['params']);
    }

    /**
     * 비어 있는 섹션 자체는 디코드 단계에서 배열이고, 응답 직전 맵 섹션만 `{}` 로 돌려놓는다.
     *
     * @scenario position=root_section
     *
     * @effects root_map_section_restored
     *
     * @return void 반환값 없음
     */
    public function test_root_sections_stay_arrays_until_restored_for_response(): void
    {
        $layout = LayoutEmptyObjects::decode('{"meta":{},"initLocal":{},"components":[],"data_sources":[]}', []);

        $this->assertSame([], $layout['meta']);
        $this->assertSame([], $layout['initLocal']);

        $restored = LayoutEmptyObjects::restoreRootMapSections($layout);

        $this->assertSame('{"meta":{},"initLocal":{},"components":[],"data_sources":[]}', json_encode($restored));
    }

    /**
     * 배열만 다루는 소비자를 위해 보존한 빈 객체를 모두 배열로 되돌린다.
     *
     * @scenario position=init_state_value
     *
     * @effects array_consumer_sees_arrays
     *
     * @return void 반환값 없음
     */
    public function test_to_arrays_reverts_preserved_objects(): void
    {
        $layout = LayoutEmptyObjects::decode('{"initLocal":{"a":{},"b":[{"c":{}}]},"components":[{"actions":[{"params":{"query":{}}}]}]}', []);

        $this->assertSame(
            ['initLocal' => ['a' => [], 'b' => [['c' => []]]], 'components' => [['actions' => [['params' => ['query' => []]]]]]],
            LayoutEmptyObjects::toArrays($layout)
        );
    }

    /**
     * 원문이 없거나 객체가 아니면 캐스트 결과를 그대로 쓴다.
     *
     * @scenario position=root_section
     *
     * @effects unreadable_raw_falls_back_to_cast
     *
     * @return void 반환값 없음
     */
    public function test_falls_back_when_raw_is_unusable(): void
    {
        $this->assertSame(['x' => 1], LayoutEmptyObjects::decode(null, ['x' => 1]));
        $this->assertSame(['x' => 1], LayoutEmptyObjects::decode('[1,2]', ['x' => 1]));
        $this->assertSame(['x' => 1], LayoutEmptyObjects::decode('{bad', ['x' => 1]));
    }
}
