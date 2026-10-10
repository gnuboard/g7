<?php

namespace App\Analytics;

/**
 * URL 정규화
 *
 * 같은 페이지가 여러 표기로 들어오면(스킴 차이·www 유무·추적 파라미터·프래그먼트)
 * 인기 페이지 목록에서 한 페이지가 여러 줄로 쪼개집니다. 저장 전에 한 형태로 맞춥니다.
 *
 * 스킴과 www 는 버리지 않고 접두 코드로 따로 담습니다 — 나중에 원래 주소로 되돌려
 * 링크를 걸어야 하기 때문입니다.
 */
class UrlNormalizer
{
    /** http:// */
    public const PREFIX_HTTP = 0;

    /** http://www. */
    public const PREFIX_HTTP_WWW = 1;

    /** https:// */
    public const PREFIX_HTTPS = 2;

    /** https://www. */
    public const PREFIX_HTTPS_WWW = 3;

    /**
     * URL 을 정규화해 접두 코드와 나머지로 나눕니다.
     *
     * @param  string  $url  원본 URL
     * @return array{name: string, url_prefix: int|null, query: array<string, string>}
     *                                                                                 정규화된 이름·접두 코드·정규화 전 쿼리 (캠페인 추출용)
     */
    public static function normalize(string $url): array
    {
        $url = trim($url);

        if ($url === '') {
            return ['name' => '', 'url_prefix' => null, 'query' => []];
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            // 상대 경로로 들어온 경우 — 경로만 정리한다.
            [$path, $query] = self::splitPathAndQuery($url);

            return [
                'name' => self::buildName('', $path, $query),
                'url_prefix' => null,
                'query' => $query,
            ];
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        $host = strtolower($parts['host']);
        $isWww = str_starts_with($host, 'www.');

        if ($isWww) {
            $host = substr($host, 4);
        }

        if (isset($parts['port'])) {
            $host .= ':'.$parts['port'];
        }

        $prefix = match (true) {
            $scheme === 'https' && $isWww => self::PREFIX_HTTPS_WWW,
            $scheme === 'https' => self::PREFIX_HTTPS,
            $isWww => self::PREFIX_HTTP_WWW,
            default => self::PREFIX_HTTP,
        };

        $query = self::parseQuery($parts['query'] ?? '');

        return [
            'name' => self::buildName($host, $parts['path'] ?? '/', $query),
            'url_prefix' => $prefix,
            'query' => $query,
        ];
    }

    /**
     * 정규화된 이름과 접두 코드로 원래 URL 을 되돌립니다.
     *
     * @param  string  $name  정규화된 이름
     * @param  int|null  $prefix  접두 코드
     * @return string 원래 형태의 URL
     */
    public static function restore(string $name, ?int $prefix): string
    {
        return match ($prefix) {
            self::PREFIX_HTTP => 'http://'.$name,
            self::PREFIX_HTTP_WWW => 'http://www.'.$name,
            self::PREFIX_HTTPS => 'https://'.$name,
            self::PREFIX_HTTPS_WWW => 'https://www.'.$name,
            default => $name,
        };
    }

    /**
     * 사이트 주소를 뗀 경로를 반환합니다.
     *
     * 인기 페이지 목록은 호스트가 늘 같으므로 경로만 보여 주는 편이 읽기 쉽습니다.
     *
     * @param  string  $name  정규화된 이름
     * @param  string|null  $siteHost  사이트 호스트 (없으면 설정에서 읽는다)
     * @return string 경로 부분
     */
    public static function stripSiteHost(string $name, ?string $siteHost = null): string
    {
        $siteHost ??= self::siteHost();

        if ($siteHost === '' || ! str_starts_with($name, $siteHost)) {
            return $name;
        }

        $path = substr($name, strlen($siteHost));

        return $path === '' ? '/' : $path;
    }

    /**
     * 화면에 보일 이름을 만듭니다.
     *
     * 사이트 호스트를 떼고 퍼센트 인코딩을 풉니다 — 한국어 주소·검색어가 「%ED%85%8C…」로 보이면 운영자가
     * 읽을 수 없습니다. 풀어서 올바른 UTF-8 글자가 아니거나 제어 문자가 섞이면 원문을 둡니다. 깨진
     * 바이트는 JSON 응답을 실패시키고 줄바꿈은 표를 깨뜨립니다. 저장된 이름(집계 라벨·이름 사전)은 그대로
     * 두는 화면 전용 변환입니다.
     *
     * @param  string  $name  정규화된 이름
     * @param  string|null  $siteHost  사이트 호스트 (없으면 설정에서 읽는다)
     * @return string 화면용 이름
     */
    public static function forDisplay(string $name, ?string $siteHost = null): string
    {
        $stripped = self::stripSiteHost($name, $siteHost);

        if (! str_contains($stripped, '%')) {
            return $stripped;
        }

        $decoded = rawurldecode($stripped);

        if (! mb_check_encoding($decoded, 'UTF-8') || preg_match('/\p{Cc}/u', $decoded) === 1) {
            return $stripped;
        }

        return $decoded;
    }

    /**
     * 사이트 자신의 호스트를 반환합니다(www 제거·소문자).
     *
     * @return string 호스트 (설정이 없으면 빈 문자열)
     */
    public static function siteHost(): string
    {
        $siteUrl = g7_core_settings('general.site_url', config('app.url', ''));

        if (! is_string($siteUrl) || $siteUrl === '') {
            return '';
        }

        $host = parse_url($siteUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return '';
        }

        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * 호스트·경로·쿼리로 정규화된 이름을 만듭니다.
     *
     * @param  string  $host  호스트 (없으면 빈 문자열)
     * @param  string  $path  경로
     * @param  array<string, string>  $query  쿼리 파라미터
     * @return string 정규화된 이름
     */
    private static function buildName(string $host, string $path, array $query): string
    {
        $path = $path === '' ? '/' : $path;

        // 추적 파라미터는 페이지를 가르는 값이 아니므로 뺀다.
        $stripped = AnalyticsTables::strippedQueryParams();
        $kept = array_diff_key($query, array_flip($stripped));

        // 같은 파라미터 조합이 순서만 달라도 다른 페이지로 세어지지 않게 정렬한다.
        ksort($kept);

        $name = $host.$path;

        if ($kept !== []) {
            $name .= '?'.http_build_query($kept);
        }

        return $name;
    }

    /**
     * 쿼리 문자열을 배열로 바꿉니다.
     *
     * @param  string  $queryString  쿼리 문자열
     * @return array<string, string> 파라미터 배열
     */
    private static function parseQuery(string $queryString): array
    {
        if ($queryString === '') {
            return [];
        }

        parse_str($queryString, $parsed);

        $result = [];
        foreach ($parsed as $key => $value) {
            if (is_string($value)) {
                $result[(string) $key] = $value;
            }
        }

        return $result;
    }

    /**
     * 상대 경로를 경로와 쿼리로 나눕니다.
     *
     * @param  string  $url  상대 경로
     * @return array{0: string, 1: array<string, string>} 경로와 쿼리 배열
     */
    private static function splitPathAndQuery(string $url): array
    {
        // 프래그먼트는 서버에 오지도 않고 페이지를 가르지도 않는다.
        $url = explode('#', $url, 2)[0];
        $pieces = explode('?', $url, 2);

        return [$pieces[0], self::parseQuery($pieces[1] ?? '')];
    }
}
