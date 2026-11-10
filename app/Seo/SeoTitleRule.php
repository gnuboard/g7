<?php

namespace App\Seo;

use App\Support\LocalizedValue;

/**
 * 화면 제목 규칙 — 검색엔진(봇) `<title>` 과 사람 브라우저 탭 제목(`document.title`)이 함께 쓰는 단일 규칙.
 *
 * 순서대로 받은 제목 후보(리소스 meta_title → 페이지 seo_meta.title → 확장 제목 틀 → `meta.seo.og.title` →
 * 라우트 meta.title) 중 처음으로 비어 있지 않고 사이트 이름만 되풀이하지 않는 값을 제목으로 고르고, 사이트 이름은
 * 코어 제목 접미사(`seo.meta_title_suffix`)로만 붙인다. 접미사가 비어 있으면 ` - {site_name}` 을 쓴다.
 * 사이트 첫 화면(`/`)은 사이트 이름만 쓴다.
 *
 * 화면 쪽 짝은 `resources/js/core/routing/DocumentTitle.ts` 의 `resolveDocumentTitle` 이다. 두 구현은
 * `tests/fixtures/seo/title-rule-cases.json` 하나로 같은 답을 내는지 잠근다 — 한쪽만 고치지 않는다.
 *
 * 순수 함수다(설정·요청을 읽지 않는다). 다국어 값 선택만 `LocalizedValue::pick` 을 쓴다.
 */
final class SeoTitleRule
{
    /** 코어 접미사가 비었을 때 쓰는 접미사 */
    public const DEFAULT_SUFFIX = ' - {site_name}';

    /** 제목 끝에 이미 사이트 이름이 붙었는지 볼 때 쓰는 구분자 */
    private const SITE_NAME_SEPARATORS = [' - ', ' | ', ' · ', ' – ', ' — '];

    /** 접미사 앞머리에서 떼는 구분자·공백 (제목이 없을 때 「| 사이트명」 방지) */
    private const LEADING_SEPARATOR_PATTERN = '/^[\s|·:\/\x{2013}\x{2014}-]+/u';

    /**
     * 제목 후보와 사이트 설정으로 화면 제목을 정합니다.
     *
     * 입력 키:
     * - `tiers` — 순서대로 시험할 제목 후보 목록. 값은 문자열·다국어 배열·(앞 후보가 비었을 때만 계산할) Closure
     * - `site_name` — 코어 사이트 이름(`general.site_name`, 문자열 또는 다국어 배열)
     * - `suffix` — 코어 제목 접미사(`seo.meta_title_suffix`) 원값. `{site_name}` 자리표시자 허용
     * - `is_home` — 사이트 첫 화면이면 true (사이트 이름만 쓴다)
     * - `locale` — 다국어 값을 고를 언어 (null 이면 현재 요청 언어)
     *
     * @param  array<string, mixed>  $input  규칙 입력
     * @return array{title: string, suffix: string, full: string} 제목·제목 뒤에 붙일 접미사·둘을 이은 전체 제목
     */
    public static function resolve(array $input): array
    {
        $locale = isset($input['locale']) && is_string($input['locale']) && $input['locale'] !== '' ? $input['locale'] : null;
        $siteName = trim(self::localize($input['site_name'] ?? '', $locale));
        $assembled = self::assembleSuffix(self::localize($input['suffix'] ?? '', $locale), $siteName);

        $title = '';
        if (! ($input['is_home'] ?? false)) {
            foreach ((array) ($input['tiers'] ?? []) as $candidate) {
                if ($candidate instanceof \Closure) {
                    $candidate = $candidate();
                }
                $text = self::normalize(self::localize($candidate, $locale));
                if ($text === '' || self::isSiteNameOnly($text, $siteName, $assembled)) {
                    continue;
                }
                $title = $text;
                break;
            }
        }

        $suffix = self::composeSuffix($title, $assembled, $siteName);

        // 제목도 접미사도 없으면(사이트 이름이 접미사로 붙지 않는 설정) 사이트 이름을 제목으로 쓴다 (공개#154)
        if ($title === '' && $suffix === '') {
            $title = $siteName;
        }

        return ['title' => $title, 'suffix' => $suffix, 'full' => $title.$suffix];
    }

    /**
     * 코어 접미사 원값을 실제로 붙일 접미사로 만듭니다.
     *
     * 비어 있으면 ` - {site_name}` 을 쓰고 `{site_name}` 을 사이트 이름으로 바꾼다. 구분자·공백만 남으면 빈 값이다
     * (사이트 이름이 비어 있는 설치본에서 「 - 」 만 붙지 않게).
     *
     * @param  string  $rawSuffix  코어 접미사 원값 (현재 언어로 고른 글자)
     * @param  string  $siteName  사이트 이름 (현재 언어)
     * @return string 붙일 접미사 (없으면 빈 문자열)
     */
    public static function assembleSuffix(string $rawSuffix, string $siteName): string
    {
        $template = trim($rawSuffix) === '' ? self::DEFAULT_SUFFIX : $rawSuffix;
        $assembled = str_replace('{site_name}', $siteName, $template);

        return self::stripLeadingSeparators($assembled) === '' ? '' : $assembled;
    }

