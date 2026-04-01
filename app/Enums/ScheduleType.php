<?php

namespace App\Enums;

/**
 * 스케줄 작업 유형 Enum
 *
 * 스케줄 작업의 실행 유형을 정의합니다.
 */
enum ScheduleType: string
{
    /**
     * Artisan 커맨드
     */
    case Artisan = 'artisan';

    /**
     * 쉘 명령
     */
    case Shell = 'shell';

    /**
     * URL 호출
     */
    case Url = 'url';

    /**
     * 모든 유형 값을 문자열 배열로 반환합니다.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 유형 값인지 확인합니다.
     *
     * @param string $value 검증할 유형 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 유형의 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 유형 라벨
     */
    public function label(): string
    {
        return __('schedule.type.' . $this->value);
    }

    /**
     * 유형별 CSS 클래스(variant)를 반환합니다.
     *
     * @return string 스타일 variant
     */
    public function variant(): string
    {
        return match ($this) {
            self::Artisan => 'blue',
            self::Shell => 'gray',
            self::Url => 'indigo',
        };
    }
}
