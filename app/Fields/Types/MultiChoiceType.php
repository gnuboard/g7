<?php

namespace App\Fields\Types;

use App\Models\FieldDefinition;
use Illuminate\Validation\Rule;

/**
 * 여러 개 선택(체크박스 또는 목록).
 *
 * @since 7.2.0
 */
class MultiChoiceType extends AbstractFieldType
{
    protected array $features = [self::FEATURE_SIGNUP, self::FEATURE_LIST_COLUMN, self::FEATURE_OPTIONS];

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'multi_choice';
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
        $rules = ['array'];
        $min = (int) $definition->constraint('min_select', 0);
        $max = (int) $definition->constraint('max_select', 0);
        if ($min > 0) {
            $rules[] = 'min:'.$min;
        }
        if ($max > 0) {
            $rules[] = 'max:'.$max;
        }

        return [
            $attribute => $rules,
            $attribute.'.*' => ['string', 'distinct', Rule::in($this->optionValues($definition))],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function normalize(FieldDefinition $definition, mixed $input): mixed
    {
        if (! is_array($input)) {
            return null;
        }

        // 선택지 선언 순서로 맞춘다 — 같은 선택이면 저장 값도 같게.
        $picked = array_map('strval', array_filter($input, 'is_scalar'));
        $ordered = array_values(array_intersect($this->optionValues($definition), $picked));

        return $ordered === [] ? null : $ordered;
    }

    /**
     * {@inheritDoc}
     */
    public function serialize(FieldDefinition $definition, mixed $value): array
    {
        return [
            'value_json' => is_array($value) ? array_values($value) : null,
            'value_index' => is_array($value) ? mb_substr(implode(',', $value), 0, self::INDEX_MAX) : null,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function deserialize(FieldDefinition $definition, array $columns): mixed
    {
        $json = $columns['value_json'] ?? null;
        if (is_string($json)) {
            $json = json_decode($json, true);
        }

        return is_array($json) && $json !== [] ? array_values($json) : null;
    }

    /**
     * {@inheritDoc}
     */
    public function display(FieldDefinition $definition, mixed $value, ?string $locale = null): ?string
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return implode(', ', array_map(fn ($item) => $this->optionLabel($definition, (string) $item, $locale), $value));
    }

    /**
     * {@inheritDoc}
     */
    public function constraintRules(): array
    {
        return [
            'constraints.display' => ['nullable', 'string', 'in:checkbox,select'],
            'constraints.min_select' => ['nullable', 'integer', 'min:0', 'max:50'],
            'constraints.max_select' => ['nullable', 'integer', 'min:1', 'max:50', 'gte:constraints.min_select'],
        ];
    }
}