    /**
     * 제목 뒤에 붙일 접미사를 정합니다 (중복 방지 포함).
     *
     * - 접미사가 없으면 빈 값
     * - 제목이 없으면 접미사 앞머리 구분자를 뗀 값(=사이트 이름) — 제목 전체가 된다
     * - 제목이 이미 접미사로 끝나거나 「구분자 + 사이트 이름」으로 끝나면 빈 값 (옮겨 온 상품의 meta_title
     *   「상품명 - 사이트명」처럼 데이터에 사이트 이름이 들어 있어도 두 번 붙지 않는다)
     * - 그 밖에는 접미사가 공백으로 시작하지 않으면 공백 하나를 복원해 잇는다 (TrimStrings 가 앞 공백을 지운 저장값)
     *
     * @param  string  $title  고른 제목
     * @param  string  $assembledSuffix  assembleSuffix() 결과
     * @param  string  $siteName  사이트 이름 (현재 언어)
     * @return string 제목 뒤에 이을 글자
     */
    public static function composeSuffix(string $title, string $assembledSuffix, string $siteName): string
    {
        if (trim($assembledSuffix) === '') {
            return '';
        }

        if ($title === '') {
            return self::stripLeadingSeparators($assembledSuffix);
        }

        $core = trim($assembledSuffix);
        if ($core !== '' && str_ends_with($title, $core)) {
            return '';
        }

        if ($siteName !== '') {
            foreach (self::SITE_NAME_SEPARATORS as $separator) {
                if (str_ends_with($title, $separator.$siteName)) {
                    return '';
                }
            }
        }

        return preg_match('/^\s/u', $assembledSuffix) === 1 ? $assembledSuffix : ' '.$assembledSuffix;
    }

    /**
     * 제목이 사이트 이름만 되풀이하는지 판정합니다.
     *
     * 사이트 이름이나 접미사에서 구분자를 뗀 값과 같으면 화면 정보가 없는 제목이다(게시판 목록 기본 틀
     * `{site_name}` 등). 이런 후보는 건너뛰고 다음 후보를 본다.
     *
     * @param  string  $title  정리한 제목 후보
     * @param  string  $siteName  사이트 이름
     * @param  string  $assembledSuffix  assembleSuffix() 결과
     * @return bool 사이트 이름만 되풀이하면 true
     */
    public static function isSiteNameOnly(string $title, string $siteName, string $assembledSuffix): bool
    {
        $normalized = trim($title);
        if ($normalized === '') {
            return false;
        }

        $suffixName = self::stripLeadingSeparators($assembledSuffix);

        return ($siteName !== '' && $normalized === $siteName)
            || ($suffixName !== '' && $normalized === $suffixName);
    }

    /**
     * 확장 변수 선언 중 필수(`required`) 변수가 비었는지 봅니다 — 비었으면 그 확장 제목 틀은 아직 제목 후보가 아닙니다.
     *
     * 필수 변수가 빈 채로 틀을 치환하면 고정 글자만 남은 제목(`{product_name} 특가` → 「특가」)이 된다. 화면은 데이터를
     * 받기 전에, 봇은 데이터가 비었을 때 이 상태가 된다. 값 목록에 없는 변수·null·빈 글자를 빈 값으로 본다.
     *
     * @param  array<string, mixed>  $definitions  변수 이름 → 선언(`source`·`key`·`required`)
     * @param  array<string, mixed>  $vars  변수 이름 → 해석한 값
     * @return bool 비어 있는 필수 변수가 하나라도 있으면 true
     */
    public static function hasMissingRequired(array $definitions, array $vars): bool
    {
        foreach ($definitions as $name => $definition) {
            if (! is_array($definition) || empty($definition['required'])) {
                continue;
            }
            $value = $vars[$name] ?? null;
            if ($value === null || $value === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * 제목 후보를 글자로 정리합니다 — 태그 제거 · 엔티티 해제 · 공백 한 칸 · 앞뒤 공백 제거.
     *
     * @param  string  $text  제목 후보
     * @return string 정리한 글자
     */
    public static function normalize(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $plain));
    }

    /**
     * 접미사 앞머리의 구분자·공백을 떼고 앞뒤 공백을 지웁니다.
     *
     * @param  string  $suffix  접미사
     * @return string 구분자를 뗀 글자
     */
    public static function stripLeadingSeparators(string $suffix): string
    {
        return trim((string) preg_replace(self::LEADING_SEPARATOR_PATTERN, '', $suffix));
    }

    /**
     * 다국어 배열·스칼라를 글자로 바꿉니다 (`LocalizesSeoValues::resolveLocalizedValue` 와 같은 규칙).
     *
     * @param  mixed  $value  대상 값
     * @param  string|null  $locale  고를 언어 (null 이면 현재 요청 언어)
     * @return string 글자
     */
    private static function localize(mixed $value, ?string $locale): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            return LocalizedValue::pick($value, $locale, anyAsLast: false) ?? '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        return (string) ($value ?? '');
    }
}
