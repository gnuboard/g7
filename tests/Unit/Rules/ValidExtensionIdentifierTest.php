<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidExtensionIdentifier;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValidExtensionIdentifierTest extends TestCase
{
    /**
     * 유효한 식별자 목록을 반환합니다.
     *
     * @return array<string, array{string}>
     */
    public static function validIdentifiersProvider(): array
    {
        return [
            '기본 형식' => ['sirsoft-board'],
            '언더스코어 포함' => ['sirsoft-daum_postcode'],
            '숫자 포함' => ['sirsoft-board2'],
            '다중 언더스코어' => ['sirsoft-my_fancy_module'],
            '벤더에 언더스코어' => ['my_vendor-my_module'],
            '긴 식별자' => ['some_vendor-very_long_module_name'],
            '숫자가 단어 중간에' => ['sirsoft-module2test'],
            '3단 하이픈' => ['sirsoft-sub-module'],
        ];
    }

    /**
     * 유효한 식별자가 통과하는지 테스트
     */
    #[DataProvider('validIdentifiersProvider')]
    public function test_valid_identifiers_pass(string $identifier): void
    {
        $validator = Validator::make(
            ['name' => $identifier],
            ['name' => [new ValidExtensionIdentifier]]
        );

        $this->assertTrue($validator->passes(), "식별자 '{$identifier}'는 유효해야 합니다");
    }

    /**
     * 무효한 식별자 목록을 반환합니다.
     *
     * @return array<string, array{string, string}>
     */
    public static function invalidIdentifiersProvider(): array
    {
        return [
            '하이픈 없음 (단일 단어)' => ['sirsoftboard', 'min_parts'],
            '숫자로 시작하는 단어' => ['sirsoft-2shop', 'word_starts_with_digit'],
            '대문자 포함' => ['Sirsoft-Board', 'invalid_characters'],
            '특수문자 포함 (@)' => ['sirsoft-my@module', 'invalid_characters'],
            '특수문자 포함 (.)' => ['sirsoft-my.module', 'invalid_characters'],
            '연속 하이픈' => ['sirsoft--board', 'empty_part'],
            '시작 하이픈' => ['-sirsoft-board', 'empty_part'],
            '끝 하이픈' => ['sirsoft-board-', 'empty_part'],
            '연속 언더스코어' => ['sirsoft-my__module', 'empty_word'],
            '시작 언더스코어' => ['sirsoft-_module', 'empty_word'],
            '끝 언더스코어' => ['sirsoft-module_', 'empty_word'],
            '언더스코어 뒤 숫자 시작' => ['sirsoft-foo_2bar', 'word_starts_with_digit'],
            '공백 포함' => ['sirsoft-my module', 'invalid_characters'],
        ];
    }

    /**
     * 무효한 식별자가 거부되는지 테스트
     */
    #[DataProvider('invalidIdentifiersProvider')]
    public function test_invalid_identifiers_fail(string $identifier, string $expectedMessageKey): void
    {
        $validator = Validator::make(
            ['name' => $identifier],
            ['name' => [new ValidExtensionIdentifier]]
        );

        $this->assertFalse($validator->passes(), "식별자 '{$identifier}'는 무효해야 합니다");

        // 에러 메시지가 예상 키를 포함하는지 확인
        $errorMessage = $validator->errors()->first('name');
        $expectedTranslation = __('validation.extension_identifier.'.$expectedMessageKey);
        $this->assertSame($expectedTranslation, $errorMessage, "식별자 '{$identifier}'의 에러 메시지가 '{$expectedMessageKey}' 키와 일치해야 합니다");
    }

    /**
     * 문자열이 아닌 값이 거부되는지 테스트
     */
    public function test_non_string_value_fails(): void
    {
        $validator = Validator::make(
            ['name' => 123],
            ['name' => [new ValidExtensionIdentifier]]
        );

        $this->assertFalse($validator->passes());
    }

    /**
     * 255자 초과 식별자가 거부되는지 테스트
     */
    public function test_too_long_identifier_fails(): void
    {
        $longIdentifier = 'sirsoft-'.str_repeat('a', 250);

        $validator = Validator::make(
            ['name' => $longIdentifier],
            ['name' => [new ValidExtensionIdentifier]]
        );

        $this->assertFalse($validator->passes());
    }
}
