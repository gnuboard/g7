<?php

namespace App\Fields\Types;

use App\Models\FieldDefinition;

/**
 * 날짜+시간.
 *
 * 브라우저의 `datetime-local` 값(`2026-11-11T09:30`)을 그대로 받는다.
 *
 * @since 7.2.0
 */
class DatetimeType extends DateType
{
    protected const FORMAT = 'Y-m-d\TH:i';

    protected array $features = [self::FEATURE_SIGNUP, self::FEATURE_LIST_COLUMN];

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'datetime';
    }

    /**
     * 보기용 값을 반환합니다.
     *
     * 저장한 값은 입력칸 형식(`2026-11-11T09:30`)이라 날짜와 시각 사이의 `T` 를 빈칸으로 바꿔 보인다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  mixed  $value  저장한 값
     * @param  string|null  $locale  로케일 (쓰지 않는다)
     * @return string|null 보기용 글자 (값이 없으면 null)
     */
    public function display(FieldDefinition $definition, mixed $value, ?string $locale = null): ?string
    {
        $text = parent::display($definition, $value, $locale);

        return $text === null ? null : str_replace('T', ' ', $text);
    }

    /**
     * {@inheritDoc}
     */
    protected function isValidBound(string $bound): bool
    {
        if (in_array($bound, [self::RELATIVE_TODAY, self::RELATIVE_AFTER_TODAY], true)) {
            return true;
        }

        // 경계는 날짜만으로도, 날짜+시간으로도 적을 수 있다.
        return $this->parse($bound) !== null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $bound) === 1;
    }
}
