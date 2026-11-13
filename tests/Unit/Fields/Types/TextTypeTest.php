<?php

namespace Tests\Unit\Fields\Types;

use App\Fields\Contracts\FieldTypeInterface;
use App\Fields\Types\TextType;
use App\Support\CountryName;
use Illuminate\Support\Facades\Validator;

/**
 * 글 유형(한 줄 글, 여러 줄 글)과 연락처 유형(전화, 이메일, URL)의 규칙과 저장 왕복.
 */
class TextTypeTest extends FieldTypeTestCase
{
    /**
     * 한 줄 글은 길이 제한과 운영자 정규식을 적용하고 값을 그대로 되살린다.
     *
     * @scenario field_type=text
     *
     * @effects field_type_rules_reject_invalid_input, field_type_value_roundtrip
     *
     * @return void 반환값 없음
     */
    public function test_text_applies_length_and_regex(): void
    {
        $definition = $this->definition('text', ['min_length' => 2, 'max_length' => 5, 'regex' => '/^[a-z]+$/']);

        $this->assertTrue($this->passes($definition, 'abc'));
        $this->assertFalse($this->passes($definition, 'a'), '최소 길이 미만이 통과했다');
        $this->assertFalse($this->passes($definition, 'abcdef'), '최대 길이 초과가 통과했다');
        $this->assertFalse($this->passes($definition, 'ab1'), '정규식에 맞지 않는 값이 통과했다');
        $this->assertSame('abc', $this->roundTrip($definition, '  abc  '), '앞뒤 공백이 정리되지 않았다');
        $this->assertNull($this->roundTrip($definition, '   '), '공백만 있는 값이 빈 값으로 정리되지 않았다');
    }

    /**
     * 한 줄 글의 최대 길이는 255자를 넘지 못하고, 중복 비교 키는 전각·대소문자를 한 모양으로 맞춘다.
     *
     * @scenario field_type=text
     *
     * @effects field_type_rules_reject_invalid_input, field_type_unique_key_normalized
     *
     * @return void 반환값 없음
     */
    public function test_text_hard_max_and_unique_key(): void
    {
        $definition = $this->definition('text', ['max_length' => 9999, 'unique' => true]);
        $type = $this->type('text');

        $this->assertTrue($this->passes($definition, str_repeat('가', 255)));
        $this->assertFalse($this->passes($definition, str_repeat('가', 256)), '255자 상한이 적용되지 않았다');
        $this->assertSame($type->uniqueKey($definition, 'ＡＢＣ'), $type->uniqueKey($definition, ' abc '), '전각·대소문자·공백이 같은 키가 되지 않았다');
        $this->assertTrue($type->supports(FieldTypeInterface::FEATURE_DISPLAY_NAME), '한 줄 글은 표시 이름으로 쓸 수 있어야 한다');
    }

    /**
     * 국가 칸(widget=country)의 보기용 값은 코드가 아니라 국가 이름이다(내 정보 보기가 종전에 이름으로 보였다).
     *
     * @scenario field_type=text
     *
     * @effects field_type_country_widget_displays_name
     *
     * @return void 반환값 없음
     */
    public function test_country_widget_displays_country_name(): void
    {
        $type = $this->type('text');
        $country = $this->definition('text', ['widget' => 'country']);

        $this->assertSame(CountryName::resolve('US', 'ko'), $type->display($country, 'US', 'ko'));
        $this->assertNotSame('US', $type->display($country, 'US', 'ko'), '국가 코드가 그대로 보였다');
        $this->assertNull($type->display($country, null, 'ko'));
        $this->assertSame('US', $type->display($this->definition('text'), 'US', 'ko'), '국가 칸이 아닌 글 값이 바뀌었다');
    }

    /**
     * 구분자가 없거나 깨진 정규식은 유효하지 않다고 판정한다(저장 전에 걸러 낸다).
     *
     * @scenario field_type=text
     *
     * @effects field_type_constraint_rules_guard_definition
     *
     * @return void 반환값 없음
     */
    public function test_text_rejects_broken_regex_constraint(): void
    {
        $this->assertTrue(TextType::isValidRegex('/^\d{3}$/'));
        $this->assertFalse(TextType::isValidRegex('^\d{3}$'), '구분자 없는 정규식이 유효로 판정됐다');
        $this->assertFalse(TextType::isValidRegex('/([a-z/'), '닫히지 않은 묶음이 유효로 판정됐다');

        $rules = $this->type('text')->constraintRules();
        $validator = Validator::make(['constraints' => ['regex' => '[a-z]+']], $rules);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('constraints.regex', $validator->errors()->toArray());
    }

