<?php

namespace App\Fields\Types;

use App\Models\FieldDefinition;
use Illuminate\Support\Carbon;

/**
 * 날짜.
 *
 * 가장 이른 날·가장 늦은 날은 날짜(`2026-01-01`) 또는 상대 기준(`today`, `after_today`)으로 정한다.
 * 상대 기준의 「오늘」은 사이트 시간대의 오늘이다.
 *
 * @since 7.2.0
 */
class DateType extends AbstractFieldType
{
    /** 상대 기준: 오늘까지(오늘 포함) */
    public const RELATIVE_TODAY = 'today';

    /** 상대 기준: 오늘 이후(오늘 제외) */
    public const RELATIVE_AFTER_TODAY = 'after_today';

    /** 입력·저장 형식 */
    protected const FORMAT = 'Y-m-d';

    protected array $features = [self::FEATURE_SIGNUP, self::FEATURE_SEARCH, self::FEATURE_LIST_COLUMN];

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'date';
    }

    /**
     * {@inheritDoc}
     */
    public function category(): string
    {
        return 'datetime';
    }

    /**
     * {@inheritDoc}
     */
    public function rules(FieldDefinition $definition, string $attribute): array
    {
        return [$attribute => ['string', 'date_format:'.static::FORMAT, ...$this->boundRules($definition)]];
    }

    /**
     * {@inheritDoc}
     */
    public function serialize(FieldDefinition $definition, mixed $value): array
    {
        return [
            'value_date' => $value === null ? null : $this->parse((string) $value),
            'value_index' => $value === null ? null : (string) $value,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function deserialize(FieldDefinition $definition, array $columns): mixed
    {
        $date = $columns['value_date'] ?? null;

        return $date === null ? null : Carbon::parse($date)->format(static::FORMAT);
    }

    /**
     * {@inheritDoc}
     */
    public function constraintRules(): array
    {
        $bound = ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) {
            if (is_string($value) && $value !== '' && ! $this->isValidBound($value)) {
                $fail(__('fields.validation.date_bound_invalid'));
            }
        }];

        return ['constraints.min' => $bound, 'constraints.max' => $bound];
    }

    /**
     * 가장 이른 날·가장 늦은 날 규칙을 만듭니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return array<int, string> after_or_equal·before_or_equal 등의 규칙
     */
    protected function boundRules(FieldDefinition $definition): array
    {
        $rules = [];
        $min = $this->resolveBound($definition->constraint('min'));
        $max = $this->resolveBound($definition->constraint('max'));

        if ($min !== null) {
            $rules[] = ($min['exclusive'] ? 'after:' : 'after_or_equal:').$min['date'];
        }
        if ($max !== null) {
            $rules[] = ($max['exclusive'] ? 'before:' : 'before_or_equal:').$max['date'];
        }

        return $rules;
    }

    /**
     * 경계 값을 실제 날짜로 풉니다.
     *
     * `today` 는 오늘을 포함한 경계, `after_today` 는 오늘을 뺀 경계다 — 가장 이른 날에 쓰면 「내일부터」,
     * 가장 늦은 날에 쓰면 쓸 수 없는 값이므로 오늘 포함 경계로 본다.
     *
     * @param  mixed  $bound  날짜 문자열 또는 상대 기준
     * @return array{date: string, exclusive: bool}|null 풀린 경계 (없거나 잘못되면 null)
     */
    protected function resolveBound(mixed $bound): ?array
    {
        if (! is_string($bound) || $bound === '') {
            return null;
        }

        $today = Carbon::now(config('app.default_user_timezone', config('app.timezone')))->format('Y-m-d');
        if ($bound === self::RELATIVE_TODAY) {
            return ['date' => $today, 'exclusive' => false];
        }
        if ($bound === self::RELATIVE_AFTER_TODAY) {
            return ['date' => $today, 'exclusive' => true];
        }

        return $this->isValidBound($bound) ? ['date' => $bound, 'exclusive' => false] : null;
    }

    /**
     * 경계 값 형식이 맞는지 반환합니다.
     *
     * @param  string  $bound  날짜 문자열 또는 상대 기준
     * @return bool 맞으면 true
     */
    protected function isValidBound(string $bound): bool
    {
        if (in_array($bound, [self::RELATIVE_TODAY, self::RELATIVE_AFTER_TODAY], true)) {
            return true;
        }

        return $this->parse($bound) !== null;
    }

    /**
     * 입력 형식 문자열을 시각으로 바꿉니다.
     *
     * @param  string  $value  입력 형식 문자열
     * @return Carbon|null 시각 (형식이 다르면 null)
     */
    protected function parse(string $value): ?Carbon
    {
        try {
            $parsed = Carbon::createFromFormat('!'.static::FORMAT, $value);
        } catch (\Throwable) {
            return null;
        }

        return $parsed !== false && $parsed->format(static::FORMAT) === $value ? $parsed : null;
    }
}
