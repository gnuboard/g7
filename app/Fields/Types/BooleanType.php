<?php

namespace App\Fields\Types;

use App\Models\FieldDefinition;

/**
 * 예/아니오.
 *
 * 필수로 두면 「예」여야 통과한다(동의 칸처럼 쓴다).
 *
 * @since 7.2.0
 */
class BooleanType extends AbstractFieldType
{
    protected array $features = [self::FEATURE_SIGNUP, self::FEATURE_SEARCH, self::FEATURE_LIST_COLUMN];

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'boolean';
    }

    /**
     * {@inheritDoc}
     */
    public function category(): string
    {
        return 'choice';
    }

    /**
     * {@inheritDoc}
     */
    public function rules(FieldDefinition $definition, string $attribute): array
    {
        return [$attribute => ['boolean']];
    }

    /**
     * {@inheritDoc}
     */
    public function normalize(FieldDefinition $definition, mixed $input): mixed
    {
        if ($input === null || $input === '') {
            return null;
        }

        return filter_var($input, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * {@inheritDoc}
     *
     * 「아니오」는 입력하지 않은 것으로 본다 — 필수 판정에서 「예」만 채워진 값이다.
     */
    public function isEmpty(mixed $value): bool
    {
        return $value !== true;
    }

    /**
     * {@inheritDoc}
     */
    public function serialize(FieldDefinition $definition, mixed $value): array
    {
        return [
            'value_number' => $value === null ? null : ($value ? 1 : 0),
            'value_index' => $value === null ? null : ($value ? '1' : '0'),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function deserialize(FieldDefinition $definition, array $columns): mixed
    {
        $number = $columns['value_number'] ?? null;

        return $number === null ? null : ((float) $number) > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function display(FieldDefinition $definition, mixed $value, ?string $locale = null): ?string
    {
        if ($value === null) {
            return null;
        }

        return __($value ? 'fields.boolean.yes' : 'fields.boolean.no', [], $locale);
    }

    /**
     * {@inheritDoc}
     */
    public function constraintRules(): array
    {
        return [
            'constraints.widget' => ['nullable', 'string', 'in:toggle,checkbox'],
            'constraints.inline_label' => ['nullable', 'array'],
            'constraints.inline_label.*' => ['nullable', 'string', 'max:255'],
            'constraints.default' => ['nullable', 'boolean'],
        ];
    }
}
