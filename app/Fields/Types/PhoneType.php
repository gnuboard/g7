<?php

namespace App\Fields\Types;

use App\Models\FieldDefinition;

/**
 * 전화번호.
 *
 * 형식은 회원 휴대폰·전화 칸의 기존 규칙(숫자, 하이픈, 더하기, 괄호, 공백, 20자)과 같다.
 *
 * @since 7.2.0
 */
class PhoneType extends AbstractFieldType
{
    /** 전화번호 형식 — 회원 요청의 기존 규칙과 같은 식 */
    public const PATTERN = '/^[0-9\-\+\(\)\s]+$/';

    protected array $features = [self::FEATURE_SIGNUP, self::FEATURE_UNIQUE, self::FEATURE_SEARCH, self::FEATURE_LIST_COLUMN];

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'phone';
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
        return [$attribute => ['string', 'max:20', 'regex:'.self::PATTERN]];
    }

    /**
     * {@inheritDoc}
     *
     * 중복 비교는 숫자만 남겨서 한다 (`010-1234-5678` 과 `01012345678` 은 같은 번호).
     */
    public function uniqueKey(FieldDefinition $definition, mixed $value): ?string
    {
        if ($this->isEmpty($value) || ! is_scalar($value)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits === '' || $digits === null ? null : $digits;
    }
}
