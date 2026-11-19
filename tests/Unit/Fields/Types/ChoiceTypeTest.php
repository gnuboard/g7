<?php

namespace Tests\Unit\Fields\Types;

use App\Models\FieldValue;

/**
 * 선택 유형(하나 선택, 여러 개 선택, 예/아니오)과 숫자 유형의 규칙과 저장 왕복.
 */
class ChoiceTypeTest extends FieldTypeTestCase
{
    /** 표본 선택지 */
    private const OPTIONS = [
        ['value' => 'student', 'label' => ['ko' => '학생', 'en' => 'Student']],
        ['value' => 'worker', 'label' => ['ko' => '직장인', 'en' => 'Worker']],
        ['value' => 'etc', 'label' => ['ko' => '그 밖']],
    ];

    /**
     * 하나 선택은 선택지에 있는 값만 받고, 보기용 문자열은 로케일별 라벨이다.
     *
     * @scenario field_type=choice
     *
     * @effects field_type_rules_reject_invalid_input, field_type_value_roundtrip
     *
     * @return void 반환값 없음
     */
    public function test_choice_accepts_only_declared_options(): void
    {
        $definition = $this->definition('choice', [], self::OPTIONS);
        $type = $this->type('choice');

        $this->assertTrue($this->passes($definition, 'student'));
        $this->assertFalse($this->passes($definition, 'hacker'), '선택지에 없는 값이 통과했다');
        $this->assertFalse($this->passes($definition, FieldValue::OTHER_INDEX), '기타를 허용하지 않았는데 기타 값이 통과했다');
        $this->assertSame('student', $this->roundTrip($definition, 'student'));
        $this->assertSame('학생', $type->display($definition, 'student', 'ko'));
        $this->assertSame('Student', $type->display($definition, 'student', 'en'));
        $this->assertSame('그 밖', $type->display($definition, 'etc', 'en'), '입력하지 않은 로케일이 기본 로케일 글자로 대체되지 않았다');
    }

    /**
     * 「기타(직접 입력)」를 허용하면 기타 값과 입력 글을 함께 받고 색인은 한 묶음으로 저장한다.
     *
     * @scenario field_type=choice
     *
     * @effects field_type_choice_other_stored_as_single_bucket
     *
     * @return void 반환값 없음
     */
    public function test_choice_allow_other_stores_text_under_other_bucket(): void
    {
        $definition = $this->definition('choice', ['allow_other' => true], self::OPTIONS);
        $type = $this->type('choice');
        $other = ['value' => FieldValue::OTHER_INDEX, 'other' => ' 프리랜서 '];

        $this->assertTrue($this->passes($definition, 'worker'), '기타 허용 항목이 일반 선택지를 거부했다');
        $this->assertTrue($this->passes($definition, $other));
        $this->assertFalse($this->passes($definition, ['value' => FieldValue::OTHER_INDEX, 'other' => '  ']), '내용 없는 기타가 통과했다');
        $this->assertFalse($this->passes($definition, ['value' => FieldValue::OTHER_INDEX, 'other' => str_repeat('가', 101)]), '기타 글 100자 상한이 적용되지 않았다');

        $normalized = $type->normalize($definition, $other);
        $columns = $type->serialize($definition, $normalized);
        $this->assertSame(FieldValue::OTHER_INDEX, $columns['value_index']);
        $this->assertSame('프리랜서', $columns['value_text']);
        $this->assertSame(['value' => FieldValue::OTHER_INDEX, 'other' => '프리랜서'], $this->roundTrip($definition, $other));
        $this->assertSame('프리랜서', $type->display($definition, $normalized));
    }

