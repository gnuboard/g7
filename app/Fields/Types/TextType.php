<?php

namespace App\Fields\Types;

use App\Models\FieldDefinition;
use App\Support\CountryName;

/**
 * 한 줄 글.
 *
 * @since 7.2.0
 */
class TextType extends AbstractFieldType
{
    /** 한 줄 글의 최대 길이 */
    protected const HARD_MAX = 255;

    protected array $features = [
        self::FEATURE_SIGNUP, self::FEATURE_UNIQUE, self::FEATURE_SEARCH,
        self::FEATURE_LIST_COLUMN, self::FEATURE_DISPLAY_NAME,
    ];

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'text';
    }

    /**
     * {@inheritDoc}
     */
    public function category(): string
    {
        return 'text';
    }

    /**
     * {@inheritDoc}
     */
    public function rules(FieldDefinition $definition, string $attribute): array
    {
        $rules = ['string', ...$this->lengthRules($definition, static::HARD_MAX)];

        $regex = $definition->constraint('regex');
        if (is_string($regex) && $regex !== '' && self::isValidRegex($regex)) {
            $rules[] = 'regex:'.$regex;
        }

        // 선언 전용 형식 — 코어 회원 시간대 칸이 쓴다(운영자 화면에는 없다).
        if ($definition->constraint('format') === 'timezone') {
            $rules[] = 'timezone';
        }

        return [$attribute => $rules];
    }

    /**
     * 보기용 값을 반환합니다.
     *
     * 국가 칸(선언 전용 `widget = country`)은 저장한 국가 코드가 아니라 국가 이름으로 보인다 — 그 밖의 글은 저장한 값 그대로다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  mixed  $value  저장한 값
     * @param  string|null  $locale  로케일 (생략하면 현재 로케일)
     * @return string|null 보기용 글자 (값이 없으면 null)
     */
    public function display(FieldDefinition $definition, mixed $value, ?string $locale = null): ?string
    {
        $text = parent::display($definition, $value, $locale);

        if ($text !== null && $definition->constraint('widget') === 'country') {
            return CountryName::resolve($text, $locale);
        }

        return $text;
    }

    /**
     * {@inheritDoc}
     */
    public function constraintRules(): array
    {
        return [
            'constraints.min_length' => ['nullable', 'integer', 'min:0', 'max:'.static::HARD_MAX],
            'constraints.max_length' => ['nullable', 'integer', 'min:1', 'max:'.static::HARD_MAX, 'gte:constraints.min_length'],
            'constraints.regex' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && $value !== '' && ! self::isValidRegex($value)) {
                    $fail(__('fields.validation.regex_invalid'));
                }
            }],
            'constraints.regex_message' => ['nullable', 'array'],
            'constraints.regex_message.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * 구분자를 포함한 정규식이 유효한지 반환합니다.
     *
     * @param  string  $pattern  정규식 (예: /^[a-z]+$/)
     * @return bool 유효하면 true
     */
    public static function isValidRegex(string $pattern): bool
    {
        // 구분자로 시작하지 않으면 Laravel regex 규칙이 예외를 던진다 — 저장 전에 걸러 낸다.
        if (! preg_match('/^([\/#~%@!]).*\1[imsxuADU]*$/s', $pattern)) {
            return false;
        }

        return @preg_match($pattern, '') !== false;
    }
}
