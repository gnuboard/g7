<?php

use Illuminate\Contracts\Foundation\Application;

/**
 * 다국어 라벨 해석 헬퍼.
 *
 * 두 가지 도메인을 단일 시그니처로 처리한다:
 *   - settings JSON (사용자 편집 가능): `value: ['ko'=>..., 'en'=>...]` + `fallbackKey: 'sirsoft-ecommerce::settings.countries.KR.name'`
 *   - registry payload (시스템 정의): `nameKey: 'notification.channels.mail.name'`
 *
 * @since 7.0.0-beta.4
 */
if (! function_exists('localized_label')) {
    /**
     * 다국어 라벨을 활성 locale 기준으로 해석합니다.
     *
     * 우선순위: nameKey __() > value[locale] > fallbackKey __() > value[fallback_locale] > value 첫 키
     *
     * @param  array<string, string>|null  $value  ['ko' => ..., 'en' => ...] 형태 (settings JSON)
     * @param  string|null  $nameKey  lang key 직접 선언 (registry payload)
     * @param  string|null  $fallbackKey  $value 의 활성 locale 키 부재 시 __() 호출 키
     * @param  string|null  $locale  명시 locale (기본: app()->getLocale())
     * @return string 해석된 라벨 (어디에도 없으면 빈 문자열)
     */
    function localized_label(
        ?array $value = null,
        ?string $nameKey = null,
        ?string $fallbackKey = null,
        ?string $locale = null,
    ): string {
        // Laravel app 미초기화 환경 (PHPUnit\Framework\TestCase 직접 상속 등) 도 안전하게 처리.
        // 명시 인자 → app locale → fallback_locale → 'ko'
        $hasLaravelApp = function (): bool {
            try {
                return function_exists('app') && app() instanceof Application;
            } catch (Throwable) {
                return false;
            }
        };

        if ($locale === null) {
            if ($hasLaravelApp()) {
                try {
                    $locale = app()->getLocale();
                } catch (Throwable) {
                    // 무시
                }
                if ($locale === null) {
                    try {
                        $locale = config('app.fallback_locale', 'ko');
                    } catch (Throwable) {
                        $locale = 'ko';
                    }
                }
            } else {
                $locale = 'ko';
            }
        }

        $tryTranslate = static function (string $key) use ($hasLaravelApp, $locale): ?string {
            if (! $hasLaravelApp()) {
                return null;
            }
            try {
                $translated = __($key, [], $locale);

                return is_string($translated) ? $translated : null;
            } catch (Throwable) {
                return null;
            }
        };

        // 1. nameKey 우선 (registry payload — 데이터에 다국어 JSON 없음)
        if ($nameKey !== null && $nameKey !== '') {
            $translated = $tryTranslate($nameKey);
            if ($translated !== null && $translated !== $nameKey) {
                return $translated;
            }
        }

        // 2. value 의 활성 locale 키 (settings JSON)
        if ($value !== null && isset($value[$locale]) && $value[$locale] !== '') {
            return $value[$locale];
        }

        // 3. fallbackKey __()
        if ($fallbackKey !== null && $fallbackKey !== '') {
            $translated = $tryTranslate($fallbackKey);
            if ($translated !== null && $translated !== $fallbackKey) {
                return $translated;
            }
        }

        // 4. value 의 fallback_locale → 첫 키
        if ($value !== null) {
            $fallback = 'ko';
            if ($hasLaravelApp()) {
                try {
                    $fallback = config('app.fallback_locale', 'ko');
                } catch (Throwable) {
                    // 'ko' 유지
                }
            }
            if (isset($value[$fallback]) && $value[$fallback] !== '') {
                return $value[$fallback];
            }
            $first = reset($value);

            return is_string($first) ? $first : '';
        }

        return '';
    }
}

