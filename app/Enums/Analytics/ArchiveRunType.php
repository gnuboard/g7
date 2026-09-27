<?php

namespace App\Enums\Analytics;

/**
 * 배치 실행 종류 Enum
 *
 * 실행 이력 표의 분류값입니다.
 */
enum ArchiveRunType: string
{
    /**
     * 시간 단위 집계
     */
    case Hour = 'hour';

    /**
     * 일 단위 확정 집계
     */
    case Day = 'day';

    /**
     * 구간 재집계
     */
    case Reaggregate = 'reaggregate';

    /**
     * 소급 익명화
     */
    case Anonymize = 'anonymize';

    /**
     * 전체 삭제
     */
    case Purge = 'purge';

    /**
     * 확장 데이터 정리
     */
    case ExtensionPurge = 'extension_purge';

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
        return __('analytics.archive_run_type.'.$this->value);
    }
}
