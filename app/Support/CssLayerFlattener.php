<?php

namespace App\Support;

/**
 * CSS 의 `@layer` 블록 래퍼를 벗겨 내부 규칙을 층 없는(unlayered) 규칙으로 만듭니다.
 *
 * 두 곳에서 쓴다.
 *   - 편집기 프리뷰 CSS(관리자 템플릿 자산 서빙) — 프리뷰 CSS 가 관리자 화면 CSS 와 같은 층 이름을
 *     쓰면 빌드가 다른 두 CSS 의 층 우선순위가 섞여 프리뷰 다크 규칙이 지는 문제를 막는다.
 *     층 래퍼를 모두 벗기고 `@layer a, b;` 순서 선언은 그대로 둔다.
 *   - 확장(모듈·플러그인) CSS 서빙 — 확장 CSS 는 템플릿의 CSS 라이브러리와 무관하게 세 칸으로
 *     실린다. 의미 클래스는 `@layer g7-extension`(문서 머리에서 템플릿 CSS 뒤에 선언), 유틸리티는
 *     층 없이 템플릿 뒤. 그래서 `g7-extension` 만 남기고 나머지 층 래퍼(확장 빌드 도구가 만든
 *     theme·utilities 등)와 순서 선언을 걷어낸다. `properties` 층도 남긴다 — Tailwind 가 `@property`
 *     미지원 브라우저용으로 `*,::before,::after{--tw-*:초기값}` 을 두는 최하위 층이라, 층 없이
 *     두면 템플릿 층 규칙(`.shadow{--tw-shadow:…}`)을 모든 요소에서 이긴다.
 *
 * 중괄호 균형 스캔으로 짝 `}` 를 찾는다(정규식은 중첩 중괄호를 안전하게 맞추지 못한다).
 * 주석(`/* … *\/`)과 문자열(`"…"`·`'…'`) 안은 그대로 옮기고 `@layer`·중괄호·`;` 를 세지 않는다 — 주석에 적은
 * `@layer` 설명 글을 층으로 읽어 주석 닫힘까지 벗겨 내면 그 뒤 규칙이 모두 주석이 된다.
 * 짝이 맞지 않으면 그 지점부터 원문을 그대로 내보낸다(서빙이 깨지지 않도록).
 */
class CssLayerFlattener
{
    /** 확장 CSS 서빙에서 남기는 층 */
    public const EXTENSION_KEEP_LAYERS = ['g7-extension', 'properties'];

    /**
     * 층 래퍼를 벗긴다.
     *
     * @param  string  $css  원본 CSS
     * @param  array<int, string>  $keep  래퍼를 남길 층 이름(이 이름 하나만 가진 블록은 그대로 둔다)
     * @param  bool  $stripStatements  `@layer a, b;` 순서 선언에서 `$keep` 밖 이름을 지울지(모두 지워지면 문장 삭제)
     * @return string 평탄화된 CSS
     */
    public static function flatten(string $css, array $keep = [], bool $stripStatements = false): string
    {
        if (! str_contains($css, '@layer')) {
            return $css;
        }

        return self::process($css, $keep, $stripStatements);
    }

    /**
     * 확장 CSS 서빙용 평탄화 — `g7-extension`·`properties` 만 남기고 순서 선언도 정리한다.
     *
     * @param  string  $css  확장 CSS
     * @return string 평탄화된 CSS
     */
    public static function forExtension(string $css): string
    {
        return self::flatten($css, self::EXTENSION_KEEP_LAYERS, true);
    }