if (! function_exists('localize_catalog_field')) {
    /**
     * Settings 카탈로그 다국어 JSON 필드에 활성 언어팩의 모든 활성 locale 키를 채워줍니다.
     *
     * 단일 string 반환이 아니라 **다국어 배열 자체** 를 반환 — settings 응답에 다국어 JSON 으로
     * 그대로 노출되어야 하므로. (운영자가 admin UI 에서 모든 locale 편집 가능해야 함)
     *
     * 운영자 편집값(비어있지 않은 값) 은 보존, 부재한 locale 만 lang pack 에서 자동 채움.
     *
     * 사용 예시:
     * ```php
     * // EcommerceSettingsService::getBuiltinPaymentMethods() 안에서
     * $cachedName = localize_catalog_field(
     *     $method['_cached_name'] ?? ['ko' => $id, 'en' => $id],
     *     "sirsoft-ecommerce::settings.payment_methods.{$id}.name",
     * );
     * ```
     *
     * @param  array<string, string>  $field  ['ko' => '...', 'en' => '...'] 다국어 JSON
     * @param  string  $langKey  완전한 lang key (네임스페이스 prefix 포함)
     * @return array<string, string> 보강된 다국어 JSON
     *
     * @since 7.0.0-beta.4
     */
    function localize_catalog_field(array $field, string $langKey): array
    {
        $locales = config('app.translatable_locales', config('app.supported_locales', ['ko', 'en']));
        if (! is_array($locales) || empty($locales)) {
            $locales = ['ko', 'en'];
        }

        foreach ($locales as $locale) {
            // 운영자 편집값 보존 — 키가 존재하고 비어있지 않으면 skip
            if (isset($field[$locale]) && $field[$locale] !== '') {
                continue;
            }
            try {
                $translated = __($langKey, [], $locale);
                if (is_string($translated) && $translated !== $langKey) {
                    $field[$locale] = $translated;
                }
            } catch (Throwable) {
                // Laravel app 미초기화 환경 — skip
            }
        }

        return $field;
    }
}

if (! function_exists('localized_payload')) {
    /**
     * Registry payload 단축 helper — entry 의 {field} (다국어 JSON) 와 {field}_key (lang key) 를 자동 처리.
     *
     * @param  array<string, mixed>  $entry  ['name' => [...], 'name_key' => '...']
     * @param  string  $field  처리할 필드명 (기본: 'name')
     * @param  string|null  $locale  명시 locale (기본: app()->getLocale())
     * @return string 해석된 라벨
     */
    function localized_payload(array $entry, string $field = 'name', ?string $locale = null): string
    {
        $value = $entry[$field] ?? null;
        $nameKey = $entry["{$field}_key"] ?? null;

        return localized_label(
            value: is_array($value) ? $value : null,
            nameKey: is_string($nameKey) ? $nameKey : null,
            locale: $locale,
        );
    }
}

if (! function_exists('trans_count')) {
    /**
     * 치환값의 수(`count`)로 단·복수 문구를 골라 번역합니다.
     *
     * 사전 값이 `단수|복수`(Laravel `trans_choice` 형)이면 `count` 가 1 일 때 단수, 0·2 이상·소수일 때
     * 복수를 고른다(영어 기준 — 프런트 번역 엔진이 `count` 로 CLDR 범주를 고르는 것과 같은 결과. 음수 -1 만
     * 서버는 복수·프런트는 단수로 다르다). 복수 구분 기호가 없는 값(한국어 등)은 `__()` 와 같은 결과를 돌려준다.
     * `count` 가 없거나 숫자가 아니면(예: `number_format()` 의 `1,000`) `__()` 를 그대로 부르므로 `단수|복수`
     * 값은 두 문장이 이어져 나온다 — 수는 서식 없이 넘긴다. 메시지 키와 치환값을 함께 넘기는 공용 응답·예외·
     * 활동 로그가 쓴다.
     *
     * @param  string  $key  다국어 키
     * @param  array<string, mixed>  $replace  치환값 (`count` 가 숫자이면 단·복수 선택에 쓴다)
     * @param  string|null  $locale  로케일 (기본: 현재 로케일)
     * @return string 번역 문구
     */
    function trans_count(string $key, array $replace = [], ?string $locale = null): string
    {
        $count = $replace['count'] ?? null;

        if (is_int($count) || is_float($count) || (is_string($count) && is_numeric($count))) {
            return trans_choice($key, $count + 0, $replace, $locale);
        }

        return __($key, $replace, $locale);
    }
}
