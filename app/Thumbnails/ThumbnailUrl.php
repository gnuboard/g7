<?php

namespace App\Thumbnails;

use App\Extension\HookManager;
use App\Support\AssetUrl;

/**
 * 서버 쪽 썸네일 URL 유틸 (og:image·SEO 봇 렌더·확장 공용) — 프론트 `resources/js/core/support/thumbnailUrl.ts` 의 짝.
 *
 * `size` 를 붙여도 되는 주소는 「같은 출처 + 등록된 서빙 경로」뿐이다. 정적 `/storage/...`·외부·CDN 직접 URL·
 * `data:`·`blob:` 는 그대로 둔다 — 그 주소들은 서버가 썸네일을 만들 지점을 지나지 않는다.
 * 서빙 경로 목록은 필터 `core.thumbnail.filter_url_patterns`(고정 접두 + `*` 한 조각 와일드카드)로 모은다.
 *
 * 쿼리는 문자열 덧붙임만 한다(재직렬화하면 서명 URL 의 서명이 깨진다). 마지막에 필터 `core.thumbnail.filter_url`
 * (값: URL, 인자: 원 URL·규격)을 지나 — 변환 서비스(이미지 CDN)로 위임하는 확장 지점이다.
 */
final class ThumbnailUrl
{
    /** @var list<string> 코어 서빙 경로 */
    public const CORE_PATTERNS = [
        '/api/attachment/*',
        '/api/templates/*/layout-attachments/*/file',
    ];

    /**
     * 주소에 규격을 붙입니다 (대상이 아니면 그대로).
     *
     * @param  string  $url  원 주소 (상대·사이트 절대)
     * @param  string  $preset  규격 이름
     * @return string 규격이 붙은 주소
     */
    public static function for(string $url, string $preset): string
    {
        $result = self::isThumbnailable($url) ? self::withSize($url, $preset) : $url;
        $filtered = HookManager::applyFilters('core.thumbnail.filter_url', $result, $url, $preset);

        return is_string($filtered) && $filtered !== '' ? $filtered : $result;
    }

    /**
     * 고정 규격의 2배 밀도 srcset — 프론트 `thumbnailSrcset()` 의 짝.
     *
     * 2x 후보 = 같은 맞춤의 규격 중 가로가 1.5배 이상인 가장 작은 것. 자르기·맞추기는 가로세로 비가 같아야 한다
     * (비가 다른 규격을 2x 로 주면 고해상도 화면에서만 다른 모양으로 잘린다). 후보가 없으면 1x 만 낸다.
     *
     * @param  string  $url  원 주소
     * @param  string  $preset  1x 규격 이름
     * @return string srcset (대상이 아니면 빈 문자열)
     */
    public static function srcset(string $url, string $preset): string
    {
        $preset = trim($preset);

        if ($preset === '' || ! self::isThumbnailable($url)) {
            return '';
        }

        $presets = app(ThumbnailPresetRegistry::class)->forFrontend();
        $base = null;

        foreach ($presets as $row) {
            if ($row['name'] === $preset) {
                $base = $row;

                break;
            }
        }

        $oneX = self::srcsetUrl(self::for($url, $preset)).' 1x';

        if ($base === null || ! is_int($base['width']) || $base['width'] <= 0) {
            return $oneX;
        }

        $baseRatio = self::aspectRatio($base);
        $candidate = null;

        foreach ($presets as $row) {
            if ($row['name'] === $preset || $row['fit'] !== $base['fit'] || ! is_int($row['width']) || $row['width'] <= 0) {
                continue;
            }

            if ($row['width'] < $base['width'] * 1.5) {
                continue;
            }

            if ($base['fit'] !== 'width') {
                $ratio = self::aspectRatio($row);

                if ($baseRatio === null || $ratio === null || abs($ratio - $baseRatio) >= 0.01) {
                    continue;
                }
            }

            if ($candidate === null || $row['width'] < $candidate['width']) {
                $candidate = $row;
            }
        }

        return $candidate === null
            ? $oneX
            : $oneX.', '.self::srcsetUrl(self::for($url, $candidate['name'])).' 2x';
    }

    /**
     * 본문 이미지 srcset — 숫자 요청 대상 규격 전부를 가로(`w`) 기술자로. 프론트 `contentSrcset()` 의 짝.
     *
     * @param  string  $url  원 주소
     * @return string srcset (대상이 아니거나 규격이 없으면 빈 문자열)
     */
    public static function contentSrcset(string $url): string
    {
        if (! self::isThumbnailable($url)) {
            return '';
        }

        $rows = array_values(array_filter(
            app(ThumbnailPresetRegistry::class)->forFrontend(),
            fn (array $row) => ($row['numeric'] ?? false) === true && is_int($row['width']) && $row['width'] > 0
        ));
        usort($rows, fn (array $a, array $b) => $a['width'] <=> $b['width']);

        $seen = [];
        $entries = [];

        foreach ($rows as $row) {
            if (isset($seen[$row['width']])) {
                continue;
            }

            $seen[$row['width']] = true;
            $entries[] = self::srcsetUrl(self::for($url, $row['name'])).' '.$row['width'].'w';
        }

        return implode(', ', $entries);
    }

    /**
     * 본문 이미지 규격 이름 (`upload.thumbnail_content_preset`, 기본 lg)
     *
     * @return string 규격 이름
     */
    public static function contentPreset(): string
    {
        $preset = config('core.thumbnail.content_preset', 'lg');

        return is_string($preset) && trim($preset) !== '' ? trim($preset) : 'lg';
    }

