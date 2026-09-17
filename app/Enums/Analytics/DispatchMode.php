<?php

namespace App\Enums\Analytics;

/**
 * 적재 방식 Enum
 *
 * 수집한 요청을 큐로 넘길지 요청 안에서 바로 적재할지 정합니다. 큐 워커가 없는
 * 환경에서는 sync 로 바꿔야 방문이 쌓입니다.
 */
enum DispatchMode: string
{
    /**
     * 큐에 넘겨 워커가 적재
     */
    case Queue = 'queue';

    /**
     * 요청 안에서 즉시 적재
     */
    case Sync = 'sync';

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
        return __('analytics.dispatch_mode.'.$this->value);
    }
}
