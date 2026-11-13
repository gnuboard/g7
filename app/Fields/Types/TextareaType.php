<?php

namespace App\Fields\Types;

use App\Models\FieldDefinition;

/**
 * 여러 줄 글.
 *
 * @since 7.2.0
 */
class TextareaType extends AbstractFieldType
{
    /** 여러 줄 글의 최대 길이 */
    protected const HARD_MAX = 5000;

    protected array $features = [self::FEATURE_SIGNUP, self::FEATURE_SEARCH];

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'textarea';
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
        return [$attribute => ['string', ...$this->lengthRules($definition, self::HARD_MAX)]];
    }

    /**
     * {@inheritDoc}
     */
    public function constraintRules(): array
    {
        return [
            'constraints.min_length' => ['nullable', 'integer', 'min:0', 'max:'.self::HARD_MAX],
            'constraints.max_length' => ['nullable', 'integer', 'min:1', 'max:'.self::HARD_MAX, 'gte:constraints.min_length'],
            'constraints.rows' => ['nullable', 'integer', 'min:2', 'max:20'],
        ];
    }
}
