<?php

namespace App\Enums;

/**
 * 스케줄 실행 주기 Enum
 *
 * 스케줄 작업의 실행 주기를 정의합니다.
 */
enum ScheduleFrequency: string
{
    /**
     * 매분
     */
    case EveryMinute = 'everyMinute';

    /**
     * 매시간
     */
    case Hourly = 'hourly';

    /**
     * 매일
     */
    case Daily = 'daily';

    /**
     * 매주
     */
    case Weekly = 'weekly';

    /**
     * 매월
     */
    case Monthly = 'monthly';

    /**
     * 사용자 정의
     */
    case Custom = 'custom';

    /**
     * 모든 주기 값을 문자열 배열로 반환합니다.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 주기 값인지 확인합니다.
     *
     * @param string $value 검증할 주기 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 주기의 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 주기 라벨
     */
    public function label(): string
    {
        return __('schedule.frequency.' . $this->value);
    }

    /**
     * 주기에 해당하는 기본 Cron 표현식을 반환합니다.
     *
     * @return string Cron 표현식
     */
    public function defaultExpression(): string
    {
        return match ($this) {
            self::EveryMinute => '* * * * *',
            self::Hourly => '0 * * * *',
            self::Daily => '0 0 * * *',
            self::Weekly => '0 0 * * 0',
            self::Monthly => '0 0 1 * *',
            self::Custom => '* * * * *',
        };
    }
}
