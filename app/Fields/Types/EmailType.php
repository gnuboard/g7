<?php

namespace App\Fields\Types;

use App\Models\FieldDefinition;

/**
 * 이메일 주소(추가 연락용 — 로그인 이메일과 별개).
 *
 * @since 7.2.0
 */
class EmailType extends AbstractFieldType
{
    protected array $features = [self::FEATURE_SIGNUP, self::FEATURE_UNIQUE, self::FEATURE_SEARCH, self::FEATURE_LIST_COLUMN];

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'email';
    }

    /**
     * {@inheritDoc}
     */
    public function category(): string
    {
        return 'contact';
    }

    /**
     * {@inheritDoc}
     */
    public function rules(FieldDefinition $definition, string $attribute): array
    {
        return [$attribute => ['string', 'email', 'max:255']];
    }
}
