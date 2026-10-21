<?php

namespace Tests\Unit\Support;

use App\Support\LayoutEmptyObjectRepair;
use PHPUnit\Framework\TestCase;

/**
 * 굳은 빈 객체 복구 판정 테스트 (#758)
 *
 * 원본이 `{}` 이고 저장본의 같은 자리가 `[]` 일 때만 `{}` 로 되돌린다. 운영자가 바꾼 다른 값은
 * 건드리지 않고, `id` 를 가진 노드 목록은 `id` 로 짝을 맞춘다.
 */
class LayoutEmptyObjectRepairTest extends TestCase
{
    /**
     * 원본 `{}`·저장본 `[]` 자리만 고치고 경로를 돌려준다. 다른 차이는 그대로 둔다.
     *
     * @scenario path=repair
     *
     * @effects repair_fixes_only_reference_empty_object_positions, repair_preserves_operator_edits
     *
     * @return void 반환값 없음
     */
    public function test_fixes_only_positions_where_reference_is_empty_object(): void
    {
        [$fixed, $paths] = LayoutEmptyObjectRepair::repair(
            json_decode('{"a":2,"b":[],"c":3,"list":[],"filled":[1],"keep":{"x":[]}}'),
            json_decode('{"a":1,"b":{},"list":[],"filled":{},"keep":{"x":[]}}'),
        );

        $this->assertSame('{"a":2,"b":{},"c":3,"list":[],"filled":[1],"keep":{"x":[]}}', json_encode($fixed));
        $this->assertSame(['b'], $paths);
    }

    /**
     * `id` 를 가진 노드 목록은 `id` 로 짝을 맞춘다 — 운영자가 노드를 끼워 넣어 인덱스가 밀려도 맞는 노드를 고친다.
     *
     * @scenario path=repair
     *
     * @effects repair_matches_nodes_by_id
     *
     * @return void 반환값 없음
     */
    public function test_matches_node_lists_by_id(): void
    {
        [$fixed, $paths] = LayoutEmptyObjectRepair::repair(
            json_decode('{"components":[{"id":"inserted","props":{"m":[]}},{"id":"x","props":{"m":[]}},{"id":"y","params":{"n":[]}}]}'),
            json_decode('{"components":[{"id":"x","props":{"m":{}}},{"id":"y","params":{"n":{}}}]}'),
        );

        $this->assertSame(
            '{"components":[{"id":"inserted","props":{"m":[]}},{"id":"x","props":{"m":{}}},{"id":"y","params":{"n":{}}}]}',
            json_encode($fixed)
        );
        $this->assertSame(['components[x].props.m', 'components[y].params.n'], $paths);
    }

    /**
     * `id` 가 없는 목록은 인덱스로 맞추고, 두 쪽 타입이 다르면 그 아래로 내려가지 않는다.
     *
     * @scenario path=repair
     *
     * @effects repair_stops_on_type_mismatch
     *
     * @return void 반환값 없음
     */
    public function test_matches_plain_lists_by_index_and_stops_on_type_mismatch(): void
    {
        [$fixed, $paths] = LayoutEmptyObjectRepair::repair(
            json_decode('{"rows":[{"q":[]},{"q":[]}],"swapped":"{{expr}}","deep":[{"z":[]}]}'),
            json_decode('{"rows":[{"q":{}},{"q":[]}],"swapped":{"inner":{}},"deep":{"z":{}}}'),
        );

        $this->assertSame('{"rows":[{"q":{}},{"q":[]}],"swapped":"{{expr}}","deep":[{"z":[]}]}', json_encode($fixed));
        $this->assertSame(['rows[0].q'], $paths);
    }

    /**
     * 고칠 자리가 없으면 입력을 그대로 돌려준다 (멱등).
     *
     * @scenario path=repair
     *
     * @effects repair_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_returns_input_unchanged_when_nothing_to_fix(): void
    {
        $target = json_decode('{"initLocal":{"filters":{}},"items":[]}');

        [$fixed, $paths] = LayoutEmptyObjectRepair::repair($target, json_decode('{"initLocal":{"filters":{}},"items":[]}'));

        $this->assertSame('{"initLocal":{"filters":{}},"items":[]}', json_encode($fixed));
        $this->assertSame([], $paths);
    }
}