    /**
     * srcset 후보 주소 — 공백은 srcset 구문에서 후보 구분자라 인코딩한다.
     *
     * @param  string  $url  주소
     * @return string 주소
     */
    private static function srcsetUrl(string $url): string
    {
        return str_replace(' ', '%20', $url);
    }

    /**
     * 가로세로 비 (세로가 없으면 null)
     *
     * @param  array<string, mixed>  $row  규격 행
     * @return float|null 세로/가로
     */
    private static function aspectRatio(array $row): ?float
    {
        $width = $row['width'] ?? null;
        $height = $row['height'] ?? null;

        return is_int($width) && $width > 0 && is_int($height) && $height > 0 ? $height / $width : null;
    }

    /**
     * 썸네일을 붙여도 되는 주소인지
     *
     * @param  string  $url  주소
     * @return bool 같은 출처 + 등록된 서빙 경로면 true
     */
    public static function isThumbnailable(string $url): bool
    {
        if (! (bool) config('core.thumbnail.enabled', true)) {
            return false;
        }

        $path = self::sameOriginPath($url);

        if ($path === null) {
            return false;
        }

        foreach (self::patterns() as $pattern) {
            if (self::matchesPattern($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 등록된 서빙 경로 패턴 (코어 + 확장)
     *
     * @return list<string> 패턴
     */
    public static function patterns(): array
    {
        $patterns = HookManager::applyFilters('core.thumbnail.filter_url_patterns', self::CORE_PATTERNS);

        if (! is_array($patterns)) {
            return self::CORE_PATTERNS;
        }

        return array_values(array_unique(array_filter(
            $patterns,
            fn ($pattern) => is_string($pattern) && str_starts_with($pattern, '/api/')
        )));
    }

    /**
     * 경로가 패턴에 맞는지 — `*` 는 `/` 가 없는 한 조각, 패턴 끝까지 정확히 맞아야 한다.
     *
     * @param  string  $path  경로 (쿼리 없음)
     * @param  string  $pattern  패턴
     * @return bool 맞으면 true
     */
    public static function matchesPattern(string $path, string $pattern): bool
    {
        $regex = '#^'.str_replace('\*', '[^/]+', preg_quote($pattern, '#')).'$#';

        return preg_match($regex, $path) === 1;
    }

    /**
     * `size` 를 덧붙입니다 — 기존 `size=` 는 교체, `#` 앞에 넣는다. 다른 쿼리는 원문 그대로 둔다.
     *
     * @param  string  $url  주소
     * @param  string  $preset  규격 이름
     * @return string 주소
     */
    public static function withSize(string $url, string $preset): string
    {
        $fragment = '';
        $hashAt = strpos($url, '#');

        if ($hashAt !== false) {
            $fragment = substr($url, $hashAt);
            $url = substr($url, 0, $hashAt);
        }

        $encoded = rawurlencode($preset);
        $questionAt = strpos($url, '?');

        if ($questionAt === false) {
            return $url.'?size='.$encoded.$fragment;
        }

        $base = substr($url, 0, $questionAt);
        // 지우는 키 판정은 서버 해석(ThumbnailQuery)과 같은 규칙이다 — `size[]`·`size%5B%5D` 를 남기면
        // 서버가 size 를 두 번 센 것으로 보고 원본을 낸다.
        $ignore = ThumbnailQuery::ignoreClosure();
        $pairs = array_values(array_filter(
            explode('&', substr($url, $questionAt + 1)),
            fn (string $pair) => $pair !== '' && ! $ignore(explode('=', $pair, 2)[0])
        ));
        $pairs[] = 'size='.$encoded;

        return $base.'?'.implode('&', $pairs).$fragment;
    }

    /**
     * 같은 출처 주소의 사이트 루트 기준 경로 (하위 폴더 접두는 벗긴다). 다른 출처·정적·data·blob 은 null.
     *
     * @param  string  $url  주소
     * @return string|null 경로
     */
    private static function sameOriginPath(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '//') || preg_match('#^(data|blob):#i', $url) === 1) {
            return null;
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            $host = parse_url($url, PHP_URL_HOST);
            $port = parse_url($url, PHP_URL_PORT);
            $siteHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            $sitePort = parse_url((string) config('app.url'), PHP_URL_PORT);
            $requestHost = app()->bound('request') ? request()->getHost() : null;

            $sameHost = is_string($host) && (strcasecmp($host, (string) $siteHost) === 0
                || ($requestHost !== null && strcasecmp($host, $requestHost) === 0));

            if (! $sameHost || ($port !== null && $sitePort !== null && $port !== $sitePort && $requestHost === null)) {
                return null;
            }

            $path = (string) parse_url($url, PHP_URL_PATH);
        } elseif (str_starts_with($url, '/')) {
            $path = (string) parse_url($url, PHP_URL_PATH);
        } else {
            return null;
        }

        foreach ([AssetUrl::basePrefix(), AssetUrl::configuredBasePath()] as $candidate) {
            $prefix = rtrim((string) $candidate, '/');

            if ($prefix !== '' && AssetUrl::hasBasePrefix($path, $prefix)) {
                $path = substr($path, strlen($prefix)) ?: '/';

                break;
            }
        }

        return $path;
    }
}
