<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\Str;
use Illuminate\Translation\MessageSelector;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;

/**
 * 규칙값으로 단·복수를 고르는 검증 문구 치환기.
 *
 * Laravel 검증기는 문구를 `translator->get()` 으로만 가져오고 수로 고르지 않는다. 그래서 영어 표준 문구
 * (`validation.min.array` 등)를 늘 복수로 적으면 `min:1` 에서 「at least 1 items」 가 나온다. 이 치환기는
 * 수가 들어가는 규칙(`min`·`max`·`size`·`gt`·`gte`·`lt`·`lte`·`digits`·`min_digits`·`max_digits`·`decimal`)의
 * 문구에 `|` 가 있으면(영어 `단수|복수`) 화면에 넣을 규칙값으로 한 문장을 고른 뒤, Laravel 기본 치환을 그대로 한다.
 * `|` 가 없는 문구(한국어·일본어, 이미 한 문장으로 고른 커스텀 문구)는 기본 치환만 한다.
 *
 * 범위(`between`·`digits_between`)는 「:min ~ :max 개」 가 늘 여럿을 가리켜 고르지 않는다.
 * 확장이 같은 규칙에 `Validator::replacer()` 를 따로 등록하면 그 치환기가 이것을 대신한다.
 */
class ValidationPluralMessages
{
    /**
     * 규칙 => 그 규칙의 수 자리표시자.
     *
     * @var array<string, string>
     */
    public const RULE_PLACEHOLDERS = [
        'min' => ':min',
        'max' => ':max',
        'size' => ':size',
        'gt' => ':value',
        'gte' => ':value',
        'lt' => ':value',
        'lte' => ':value',
        'digits' => ':digits',
        'min_digits' => ':min',
        'max_digits' => ':max',
        'decimal' => ':decimal',
    ];

    /**
     * 검증 팩토리에 규칙별 치환기를 등록합니다.
     *
     * @param  Factory  $factory  검증 팩토리
     * @return void 반환값 없음
     */
    public static function register(Factory $factory): void
    {
        $replacer = new self;

        foreach (array_keys(self::RULE_PLACEHOLDERS) as $rule) {
            $factory->replacer($rule, $replacer->replace(...));
        }
    }

    /**
     * 문구의 `단수|복수` 를 규칙값으로 고르고 Laravel 기본 치환을 합니다.
     *
     * @param  string  $message  `:attribute` 등이 치환된 검증 문구
     * @param  string  $attribute  검증 대상 필드
     * @param  string  $rule  규칙 이름 (snake_case — 예: `min_digits`)
     * @param  array<int, string>  $parameters  규칙 인자
     * @param  Validator  $validator  검증기
     * @return string 치환된 문구
     */
    public function replace(string $message, string $attribute, string $rule, array $parameters, Validator $validator): string
    {
        $builtIn = 'replace'.Str::studly($rule);

        if (str_contains($message, '|')) {
            $shown = $rule === 'decimal'
                ? (string) ($parameters[1] ?? $parameters[0] ?? '')
                : $this->callBuiltIn($validator, $builtIn, self::RULE_PLACEHOLDERS[$rule], $attribute, $rule, $parameters);

            // 수를 알 수 없으면(비교 대상이 값 없는 필드 이름 등) 복수 문장을 쓴다.
            $number = is_numeric($shown) ? $shown + 0 : 2;
            $message = $this->selector($validator)->choose($message, $number, $validator->getTranslator()->getLocale());
        }

        return $this->callBuiltIn($validator, $builtIn, $message, $attribute, $rule, $parameters);
    }

    /**
     * 검증기의 Laravel 기본 치환 메서드(`replaceMin` 등, protected)를 부릅니다.
     *
     * @param  Validator  $validator  검증기
     * @param  string  $method  기본 치환 메서드 이름
     * @param  string  $message  치환할 문구
     * @param  string  $attribute  검증 대상 필드
     * @param  string  $rule  규칙 이름
     * @param  array<int, string>  $parameters  규칙 인자
     * @return string 치환된 문구
     */
    private function callBuiltIn(Validator $validator, string $method, string $message, string $attribute, string $rule, array $parameters): string
    {
        $call = Closure::bind(
            function (string $method, string $message, string $attribute, string $rule, array $parameters): string {
                return (string) $this->{$method}($message, $attribute, $rule, $parameters);
            },
            $validator,
            $validator::class
        );

        return $call($method, $message, $attribute, $rule, $parameters);
    }

    /**
     * 번역기와 같은 복수형 선택기를 돌려줍니다.
     *
     * @param  Validator  $validator  검증기
     * @return MessageSelector 복수형 선택기
     */
    private function selector(Validator $validator): MessageSelector
    {
        $translator = $validator->getTranslator();

        return $translator instanceof Translator ? $translator->getSelector() : new MessageSelector;
    }
}
