<?php

namespace App\Enums\Analytics;

/**
 * 집계를 건너뛴 이유 Enum
 *
 * 실행 이력의 `error` 칸에는 기록한 요청의 언어로 된 문장이 남는다. 화면은 이 코드로 이유를
 * 가려 보는 사람의 언어로 다시 그린다.
 */
enum ArchiveSkipReason: string
{
    /**
     * 같은 기간을 다른 집계가 이미 돌리고 있었다
     */
    case Locked = 'locked';

    /**
     * 이미 확정된 날이다
     */
    case Finalized = 'finalized';

    /**
     * 원장 보존 기간이 지나 다시 계산할 재료가 없다
     */
    case BeyondRetention = 'beyond_retention';

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
     * 다국어 라벨(건너뛴 이유 문장)을 반환합니다.
     *
     * @param  string|null  $locale  표시 로케일 (기본: 현재 앱 로케일)
     * @return string 번역된 문장
     */
    public function label(?string $locale = null): string
    {
        return __('analytics.archive.skipped_'.$this->value, [], $locale);
    }

    /**
     * 저장된 건너뜀 문장이 어떤 이유인지 찾습니다.
     *
     * 문장은 기록한 요청의 언어로 저장되므로, 이유마다 사이트가 아는 모든 언어의 문장과 맞춰 봅니다.
     *
     * @param  string  $message  저장된 문장
     * @return self|null 이유 (모르는 문장이면 null)
     */
    public static function fromMessage(string $message): ?self
    {
        $locales = array_unique(array_filter(array_merge(
            (array) config('app.supported_locales', []),
            [app()->getLocale(), (string) config('app.fallback_locale', 'en')],
        ), 'is_string'));

        foreach (self::cases() as $reason) {
            foreach ($locales as $locale) {
                if ($reason->label($locale) === $message) {
                    return $reason;
                }
            }
        }

        return null;
    }
}
