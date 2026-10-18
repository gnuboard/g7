<?php

namespace Tests\Unit\Extension\Layout;

use App\Extension\Layout\DTO\LayoutMergeResult;
use App\Extension\Layout\LayoutThreeWayMerger;
use App\Support\LayoutMarkup\LayoutPartialOrigin;
use PHPUnit\Framework\TestCase;

/**
 * 레이아웃 3자 병합기 단위 테스트 (#727 §5.5 판정표 · §6.2).
 *
 * 판정표의 행마다 조각 하나짜리 트리로 한 건 이상, 그리고 루트 키·data_sources 항목·중첩 조각·같은 조각
 * 반복·투영(옮김·맞바꿈)·고아 방지·인스턴스 수 변화·개수 규칙(W16)·표식 잔존을 고정한다.
 */
class LayoutThreeWayMergerTest extends TestCase
{
    /**
     * 텍스트 노드를 만듭니다.
     *
     * @param  string  $id  노드 id
     * @param  string  $text  문구
     * @return array<string, mixed> 노드
     */
    private function node(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'basic', 'name' => 'Div', 'text' => $text];
    }

    /**
     * 조각 출처 표식을 붙입니다.
     *
     * @param  string  $origin  조각 경로
     * @param  array<string, mixed>  $node  조각 루트
     * @return array<string, mixed> 표식이 붙은 노드
     */
    private function part(string $origin, array $node): array
    {
        return $node + [LayoutPartialOrigin::KEY => $origin];
    }

    /**
     * 조각 두 개(A·B)를 품은 화면을 만듭니다.
     *
     * @param  string|null  $a  조각 A 문구 (null 이면 조각 없음)
     * @param  string|null  $b  조각 B 문구 (null 이면 조각 없음)
     * @param  bool  $marked  조각 출처 표식을 붙일지 (운영자 판은 false)
     * @param  array<string, mixed>  $extra  최상위 키 덧붙이기
     * @return array<string, mixed> 화면
     */
    private function doc(?string $a, ?string $b, bool $marked = true, array $extra = []): array
    {
        $content = [$this->node('title', '목록')];
        if ($a !== null) {
            $content[] = $marked ? $this->part('partials/x/_a', $this->node('part_a', $a)) : $this->node('part_a', $a);
        }
        if ($b !== null) {
            $content[] = $marked ? $this->part('partials/x/_b', $this->node('part_b', $b)) : $this->node('part_b', $b);
        }

        return $extra + ['version' => '1.0.0', 'layout_name' => 'x', 'slots' => ['content' => $content]];
    }

    /**
     * 병합 결과 본문을 JSON 문자열로 만듭니다.
     *
     * @param  array<string, mixed>  $merged  병합된 본문
     * @return string 펼친 JSON 문자열
     */
    private function flat(array $merged): string
    {
        return json_encode($merged, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 병합기 실행 도우미.
     *
     * @param  array<string, mixed>  $base  원본
     * @param  array<string, mixed>  $ours  운영자 판
     * @param  array<string, mixed>  $theirs  새 판
     * @return LayoutMergeResult 결과
     */
    private function merge(array $base, array $ours, array $theirs)
    {
        return (new LayoutThreeWayMerger)->merge($base, $ours, $theirs);
    }

    /**
     * 판정에서 이유별 충돌 단위를 뽑습니다.
     *
     * @param  LayoutMergeResult  $result  결과
     * @return array<int, string> 충돌 이유 목록
     */
    private function reasons($result): array
    {
        return array_column($result->conflicts, 'reason');
    }

    /**
     * 지름길 — 운영자가 안 고쳤으면 새 판을 그대로 쓴다(표식 제거).
     *
     * @effects untouched_unit_takes_new_version
     *
     * @return void 반환값 없음
     */
    public function test_shortcut_ours_equals_base_takes_theirs(): void
    {
        $result = $this->merge($this->doc('A', 'B'), $this->doc('A', 'B', false), $this->doc('A', 'B2'));

        $this->assertSame('took_theirs', $result->verdict);
        $this->assertStringContainsString('B2', $this->flat($result->merged));
        $this->assertStringNotContainsString(LayoutPartialOrigin::KEY, $this->flat($result->merged));
    }

    /**
     * 지름길 — 새 판이 안 바꿨으면 운영자 판 유지.
     *
     * @effects operator_modified_unit_is_kept
     *
     * @return void 반환값 없음
     */
    public function test_shortcut_theirs_equals_base_keeps_ours(): void
    {
        $result = $this->merge($this->doc('A', 'B'), $this->doc('A-op', 'B', false), $this->doc('A', 'B'));

        $this->assertSame('kept', $result->verdict);
        $this->assertStringContainsString('A-op', $this->flat($result->merged));
    }

    /**
     * 지름길 — 운영자 판과 새 판이 같으면 새 판.
     *
     * @effects operator_modified_unit_is_kept
     *
     * @return void 반환값 없음
     */
    public function test_shortcut_ours_equals_theirs(): void
    {
        $result = $this->merge($this->doc('A', 'B'), $this->doc('A2', 'B', false), $this->doc('A2', 'B'));

        $this->assertSame('took_theirs', $result->verdict);
    }

    /**
     * 판정표 — 운영자 안 고침(A) + 새 판 고침(B) / 운영자 고침(A) + 새 판 안 고침 → 둘 다 반영 (merged).
     *
     * @effects untouched_unit_takes_new_version, operator_modified_unit_is_kept
     *
     * @return void 반환값 없음
     */
    public function test_untouched_unit_takes_theirs_and_modified_unit_keeps_ours(): void
    {
        $result = $this->merge($this->doc('A', 'B'), $this->doc('A-op', 'B', false), $this->doc('A', 'B2'));

        $this->assertSame('merged', $result->verdict);
        $this->assertStringContainsString('A-op', $this->flat($result->merged));
        $this->assertStringContainsString('B2', $this->flat($result->merged));
        $this->assertSame([], $result->conflicts);
        $this->assertSame(['root > partials/x/_b @ 0'], array_column($result->applied, 'unit'));
    }

    /**
     * 판정표 — 같은 단위를 양쪽이 같은 값으로 고침 → 운영자 것(충돌 아님).
     *
     * @effects operator_modified_unit_is_kept
     *
     * @return void 반환값 없음
     */
    public function test_same_change_on_both_sides_is_not_a_conflict(): void
    {
        $result = $this->merge($this->doc('A', 'B'), $this->doc('A2', 'B', false), $this->doc('A2', 'B2'));

        $this->assertSame([], $result->conflicts);
        $this->assertStringContainsString('A2', $this->flat($result->merged));
        $this->assertStringContainsString('B2', $this->flat($result->merged));
    }

    /**
     * 판정표 — 양쪽이 같은 단위를 다르게 고침 → 운영자 것 + both_changed.
     *
     * @effects conflicting_change_keeps_operator_version_and_is_reported
     *
     * @return void 반환값 없음
     */
    public function test_both_changed_keeps_ours_as_conflict(): void
    {
        $result = $this->merge($this->doc('A', 'B'), $this->doc('A-op', 'B', false), $this->doc('A-new', 'B'));

        $this->assertSame('conflict', $result->verdict);
        $this->assertSame(['both_changed'], $this->reasons($result));
        $this->assertStringContainsString('A-op', $this->flat($result->merged));
        $this->assertStringNotContainsString('A-new', $this->flat($result->merged));
    }

    /**
     * 판정표 — 운영자 안 고침 + 새 판이 조각을 뺌 → 지움(applied).
     *
     * @effects removal_follows_the_side_that_removed
     *
     * @return void 반환값 없음
     */
    public function test_untouched_unit_removed_in_new_version_is_removed(): void
    {
        $base = $this->doc('A', 'B');
        $theirs = $this->doc('A', null);
        // 운영자는 다른 곳(최상위 meta)만 고침 — 지름길을 피한다
        $ours = $this->doc('A', 'B', false, ['meta' => ['title' => 'op']]);

        $result = $this->merge($base, $ours, $theirs);

        $this->assertStringNotContainsString('part_b', $this->flat($result->merged));
        $this->assertStringContainsString('"title":"op"', $this->flat($result->merged));
    }

    /**
     * 판정표 — 운영자 고침 + 새 판이 조각을 뺌 → 남김 + removed_in_new_version (D4).
     *
     * @effects conflicting_change_keeps_operator_version_and_is_reported
     *
     * @return void 반환값 없음
     */
    public function test_modified_unit_removed_in_new_version_is_kept(): void
    {
        $result = $this->merge($this->doc('A', 'B'), $this->doc('A', 'B-op', false), $this->doc('A', null));

        $this->assertContains('removed_in_new_version', $this->reasons($result));
        $this->assertStringContainsString('B-op', $this->flat($result->merged));
    }

    /**
     * 판정표 — 운영자가 지움 + 새 판 안 바꿈 → 지운 채.
     *
     * @effects removal_follows_the_side_that_removed
     *
     * @return void 반환값 없음
     */
    public function test_operator_removed_unit_stays_removed(): void
    {
        $result = $this->merge($this->doc('A', 'B'), $this->doc('A', null, false), $this->doc('A', 'B', true, ['meta' => ['t' => 1]]));

        $this->assertStringNotContainsString('part_b', $this->flat($result->merged));
        $this->assertSame([], $result->conflicts);
    }

    /**
     * 판정표 — 운영자가 지움 + 새 판이 고침 → 지운 채 + removed_by_operator.
     *
     * @effects conflicting_change_keeps_operator_version_and_is_reported
     *
     * @return void 반환값 없음
     */
    public function test_operator_removed_unit_changed_by_new_version_is_conflict(): void
    {
        $result = $this->merge($this->doc('A', 'B'), $this->doc('A', null, false), $this->doc('A', 'B2'));

        $this->assertSame(['removed_by_operator'], $this->reasons($result));
        $this->assertStringNotContainsString('part_b', $this->flat($result->merged));
    }

    /**
     * 판정표 — 새 판이 조각을 추가하고 부모 몸통을 새 판으로 고름 → 추가(applied).
     *
     * @effects untouched_unit_takes_new_version
     *
     * @return void 반환값 없음
     */
    public function test_unit_added_by_new_version_enters_when_parent_takes_theirs(): void
    {
        $result = $this->merge($this->doc('A', null), $this->doc('A-op', null, false), $this->doc('A', 'B-new'));

        $this->assertStringContainsString('B-new', $this->flat($result->merged));
        $this->assertStringContainsString('A-op', $this->flat($result->merged));
    }

    /**
     * 판정표 — 새 판이 조각을 추가했지만 운영자가 부모 몸통을 고침 → added_but_parent_kept.
     *
     * @effects conflicting_change_keeps_operator_version_and_is_reported
     *
     * @return void 반환값 없음
     */
    public function test_unit_added_while_parent_kept_is_reported(): void
    {
        $ours = $this->doc('A', null, false);
        array_unshift($ours['slots']['content'], $this->node('op_first', '운영자 추가'));

        $result = $this->merge($this->doc('A', null), $ours, $this->doc('A', 'B-new'));

        $this->assertContains('added_but_parent_kept', $this->reasons($result));
        $this->assertStringNotContainsString('B-new', $this->flat($result->merged));
        $this->assertStringContainsString('운영자 추가', $this->flat($result->merged));
    }

    /**
     * 루트 키 — 운영자만 추가한 최상위 키는 유지, 양쪽이 같은 키를 다르게 추가하면 both_added.
     *
     * @effects conflicting_change_keeps_operator_version_and_is_reported
     *
     * @return void 반환값 없음
     */
    public function test_root_keys_added_by_operator_and_both_added(): void
    {
        $result = $this->merge(
            $this->doc('A', 'B'),
            $this->doc('A', 'B', false, ['computed' => ['x' => '{{1}}'], 'named_actions' => ['a' => ['handler' => 'x']]]),
            $this->doc('A', 'B2', true, ['named_actions' => ['a' => ['handler' => 'y']]])
        );

        $flat = $this->flat($result->merged);
        $this->assertStringContainsString('"computed"', $flat);
        $this->assertStringContainsString('"handler":"x"', $flat);
        $this->assertContains('both_added', $this->reasons($result));
        $this->assertStringContainsString('B2', $flat);
    }

    /**
     * 루트 키 교차 수정 — meta·init_actions·initLocal·computed·modals 를 한쪽씩 고치면 둘 다 반영.
     *
     * @effects untouched_unit_takes_new_version
     *
     * @return void 반환값 없음
     */
    public function test_cross_root_key_changes_merge(): void
    {
        $base = $this->doc('A', 'B', true, ['meta' => ['title' => 't'], 'init_actions' => [['handler' => 'a']], 'initLocal' => ['x' => 1], 'computed' => ['c' => '1'], 'modals' => [['id' => 'm', 'type' => 'composite', 'name' => 'Modal']]]);
        $ours = $this->doc('A', 'B', false, ['meta' => ['title' => 'op'], 'init_actions' => [['handler' => 'a']], 'initLocal' => ['x' => 2], 'computed' => ['c' => '1'], 'modals' => [['id' => 'm', 'type' => 'composite', 'name' => 'Modal']]]);
        $theirs = $this->doc('A', 'B', true, ['meta' => ['title' => 't'], 'init_actions' => [['handler' => 'b']], 'initLocal' => ['x' => 1], 'computed' => ['c' => '2'], 'modals' => [['id' => 'm', 'type' => 'composite', 'name' => 'Modal', 'props' => ['size' => 'lg']]]]);

        $result = $this->merge($base, $ours, $theirs);
        $flat = $this->flat($result->merged);

        $this->assertSame('merged', $result->verdict);
        foreach (['"title":"op"', '"handler":"b"', '"x":2', '"c":"2"', '"size":"lg"'] as $needle) {
            $this->assertStringContainsString($needle, $flat);
        }
    }

    /**
     * data_sources — 항목 id 단위: 운영자 항목 추가 + 새 판 항목 수정 → 둘 다, 순서는 새 판 → 운영자 전용.
     *
     * @effects data_sources_merge_by_item_id
     *
     * @return void 반환값 없음
     */
    public function test_data_sources_merge_by_item_id(): void
    {
        $ds = fn (array $items) => ['data_sources' => $items];
        $base = $this->doc('A', 'B', true, $ds([['id' => 'list', 'endpoint' => '/a', 'params' => ['per_page' => 10]]]));
        $ours = $this->doc('A', 'B', false, $ds([['id' => 'list', 'endpoint' => '/a', 'params' => ['per_page' => 10]], ['id' => 'mine', 'endpoint' => '/m']]));
        $theirs = $this->doc('A', 'B', true, $ds([['id' => 'list', 'endpoint' => '/a', 'params' => ['per_page' => 20]]]));

        $result = $this->merge($base, $ours, $theirs);

        $this->assertSame(['list', 'mine'], array_column($result->merged['data_sources'], 'id'));
        $this->assertSame(20, $result->merged['data_sources'][0]['params']['per_page']);
        $this->assertContains('item:data_sources/list', array_column($result->applied, 'unit'));
    }

    /**
     * data_sources — 같은 id 를 양쪽이 다르게 고치면 충돌·운영자 것, id 없는 항목이 섞이면 키 통째 판정.
     *
     * @effects data_sources_merge_by_item_id
     *
     * @return void 반환값 없음
     */
    public function test_data_sources_conflict_and_idless_items(): void
    {
        $ds = fn (array $items) => ['data_sources' => $items];
        $base = $this->doc('A', 'B', true, $ds([['id' => 'list', 'endpoint' => '/a']]));

        $conflict = $this->merge($base, $this->doc('A', 'B', false, $ds([['id' => 'list', 'endpoint' => '/op']])), $this->doc('A', 'B', true, $ds([['id' => 'list', 'endpoint' => '/new']])));
        $this->assertSame('/op', $conflict->merged['data_sources'][0]['endpoint']);
        $this->assertContains('both_changed', $this->reasons($conflict));

        $idless = $this->merge($base, $this->doc('A', 'B', false, $ds([['id' => 'list', 'endpoint' => '/a'], ['endpoint' => '/noid']])), $this->doc('A', 'B', true, $ds([['id' => 'list', 'endpoint' => '/new']])));
        $this->assertContains('both_changed', $this->reasons($idless));
        $this->assertSame('/noid', $idless->merged['data_sources'][1]['endpoint']);
    }

    /**
     * 중첩 조각 — 운영자가 바깥 조각 몸통을, 새 판이 안쪽 조각만 고침 → 안쪽 반영·바깥 유지. 반대도 마찬가지.
     *
     * @effects untouched_unit_takes_new_version
     *
     * @return void 반환값 없음
     */
    public function test_nested_partials_are_independent_units(): void
    {
        $outer = fn (string $label, string $inner, bool $marked) => $marked
            ? $this->part('partials/x/_outer', ['id' => 'outer', 'type' => 'basic', 'name' => 'Div', 'props' => ['label' => $label], 'children' => [$this->part('partials/x/_inner', $this->node('inner', $inner))]])
            : ['id' => 'outer', 'type' => 'basic', 'name' => 'Div', 'props' => ['label' => $label], 'children' => [$this->node('inner', $inner)]];
        $wrap = fn (array $n) => ['version' => '1', 'slots' => ['content' => [$n]]];

        $r1 = $this->merge($wrap($outer('L', 'I', true)), $wrap($outer('L-op', 'I', false)), $wrap($outer('L', 'I2', true)));
        $this->assertStringContainsString('L-op', $this->flat($r1->merged));
        $this->assertStringContainsString('I2', $this->flat($r1->merged));

        $r2 = $this->merge($wrap($outer('L', 'I', true)), $wrap($outer('L', 'I-op', false)), $wrap($outer('L2', 'I', true)));
        $this->assertStringContainsString('L2', $this->flat($r2->merged));
        $this->assertStringContainsString('I-op', $this->flat($r2->merged));
    }

    /**
     * 같은 조각 두 번 사용 — 한 인스턴스만 운영자가 고치면 다른 인스턴스는 새 판.
     *
     * @effects untouched_unit_takes_new_version
     *
     * @return void 반환값 없음
     */
    public function test_same_partial_used_twice(): void
    {
        $make = fn (string $x, string $y, bool $m) => ['version' => '1', 'slots' => ['content' => [
            $m ? $this->part('partials/x/_row', $this->node('r1', $x)) : $this->node('r1', $x),
            $m ? $this->part('partials/x/_row', $this->node('r2', $y)) : $this->node('r2', $y),
        ]]];

        $result = $this->merge($make('R', 'R', true), $make('R-op', 'R', false), $make('R-new', 'R-new', true));

        $this->assertSame('R-op', $result->merged['slots']['content'][0]['text']);
        $this->assertSame('R-new', $result->merged['slots']['content'][1]['text']);
        $this->assertContains('both_changed', $this->reasons($result));
    }

    /**
     * 투영 — 운영자가 조각 앞에 형제를 넣어 경로가 밀려도, 조각이 그대로면 유일 일치로 찾아 새 판을 붙인다.
     *
     * @effects moved_partial_is_matched_by_projection
     *
     * @return void 반환값 없음
     */
    public function test_projection_finds_moved_untouched_partial(): void
    {
        $ours = $this->doc('A', 'B', false);
        array_splice($ours['slots']['content'], 1, 0, [$this->node('op_inserted', '운영자 삽입')]);

        $result = $this->merge($this->doc('A', 'B'), $ours, $this->doc('A', 'B2'));
        $flat = $this->flat($result->merged);

        $this->assertStringContainsString('운영자 삽입', $flat);
        $this->assertStringContainsString('B2', $flat);
        $this->assertSame(['title', 'op_inserted', 'part_a', 'part_b'], array_column($result->merged['slots']['content'], 'id'));
    }

    /**
     * 투영 — 운영자가 조각을 옮기면서 고쳤고 새 판도 그 조각을 고쳤으면 운영자 것(충돌).
     *
     * @effects moved_partial_is_matched_by_projection
     *
     * @return void 반환값 없음
     */
    public function test_projection_moved_and_modified_partial_is_conflict(): void
    {
        $ours = ['version' => '1.0.0', 'layout_name' => 'x', 'slots' => ['content' => [$this->node('title', '목록'), $this->node('op', 'x'), $this->node('part_b', 'B'), $this->node('part_a', 'A-op')]]];

        $result = $this->merge($this->doc('A', 'B'), $ours, $this->doc('A-new', 'B'));

        $this->assertStringContainsString('A-op', $this->flat($result->merged));
        $this->assertStringNotContainsString('A-new', $this->flat($result->merged));
        $this->assertNotSame([], $result->conflicts);
    }

    /**
     * 투영 안전 — 같은 name 형제 둘을 운영자가 맞바꾸고 새 판이 하나를 고치면 엉뚱한 노드에 붙지 않는다.
     *
     * @effects moved_partial_is_matched_by_projection
     *
     * @return void 반환값 없음
     */
    public function test_projection_swap_does_not_misattach(): void
    {
        $base = ['version' => '1', 'slots' => ['content' => [
            $this->part('partials/x/_p', ['type' => 'basic', 'name' => 'Div', 'text' => 'P']),
            $this->part('partials/x/_q', ['type' => 'basic', 'name' => 'Div', 'text' => 'Q']),
        ]]];
        $ours = ['version' => '1', 'slots' => ['content' => [
            ['type' => 'basic', 'name' => 'Div', 'text' => 'Q'],
            ['type' => 'basic', 'name' => 'Div', 'text' => 'P'],
        ]]];
        $theirs = $base;
        $theirs['slots']['content'][0]['text'] = 'P2';

        $result = $this->merge($base, $ours, $theirs);
        $texts = array_column($result->merged['slots']['content'], 'text');

        // P 는 새 판(P2)이 되거나 운영자 것(P)으로 남을 수 있지만, Q 자리에 P2 가 붙어서는 안 된다
        $this->assertNotContains('Q2', $texts);
        $this->assertContains('Q', $texts);
        $this->assertCount(2, $texts);
    }

    /**
     * 고아 방지 — 운영자가 고친 조각을 새 판이 부모에서 빼면 부모를 운영자 것으로 + child_kept_parent_changed.
     *
     * @effects modified_child_is_not_orphaned
     *
     * @return void 반환값 없음
     */
    public function test_orphan_prevention_when_parent_drops_modified_child(): void
    {
        $base = $this->doc('A', 'B');
        $theirs = $base;
        array_pop($theirs['slots']['content']);          // B 를 뺌
        $theirs['slots']['content'][] = $this->node('new_tail', 'NEW');

        $result = $this->merge($base, $this->doc('A', 'B-op', false), $theirs);

        $this->assertStringContainsString('B-op', $this->flat($result->merged));
        $this->assertContains('removed_in_new_version', $this->reasons($result));
    }

    /**
     * 고아 방지 — 새 판이 `modals` 키를 통째로 없앴는데 운영자가 그 안 모달 조각을 고쳤으면 `modals` 는 운영자 것.
     *
     * @effects modified_child_is_not_orphaned
     *
     * @return void 반환값 없음
     */
    public function test_orphan_prevention_when_new_version_drops_whole_modals_key(): void
    {
        $modal = fn (string $t, bool $m) => $m ? $this->part('partials/x/_modal', ['id' => 'm', 'type' => 'composite', 'name' => 'Modal', 'text' => $t]) : ['id' => 'm', 'type' => 'composite', 'name' => 'Modal', 'text' => $t];
        $base = $this->doc('A', 'B', true, ['modals' => [$modal('M', true)]]);
        $ours = $this->doc('A', 'B', false, ['modals' => [$modal('M-op', false)]]);
        $theirs = $this->doc('A', 'B2');

        $result = $this->merge($base, $ours, $theirs);

        $this->assertStringContainsString('M-op', $this->flat($result->merged));
        $this->assertStringContainsString('B2', $this->flat($result->merged));
    }

    /**
     * 인스턴스 수 변화 — 새 판이 같은 조각을 한 번 더 넣고 운영자가 기존 인스턴스를 고쳤으면 그 origin 전부 운영자 것.
     *
     * @effects instance_count_change_is_guarded
     *
     * @return void 반환값 없음
     */
    public function test_instance_count_change_with_modified_instance(): void
    {
        $row = fn (string $t, bool $m) => $m ? $this->part('partials/x/_row', $this->node('r', $t)) : $this->node('r', $t);
        $base = ['version' => '1', 'slots' => ['content' => [$row('R', true)]]];
        $ours = ['version' => '1', 'slots' => ['content' => [$row('R-op', false)]]];
        $theirs = ['version' => '1', 'slots' => ['content' => [$row('R0', true), $row('R', true)]]];

        $result = $this->merge($base, $ours, $theirs);

        $this->assertContains('instance_count_changed', $this->reasons($result));
        $this->assertSame(['R-op'], array_column($result->merged['slots']['content'], 'text'));
    }

    /**
     * 인스턴스 수 변화 — 운영자가 아무 인스턴스도 안 고쳤으면 새 배치를 그대로 받는다.
     *
     * @effects instance_count_change_is_guarded
     *
     * @return void 반환값 없음
     */
    public function test_instance_count_change_without_modification_takes_new_layout(): void
    {
        $row = fn (string $t, bool $m) => $m ? $this->part('partials/x/_row', $this->node('r', $t)) : $this->node('r', $t);
        $base = ['version' => '1', 'meta' => ['a' => 1], 'slots' => ['content' => [$row('R', true)]]];
        $ours = ['version' => '1', 'meta' => ['a' => 2], 'slots' => ['content' => [$row('R', false)]]];
        $theirs = ['version' => '1', 'meta' => ['a' => 1], 'slots' => ['content' => [$row('R0', true), $row('R', true)]]];

        $result = $this->merge($base, $ours, $theirs);

        $this->assertSame(['R0', 'R'], array_column($result->merged['slots']['content'], 'text'));
        $this->assertSame(2, $result->merged['meta']['a']);
    }

    /**
     * 개수 규칙(W16) — comment·version 만 바뀐 새 판은 반영하되 자동 반영 개수는 0.
     *
     * @effects merge_result_carries_no_markers_and_bookkeeping_keys_are_not_counted
     *
     * @return void 반환값 없음
     */
    public function test_comment_and_version_changes_are_applied_but_not_counted(): void
    {
        $base = $this->doc('A', 'B', true, ['comment' => 'c1']);
        $ours = $this->doc('A-op', 'B', false, ['comment' => 'c1']);
        $theirs = $this->doc('A', 'B', true, ['comment' => 'c2']);
        $theirs['version'] = '2.0.0';

        $result = $this->merge($base, $ours, $theirs);

        $this->assertSame([], $result->applied);
        $this->assertSame('c2', $result->merged['comment']);
        $this->assertSame('2.0.0', $result->merged['version']);
        $this->assertStringContainsString('A-op', $this->flat($result->merged));
    }

    /**
     * 표식 — 결과에 병합 자리표시·조각 출처 표식이 남지 않는다.
     *
     * @effects merge_result_carries_no_markers_and_bookkeeping_keys_are_not_counted
     *
     * @return void 반환값 없음
     */
    public function test_result_has_no_markers(): void
    {
        $result = $this->merge($this->doc('A', 'B'), $this->doc('A-op', 'B', false), $this->doc('A', 'B2'));
        $flat = $this->flat($result->merged);

        $this->assertStringNotContainsString(LayoutThreeWayMerger::UNIT_KEY, $flat);
        $this->assertStringNotContainsString(LayoutPartialOrigin::KEY, $flat);
    }

    /**
     * 문서 vs 조각 정규화 — 조각 몸통의 노드 키 `state` 는 `initLocal` 로 접히지 않는다.
     *
     * @effects merge_result_carries_no_markers_and_bookkeeping_keys_are_not_counted
     *
     * @return void 반환값 없음
     */
    public function test_node_level_state_key_is_not_folded(): void
    {
        $withState = fn (string $v, bool $m) => ['version' => '1', 'slots' => ['content' => [
            $m ? $this->part('partials/x/_s', ['id' => 's', 'type' => 'basic', 'name' => 'Div', 'state' => ['v' => $v]]) : ['id' => 's', 'type' => 'basic', 'name' => 'Div', 'state' => ['v' => $v]],
        ]], 'meta' => ['m' => $v]];

        $result = $this->merge($withState('1', true), $withState('1', false) + ['initLocal' => ['z' => 1]], $withState('2', true));

        $this->assertSame('2', $result->merged['slots']['content'][0]['state']['v']);
        $this->assertArrayNotHasKey('initLocal', $result->merged['slots']['content'][0]);
    }

    /**
     * 판정표 2행 — 양쪽 다 손대지 않은 단위(X | =b | =b)는 그대로 두고 반영·충돌 어디에도 세지 않는다 (지름길을 피하려고 다른 단위는 양쪽이 각각 고친다).
     *
     * @effects untouched_unit_takes_new_version, operator_modified_unit_is_kept
     *
     * @return void 반환값 없음
     */
    public function test_unit_unchanged_on_both_sides_is_left_as_is(): void
    {
        // 운영자: 조각 B / 새 판: 최상위 meta 추가 — 조각 A 는 양쪽 다 원본 그대로
        $result = $this->merge($this->doc('A', 'B'), $this->doc('A', 'B-op', false), $this->doc('A', 'B', true, ['meta' => ['title' => 'new']]));

        $this->assertSame('merged', $result->verdict);
        $this->assertSame(['key:meta'], array_column($result->applied, 'unit'), '반영은 새 판이 바꾼 meta 하나뿐');
        $this->assertSame([], $result->conflicts);
        $this->assertSame('A', $result->merged['slots']['content'][1]['text'], '양쪽 다 안 고친 조각은 원본 그대로');
        $this->assertSame('B-op', $result->merged['slots']['content'][2]['text']);
        $this->assertSame('new', $result->merged['meta']['title']);
    }

    /**
     * 판정표 12a행 — 원본에 없던 같은 값을 양쪽이 똑같이 추가(⊥ | Z | Y≡Z)하면 충돌 없이 한 번만 남는다 (최상위 키·data_sources 항목).
     *
     * @effects operator_modified_unit_is_kept, data_sources_merge_by_item_id
     *
     * @return void 반환값 없음
     */
    public function test_identical_addition_on_both_sides_is_not_a_conflict(): void
    {
        $ds = fn (array $items) => ['data_sources' => $items];
        $list = ['id' => 'list', 'endpoint' => '/a'];
        $extra = ['id' => 'extra', 'endpoint' => '/e'];

        $result = $this->merge(
            $this->doc('A', 'B', true, $ds([$list])),
            $this->doc('A-op', 'B', false, $ds([$list, $extra]) + ['computed' => ['x' => '{{1}}']]),
            $this->doc('A', 'B2', true, $ds([$list, $extra]) + ['computed' => ['x' => '{{1}}']])
        );

        $this->assertNotContains('both_added', $this->reasons($result));
        $this->assertSame([], $result->conflicts);
        $this->assertSame(['x' => '{{1}}'], $result->merged['computed']);
        $this->assertSame(['list', 'extra'], array_column($result->merged['data_sources'], 'id'), '같은 추가 항목은 한 번만');
        $this->assertStringContainsString('A-op', $this->flat($result->merged));
        $this->assertStringContainsString('B2', $this->flat($result->merged));
    }

    /**
     * 고아 방지 사유 — 운영자가 고친 조각을 새 판이 빼면 그 자리를 품은 최상위 키(`slots`·`modals`)를 운영자 판으로 되돌리고
     * `child_kept_parent_changed` 로 보고하며, 되돌린 키는 반영 목록에서 뺀다.
     *
     * @effects modified_child_is_not_orphaned, conflicting_change_keeps_operator_version_and_is_reported
     *
     * @return void 반환값 없음
     */
    public function test_orphan_prevention_reports_child_kept_parent_changed_on_root_keys(): void
    {
        $base = $this->doc('A', 'B');
        $theirs = $base;
        array_pop($theirs['slots']['content']);
        $theirs['slots']['content'][] = $this->node('new_tail', 'NEW');

        $slots = $this->merge($base, $this->doc('A', 'B-op', false), $theirs);

        $this->assertContains(['unit' => 'key:slots', 'reason' => 'child_kept_parent_changed'], $slots->conflicts);
        $this->assertNotContains('key:slots', array_column($slots->applied, 'unit'), '운영자 판으로 되돌린 키를 반영으로 세면 안 된다');
        $this->assertStringNotContainsString('NEW', $this->flat($slots->merged));

        $modal = fn (string $t, bool $m) => $m ? $this->part('partials/x/_modal', ['id' => 'm', 'type' => 'composite', 'name' => 'Modal', 'text' => $t]) : ['id' => 'm', 'type' => 'composite', 'name' => 'Modal', 'text' => $t];
        $modals = $this->merge(
            $this->doc('A', 'B', true, ['modals' => [$modal('M', true)]]),
            $this->doc('A', 'B', false, ['modals' => [$modal('M-op', false)]]),
            $this->doc('A', 'B2')
        );

        $this->assertContains(['unit' => 'key:modals', 'reason' => 'child_kept_parent_changed'], $modals->conflicts);
        $this->assertSame('M-op', $modals->merged['modals'][0]['text']);
    }

    /**
     * 고아 방지 사유(중첩) — 운영자가 고친 안쪽 조각을 새 판이 바깥 조각 몸통에서 빼면 바깥 조각 단위를 운영자 판으로 되돌리고
     * 그 단위에 `child_kept_parent_changed` 를 남긴다.
     *
     * @effects modified_child_is_not_orphaned
     *
     * @return void 반환값 없음
     */
    public function test_orphan_prevention_reports_child_kept_parent_changed_on_nested_partial(): void
    {
        $outer = fn (array $children, bool $marked) => ['version' => '1', 'slots' => ['content' => [
            $marked
                ? $this->part('partials/x/_outer', ['id' => 'outer', 'type' => 'basic', 'name' => 'Div', 'children' => $children])
                : ['id' => 'outer', 'type' => 'basic', 'name' => 'Div', 'children' => $children],
        ]]];

        $result = $this->merge(
            $outer([$this->part('partials/x/_inner', $this->node('inner', 'I'))], true),
            $outer([$this->node('inner', 'I-op')], false),
            $outer([$this->node('replacement', 'NEW')], true)
        );

        $this->assertContains(['unit' => 'root > partials/x/_outer @ 0', 'reason' => 'child_kept_parent_changed'], $result->conflicts);
        $this->assertNotContains('root > partials/x/_outer @ 0', array_column($result->applied, 'unit'));
        $this->assertStringContainsString('I-op', $this->flat($result->merged));
        $this->assertStringNotContainsString('NEW', $this->flat($result->merged));
    }
}
