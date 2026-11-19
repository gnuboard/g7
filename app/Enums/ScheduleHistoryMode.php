<?php

namespace App\Enums;

/**
 * 스케줄 실행 이력 기록 방식 Enum
 *
 * 스케줄 행의 이력 방식(history_mode 컬럼)을 정의합니다.
 */
enum ScheduleHistoryMode: string
{
    /**
     * 모든 실행을 기록
     */
    case All = 'all';

    /**
     * 실패한 실행만 기록 (30초 주기 등 잦은 작업용)
     */
    case FailuresOnly = 'failures_only';

    /**
     * 모든 값을 문자열 배열로 반환합니다.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 값인지 확인합니다.
     *
     * @param  string  $value  검증할 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 라벨
     */
    public function label(): string
    {
        return __('schedule.history_mode.'.$this->value);
    }
}
