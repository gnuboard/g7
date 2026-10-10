<?php

namespace Tests\Unit\Models\Concerns;

use App\Enums\IdentityPolicyFailMode;
use App\Models\Concerns\UserOverrideValueComparator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 운영자 수정 기록 판정 비교기 단위 테스트 (#727 §5.11).
 *
 * 폼이 표기만 다르게 보내는 값은 같다고, 실제로 다른 값은 다르다고 판정하는지 고정한다.
 */
class UserOverrideValueComparatorTest extends TestCase
{
    /**
     * 같은 뜻의 표기 쌍.
     *
     * @return array<string, array{0: mixed, 1: mixed}> 데이터
     */
    public static function sameProvider(): array
    {
        return [
            '앞뒤 공백' => ['안녕', ' 안녕 '],
            '빈 문자열 = null' => ['', null],
            '빈 배열 = null' => [[], null],
            '값이 전부 null 인 맵 = null' => [['signup_stage' => null], null],
            '빈 문자열만 있는 맵 = null' => [['ko' => ''], null],
            '숫자 문자열 = 숫자' => ['30', 30],
            'bool = 0/1' => [true, 1],
            '키 순서 무관' => [['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]],
            'enum = value' => [IdentityPolicyFailMode::Block, 'block'],
            '중첩 공백' => [['ko' => '<p>본문</p>'], ['ko' => "<p>본문</p>\n"]],
        ];
    }

    /**
     * 같은 뜻의 표기는 같다고 판정한다.
     *
     * @scenario surface=value_comparator
     *
     * @effects same_meaning_values_are_not_overrides
     *
     * @param  mixed  $a  한쪽 값
     * @param  mixed  $b  다른 쪽 값
     * @return void 반환값 없음
     */
    #[DataProvider('sameProvider')]
    public function test_same_meaning_is_equal(mixed $a, mixed $b): void
    {
        $this->assertTrue(UserOverrideValueComparator::same($a, $b));
    }

    /**
     * 다른 뜻의 값 쌍.
     *
     * @return array<string, array{0: mixed, 1: mixed}> 데이터
     */
    public static function differentProvider(): array
    {
        return [
            '문구 다름' => ['안녕', '안녕!'],
            '리스트 순서' => [[1, 2], [2, 1]],
            '맵 값 다름' => [['signup_stage' => 'email'], null],
            '숫자 다름' => ['30', 31],
            'false 와 null' => [false, null],
        ];
    }

    /**
     * 다른 뜻의 값은 다르다고 판정한다.
     *
     * @scenario surface=value_comparator
     *
     * @effects real_change_is_recorded_per_field
     *
     * @param  mixed  $a  한쪽 값
     * @param  mixed  $b  다른 쪽 값
     * @return void 반환값 없음
     */
    #[DataProvider('differentProvider')]
    public function test_different_meaning_is_not_equal(mixed $a, mixed $b): void
    {
        $this->assertFalse(UserOverrideValueComparator::same($a, $b));
    }
}