    /**
     * 모든 층 블록을 한 층(`$layer`)으로 모은다 — 층 안 규칙은 그 층에, 층 밖 규칙은 층 밖에 그대로 둔다.
     *
     * 편집기 프리뷰 CSS 용이다. 프리뷰가 관리자 화면 CSS 와 같은 층 이름(`theme`·`base`·`utilities`)을 쓰면 두 빌드의
     * 층 순서가 섞이므로 이름을 하나로 합치고, 순서 선언(`@layer a, b;`)은 지운다 — 남기면 관리자 화면에 없는 이름이
     * 확장 의미 칸(`g7-extension`) 뒤에 새 층으로 생긴다. 안쪽 중첩 층은 벗긴다.
     *
     * @param  string  $css  원본 CSS
     * @param  string  $layer  모을 층 이름
     * @return string 층 이름을 하나로 모은 CSS
     */
    public static function mergeInto(string $css, string $layer): string
    {
        if (! str_contains($css, '@layer')) {
            return $css;
        }

        return self::process($css, [], true, $layer);
    }

    /**
     * 확장 매니페스트 `assets` 에서 CSS 앞글자 선언을 꺼냅니다.
     *
     * 확장 CSS 서빙 평탄화는 앞글자를 선언한 확장에만 적용한다. 선언은 「이 확장 CSS 의 모든 클래스가
     * 자기 앞글자로 시작한다」는 약속이라, 층을 벗겨도 템플릿·다른 확장 규칙과 이름이 겹치지 않는다.
     *
     * @param  array<string, mixed>  $assets  매니페스트 `assets`
     * @return string|null 선언된 namespace (없으면 null)
     */
    public static function declaredNamespace(array $assets): ?string
    {
        $namespace = is_array($assets['css'] ?? null) ? ($assets['css']['namespace'] ?? null) : null;

        return is_string($namespace) && $namespace !== '' ? $namespace : null;
    }

    /**
     * 한 구간을 처리한다. 벗긴 블록의 안쪽은 재귀로 다시 처리한다(중첩 층).
     *
     * @param  string  $css  구간
     * @param  array<int, string>  $keep  남길 층
     * @param  bool  $stripStatements  순서 선언 정리 여부
     * @param  string|null  $into  주면 맨 바깥 층 블록을 벗기지 않고 이 이름의 층으로 바꿔 담는다(안쪽 층은 벗긴다)
     * @return string 처리 결과
     */
    private static function process(string $css, array $keep, bool $stripStatements, ?string $into = null): string
    {
        $out = '';
        $len = strlen($css);
        $i = 0;

        while ($i < $len) {
            // 주석·문자열·이스케이프·`@` 가 아닌 구간은 통째로 옮긴다
            $run = strcspn($css, '@/"\'\\', $i);
            if ($run > 0) {
                $out .= substr($css, $i, $run);
                $i += $run;

                continue;
            }

            $opaque = self::opaqueEnd($css, $i);
            if ($opaque !== null) {
                $out .= substr($css, $i, $opaque - $i);
                $i = $opaque;

                continue;
            }

            if (substr_compare($css, '@layer', $i, 6) !== 0) {
                $out .= $css[$i];
                $i++;

                continue;
            }

            $at = $i;
            $j = $at + 6; // strlen('@layer')

            // `@layers`·`@layer-x` 같은 다른 식별자는 건드리지 않는다
            if ($j < $len && preg_match('/[A-Za-z0-9_-]/', $css[$j])) {
                $out .= '@layer';
                $i = $j;

                continue;
            }

            [$brace, $semi] = self::nextDelimiters($css, $j);

            // 순서 선언 `@layer a, b;`
            if ($semi !== null && ($brace === null || $semi < $brace)) {
                $statement = substr($css, $at, $semi - $at + 1);
                $out .= $stripStatements ? self::filterStatement(substr($css, $j, $semi - $j), $keep) : $statement;
                $i = $semi + 1;

                continue;
            }

            if ($brace === null) {
                $out .= substr($css, $at);
                break;
            }

            $close = self::matchingBrace($css, $brace);
            if ($close === null) {
                $out .= substr($css, $at);
                break;
            }

            $names = self::names(substr($css, $j, $brace - $j));
            $inner = substr($css, $brace + 1, $close - $brace - 1);

            if ($into !== null) {
                $out .= '@layer '.$into.'{'.self::process($inner, [], $stripStatements).'}';
            } elseif (count($names) === 1 && in_array($names[0], $keep, true)) {
                // 남기는 층 — 안쪽의 다른 층만 다시 처리하고 래퍼는 유지
                $out .= '@layer '.$names[0].'{'.self::process($inner, $keep, $stripStatements).'}';
            } else {
                $out .= self::process($inner, $keep, $stripStatements);
            }

            $i = $close + 1;
        }

        return $out;
    }

