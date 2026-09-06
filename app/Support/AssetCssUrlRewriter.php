<?php

namespace App\Support;

/**
 * 확장 자산으로 서빙되는 CSS 안의 **상대 경로 참조**를 절대 자산 URL 로 바꿉니다.
 *
 * 왜 필요한가:
 *   자산 URL 은 두 모드로 나간다 (`general.asset_url_mode`).
 *     - `extension`      → `/api/templates/assets/{id}/vendor/x/1.0/a.css`
 *     - `extensionless`  → `/api/templates/assets/{id}?file=vendor%2Fx%2F1.0%2Fa.css`
 *
 *   브라우저는 CSS 안의 상대 `url()` 을 **그 스타일시트 URL의 디렉토리** 기준으로 푼다.
 *   확장자 모드에서는 디렉토리가 `.../vendor/x/1.0/` 이라 `./woff2/f.woff2` 가 제대로 풀리지만,
 *   확장자 없는 모드에서는 경로의 마지막 세그먼트가 식별자(`{id}`)이고 파일명은 쿼리에 있으므로
 *   디렉토리가 `/api/templates/assets/` 로 잡힌다 — `./woff2/f.woff2` 가
 *   `/api/templates/assets/woff2/f.woff2` 라는 존재하지 않는 주소가 된다.
 *
 *   이 실패는 서버 로그에 아무 흔적을 남기지 않는다. 요청은 정상 404 이고, 화면은 글꼴이
 *   기본 서체로 대체되거나 아이콘이 빈칸으로 보일 뿐이라 운영자가 원인을 특정할 단서가 없다.
 *   실제로 사용자 템플릿의 웹폰트 1건과 관리자 템플릿 국기 아이콘 약 500건이 이 상태였다.
 *
 * 왜 서빙 시점인가:
 *   최종 URL 은 런타임 모드와 캐시 버전이 정한다 — 빌드 시점에는 알 수 없다. 그리고 동봉
 *   자산은 제3자 산출물이라 원본을 손대면 상류 갱신 때마다 재작업이 된다. 그래서 원본은
 *   상대 경로 그대로 두고, 내보내는 순간에만 해석한다.
 *
 * 대상이 아닌 것:
 *   절대 URL(`https://`, `//`), 루트 상대(`/`), `data:`/`about:` 등 스킴 참조, 빈 참조.
 *   정적 게시본(`/build/ext/{v}/...`)은 웹서버가 직접 서빙하고 경로 형태라 상대 해석이
 *   정상이므로 이 경로를 타지 않는다.
 */
class AssetCssUrlRewriter
{
    /** `url(...)` 참조 — 따옴표 3종(없음/홑/겹)을 모두 받는다 */
    private const URL_RE = '/\burl\(\s*(["\']?)(.*?)\1\s*\)/s';

    /** `@import "..."` / `@import \'...\'` (url() 없이 문자열만 오는 형태) */
    private const IMPORT_RE = '/@import\s+(["\'])(.*?)\1/s';

    /**
     * CSS 안의 상대 참조를 절대 자산 URL 로 치환합니다.
     *
     * @param  string  $css  원본 CSS
     * @param  string  $cssPath  확장 기준 CSS 경로 (서빙 요청에 쓰인 것과 같은 좌표계)
     * @param  callable(string): string  $urlFor  확장 기준 경로 → 절대 자산 URL 변환기
     * @return string 치환된 CSS
     */
    public static function rewrite(string $css, string $cssPath, callable $urlFor): string
    {
        $baseDir = self::baseDirectory($cssPath);

        $replace = function (array $m) use ($baseDir, $urlFor): string {
            $quote = $m[1];
            $ref = trim($m[2]);

            $resolved = self::resolve($ref, $baseDir);

            if ($resolved === null) {
                return $m[0];
            }

            [$path, $fragment] = $resolved;

            $url = $urlFor($path).$fragment;

            // 따옴표가 없던 참조도 겹따옴표로 감싼다 — 생성된 URL 은 `?`·`&` 를 포함할 수
            // 있는데, 따옴표 없는 url() 토큰에서 그 문자들은 CSS 문법상 허용되지 않는다.
            $quote = $quote !== '' ? $quote : '"';

            return str_starts_with($m[0], '@import')
                ? '@import '.$quote.$url.$quote
                : 'url('.$quote.$url.$quote.')';
        };

        $css = preg_replace_callback(self::URL_RE, $replace, $css) ?? $css;

        return preg_replace_callback(self::IMPORT_RE, $replace, $css) ?? $css;
    }

    /**
     * 참조가 상대 경로인지 판정하고, 확장 기준 절대 경로로 해석합니다.
     *
     * @param  string  $ref  CSS 안의 원본 참조
     * @param  array<int, string>  $baseDir  CSS 가 놓인 디렉토리 세그먼트
     * @return array{0: string, 1: string}|null `[확장 기준 경로, 프래그먼트]` 또는 대상 아님이면 null
     */
    private static function resolve(string $ref, array $baseDir): ?array
    {
        if ($ref === '') {
            return null;
        }

        // 루트 상대(`/x`) · 프로토콜 상대(`//host/x`) · 스킴 참조(`https:`, `data:`, `#`) 는 그대로 둔다.
        if ($ref[0] === '/' || $ref[0] === '#' || preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $ref) === 1) {
            return null;
        }

        // 프래그먼트는 보존하고(레거시 `#iefix` 등), 참조 자신의 쿼리는 버린다 —
        // 생성되는 자산 URL 이 자기 캐시 버전 쿼리를 갖는다.
        $fragment = '';
        if (($hash = strpos($ref, '#')) !== false) {
            $fragment = substr($ref, $hash);
            $ref = substr($ref, 0, $hash);
        }

        if (($q = strpos($ref, '?')) !== false) {
            $ref = substr($ref, 0, $q);
        }

        if ($ref === '') {
            return null;
        }

        $segments = $baseDir;

        foreach (explode('/', $ref) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            return null;
        }

        return [implode('/', $segments), $fragment];
    }

    /**
     * CSS 경로가 놓인 디렉토리 세그먼트를 구합니다.
     *
     * @param  string  $cssPath  확장 기준 CSS 경로
     * @return array<int, string> 디렉토리 세그먼트 (루트면 빈 배열)
     */
    private static function baseDirectory(string $cssPath): array
    {
        $parts = explode('/', trim(str_replace('\\', '/', $cssPath), '/'));

        array_pop($parts);

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== '' && $p !== '.'));
    }
}
