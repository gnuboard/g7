<?php

namespace Tests\Unit\Enums;

use App\Enums\LayoutMarkupErrorCode;
use Tests\TestCase;

/**
 * LayoutMarkupErrorCode ↔ 다국어 메시지 키 정합성 테스트.
 *
 * 에러 코드 값은 그대로 `exceptions.layout_markup.{value}` 메시지 키가 된다. 코드만
 * 추가하고 키를 빠뜨리면 저작자에게 번역문 대신 `exceptions.layout_markup.xxx` 라는
 * 키 문자열이 그대로 노출되는데, 이는 컴파일 오류를 만나야만 드러나는 침묵 결함이다.
 * S2 이후 단계가 코드를 누적하므로 그 드리프트를 여기서 기계로 막는다.
 */
class LayoutMarkupErrorCodeTest extends TestCase
{
    /**
     * @effects every_error_code_has_ko_and_en_messages
     */
    public function test_every_error_code_has_a_message_in_every_supported_locale(): void
    {
        $missing = [];

        foreach (config('app.supported_locales') as $locale) {
            $this->app->setLocale($locale);

            foreach (LayoutMarkupErrorCode::cases() as $code) {
                $key = 'exceptions.layout_markup.'.$code->value;

                // __() 는 키를 찾지 못하면 키 문자열을 그대로 돌려준다.
                if ($code->message() === $key) {
                    $missing[] = "{$locale}: {$key}";
                }
            }
        }

        $this->assertSame([], $missing, '다국어 메시지가 없는 에러 코드: '.implode(', ', $missing));
    }

    /**
     * @effects every_error_code_has_ko_and_en_messages
     */
    public function test_error_code_values_are_snake_case_and_unique(): void
    {
        $values = LayoutMarkupErrorCode::values();

        $this->assertSame($values, array_unique($values));

        foreach ($values as $value) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $value);
        }
    }
}
