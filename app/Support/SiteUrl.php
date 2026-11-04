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
 *
 * 사이트 주소가 하위 폴더(마운트 접두사)를 가지면, 경로가 그 접두사를 이미 실었을 때 다시 붙이지
 * 않는다(멱등). 같은 사이트 안 상대 경로에 접두사를 채우는 일은 `AssetUrl::sitePath()` 가 맡는다.
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
     * 사이트 주소가 하위 폴더(`https://example.com/g7`)이고 경로가 그 마운트 접두사를 **이미** 갖고
     * 있으면(`/g7`·`/g7/...`·`/g7?x`) 접두사를 다시 붙이지 않는다 — {@see self::join()}.
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

        $trimmed = trim($path, '/');

        if ($trimmed === '') {
            return self::base().$query;
        }

        return self::join(self::base(), '/'.$trimmed).$query;
    }

    /**
     * 화면 주소를 정본 끝 슬래시 형태로 맞춥니다 — 하위 폴더 마운트 루트만 끝 슬래시, 그 밖은 끝 슬래시 없음.
     *
     * 하위 폴더 설치(`https://example.com/g7`)의 마운트 루트는 웹서버에게 **폴더**라, 끝 슬래시 없는
     * `…/g7` 로 들어오면 웹서버가 `…/g7/` 로 301 을 돌려보낸다. 검색엔진에 알리는 정본(canonical·og:url·
     * hreflang·사이트맵 loc)이 접힌 형태면 그 주소마다 이동을 한 번 거치고, 엔진이 주소창에 두는 정본
     * (마운트 루트만 끝 슬래시 유지)과도 갈린다. 그 밖의 화면 주소는 끝·연속 슬래시 정규화 규칙대로
     * 끝 슬래시가 없는 형태가 정본이다. 루트 설치의 홈은 종전과 같이 `https://example.com` 이다.
     *
     * 쿼리는 그대로 뒤에 붙는다(`…/g7/?locale=en`). 마운트 접두사는 `$base`(생략 시 사이트 주소)의
     * 경로 부분으로 판정한다 — 사용자 화면 URL 처럼 사이트 주소와 다른 베이스로 만든 주소는 그 베이스를 넘긴다.
     *
     * @param  string  $url  절대 화면 주소 (쿼리 포함 가능)
     * @param  string|null  $base  주소를 만든 베이스 (null 이면 사이트 주소)
     * @return string 정본 끝 슬래시 형태의 주소
     */
    public static function canonicalScreenForm(string $url, ?string $base = null): string
    {
        $query = '';
        $queryPosition = strpos($url, '?');
        if ($queryPosition !== false) {
            $query = substr($url, $queryPosition);
            $url = substr($url, 0, $queryPosition);
        }

        $trimmed = rtrim($url, '/');
        $mount = self::pathOf(rtrim(trim((string) ($base ?? self::base())), '/'));

        if ($mount !== '' && parse_url($trimmed, PHP_URL_PATH) === $mount) {
            return $trimmed.'/'.$query;
        }

        return $trimmed.$query;
    }

    /**
     * 요청에서 온 경로(+쿼리)를 사이트 주소 안의 절대 URL 로 바꿉니다.
     *
     * 입력을 언제나 경로로 취급한다 — 제어 문자를 지우고 앞의 `/`·`\` 를 모두 뗀 뒤 `/` 하나로
     * 시작시킨다. 그래서 `//evil.com/x`·`/\evil.com` 같은 경로도 사이트 주소 밖으로 나가지 않는다
     * (`https://<사이트>/evil.com/x`).
     *
     * 하위 폴더 설치에서 요청 경로는 마운트 접두사를 싣고 온다(`/g7/products/1`). 그런 경로에는
     * 접두사를 다시 붙이지 않는다 — {@see self::join()}.
     *
     * @param  string  $pathAndQuery  요청 경로 (예: `getRequestUri()` 값)
     * @return string 사이트 주소 안의 절대 URL (사이트 주소가 없으면 `/` 로 시작하는 상대 경로)
     */
    public static function forPath(string $pathAndQuery): string
    {
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $pathAndQuery);
        $path = '/'.ltrim($clean, '/\\');

        return self::join(self::base(), $path);
    }

    /**
     * 사이트 주소의 경로 부분(마운트 접두사)을 반환합니다.
     *
     * 쿼리·프래그먼트는 경로가 아니므로 버리고, 끝 슬래시를 뗀다.
     *
     * @return string `''`(루트 설치·미설정) 또는 `/` 로 시작하고 `/` 로 끝나지 않는 접두사 (예: `/g7`)
     */
    public static function basePath(): string
    {
        return self::pathOf(self::base());
    }

    /**
     * 루트 상대 경로가 접두사를 **이미** 갖고 있는지 판정합니다.
     *
     * 접두사 뒤가 끝·`/`·`?`·`#` 일 때만 보유다. `/g7x/foo` 는 접두사가 경계 없이 이어진 다른 경로라
     * 보유가 아니다. 프론트 `withBasePrefix()`/`stripBasePrefix()` 와 같은 규칙이며,
     * `AssetUrl::hasBasePrefix()` 가 이 판정을 그대로 쓴다.
     *
     * @param  string  $path  `/` 로 시작하는 경로
     * @param  string  $prefix  `/` 로 시작하고 `/` 로 끝나지 않는 접두사
     * @return bool 접두사를 이미 갖고 있으면 true
     */
    public static function hasPathPrefix(string $path, string $prefix): bool
    {
        if (! str_starts_with($path, $prefix)) {
            return false;
        }

        $next = substr($path, strlen($prefix), 1);

        return $next === '' || $next === '/' || $next === '?' || $next === '#';
    }

    /**
     * 베이스 주소 뒤에 루트 상대 경로를 잇습니다 — 경로가 베이스의 경로 부분을 이미 가지면 다시 붙이지 않습니다.
     *
     * 하위 폴더 설치(`https://example.com/g7`)에서 서버가 만드는 사이트 경로에는 마운트 접두사를 이미
     * 실은 값(`AssetUrl::sitePath()` 결과·요청 URI)과 싣지 않은 값(`/login`)이 섞여 도착한다. 앞의 것에
     * 접두사를 한 번 더 붙이면 `/g7/g7/…` 가 되어 사이트 밖(404)을 가리킨다. 그래서 이미 가진 값은
     * 베이스의 오리진 뒤에, 없는 값은 베이스 뒤에 잇는다. 루트 설치(경로 부분 없음)에서는 단순 연결이다.
     *
     * @param  string  $base  끝 슬래시 없는 베이스 주소 (예: `https://example.com/g7`, 빈 문자열 가능)
     * @param  string  $path  `/` 로 시작하는 경로 (쿼리·프래그먼트 포함 가능)
     * @return string 이은 주소
     */
    public static function join(string $base, string $path): string
    {
        $prefix = self::pathOf($base);

        if ($prefix !== '' && str_ends_with($base, $prefix) && self::hasPathPrefix($path, $prefix)) {
            return substr($base, 0, strlen($base) - strlen($prefix)).$path;
        }

        return $base.$path;
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
     * 주소의 경로 부분을 접두사 꼴로 반환합니다.
     *
     * @param  string  $base  주소 (예: `https://example.com/g7/`)
     * @return string `''` 또는 `/` 로 시작하고 `/` 로 끝나지 않는 경로
     */
    private static function pathOf(string $base): string
    {
        $path = parse_url($base, PHP_URL_PATH);

        if (! is_string($path)) {
            return '';
        }

        $path = rtrim($path, '/');

        return str_starts_with($path, '/') ? $path : '';
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