    /**
     * 위치가 주석·문자열·백슬래시 이스케이프의 시작이면 그 끝(다음 위치)을 돌려준다.
     *
     * @param  string  $css  CSS
     * @param  int  $at  위치
     * @return int|null 주석·문자열 끝 다음 위치(닫히지 않으면 문서 끝), 시작이 아니면 null
     */
    private static function opaqueEnd(string $css, int $at): ?int
    {
        $len = strlen($css);
        $ch = $css[$at];

        // 선택자 이스케이프(`.after\:content-\[\'\'\]`)의 따옴표·중괄호는 문자열·블록이 아니다
        if ($ch === '\\') {
            return min($at + 2, $len);
        }

        if ($ch === '/' && $at + 1 < $len && $css[$at + 1] === '*') {
            $end = strpos($css, '*/', $at + 2);

            return $end === false ? $len : $end + 2;
        }

        if ($ch === '"' || $ch === "'") {
            for ($k = $at + 1; $k < $len; $k++) {
                if ($css[$k] === '\\') {
                    $k++;
                } elseif ($css[$k] === $ch || $css[$k] === "\n") {
                    return $k + 1;
                }
            }

            return $len;
        }

        return null;
    }

    /**
     * 주석·문자열 밖에서 처음 나오는 `{` 와 `;` 위치를 찾는다.
     *
     * @param  string  $css  CSS
     * @param  int  $from  시작 위치
     * @return array{0: int|null, 1: int|null} [`{` 위치, `;` 위치] — 먼저 나온 쪽에서 멈추므로 다른 쪽은 null
     */
    private static function nextDelimiters(string $css, int $from): array
    {
        $len = strlen($css);
        for ($k = $from; $k < $len; $k++) {
            $opaque = self::opaqueEnd($css, $k);
            if ($opaque !== null) {
                $k = $opaque - 1;

                continue;
            }
            if ($css[$k] === '{') {
                return [$k, null];
            }
            if ($css[$k] === ';') {
                return [null, $k];
            }
        }

        return [null, null];
    }

    /**
     * `{` 의 짝 `}` 위치를 찾는다(주석·문자열 안의 중괄호는 세지 않는다).
     *
     * @param  string  $css  CSS
     * @param  int  $open  `{` 위치
     * @return int|null 짝 위치(없으면 null)
     */
    private static function matchingBrace(string $css, int $open): ?int
    {
        $depth = 0;
        $len = strlen($css);
        for ($k = $open; $k < $len; $k++) {
            $opaque = self::opaqueEnd($css, $k);
            if ($opaque !== null) {
                $k = $opaque - 1;

                continue;
            }
            $ch = $css[$k];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            }
        }

        return null;
    }

    /**
     * `@layer` 뒤 이름 목록을 나눈다.
     *
     * @param  string  $raw  이름 목록 원문
     * @return array<int, string> 이름들(익명 블록이면 빈 배열)
     */
    private static function names(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($n) => $n !== ''));
    }

    /**
     * 순서 선언에서 남길 이름만 추린다.
     *
     * @param  string  $raw  이름 목록 원문
     * @param  array<int, string>  $keep  남길 층
     * @return string 새 선언(남길 이름이 없으면 빈 문자열)
     */
    private static function filterStatement(string $raw, array $keep): string
    {
        $kept = array_values(array_filter(self::names($raw), fn ($n) => in_array($n, $keep, true)));

        return $kept === [] ? '' : '@layer '.implode(', ', $kept).';';
    }
}
