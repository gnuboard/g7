<?php

namespace App\Enums\Analytics;

/**
 * 배치 실행 상태 Enum
 */
enum ArchiveRunStatus: string
{
    /**
     * 실행 중
     */
    case Running = 'running';

    /**
     * 성공
     */
    case Success = 'success';

    /**
     * 실패
     */
    case Failed = 'failed';

    /**
     * 건너뜀 — 같은 작업이 이미 돌고 있었다
     */
    case Skipped = 'skipped';

    /**
     * 모든 값을 문자열 배열로 반환합니다.
     *
     * @return array<string> 값 목록
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
        return __('analytics.archive_run_status.'.$this->value);
    }
}
