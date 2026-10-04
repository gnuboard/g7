<?php

namespace App\Support;

/**
 * 사이트 주소(`config('app.url')`) 기준 절대 URL 의 단일 출처
 *
 * Laravel `url()`·`route()`(절대)·상대 경로 `redirect()`·`redirect()->back()` 은 **현재 요청의
 * Host 헤더(또는 Referer)** 로 절대 URL 을 만든다. 요청 Host·Referer 는 방문자가 위조할 수 있다 —
 * 허용 도메인(TrustHosts)은 외부 도메인만 막을 뿐 사이트 주소의 하위 도메인·포트만 바꾼 Host·
 * 별칭·신뢰 프록시의 전달 헤더는 통과시킨다. 그래서 리다이렉트·콜백·제3자(PG·본인인증사) 전송·
 * 메일/알림 링크·저장되는 주소처럼 **다른 사람이나 다른 시스템에 닿는 절대 URL** 은 요청이 아니라
 * 운영자가 정한 사이트 주소로 이 클래스를 통해 만든다.
 *
 * 사이트 주소가 비어 있으면 요청 Host 로 되돌아가지 않는다 — 상대 경로를 돌려준다.
 */
final class SiteUrl
{
    /**
     * 사이트 주소(끝 슬래시 제거)를 반환합니다.
     *
     * @return string 사이트 주소 (예: `https://example.com`). 설정이 없으면 빈 문자열
     */
    public static function base(): string
    {
        return rtrim(trim((string) config('app.url')), '/');
    }

    /**
     * 경로를 사이트 주소 기준 절대 URL 로 바꿉니다.
     *
     * `url()` 과 같은 모양을 낸다 — 이미 완전한 URL(`https://`·`//`·`mailto:` 등)은 그대로 두고,
     * 끝 슬래시를 떼며, 쿼리 문자열은 보존한다. 다른 점은 기준 주소가 요청 Host 가 아니라는 것뿐이다.
     * 완전한 URL 을 통과시키므로 **요청에서 온 경로**(`getRequestUri()` 등)에는 `forPath()` 를 쓴다.
     *
     * @param  string  $path  경로 (예: `/products/1?page=2`)
     * @return string 절대 URL (사이트 주소가 없으면 상대 경로)
     */
    public static function absolute(string $path): string
    {
        if (app('url')->isValidUrl($path)) {
            return $path;
        }

        $query = '';
        $queryPosition = strpos($path, '?');
        if ($queryPosition !== false) {
            $query = substr($path, $queryPosition);
            $path = substr($path, 0, $queryPosition);
        }

        return rtrim(self::base().'/'.trim($path, '/'), '/').$query;
    }

    /**
     * 요청에서 온 경로(+쿼리)를 사이트 주소 안의 절대 URL 로 바꿉니다.
     *
     * 입력을 언제나 경로로 취급한다 — 제어 문자를 지우고 앞의 `/`·`\` 를 모두 뗀 뒤 `/` 하나로
     * 시작시킨다. 그래서 `//evil.com/x`·`/\evil.com` 같은 경로도 사이트 주소 밖으로 나가지 않는다
     * (`https://<사이트>/evil.com/x`).
     *
     * @param  string  $pathAndQuery  요청 경로 (예: `getRequestUri()` 값)
     * @return string 사이트 주소 안의 절대 URL (사이트 주소가 없으면 `/` 로 시작하는 상대 경로)
     */
    public static function forPath(string $pathAndQuery): string
    {
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $pathAndQuery);
        $path = '/'.ltrim($clean, '/\\');

        return self::base().$path;
    }

    /**
     * 사이트 주소의 origin(scheme://host[:port])을 반환합니다.
     *
     * @return string 소문자 정규화된 origin (사이트 주소가 없으면 빈 문자열)
     */
    public static function origin(): string
    {
        $parts = parse_url(self::base());

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $origin = strtolower($parts['scheme']).'://'.strtolower($parts['host']);

        return isset($parts['port']) ? $origin.':'.$parts['port'] : $origin;
    }

    /**
     * 사이트 주소의 host(소문자)와 명시된 포트를 반환합니다.
     *
     * 요청의 `getHttpHost()` 와 비교하는 용도다 — 둘 다 표준 포트는 생략하고 명시 포트만 붙인다.
     *
     * @return string 예: `example.com`, `example.com:8443` (사이트 주소가 없으면 빈 문자열)
     */
    public static function hostWithPort(): string
    {
        $parts = parse_url(self::base());

        if (! is_array($parts) || ! isset($parts['host'])) {
            return '';
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $scheme = strtolower($parts['scheme'] ?? '');

        if ($port === null || ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            return $host;
        }

        return $host.':'.$port;
    }

    /**
     * 주소가 사이트 주소와 같은 출처를 가리키는지 판정합니다.
     *
     * 브라우저와 PHP 가 다르게 해석할 수 있는 입력은 모두 거부한다 — 역슬래시·제어 문자·공백이
     * 들어 있으면 false(`/\evil.com` 은 브라우저가 `//evil.com` 으로, `https://evil.com\@site/` 는
     * PHP 가 site 로·브라우저가 evil.com 으로 읽는다). 상대 경로는 `/` 로 시작하고 두 번째 글자가
     * `/`·`\` 가 아닐 때만, 절대 URL 은 userinfo 가 없고 scheme·host·port(기본 포트 정규화)가 사이트
     * 주소와 모두 같을 때만 true.
     *
     * @param  string  $url  검사할 주소
     * @return bool 같은 출처면 true
     */
    public static function isSameOrigin(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        foreach ([$url, rawurldecode($url)] as $candidate) {
            if (str_contains($candidate, '\\') || preg_match('/[\x00-\x20\x7F]/', $candidate)) {
                return false;
            }
        }

        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//');
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $site = parse_url(self::base());
        if (! is_array($site) || ! isset($site['scheme'], $site['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $siteScheme = strtolower($site['scheme']);

        return $scheme === $siteScheme
            && strtolower($parts['host']) === strtolower($site['host'])
            && self::effectivePort($scheme, $parts['port'] ?? null) === self::effectivePort($siteScheme, $site['port'] ?? null);
    }

    /**
     * 명시 포트가 없으면 scheme 의 기본 포트를 돌려줍니다.
     *
     * @param  string  $scheme  소문자 scheme
     * @param  int|null  $port  명시 포트
     * @return int|null 비교에 쓸 포트 (알 수 없는 scheme 이면 null)
     */
    private static function effectivePort(string $scheme, ?int $port): ?int
    {
        if ($port !== null) {
            return $port;
        }

        return match ($scheme) {
            'https' => 443,
            'http' => 80,
            default => null,
        };
    }
}
