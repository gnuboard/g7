<?php

namespace Tests\Unit\Support;

use App\Support\LayoutEmptyObjects;
use App\Support\LayoutMarkup\EmptyMap;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * 화면이 그대로 받는 확대 자리(#758) — props 값·responsive props 값·isolatedState 값·data_sources data/headers 아래·
     * defines·computed 값·overlay injections props 값·extensionPointProps 값의 빈 객체를 보존한다.
     *
     * @scenario position=expanded_value
     *
     * @effects expanded_value_positions_preserved
     *
     * @return void 반환값 없음
     */
    public function test_preserves_expanded_value_positions(): void
    {
        $layout = LayoutEmptyObjects::decode(
            '{"defines":{"d":{}},"computed":{"c":{}},'
            .'"data_sources":[{"id":"s","data":{"byId":{}},"headers":{"X-Map":{}}}],'
            .'"injections":[{"target_id":"t","position":"inject_props","props":{"m":{}}}],'
            .'"components":[{"id":"a","props":{"dataMap":{}},"isolatedState":{"sel":{}},'
            .'"responsive":{"mobile":{"props":{"mm":{}}}},"extensionPointProps":{"h":{}}}]}',
            []
        );

        $this->assertInstanceOf(stdClass::class, $layout['defines']['d'], 'defines 값');
        $this->assertInstanceOf(stdClass::class, $layout['computed']['c'], 'computed 값');
        $this->assertInstanceOf(stdClass::class, $layout['data_sources'][0]['data']['byId'], 'data 아래');
        $this->assertInstanceOf(stdClass::class, $layout['data_sources'][0]['headers']['X-Map'], 'headers 아래');
        $this->assertInstanceOf(stdClass::class, $layout['injections'][0]['props']['m'], 'injections props 값');
        $this->assertInstanceOf(stdClass::class, $layout['components'][0]['props']['dataMap'], 'props 값');
        $this->assertInstanceOf(stdClass::class, $layout['components'][0]['isolatedState']['sel'], 'isolatedState 값');
        $this->assertInstanceOf(stdClass::class, $layout['components'][0]['responsive']['mobile']['props']['mm'], 'responsive props 값');
        $this->assertInstanceOf(stdClass::class, $layout['components'][0]['extensionPointProps']['h'], 'extensionPointProps 값');
    }

    /**
     * 구조 키 자기 자신이 빈 객체면 배열로 둔다 — PHP 병합 코드가 `props`·`params`·`isolatedState`·`data` 를 배열로 다룬다.
     *
     * @scenario position=expanded_value
     *
     * @effects structural_self_empty_stays_array
     *
     * @return void 반환값 없음
     */
    public function test_structural_self_empty_stays_array(): void
    {
        $layout = LayoutEmptyObjects::decode(
            '{"data_sources":[{"id":"s","data":{},"headers":{},"params":{}}],"components":[{"id":"a","props":{},"isolatedState":{}}]}',
            []
        );

        $this->assertSame([], $layout['data_sources'][0]['data']);
        $this->assertSame([], $layout['data_sources'][0]['headers']);
        $this->assertSame([], $layout['data_sources'][0]['params']);
        $this->assertSame([], $layout['components'][0]['props']);
        $this->assertSame([], $layout['components'][0]['isolatedState']);
    }

    /**
     * 배열만 다루는 소비자용 되돌림은 원문 보존 디코드의 표식(EmptyMap)도 배열로 바꾼다.
     *
     * @scenario position=expanded_value
     *
     * @effects array_consumer_sees_arrays
     *
     * @return void 반환값 없음
     */
    public function test_to_arrays_reverts_empty_map_markers(): void
    {
        $layout = EmptyMap::decodePreserving('{"components":[{"props":{},"classMap":{"m":{}}}],"items":[]}');

        $this->assertSame(['components' => [['props' => [], 'classMap' => ['m' => []]]], 'items' => []], LayoutEmptyObjects::toArrays($layout));
    }

    /**
     * 모델 원문을 원문 보존 디코드로 읽고, 원문이 없거나 깨졌으면 캐스트 결과를 쓴다.
     *
     * @scenario position=expanded_value
     *
     * @effects unreadable_raw_falls_back_to_cast
     *
     * @return void 반환값 없음
     */
    public function test_raw_preserving_reads_model_raw_and_falls_back_to_cast(): void
    {
        $model = new class extends Model
        {
            /** @var array<string, string> */
            protected $casts = ['content' => 'array'];
        };

        $model->setRawAttributes(['content' => '{"components":[{"props":{}}],"items":[]}'], true);
        $this->assertSame('{"components":[{"props":{}}],"items":[]}', json_encode(LayoutEmptyObjects::rawPreserving($model)));

        $model->setRawAttributes(['content' => '{bad'], true);
        $this->assertSame([], LayoutEmptyObjects::rawPreserving($model), '깨진 원문은 캐스트 결과(빈 배열)');

        $model->setRawAttributes(['content' => '{"a":{}}'], false);
        $model->setRawAttributes(['content' => null], true);
        $this->assertSame([], LayoutEmptyObjects::rawPreserving($model), '원문이 없으면 빈 배열');
    }
}
