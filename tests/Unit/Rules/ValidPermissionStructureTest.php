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

    /** @test #1 빈 배열 */
    public function test_b1_empty_array(): void
    {
        $this->assertNull($this->validate([]));
    }

    /** @test #2 단일 식별자 */
    public function test_b1_single_identifier(): void
    {
        $this->assertNull($this->validate(['a.b.c']));
    }

    /** @test #3 복수 식별자 */
    public function test_b1_multiple_identifiers(): void
    {
        $this->assertNull($this->validate(['a.b', 'c.d']));
    }

    /** @test #4 OR 구조 */
    public function test_b1_or_structure(): void
    {
        $this->assertNull($this->validate(['or' => ['a.b', 'c.d']]));
    }

    /** @test #5 AND 구조 */
    public function test_b1_and_structure(): void
    {
        $this->assertNull($this->validate(['and' => ['a.b', 'c.d']]));
    }

    /** @test #6 AND>OR 중첩 */
    public function test_b1_and_or_nested(): void
    {
        $this->assertNull($this->validate(['and' => ['a.b', ['or' => ['b.c', 'c.d']]]]));
    }

    /** @test #7 OR>[AND,AND] 중첩 */
    public function test_b1_or_and_and_nested(): void
    {
        $this->assertNull($this->validate([
            'or' => [
                ['and' => ['a.b', 'b.c']],
                ['and' => ['c.d', 'd.e']],
            ],
        ]));
    }

    /** @test #8 3단계 중첩 */
    public function test_b1_depth3_nested(): void
    {
        $this->assertNull($this->validate([
            'and' => ['a.b', ['or' => ['b.c', ['and' => ['c.d', 'd.e']]]]],
        ]));
    }

    // ================================================================
    // B-2. 무효한 연산자
    // ================================================================

    /** @test #9 invalid 연산자 */
    public function test_b2_invalid_operator(): void
    {
        $this->assertNotNull($this->validate(['invalid' => ['a.b', 'c.d']]));
    }

    /** @test #10 대문자 OR */
    public function test_b2_uppercase_or(): void
    {
        $this->assertNotNull($this->validate(['OR' => ['a.b', 'c.d']]));
    }

    /** @test #11 not 연산자 */
    public function test_b2_not_operator(): void
    {
        $this->assertNotNull($this->validate(['not' => ['a.b']]));
    }

    /** @test #12 xor 연산자 */
    public function test_b2_xor_operator(): void
    {
        $this->assertNotNull($this->validate(['xor' => ['a.b', 'c.d']]));
    }

    /** @test #13 복수 키 (or + and) */
    public function test_b2_multiple_keys(): void
    {
        $this->assertNotNull($this->validate(['or' => ['a.b'], 'and' => ['c.d']]));
    }

    // ================================================================
    // B-3. 최소 항목 위반
    // ================================================================

    /** @test #14 or: 1개 */
    public function test_b3_or_one_item(): void
    {
        $this->assertNotNull($this->validate(['or' => ['a.b']]));
    }

    /** @test #15 and: 1개 */
    public function test_b3_and_one_item(): void
    {
        $this->assertNotNull($this->validate(['and' => ['a.b']]));
    }

    /** @test #16 or: 0개 */
    public function test_b3_or_empty(): void
    {
        $this->assertNotNull($this->validate(['or' => []]));
    }

    // ================================================================
    // B-4. 깊이 초과
    // ================================================================

    /** @test #17 4단계 중첩 */
    public function test_b4_depth4(): void
    {
        $this->assertNotNull($this->validate([
            'and' => ['a.b', ['or' => ['b.c', ['and' => ['c.d', ['or' => ['d.e', 'e.f']]]]]]],
        ]));
    }

    /** @test #18 5단계 중첩 */
    public function test_b4_depth5(): void
    {
        $this->assertNotNull($this->validate([
            'and' => ['a.b', ['or' => ['b.c', ['and' => ['c.d', ['or' => ['d.e', ['and' => ['e.f', 'f.g']]]]]]]]],
        ]));
    }

    // ================================================================
    // B-5. 권한 식별자 형식 오류
    // ================================================================

    /** @test #19 공백 포함 식별자 */
    public function test_b5_space_in_identifier(): void
    {
        $this->assertNotNull($this->validate(['invalid identifier']));
    }

    /** @test #20 빈 문자열 */
    public function test_b5_empty_string(): void
    {
        $this->assertNotNull($this->validate(['']));
    }

    /** @test #21 정수 */
    public function test_b5_integer_in_array(): void
    {
        $this->assertNotNull($this->validate([123]));
    }

    /** @test #22 null 항목 */
    public function test_b5_null_in_array(): void
    {
        $this->assertNotNull($this->validate([null]));
    }

    /** @test #23 or 내 정수 */
    public function test_b5_integer_in_or(): void
    {
        $this->assertNotNull($this->validate(['or' => ['a.b', 123]]));
    }

    /** @test #24 or 내 null */
    public function test_b5_null_in_or(): void
    {
        $this->assertNotNull($this->validate(['or' => ['a.b', null]]));
    }

    // ================================================================
    // B-6. 타입 오류
    // ================================================================

    /** @test #25 문자열 입력 */
    public function test_b6_string_input(): void
    {
        $this->assertNotNull($this->validate('string'));
    }

    /** @test #26 정수 입력 */
    public function test_b6_integer_input(): void
    {
        $this->assertNotNull($this->validate(123));
    }

    /** @test #27 null 입력 (ValidPermissionStructure에서는 통과 - nullable은 외부 규칙) */
    public function test_b6_null_input(): void
    {
        // null은 nullable 규칙에서 처리되므로 ValidPermissionStructure에서는 통과
        $this->assertNull($this->validate(null));
    }

    /** @test #28 or 값이 배열이 아닌 문자열 */
    public function test_b6_or_value_not_array(): void
    {
        $this->assertNotNull($this->validate(['or' => 'a.b']));
    }
}
