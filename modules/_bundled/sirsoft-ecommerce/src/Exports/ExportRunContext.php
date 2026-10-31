<?php

namespace Modules\Sirsoft\Ecommerce\Exports;

/**
 * 파일 하나를 만드는 동안 고정되는 출력 조건
 *
 * 요청 시점에 저장한 값(로케일·시간대·다국어 형식·행 단위)을 들고 다닌다. 큐 작업이 늦게 돌아도 요청자가
 * 고른 조건대로 파일이 나오게 하려는 것이다.
 */
final class ExportRunContext
{
    public const LOCALE_MODE_ONE = 'locale';

    public const LOCALE_MODE_PER_COLUMN = 'per_column';

    public const LOCALE_MODE_SINGLE_COLUMN = 'single_column';

    /**
     * @param  string  $rowUnit  행 단위
     * @param  string  $localeMode  다국어 형식 (locale | per_column | single_column)
     * @param  string  $locale  「특정 언어만」에서 고른 언어
     * @param  array<int, string>  $locales  지원 언어 목록 (언어별 컬럼·단일 컬럼 순서)
     * @param  string  $fallbackLocale  번역이 없을 때 대체할 기본 언어
     * @param  string  $timezone  일시 표기 시간대
     * @param  bool  $includeTotals  합계 행 포함 여부
     */
    public function __construct(
        public readonly string $rowUnit,
        public readonly string $localeMode,
        public readonly string $locale,
        public readonly array $locales,
        public readonly string $fallbackLocale,
        public readonly string $timezone,
        public readonly bool $includeTotals,
    ) {}
}
