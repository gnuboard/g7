<?php

namespace App\Enums;

/**
 * 스케줄 실행 방식 Enum
 *
 * 스케줄 행의 실행 방식(dispatch_mode 컬럼)을 정의합니다.
 */
enum ScheduleDispatchMode: string
{
    /**
     * 스케줄러 프로세스 안에서 바로 실행 (워커 없이 동작)
     */
    case Sync = 'sync';

    /**
     * 큐 Job 으로 보내 워커가 실행
     */
    case Queue = 'queue';

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
        return __('schedule.dispatch_mode.'.$this->value);
    }
}