    /**
     * 선언 전용 형식(시간대)은 시간대 이름만 받는다 — 코어 회원 시간대 칸이 쓴다.
     *
     * @scenario field_type=text
     *
     * @effects field_type_rules_reject_invalid_input
     *
     * @return void 반환값 없음
     */
    public function test_text_timezone_format(): void
    {
        $definition = $this->definition('text', ['max_length' => 64, 'format' => 'timezone']);

        $this->assertTrue($this->passes($definition, 'Asia/Seoul'));
        $this->assertFalse($this->passes($definition, 'Mars/Olympus'));
    }

    /**
     * 여러 줄 글은 5000자까지 받고 표시 이름으로는 쓸 수 없다.
     *
     * @scenario field_type=textarea
     *
     * @effects field_type_rules_reject_invalid_input, field_type_value_roundtrip
     *
     * @return void 반환값 없음
     */
    public function test_textarea_length_and_features(): void
    {
        $definition = $this->definition('textarea', ['max_length' => 10]);
        $type = $this->type('textarea');

        $this->assertTrue($this->passes($definition, "첫 줄\n둘째 줄"));
        $this->assertFalse($this->passes($definition, str_repeat('a', 11)));
        $this->assertFalse($this->passes($this->definition('textarea'), str_repeat('a', 5001)), '5000자 상한이 적용되지 않았다');
        $this->assertSame("첫 줄\n둘째 줄", $this->roundTrip($definition, "첫 줄\n둘째 줄"));
        $this->assertFalse($type->supports(FieldTypeInterface::FEATURE_DISPLAY_NAME));
        $this->assertFalse($type->supports(FieldTypeInterface::FEATURE_UNIQUE));
    }

    /**
     * 전화번호는 회원 휴대폰 칸의 기존 형식을 쓰고, 중복 비교는 숫자만으로 한다.
     *
     * @scenario field_type=phone
     *
     * @effects field_type_rules_reject_invalid_input, field_type_unique_key_normalized
     *
     * @return void 반환값 없음
     */
    public function test_phone_format_and_digit_only_unique_key(): void
    {
        $definition = $this->definition('phone', ['unique' => true]);
        $type = $this->type('phone');

        $this->assertTrue($this->passes($definition, '+82 (10) 1234-5678'));
        $this->assertFalse($this->passes($definition, '010-1234-abcd'));
        $this->assertFalse($this->passes($definition, str_repeat('1', 21)), '20자 상한이 적용되지 않았다');
        $this->assertSame('01012345678', $type->uniqueKey($definition, '010-1234-5678'));
        $this->assertSame('010-1234-5678', $this->roundTrip($definition, '010-1234-5678'));
    }

    /**
     * 이메일 항목은 이메일 형식만 받는다.
     *
     * @scenario field_type=email
     *
     * @effects field_type_rules_reject_invalid_input, field_type_value_roundtrip
     *
     * @return void 반환값 없음
     */
    public function test_email_format(): void
    {
        $definition = $this->definition('email');

        $this->assertTrue($this->passes($definition, 'sub@example.com'));
        $this->assertFalse($this->passes($definition, 'not-an-email'));
        $this->assertSame('sub@example.com', $this->roundTrip($definition, 'sub@example.com'));
    }

    /**
     * URL 항목은 http·https 만 받는다. 코어 홈페이지 칸은 종전 규칙대로 다른 스킴도 받는다.
     *
     * @scenario field_type=url
     *
     * @effects field_type_rules_reject_invalid_input, field_type_value_roundtrip
     *
     * @return void 반환값 없음
     */
    public function test_url_restricts_scheme_except_legacy_homepage(): void
    {
        $definition = $this->definition('url');

        $this->assertTrue($this->passes($definition, 'https://example.com/a?b=1'));
        $this->assertFalse($this->passes($definition, 'javascript:alert(1)'), 'javascript 스킴이 통과했다');
        $this->assertFalse($this->passes($definition, 'ftp://example.com'), '운영자 추가 URL 항목이 ftp 를 받았다');
        $this->assertTrue($this->passes($this->definition('url', ['schemes' => '*']), 'ftp://example.com'), '코어 홈페이지 칸의 종전 규칙이 바뀌었다');
        $this->assertSame('https://example.com', $this->roundTrip($definition, 'https://example.com'));
    }
}
