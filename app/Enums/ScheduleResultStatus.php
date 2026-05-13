<?php

namespace App\Enums;

/**
 * 스케줄 실행 결과 상태 Enum
 *
 * 스케줄 작업의 실행 결과 상태를 정의합니다.
 */
enum ScheduleResultStatus: string
{
    /**
     * 성공
     */
    case Success = 'success';

    /**
     * 실패
     */
    case Failed = 'failed';

    /**
     * 실행 중
     */
    case Running = 'running';

    /**
     * 미실행
     */
    case Never = 'never';

    /**
     * 모든 상태 값을 문자열 배열로 반환합니다.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 상태 값인지 확인합니다.
     *
     * @param string $value 검증할 상태 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 상태의 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 상태 라벨
     */
    public function label(): string
    {
        return __('schedule.result.' . $this->value);
    }

    /**
     * 상태별 CSS 클래스(variant)를 반환합니다.
     *
     * @return string 스타일 variant
     */
    public function variant(): string
    {
        return match ($this) {
            self::Success => 'green',
            self::Failed => 'red',
            self::Running => 'yellow',
            self::Never => 'gray',
        };
    }
}