    /**
     * 여러 개 선택은 선택지 값만 받고 최소·최대 개수를 지키며 선언 순서로 저장한다.
     *
     * @scenario field_type=multi_choice
     *
     * @effects field_type_rules_reject_invalid_input, field_type_value_roundtrip
     *
     * @return void 반환값 없음
     */
    public function test_multi_choice_counts_and_declared_order(): void
    {
        $definition = $this->definition('multi_choice', ['min_select' => 1, 'max_select' => 2], self::OPTIONS);
        $type = $this->type('multi_choice');

        $this->assertTrue($this->passes($definition, ['worker', 'student']));
        $this->assertFalse($this->passes($definition, []), '최소 개수 미만이 통과했다');
        $this->assertFalse($this->passes($definition, ['worker', 'student', 'etc']), '최대 개수 초과가 통과했다');
        $this->assertFalse($this->passes($definition, ['worker', 'nope']), '선택지에 없는 값이 통과했다');
        $this->assertFalse($this->passes($definition, ['worker', 'worker']), '같은 값 두 번이 통과했다');
        $this->assertSame(['student', 'worker'], $this->roundTrip($definition, ['worker', 'student']), '선택지 선언 순서로 정리되지 않았다');
        $this->assertSame('학생, 직장인', $type->display($definition, ['student', 'worker'], 'ko'));
        $this->assertNull($type->normalize($definition, []), '빈 선택이 빈 값이 되지 않았다');
    }

    /**
     * 예/아니오는 불리언만 받고, 「아니오」는 저장하되 필수 판정에서는 빈 값이다.
     *
     * @scenario field_type=boolean
     *
     * @effects field_type_rules_reject_invalid_input, field_type_value_roundtrip
     *
     * @return void 반환값 없음
     */
    public function test_boolean_false_is_stored_but_counts_as_empty(): void
    {
        $definition = $this->definition('boolean');
        $type = $this->type('boolean');

        $this->assertTrue($this->passes($definition, true));
        $this->assertTrue($this->passes($definition, '0'));
        $this->assertFalse($this->passes($definition, 'maybe'));
        $this->assertTrue($this->roundTrip($definition, '1'));
        $this->assertFalse($this->roundTrip($definition, false), '「아니오」가 저장 왕복에서 사라졌다');
        $this->assertTrue($type->isEmpty(false), '「아니오」가 필수 판정에서 채워진 값으로 취급됐다');
        $this->assertFalse($type->isEmpty(true));
        $this->assertSame('예', $type->display($definition, true, 'ko'));
    }

    /**
     * 숫자는 범위와 소수 자리를 지키고 지수 표기를 받지 않는다.
     *
     * @scenario field_type=number
     *
     * @effects field_type_rules_reject_invalid_input, field_type_value_roundtrip
     *
     * @return void 반환값 없음
     */
    public function test_number_range_decimals_and_exponent(): void
    {
        $integer = $this->definition('number', ['min' => 1, 'max' => 150]);
        $decimal = $this->definition('number', ['decimals' => 2, 'unit' => ['ko' => 'kg']]);
        $type = $this->type('number');

        $this->assertTrue($this->passes($integer, '42'));
        $this->assertFalse($this->passes($integer, '0'), '최솟값 미만이 통과했다');
        $this->assertFalse($this->passes($integer, '151'), '최댓값 초과가 통과했다');
        $this->assertFalse($this->passes($integer, '1e2'), '지수 표기가 통과했다');
        $this->assertFalse($this->passes($integer, '4.5'), '소수 자리를 정하지 않은 항목이 소수를 받았다');
        $this->assertTrue($this->passes($decimal, '70.25'));
        $this->assertFalse($this->passes($decimal, '70.255'), '소수 자리 초과가 통과했다');
        $this->assertSame(42, $this->roundTrip($integer, '42'));
        $this->assertSame(70.25, $this->roundTrip($decimal, '70.25'));
        $this->assertSame(0, $this->roundTrip($integer, '0'), '0 이 빈 값으로 사라졌다');
        $this->assertFalse($type->isEmpty(0), '0 이 필수 판정에서 빈 값으로 취급됐다');
        $this->assertSame('70.25 kg', $type->display($decimal, 70.25, 'ko'));
    }
}
