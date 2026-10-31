<?php

namespace Tests\Unit\Extension\Layout;

use App\Extension\Layout\LayoutContentComparator;
use App\Support\LayoutMarkup\EmptyMap;
use App\Support\LayoutMarkup\LayoutPartialOrigin;
use PHPUnit\Framework\TestCase;

/**
 * 레이아웃 정규화 비교기 단위 테스트 (#727 §5.4).
 *
 * 편집기 왕복 게이트와 같은 정규화를 쓰는지 — 표기만 다른 두 판은 같고, 리스트 순서처럼 의미가 다른 차이는
 * 다르다고 판정하는지 고정한다.
 */
class LayoutContentComparatorTest extends TestCase
{
    /**
     * 키 순서·빈 map·노드 type·meta.is_partial·조각 출처 표식·편집기 부가 정보는 무시한다.
     *
     * @effects representation_only_differences_are_not_modifications
     *
     * @return void 반환값 없음
     */
    public function test_representation_only_differences_are_equal(): void
    {
        $a = ['slots' => ['content' => [['type' => 'basic', 'name' => 'Div', 'props' => ['a' => 1, 'b' => 2], 'meta' => ['is_partial' => true], LayoutPartialOrigin::KEY => 'partials/x/_a']]], 'initLocal' => EmptyMap::instance(), 'lock_version' => 3];
        $b = ['initLocal' => [], 'slots' => ['content' => [['name' => 'Div', 'props' => ['b' => 2, 'a' => 1], '__editor' => ['x' => 1]]]]];

        $this->assertTrue(LayoutContentComparator::sameDocument($a, $b));
        $this->assertTrue(LayoutContentComparator::sameFragment($a['slots'], $b['slots']));
    }

    /**
     * 리스트 순서는 구분한다.
     *
     * @effects representation_only_differences_are_not_modifications
     *
     * @return void 반환값 없음
     */
    public function test_list_order_matters(): void
    {
        $this->assertFalse(LayoutContentComparator::sameFragment([['id' => 'a'], ['id' => 'b']], [['id' => 'b'], ['id' => 'a']]));
    }

    /**
     * null 은 null 끼리만 같다.
     *
     * @effects representation_only_differences_are_not_modifications
     *
     * @return void 반환값 없음
     */
    public function test_null_equals_only_null(): void
    {
        $this->assertTrue(LayoutContentComparator::sameDocument(null, null));
        $this->assertFalse(LayoutContentComparator::sameDocument(null, []));
        $this->assertFalse(LayoutContentComparator::sameFragment([], null));
    }

    /**
     * 문서 루트 비교는 `state` 별칭을 `initLocal` 로 접지만, 조각 비교는 접지 않는다.
     *
     * @effects representation_only_differences_are_not_modifications
     *
     * @return void 반환값 없음
     */
    public function test_document_folds_state_alias_but_fragment_does_not(): void
    {
        $this->assertTrue(LayoutContentComparator::sameDocument(['state' => ['x' => 1]], ['initLocal' => ['x' => 1]]));
        $this->assertFalse(LayoutContentComparator::sameFragment(['state' => ['x' => 1]], ['initLocal' => ['x' => 1]]));
    }

    /**
     * JSON 문자열도 받는다 (DB 원문 비교).
     *
     * @effects representation_only_differences_are_not_modifications
     *
     * @return void 반환값 없음
     */
    public function test_accepts_json_strings(): void
    {
        $this->assertTrue(LayoutContentComparator::sameDocument('{"b":1,"a":{}}', ['a' => [], 'b' => 1]));
    }
}
