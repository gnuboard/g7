<?php

namespace App\Fields\Types;

use App\Models\FieldDefinition;
use Illuminate\Support\Carbon;

/**
 * 기간(시작일과 종료일).
 *
 * 값은 `{start, end}` 이고 종료일은 시작일보다 빠를 수 없다.
 *
 * @since 7.2.0
 */
class DateRangeType extends DateType
{
    protected array $features = [self::FEATURE_SIGNUP];

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'date_range';
    }

    /**
     * {@inheritDoc}
     */
    public function rules(FieldDefinition $definition, string $attribute): array
    {
        $bounds = $this->boundRules($definition);

        return [
            $attribute => ['array:start,end'],
            $attribute.'.start' => ['nullable', 'required_with:'.$attribute.'.end', 'string', 'date_format:'.self::FORMAT, ...$bounds],
            $attribute.'.end' => ['nullable', 'required_with:'.$attribute.'.start', 'string', 'date_format:'.self::FORMAT, 'after_or_equal:'.$attribute.'.start', ...$bounds],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function requiredAttributes(FieldDefinition $definition, string $attribute): array
    {
        return [$attribute.'.start', $attribute.'.end'];
    }

    /**
     * {@inheritDoc}
     */
    public function normalize(FieldDefinition $definition, mixed $input): mixed
    {
        if (! is_array($input)) {
            return null;
        }

        $start = is_string($input['start'] ?? null) ? trim($input['start']) : '';
        $end = is_string($input['end'] ?? null) ? trim($input['end']) : '';

        return $start === '' || $end === '' ? null : ['start' => $start, 'end' => $end];
    }

    /**
     * {@inheritDoc}
     */
    public function serialize(FieldDefinition $definition, mixed $value): array
    {
        if (! is_array($value)) {
            return ['value_date' => null, 'value_date_end' => null, 'value_index' => null];
        }

        return [
            'value_date' => $this->parse((string) $value['start']),
            'value_date_end' => $this->parse((string) $value['end']),
            'value_index' => $value['start'],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function deserialize(FieldDefinition $definition, array $columns): mixed
    {
        $start = $columns['value_date'] ?? null;
        $end = $columns['value_date_end'] ?? null;
        if ($start === null || $end === null) {
            return null;
        }

        return [
            'start' => Carbon::parse($start)->format(self::FORMAT),
            'end' => Carbon::parse($end)->format(self::FORMAT),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function display(FieldDefinition $definition, mixed $value, ?string $locale = null): ?string
    {
        return is_array($value) && isset($value['start'], $value['end']) ? $value['start'].' ~ '.$value['end'] : null;
    }
}
