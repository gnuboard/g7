<?php

namespace Tests\Unit\Support;

use App\Support\LayoutErrorHandlingPrune;
use App\Support\LayoutMarkup\EmptyMap;
use stdClass;
use Tests\TestCase;

/**
 * LayoutErrorHandlingPrune 단위 테스트
 *
 * 빈 동작의 세 가지 표현(EmptyMap 표식, stdClass, 빈 배열)을 모두 빈 동작으로 보고, 오류 처리 밖의 값과
 * 동작이 있는 코드는 건드리지 않는지 잰다.
 */
class LayoutErrorHandlingPruneTest extends TestCase
{
    /**
     * 빈 동작 세 표현을 모두 지우고 동작이 있는 코드는 남긴다.
     *
     * @scenario path=unit
     *
     * @effects prune_treats_empty_map_marker_object_and_array_as_empty
     *
     * @return void 반환값 없음
     */
    public function test_prunes_all_empty_action_representations(): void
    {
        $out = LayoutErrorHandlingPrune::prune([
            'errorHandling' => [
                '401' => EmptyMap::instance(),
                '403' => new stdClass,
                '404' => [],
                'default' => ['handler' => 'toast'],
            ],
        ]);

        $this->assertSame(['default' => ['handler' => 'toast']], $out['errorHandling']);
    }

    /**
     * 맵 자체가 비었거나(빈 표식 포함) 코드가 모두 비면 키를 빼고, 오류 처리 밖의 빈 객체는 그대로 둔다.
     *
     * @scenario path=unit
     *
     * @effects prune_drops_empty_maps_and_leaves_other_values
     *
     * @return void 반환값 없음
     */
    public function test_drops_empty_maps_and_leaves_other_values(): void
    {
        $marker = EmptyMap::instance();
        $out = LayoutErrorHandlingPrune::prune([
            'errorHandling' => $marker,
            'initLocal' => ['filters' => $marker],
            'data_sources' => [
                ['id' => 'a', 'errorHandling' => ['401' => []]],
                ['id' => 'b', 'errorHandling' => []],
                ['id' => 'c', 'params' => ['q' => $marker]],
            ],
        ]);

        $this->assertArrayNotHasKey('errorHandling', $out);
        $this->assertSame($marker, $out['initLocal']['filters']);
        $this->assertSame([['id' => 'a'], ['id' => 'b'], ['id' => 'c', 'params' => ['q' => $marker]]], $out['data_sources']);
    }
}
