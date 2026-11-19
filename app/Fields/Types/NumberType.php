<?php

namespace App\Fields\Types;

use App\Models\FieldDefinition;

/**
 * 숫자.
 *
 * @since 7.2.0
 */
class NumberType extends AbstractFieldType
{
    /** 허용하는 절댓값 상한 (value_number 는 decimal(20,6)) */
    protected const ABS_MAX = 99999999999999;

    protected array $features = [self::FEATURE_SIGNUP, self::FEATURE_UNIQUE, self::FEATURE_SEARCH, self::FEATURE_LIST_COLUMN];

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'number';
    }

    /**
     * {@inheritDoc}
     */
    public function category(): string
    {
        return 'number';
    }

    /**
     * {@inheritDoc}
     */
    public function rules(FieldDefinition $definition, string $attribute): array
    {
        $decimals = (int) $definition->constraint('decimals', 0);

        // `1e5` 같은 지수 표기는 numeric 규칙을 통과하므로 자릿수 정규식으로 막는다.
        $rules = [$decimals > 0 ? 'regex:/^-?\d+(\.\d{1,'.$decimals.'})?$/' : 'regex:/^-?\d+$/', 'numeric'];

        $min = $definition->constraint('min');
        $max = $definition->constraint('max');
        $rules[] = 'min:'.(is_numeric($min) ? $min + 0 : -self::ABS_MAX);
        $rules[] = 'max:'.(is_numeric($max) ? $max + 0 : self::ABS_MAX);

        return [$attribute => $rules];
    }

    /**
     * {@inheritDoc}
     */
    public function normalize(FieldDefinition $definition, mixed $input): mixed
    {
        if ($input === null || $input === '' || ! is_numeric($input)) {
            return null;
        }

        return (int) $definition->constraint('decimals', 0) > 0 ? (float) $input : (int) $input;
    }

    /**
     * {@inheritDoc}
     */
    public function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * {@inheritDoc}
     */
    public function serialize(FieldDefinition $definition, mixed $value): array
    {
        return [
            'value_number' => $value,
            'value_index' => $value === null ? null : (string) $value,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function deserialize(FieldDefinition $definition, array $columns): mixed
    {
        $number = $columns['value_number'] ?? null;
        if ($number === null) {
            return null;
        }

        return (int) $definition->constraint('decimals', 0) > 0 ? (float) $number : (int) $number;
    }

    /**
     * {@inheritDoc}
     */
    public function display(FieldDefinition $definition, mixed $value, ?string $locale = null): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        $text = number_format((float) $value, (int) $definition->constraint('decimals', 0));
        $unit = FieldDefinition::localizeValue($definition->constraint('unit'), $locale);

        return $unit === null ? $text : $text.' '.$unit;
    }

    /**
     * {@inheritDoc}
     */
    public function constraintRules(): array
    {
        return [
            'constraints.min' => ['nullable', 'numeric', 'min:'.-self::ABS_MAX, 'max:'.self::ABS_MAX],
            'constraints.max' => ['nullable', 'numeric', 'min:'.-self::ABS_MAX, 'max:'.self::ABS_MAX, 'gte:constraints.min'],
            'constraints.decimals' => ['nullable', 'integer', 'min:0', 'max:6'],
            'constraints.unit' => ['nullable', 'array'],
            'constraints.unit.*' => ['nullable', 'string', 'max:20'],
        ];
    }
}
