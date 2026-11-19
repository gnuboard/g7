<?php

namespace App\Fields\Types;

use App\Models\FieldDefinition;
use App\Models\FieldValue;
use Illuminate\Validation\Rule;

/**
 * 하나 선택(목록 또는 라디오).
 *
 * 「기타(직접 입력)」를 허용하면(`constraints.allow_other`) 값은 `{value: '__other__', other: '입력 글'}` 로 받는다.
 * 저장은 색인 칸에 `__other__`, 글 칸에 입력 글을 둔다 — 관리자 필터에서는 「기타」 한 묶음으로 센다.
 *
 * @since 7.2.0
 */
class ChoiceType extends AbstractFieldType
{
    /** 「기타」 입력 글의 최대 길이 */
    protected const OTHER_MAX = 100;

    protected array $features = [self::FEATURE_SIGNUP, self::FEATURE_SEARCH, self::FEATURE_LIST_COLUMN, self::FEATURE_OPTIONS];

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'choice';
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
        $values = $this->optionValues($definition);

        if (! $definition->constraint('allow_other', false)) {
            return [$attribute => ['string', Rule::in($values)]];
        }

        // 「기타」 허용: 문자열(선택지 값) 또는 {value, other} 둘 다 받는다.
        $allowed = [...$values, FieldValue::OTHER_INDEX];

        return [
            $attribute => [function (string $attr, mixed $value, \Closure $fail) use ($allowed) {
                $picked = is_array($value) ? ($value['value'] ?? null) : $value;
                if (! is_string($picked) || ! in_array($picked, $allowed, true)) {
                    $fail(__('validation.in', ['attribute' => $attr]));

                    return;
                }
                if ($picked === FieldValue::OTHER_INDEX) {
                    $other = is_array($value) ? trim((string) ($value['other'] ?? '')) : '';
                    if ($other === '') {
                        $fail(__('fields.validation.other_required'));
                    } elseif (mb_strlen($other) > self::OTHER_MAX) {
                        $fail(trans_choice('validation.max.string', self::OTHER_MAX, ['attribute' => $attr, 'max' => self::OTHER_MAX]));
                    }
                }
            }],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function normalize(FieldDefinition $definition, mixed $input): mixed
    {
        if (is_array($input)) {
            $picked = is_string($input['value'] ?? null) ? $input['value'] : '';
            if ($picked === '') {
                return null;
            }
            if ($picked === FieldValue::OTHER_INDEX) {
                return ['value' => $picked, 'other' => trim((string) ($input['other'] ?? ''))];
            }

            return $picked;
        }

        return parent::normalize($definition, $input);
    }

    /**
     * {@inheritDoc}
     */
    public function serialize(FieldDefinition $definition, mixed $value): array
    {
        if (is_array($value)) {
            return ['value_index' => FieldValue::OTHER_INDEX, 'value_text' => $value['other'] ?? null];
        }

        return ['value_index' => $value === null ? null : (string) $value, 'value_text' => null];
    }

    /**
     * {@inheritDoc}
     */
    public function deserialize(FieldDefinition $definition, array $columns): mixed
    {
        $index = $columns['value_index'] ?? null;
        if ($index === FieldValue::OTHER_INDEX) {
            return ['value' => $index, 'other' => $columns['value_text'] ?? ''];
        }

        return $index;
    }

    /**
     * {@inheritDoc}
     */
    public function display(FieldDefinition $definition, mixed $value, ?string $locale = null): ?string
    {
        if (is_array($value)) {
            $other = trim((string) ($value['other'] ?? ''));

            return $other === '' ? __('fields.choice.other', [], $locale) : $other;
        }
        if ($this->isEmpty($value)) {
            return null;
        }

        return $this->optionLabel($definition, (string) $value, $locale);
    }

    /**
     * {@inheritDoc}
     */
    public function constraintRules(): array
    {
        return [
            'constraints.display' => ['nullable', 'string', 'in:select,radio'],
            'constraints.allow_other' => ['nullable', 'boolean'],
        ];
    }
}
