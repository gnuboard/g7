<?php

namespace App\Support;

/**
 * 언어 코드(BCP 47) 판정·정규화·협상의 단일 기준.
 *
 * 언어팩 설치 검사는 `fil`·`fil-PH`·`zh-Hant`·`es-419` 를 통과시키는데, 화면·API·정적 게시·
 * 국가 추정 경로가 저마다 "소문자 2글자(+대문자 2글자 지역)" 패턴을 따로 들고 있어 설치된
 * 언어를 쓸 수 없었다(#148). 허용 규칙을 언어팩 설치 규칙 하나로 두고 모든 경로가 여기서
 * 읽는다.
 *
 * 형식: 언어(소문자 2~3) [-문자 표기(첫 글자 대문자 4자)] [-지역(대문자 2자 또는 숫자 3자)]
 * [-변형(영숫자 5~8자)]. 대소문자는 정규형만 받는다 — `fil-ph`·`zh-hant` 는 거부한다
 * (정규형이 유일해야 대소문자를 가리지 않는 파일시스템에서도 언어 파일 이름이 겹치지 않는다).
 *
 * 라우트 캐시 안전: `PATTERN_BODY` 는 상수라 `where()` 정의 시점에 평가되어 캐시에 박힌다.
 */
final class LocaleTag
{
    /**
     * 앵커·구분자 없는 패턴 본문 (`Route::where()` 용).
     *
     * 문자 집합이 `[A-Za-z0-9-]` 뿐이라 `/`·`.`·`\`·`_` 가 들어갈 수 없다(경로 탈출 차단).
     */
    public const PATTERN_BODY = '[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?(?:-[a-zA-Z0-9]{5,8})?';

    /**
     * 전체 일치 정규식.
     */
    public const PATTERN = '/^'.self::PATTERN_BODY.'$/';

    /**
     * 언어 코드 최대 길이 — `language_packs.locale` varchar(20) 와 같다.
     */
    public const MAX_LENGTH = 20;

    /**
     * 언어팩 설치 규칙에 맞는 정규형 언어 코드인지 판정합니다.
     *
     * @param  mixed  $tag  검사할 값
     * @return bool 문자열이고 20자 이하이며 형식에 맞으면 true
     */
    public static function isValid(mixed $tag): bool
    {
        return is_string($tag)
            && strlen($tag) <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $tag) === 1;
    }

    /**
     * 대소문자·구분자를 정규형으로 맞춥니다.
     *
     * `en-us`→`en-US`, `zh_hant_tw`→`zh-Hant-TW`, `FIL-ph`→`fil-PH`. 맞춘 결과가 형식에
     * 맞지 않으면 null 입니다(`x-klingon`·`*` 등).
     *
     * @param  string  $tag  원본 언어 코드
     * @return string|null 정규형 (무효면 null)
     */
    public static function canonicalize(string $tag): ?string
    {
        $tag = str_replace('_', '-', trim($tag));
        if ($tag === '') {
            return null;
        }

        $parts = explode('-', $tag);
        $out = [strtolower(array_shift($parts))];

        foreach ($parts as $part) {
            $len = strlen($part);
            if ($len === 4 && ctype_alpha($part)) {
                $out[] = ucfirst(strtolower($part));
            } elseif ($len === 2 && ctype_alpha($part)) {
                $out[] = strtoupper($part);
            } elseif ($len === 3 && ctype_digit($part)) {
                $out[] = $part;
            } else {
                $out[] = strtolower($part);
            }
        }

        $canonical = implode('-', $out);

        return self::isValid($canonical) ? $canonical : null;
    }

    /**
     * 주 언어 하위 태그를 돌려줍니다 (`fil-PH`→`fil`).
     *
     * @param  string  $tag  언어 코드
     * @return string|null 주 언어 (무효면 null)
     */
    public static function primaryLanguage(string $tag): ?string
    {
        $canonical = self::canonicalize($tag);

        return $canonical === null ? null : explode('-', $canonical)[0];
    }

    /**
     * 지역 하위 태그를 돌려줍니다 (`fil-PH`→`PH`, `es-419`→`419`, `zh-Hant`→null).
     *
     * @param  string  $tag  언어 코드
     * @return string|null 지역 (없거나 무효면 null)
     */
    public static function region(string $tag): ?string
    {
        $canonical = self::canonicalize($tag);
        if ($canonical === null) {
            return null;
        }

        foreach (array_slice(explode('-', $canonical), 1) as $part) {
            if (preg_match('/^(?:[A-Z]{2}|[0-9]{3})$/', $part) === 1) {
                return $part;
            }
        }

        return null;
    }

    /**
     * 국가 코드(ISO 3166-1 alpha-2)로 읽을 수 있는 지역만 돌려줍니다.
     *
     * 숫자 지역(`es-419` 라틴아메리카)은 나라 하나가 아니므로 null 입니다.
     *
     * @param  string  $tag  언어 코드
     * @return string|null 국가 코드 (없으면 null)
     */
    public static function countryCode(string $tag): ?string
    {
        $region = self::region($tag);

        return $region !== null && ctype_alpha($region) ? $region : null;
    }

    /**
     * 요청 언어를 지원 목록에서 고릅니다.
     *
     * ① 정규형 완전 일치 ② 주 언어 자체가 지원되면 그것 ③ 주 언어가 같은 첫 지원 항목.
     * `fil`/[ko, fil-PH] → `fil-PH`, `en-GB`/[ko, en] → `en`, `zh-TW`/[zh-Hant, zh-CN] → `zh-Hant`.
     *
     * @param  string  $requested  요청 언어
     * @param  array<int, mixed>  $supported  지원 언어 목록 (정규형)
     * @return string|null 고른 지원 언어 (없으면 null)
     */
    public static function match(string $requested, array $supported): ?string
    {
        $canonical = self::canonicalize($requested);
        if ($canonical === null) {
            return null;
        }

        $supported = array_values(array_filter($supported, 'is_string'));

        foreach ($supported as $candidate) {
            if (self::canonicalize($candidate) === $canonical) {
                return $candidate;
            }
        }

        $primary = explode('-', $canonical)[0];
        if (in_array($primary, $supported, true)) {
            return $primary;
        }

        foreach ($supported as $candidate) {
            if (self::primaryLanguage($candidate) === $primary) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Accept-Language 헤더를 지원 목록과 협상합니다.
     *
     * 헤더에 적힌 순서대로 각 항목을 `match()` 로 고른다(q 값은 보지 않는다 — 종전 동작).
     * `*`·형식 위반 항목은 건너뜁니다.
     *
     * @param  string  $acceptLanguage  Accept-Language 헤더 값
     * @param  array<int, mixed>  $supported  지원 언어 목록
     * @return string|null 고른 지원 언어 (없으면 null)
     */
    public static function negotiate(string $acceptLanguage, array $supported): ?string
    {
        foreach (explode(',', $acceptLanguage) as $item) {
            $tag = trim(explode(';', $item)[0]);
            if ($tag === '' || $tag === '*') {
                continue;
            }

            $matched = self::match($tag, $supported);
            if ($matched !== null) {
                return $matched;
            }
        }

        return null;
    }

    /**
     * Accept-Language 헤더의 첫 항목을 정규형으로 돌려줍니다.
     *
     * @param  string|null  $header  Accept-Language 헤더 값
     * @return string|null 첫 항목의 정규형 (없거나 무효면 null)
     */
    public static function firstAcceptLanguageTag(?string $header): ?string
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $first = trim(explode(';', explode(',', $header)[0])[0]);

        return $first === '' || $first === '*' ? null : self::canonicalize($first);
    }
}
