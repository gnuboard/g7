<?php

namespace Modules\Sirsoft\Ecommerce\Exports;

use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * 필드 원값을 파일 셀 값으로 바꾸는 규칙 한곳
 *
 * 선언된 종류(`ExportFieldType`)만 보고 바꾼다 — 새 필드도 종류만 적으면 같은 규칙을 받는다(D19).
 * 수식 주입 방어는 형식 작성기(CSV) 몫이다 — 이 단계는 「무엇을 쓰는가」만 정한다.
 */
final class ExportValueFormatter
{
    /**
     * 셀 값을 만듭니다.
     *
     * @param  ExportColumn  $column  대상 열
     * @param  mixed  $raw  필드 원값
     * @param  ExportRunContext  $ctx  출력 조건
     * @return string|int|null 셀 값 (빈칸이면 null)
     */
    public function format(ExportColumn $column, mixed $raw, ExportRunContext $ctx): string|int|null
    {
        $field = $column->field;

        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        return match ($field->type) {
            ExportFieldType::TRANSLATABLE => $this->translatable($raw, $column->locale, $ctx),
            ExportFieldType::INTEGER => is_numeric($raw) ? (int) $raw : (string) $raw,
            ExportFieldType::DECIMAL, ExportFieldType::MONEY => ExportDecimal::normalize($raw) ?? (string) $raw,
            ExportFieldType::DATETIME => $this->datetime($raw, $ctx, 'Y-m-d H:i:s'),
            ExportFieldType::DATE => $this->datetime($raw, $ctx, 'Y-m-d'),
            ExportFieldType::BOOLEAN => __($this->truthy($raw) ? 'sirsoft-ecommerce::export.values.yes' : 'sirsoft-ecommerce::export.values.no'),
            ExportFieldType::ENUM => $this->enum($raw, $field->enumClass),
            ExportFieldType::LIST => implode('; ', array_map(fn ($v) => $this->scalar($v, $ctx), array_filter((array) $raw, fn ($v) => $v !== null && $v !== ''))),
            ExportFieldType::STRING => $this->scalar($raw, $ctx),
        };
    }

    /**
     * 다국어 값을 다국어 형식에 맞춰 문자열로 바꿉니다.
     *
     * - 특정 언어: 그 언어 값, 비었으면 기본 언어 값
     * - 언어별 컬럼: 이 열의 언어 값, 비었으면 빈칸(다른 언어를 섞지 않는다)
     * - 단일 컬럼: `ko: 사과 | en: Apple` (빈 번역 생략, 값 안의 `|` 는 `\|`)
     *
     * @param  mixed  $raw  다국어 원값 (배열 또는 문자열)
     * @param  string|null  $columnLocale  언어별 컬럼일 때 열의 언어
     * @param  ExportRunContext  $ctx  출력 조건
     * @return string|null 셀 값
     */
    private function translatable(mixed $raw, ?string $columnLocale, ExportRunContext $ctx): ?string
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [$ctx->fallbackLocale => $raw];
        }

        if (! is_array($raw)) {
            return (string) $raw;
        }

        $pick = static fn (?string $locale) => $locale !== null && isset($raw[$locale]) && $raw[$locale] !== '' ? (string) $raw[$locale] : null;

        return match ($ctx->localeMode) {
            ExportRunContext::LOCALE_MODE_PER_COLUMN => $pick($columnLocale),
            ExportRunContext::LOCALE_MODE_SINGLE_COLUMN => $this->joinLocales($raw, $ctx) ?: null,
            default => $pick($ctx->locale) ?? $pick($ctx->fallbackLocale) ?? $this->firstNonEmpty($raw),
        };
    }

    /**
     * 언어별 값을 한 칸으로 잇습니다.
     *
     * @param  array<string, mixed>  $raw  언어 → 값
     * @param  ExportRunContext  $ctx  출력 조건 (언어 순서)
     * @return string 이은 값
     */
    private function joinLocales(array $raw, ExportRunContext $ctx): string
    {
        $parts = [];
        $order = array_values(array_unique(array_merge($ctx->locales, array_keys($raw))));

        foreach ($order as $locale) {
            $value = $raw[$locale] ?? null;
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }
            $parts[] = $locale.': '.str_replace('|', '\\|', (string) $value);
        }

        return implode(' | ', $parts);
    }

    /**
     * 비지 않은 첫 값을 돌려줍니다.
     *
     * @param  array<string, mixed>  $raw  언어 → 값
     * @return string|null 첫 값
     */
    private function firstNonEmpty(array $raw): ?string
    {
        foreach ($raw as $value) {
            if ($value !== null && $value !== '' && ! is_array($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * 일시 원값을 출력 시간대로 바꿔 씁니다.
     *
     * @param  mixed  $raw  일시 원값 (Carbon·DateTime·문자열)
     * @param  ExportRunContext  $ctx  출력 조건
     * @param  string  $format  출력 형식
     * @return string|null 셀 값
     */
    private function datetime(mixed $raw, ExportRunContext $ctx, string $format): ?string
    {
        try {
            $date = match (true) {
                $raw instanceof CarbonInterface => $raw->copy(),
                $raw instanceof DateTimeInterface => Carbon::instance($raw),
                default => Carbon::parse((string) $raw),
            };
        } catch (\Throwable) {
            return (string) $raw;
        }

        // 날짜만 있는 값(방문 예정일 등)은 시간대를 옮기지 않는다 — 옮기면 하루가 밀린다. 모델의 date 캐스트는 그 날
        // 0시의 날짜 객체를 주므로 문자열이든 객체든 달력 날짜를 그대로 쓴다.
        if ($format === 'Y-m-d') {
            return $date->format($format);
        }

        return $date->setTimezone($ctx->timezone)->format($format);
    }

    /**
     * 상태값을 라벨로 바꿉니다.
     *
     * @param  mixed  $raw  Enum 또는 문자열 원값
     * @param  string|null  $enumClass  문자열을 해석할 Enum 클래스
     * @return string 라벨 (해석할 수 없으면 원값)
     */
    private function enum(mixed $raw, ?string $enumClass): string
    {
        if (! $raw instanceof BackedEnum && $enumClass !== null && enum_exists($enumClass) && (is_string($raw) || is_int($raw))) {
            $raw = $enumClass::tryFrom($raw) ?? $raw;
        }

        if ($raw instanceof BackedEnum) {
            return method_exists($raw, 'label') ? (string) $raw->label() : (string) $raw->value;
        }

        return (string) $raw;
    }

    /**
     * 참/거짓 원값을 판정합니다.
     *
     * @param  mixed  $raw  원값
     * @return bool 참 여부
     */
    private function truthy(mixed $raw): bool
    {
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $raw;
    }

    /**
     * 스칼라 문자열로 바꿉니다(Enum·일시·배열도 안전하게).
     *
     * @param  mixed  $raw  원값
     * @param  ExportRunContext  $ctx  출력 조건
     * @return string 문자열
     */
    private function scalar(mixed $raw, ExportRunContext $ctx): string
    {
        return match (true) {
            $raw instanceof BackedEnum => $this->enum($raw, null),
            $raw instanceof DateTimeInterface => (string) $this->datetime($raw, $ctx, 'Y-m-d H:i:s'),
            is_array($raw) => (string) ($this->translatable($raw, null, $ctx) ?? ''),
            is_bool($raw) => $raw ? '1' : '0',
            default => (string) $raw,
        };
    }
}
