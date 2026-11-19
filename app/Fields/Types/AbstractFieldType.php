<?php

namespace App\Fields\Types;

use App\Fields\Contracts\FieldTypeInterface;
use App\Models\FieldDefinition;

/**
 * 항목 유형 공통 기반.
 *
 * 글 한 줄 모양(문자열 값 하나)을 기본 동작으로 두고, 유형별 클래스가 필요한 메서드만 바꾼다.
 *
 * @since 7.2.0
 */
abstract class AbstractFieldType implements FieldTypeInterface
{
    /** 색인 칸(value_index)에 담을 최대 글자 수 */
    protected const INDEX_MAX = 191;

    /**
     * 이 유형이 지원하는 기능 목록.
     *
     * @var array<int, string>
     */
    protected array $features = [self::FEATURE_SIGNUP];

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return __('fields.types.'.$this->key().'.label');
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return __('fields.types.'.$this->key().'.description');
    }

    /**
     * {@inheritDoc}
     */
    public function renderer(): string
    {
        return 'builtin';
    }

    /**
     * {@inheritDoc}
     */
    public function supports(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    /**
     * {@inheritDoc}
     */
    public function requiredAttributes(FieldDefinition $definition, string $attribute): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function inputPaths(FieldDefinition $definition, string $attribute): array
    {
        return [$attribute];
    }

    /**
     * {@inheritDoc}
     */
    public function extract(FieldDefinition $definition, string $attribute, array $data): mixed
    {
        return data_get($data, $attribute);
    }

    /**
     * {@inheritDoc}
     */
    public function normalize(FieldDefinition $definition, mixed $input): mixed
    {
        if ($input === null) {
            return null;
        }
        $value = is_scalar($input) ? trim((string) $input) : null;

        return $value === '' ? null : $value;
    }

    /**
     * {@inheritDoc}
     */
    public function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * {@inheritDoc}
     */
    public function serialize(FieldDefinition $definition, mixed $value): array
    {
        $text = $value === null ? null : (string) $value;

        return [
            'value_text' => $text,
            'value_index' => $text === null ? null : $this->indexOf($text),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function deserialize(FieldDefinition $definition, array $columns): mixed
    {
        return $columns['value_text'] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function display(FieldDefinition $definition, mixed $value, ?string $locale = null): ?string
    {
        return $this->isEmpty($value) || ! is_scalar($value) ? null : (string) $value;
    }

    /**
     * {@inheritDoc}
     */
    public function uniqueKey(FieldDefinition $definition, mixed $value): ?string
    {
        if (! $this->supports(self::FEATURE_UNIQUE) || $this->isEmpty($value) || ! is_scalar($value)) {
            return null;
        }

        return $this->indexOf((string) $value);
    }

    /**
     * {@inheritDoc}
     */
    public function constraintRules(): array
    {
        return [];
    }

    /**
     * 검색·정렬·중복 비교용 색인 문자열을 만듭니다 (NFKC, 소문자, 앞뒤 공백 제거, 길이 제한).
     *
     * @param  string  $text  원문
     * @return string 색인 문자열
     */
    protected function indexOf(string $text): string
    {
        $normalized = class_exists(\Normalizer::class) ? (\Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text) : $text;

        return mb_substr(mb_strtolower(trim($normalized)), 0, self::INDEX_MAX);
    }

    /**
     * 글 길이 제한 규칙을 만듭니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  int  $hardMax  유형이 허용하는 최대 길이
     * @return array<int, string> min·max 규칙
     */
    protected function lengthRules(FieldDefinition $definition, int $hardMax): array
    {
        $rules = [];
        $min = (int) $definition->constraint('min_length', 0);
        $max = (int) $definition->constraint('max_length', 0);
        if ($min > 0) {
            $rules[] = 'min:'.$min;
        }
        $rules[] = 'max:'.($max > 0 ? min($max, $hardMax) : $hardMax);

        return $rules;
    }

    /**
     * 선택지 값 목록을 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return array<int, string> 선택지 값
     */
    protected function optionValues(FieldDefinition $definition): array
    {
        $values = [];
        foreach ($definition->options ?? [] as $option) {
            if (is_array($option) && isset($option['value']) && is_scalar($option['value'])) {
                $values[] = (string) $option['value'];
            }
        }

        return $values;
    }

    /**
     * 선택지 값의 라벨을 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  string  $value  선택지 값
     * @param  string|null  $locale  로케일
     * @return string 라벨 (없으면 값 그대로)
     */
    protected function optionLabel(FieldDefinition $definition, string $value, ?string $locale = null): string
    {
        foreach ($definition->options ?? [] as $option) {
            if (is_array($option) && (string) ($option['value'] ?? '') === $value) {
                return FieldDefinition::localizeValue($option['label'] ?? null, $locale) ?? $value;
            }
        }

        return $value;
    }
}
