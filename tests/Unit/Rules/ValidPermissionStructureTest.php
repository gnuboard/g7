<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidPermissionStructure;
use Tests\TestCase;

/**
 * ValidPermissionStructure 검증 규칙 테스트
 *
 * flat array, 구조화 객체(or/and), 중첩 구조, 무효한 입력을 포함합니다.
 */
class ValidPermissionStructureTest extends TestCase
{
    private ValidPermissionStructure $rule;

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new ValidPermissionStructure;
    }

    /**
     * 검증을 실행하고 실패 메시지를 반환합니다.
     *
     * @param  mixed  $value  검증 대상 값
     * @return string|null 실패 메시지 (성공 시 null)
     */
    private function validate(mixed $value): ?string
    {
        $failMessage = null;
        $this->rule->validate('permissions', $value, function ($message) use (&$failMessage) {
            $failMessage = $message;
        });

        return $failMessage;
    }

    // ================================================================
    // B-1. 유효한 구조 (통과)
    // ================================================================

    /**
     * #1 빈 배열
     *
     * @scenario checker=structure_rule, permission_shape=empty, held_permissions=not_applicable
     *
     * @effects b1_empty_array
     *
     * @return void 반환값 없음
     */
    public function test_b1_empty_array(): void
    {
        $this->assertNull($this->validate([]));
    }

    /**
     * #2 단일 식별자
     *
     * @scenario checker=structure_rule, permission_shape=flat, held_permissions=not_applicable
     *
     * @effects b1_single_identifier
     *
     * @return void 반환값 없음
     */
    public function test_b1_single_identifier(): void
    {
        $this->assertNull($this->validate(['a.b.c']));
    }

    /**
     * #3 복수 식별자
     *
     * @scenario checker=structure_rule, permission_shape=flat, held_permissions=not_applicable
     *
     * @effects b1_multiple_identifiers
     *
     * @return void 반환값 없음
     */
    public function test_b1_multiple_identifiers(): void
    {
        $this->assertNull($this->validate(['a.b', 'c.d']));
    }

    /**
     * #4 OR 구조
     *
     * @scenario checker=structure_rule, permission_shape=or_group, held_permissions=not_applicable
     *
     * @effects b1_or_structure
     *
     * @return void 반환값 없음
     */
    public function test_b1_or_structure(): void
    {
        $this->assertNull($this->validate(['or' => ['a.b', 'c.d']]));
    }

    /**
     * #5 AND 구조
     *
     * @scenario checker=structure_rule, permission_shape=and_group, held_permissions=not_applicable
     *
     * @effects b1_and_structure
     *
     * @return void 반환값 없음
     */
    public function test_b1_and_structure(): void
    {
        $this->assertNull($this->validate(['and' => ['a.b', 'c.d']]));
    }

    /**
     * #6 AND>OR 중첩
     *
     * @scenario checker=structure_rule, permission_shape=nested_depth2, held_permissions=not_applicable
     *
     * @effects b1_and_or_nested
     *
     * @return void 반환값 없음
     */
    public function test_b1_and_or_nested(): void
    {
        $this->assertNull($this->validate(['and' => ['a.b', ['or' => ['b.c', 'c.d']]]]));
    }

    /**
     * #7 OR>[AND,AND] 중첩
     *
     * @scenario checker=structure_rule, permission_shape=nested_depth2, held_permissions=not_applicable
     *
     * @effects b1_or_and_and_nested
     *
     * @return void 반환값 없음
     */
    public function test_b1_or_and_and_nested(): void
    {
        $this->assertNull($this->validate([
            'or' => [
                ['and' => ['a.b', 'b.c']],
                ['and' => ['c.d', 'd.e']],
            ],
        ]));
    }

    /**
     * #8 3단계 중첩
     *
     * @scenario checker=structure_rule, permission_shape=nested_depth3, held_permissions=not_applicable
     *
     * @effects b1_depth3_nested
     *
     * @return void 반환값 없음
     */
    public function test_b1_depth3_nested(): void
    {
        $this->assertNull($this->validate([
            'and' => ['a.b', ['or' => ['b.c', ['and' => ['c.d', 'd.e']]]]],
        ]));
    }

    // ================================================================
    // B-2. 무효한 연산자
    // ================================================================

    /**
     * #9 invalid 연산자
     *
     * @scenario checker=structure_rule, permission_shape=invalid_operator, held_permissions=not_applicable
     *
     * @effects b2_invalid_operator
     *
     * @return void 반환값 없음
     */
    public function test_b2_invalid_operator(): void
    {
        $this->assertNotNull($this->validate(['invalid' => ['a.b', 'c.d']]));
    }

    /**
     * #10 대문자 OR
     *
     * @scenario checker=structure_rule, permission_shape=invalid_operator, held_permissions=not_applicable
     *
     * @effects b2_uppercase_or
     *
     * @return void 반환값 없음
     */
    public function test_b2_uppercase_or(): void
    {
        $this->assertNotNull($this->validate(['OR' => ['a.b', 'c.d']]));
    }

    /**
     * #11 not 연산자
     *
     * @scenario checker=structure_rule, permission_shape=invalid_operator, held_permissions=not_applicable
     *
     * @effects b2_not_operator
     *
     * @return void 반환값 없음
     */
    public function test_b2_not_operator(): void
    {
        $this->assertNotNull($this->validate(['not' => ['a.b']]));
    }

    /**
     * #12 xor 연산자
     *
     * @scenario checker=structure_rule, permission_shape=invalid_operator, held_permissions=not_applicable
     *
     * @effects b2_xor_operator
     *
     * @return void 반환값 없음
     */
    public function test_b2_xor_operator(): void
    {
        $this->assertNotNull($this->validate(['xor' => ['a.b', 'c.d']]));
    }

    /**
     * #13 복수 키 (or + and)
     *
     * @scenario checker=structure_rule, permission_shape=invalid_operator, held_permissions=not_applicable
     *
     * @effects b2_multiple_keys
     *
     * @return void 반환값 없음
     */
    public function test_b2_multiple_keys(): void
    {
        $this->assertNotNull($this->validate(['or' => ['a.b'], 'and' => ['c.d']]));
    }

    // ================================================================
    // B-3. 최소 항목 위반
    // ================================================================

    /**
     * #14 or: 1개
     *
     * @scenario checker=structure_rule, permission_shape=degenerate_group, held_permissions=not_applicable
     *
     * @effects b3_or_one_item
     *
     * @return void 반환값 없음
     */
    public function test_b3_or_one_item(): void
    {
        $this->assertNotNull($this->validate(['or' => ['a.b']]));
    }

    /**
     * #15 and: 1개
     *
     * @scenario checker=structure_rule, permission_shape=degenerate_group, held_permissions=not_applicable
     *
     * @effects b3_and_one_item
     *
     * @return void 반환값 없음
     */
    public function test_b3_and_one_item(): void
    {
        $this->assertNotNull($this->validate(['and' => ['a.b']]));
    }

    /**
     * #16 or: 0개
     *
     * @scenario checker=structure_rule, permission_shape=degenerate_group, held_permissions=not_applicable
     *
     * @effects b3_or_empty
     *
     * @return void 반환값 없음
     */
    public function test_b3_or_empty(): void
    {
        $this->assertNotNull($this->validate(['or' => []]));
    }

    // ================================================================
    // B-4. 깊이 초과
    // ================================================================

    /**
     * #17 4단계 중첩
     *
     * @scenario checker=structure_rule, permission_shape=nested_too_deep, held_permissions=not_applicable
     *
     * @effects b4_depth4
     *
     * @return void 반환값 없음
     */
    public function test_b4_depth4(): void
    {
        $this->assertNotNull($this->validate([
            'and' => ['a.b', ['or' => ['b.c', ['and' => ['c.d', ['or' => ['d.e', 'e.f']]]]]]],
        ]));
    }

    /**
     * #18 5단계 중첩
     *
     * @scenario checker=structure_rule, permission_shape=nested_too_deep, held_permissions=not_applicable
     *
     * @effects b4_depth5
     *
     * @return void 반환값 없음
     */
    public function test_b4_depth5(): void
    {
        $this->assertNotNull($this->validate([
            'and' => ['a.b', ['or' => ['b.c', ['and' => ['c.d', ['or' => ['d.e', ['and' => ['e.f', 'f.g']]]]]]]]],
        ]));
    }

    // ================================================================
    // B-5. 권한 식별자 형식 오류
    // ================================================================

    /**
     * #19 공백 포함 식별자
     *
     * @scenario checker=structure_rule, permission_shape=invalid_identifier, held_permissions=not_applicable
     *
     * @effects b5_space_in_identifier
     *
     * @return void 반환값 없음
     */
    public function test_b5_space_in_identifier(): void
    {
        $this->assertNotNull($this->validate(['invalid identifier']));
    }

    /**
     * #20 빈 문자열
     *
     * @scenario checker=structure_rule, permission_shape=invalid_identifier, held_permissions=not_applicable
     *
     * @effects b5_empty_string
     *
     * @return void 반환값 없음
     */
    public function test_b5_empty_string(): void
    {
        $this->assertNotNull($this->validate(['']));
    }

    /**
     * #21 정수
     *
     * @scenario checker=structure_rule, permission_shape=invalid_identifier, held_permissions=not_applicable
     *
     * @effects b5_integer_in_array
     *
     * @return void 반환값 없음
     */
    public function test_b5_integer_in_array(): void
    {
        $this->assertNotNull($this->validate([123]));
    }

    /**
     * #22 null 항목
     *
     * @scenario checker=structure_rule, permission_shape=invalid_identifier, held_permissions=not_applicable
     *
     * @effects b5_null_in_array
     *
     * @return void 반환값 없음
     */
    public function test_b5_null_in_array(): void
    {
        $this->assertNotNull($this->validate([null]));
    }

    /**
     * #23 or 내 정수
     *
     * @scenario checker=structure_rule, permission_shape=invalid_identifier, held_permissions=not_applicable
     *
     * @effects b5_integer_in_or
     *
     * @return void 반환값 없음
     */
    public function test_b5_integer_in_or(): void
    {
        $this->assertNotNull($this->validate(['or' => ['a.b', 123]]));
    }

    /**
     * #24 or 내 null
     *
     * @scenario checker=structure_rule, permission_shape=invalid_identifier, held_permissions=not_applicable
     *
     * @effects b5_null_in_or
     *
     * @return void 반환값 없음
     */
    public function test_b5_null_in_or(): void
    {
        $this->assertNotNull($this->validate(['or' => ['a.b', null]]));
    }

    // ================================================================
    // B-6. 타입 오류
    // ================================================================

    /**
     * #25 문자열 입력
     *
     * @scenario checker=structure_rule, permission_shape=non_array_input, held_permissions=not_applicable
     *
     * @effects b6_string_input
     *
     * @return void 반환값 없음
     */
    public function test_b6_string_input(): void
    {
        $this->assertNotNull($this->validate('string'));
    }

    /**
     * #26 정수 입력
     *
     * @scenario checker=structure_rule, permission_shape=non_array_input, held_permissions=not_applicable
     *
     * @effects b6_integer_input
     *
     * @return void 반환값 없음
     */
    public function test_b6_integer_input(): void
    {
        $this->assertNotNull($this->validate(123));
    }

    /**
     * #27 null 입력 (ValidPermissionStructure에서는 통과 - nullable은 외부 규칙)
     *
     * @scenario checker=structure_rule, permission_shape=non_array_input, held_permissions=not_applicable
     *
     * @effects b6_null_input
     *
     * @return void 반환값 없음
     */
    public function test_b6_null_input(): void
    {
        // null은 nullable 규칙에서 처리되므로 ValidPermissionStructure에서는 통과
        $this->assertNull($this->validate(null));
    }

    /**
     * #28 or 값이 배열이 아닌 문자열
     *
     * @scenario checker=structure_rule, permission_shape=non_array_input, held_permissions=not_applicable
     *
     * @effects b6_or_value_not_array
     *
     * @return void 반환값 없음
     */
    public function test_b6_or_value_not_array(): void
    {
        $this->assertNotNull($this->validate(['or' => 'a.b']));
    }
}
