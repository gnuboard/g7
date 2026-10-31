<?php

namespace App\Support;

use App\Extension\HookManager;

/**
 * 서버가 만드는 **사람이 여는 화면 주소** 헬퍼 (#792 D4·D13·D14).
 *
 * 비밀번호 재설정·알림 메일 링크·결제 뒤 돌아가는 주소처럼 서버가 화면 주소를 만들 때 쓴다. 호출자가 키와 G7
 * 기본 경로를 함께 넘기고(`ScreenUrl::path('auth.reset_password', '/reset-password')`), 개발자는 필터 훅
 * `core.screen_url.filter_path` 로 주소 구조를 바꾼다(외부 화면 Next.js 등). 키 등록부는 없다.
 *
 * - 절대 주소의 베이스: `user` 범위 = 환경설정 「사용자 화면 URL」(비우면 사이트 URL), `admin` 범위 = 사이트 URL.
 *   필터가 절대 URL 을 돌려주면 그대로 쓴다.
 * - 서버가 받는 주소(PG 콜백·웹훅·본인인증 브리지)는 이 헬퍼가 아니라 {@see SiteUrl} 이다 — 외부 화면 주소로
 *   콜백을 보내면 결제가 깨진다.
 * - G7 엔진 SEO 캐시 무효화처럼 G7 화면 자체의 주소가 필요한 곳은 필터를 거치지 않은 기본 경로를 쓴다.
 *
 * @since 7.2.0
 */
final class ScreenUrl
{
    /** 주소 필터 훅 이름 — (경로, 키, 파라미터, 범위) */
    public const FILTER = 'core.screen_url.filter_path';

    /** 사용자 화면 범위 */
    public const SCOPE_USER = 'user';

    /** 관리자 화면 범위 */
    public const SCOPE_ADMIN = 'admin';

    /**
     * 화면 경로를 만듭니다 (`{name}` 치환 → 필터).
     *
     * @param  string  $key  화면 키 (예: `auth.reset_password`, `board.post`)
     * @param  string  $defaultPath  G7 기본 경로 (예: `/board/{slug}/{id}`, 쿼리 포함 가능)
     * @param  array<string, scalar|null>  $params  자리 값 (경로 구간은 URL 인코딩)
     * @param  string  $scope  범위 (`user`|`admin`)
     * @return string 필터를 거친 경로 (또는 필터가 돌려준 절대 URL)
     *
     * @throws \InvalidArgumentException 채우지 못한 자리가 남으면
     */
    public static function path(string $key, string $defaultPath, array $params = [], string $scope = self::SCOPE_USER): string
    {
        $path = self::fill($defaultPath, $params);

        $filtered = HookManager::applyFilters(self::FILTER, $path, $key, $params, $scope);

        return is_string($filtered) && $filtered !== '' ? $filtered : $path;
    }

    /**
     * 화면 절대 주소를 만듭니다.
     *
     * @param  string  $key  화면 키
     * @param  string  $defaultPath  G7 기본 경로
     * @param  array<string, scalar|null>  $params  자리 값
     * @param  string  $scope  범위 (`user`|`admin`)
     * @return string 절대 URL
     */
    public static function absolute(string $key, string $defaultPath, array $params = [], string $scope = self::SCOPE_USER): string
    {
        return self::toAbsolute(self::path($key, $defaultPath, $params, $scope), $scope);
    }

    /**
     * 이미 만들어진 화면 경로(또는 절대 URL)를 범위의 베이스로 절대화합니다.
     *
     * @param  string  $pathOrUrl  경로 또는 절대 URL
     * @param  string  $scope  범위 (`user`|`admin`)
     * @return string 절대 URL
     */
    public static function toAbsolute(string $pathOrUrl, string $scope = self::SCOPE_USER): string
    {
        if (preg_match('#^https?://#i', $pathOrUrl) === 1) {
            return $pathOrUrl;
        }

        $base = $scope === self::SCOPE_ADMIN ? SiteUrl::base() : self::userBase();

        $query = '';
        $position = strpos($pathOrUrl, '?');
        if ($position !== false) {
            $query = substr($pathOrUrl, $position);
            $pathOrUrl = substr($pathOrUrl, 0, $position);
        }

        $trimmed = trim($pathOrUrl, '/');

        // 베이스가 하위 폴더(`https://example.com/g7`)이고 경로가 그 접두사를 이미 실었으면 다시 붙이지 않는다 —
        // SiteUrl::absolute() 와 같은 멱등 규칙(SiteUrl::join()).
        return ($trimmed === '' ? $base.'/' : SiteUrl::join($base, '/'.$trimmed)).$query;
    }

    /**
     * 사용자 화면 베이스 URL (환경설정 「사용자 화면 URL」, 비우면 사이트 URL).
     *
     * @return string 끝 슬래시 없는 베이스 URL
     */
    public static function userBase(): string
    {
        $configured = rtrim(trim((string) g7_core_settings('general.user_screen_url', '')), '/');

        return $configured !== '' ? $configured : SiteUrl::base();
    }

    /**
     * URL 이 사용자 화면 베이스와 같은 출처(scheme·host·port)인지 판정합니다.
     *
     * @param  string  $url  검사할 URL
     * @return bool 같은 출처면 true
     */
    public static function isUserScreenOrigin(string $url): bool
    {
        // 브라우저와 PHP 가 다르게 해석하는 입력(역슬래시·제어 문자)과 userinfo 는 거부한다 — SiteUrl::isSameOrigin() 과 같은 기준
        foreach ([$url, rawurldecode($url)] as $candidate) {
            if (str_contains($candidate, '\\') || preg_match('/[\x00-\x20\x7F]/', $candidate)) {
                return false;
            }
        }
        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $origin = self::origin($url);

        return $origin !== null && $origin === self::origin(self::userBase());
    }

    /**
     * URL 의 출처 문자열을 만듭니다.
     *
     * @param  string  $url  URL
     * @return string|null `scheme://host[:port]` (소문자), 파싱 불가면 null
     */
    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $port = $parts['port'] ?? null;
        $defaultPort = $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null);

        return $scheme.'://'.strtolower((string) $parts['host']).($port !== null && $port !== $defaultPort ? ':'.$port : '');
    }

    /**
     * `{name}` 자리를 채웁니다. 경로 부분 값은 URL 인코딩하고 쿼리 부분 값도 인코딩합니다.
     *
     * @param  string  $template  기본 경로 템플릿
     * @param  array<string, scalar|null>  $params  자리 값
     * @return string 채운 경로
     *
     * @throws \InvalidArgumentException 채우지 못한 자리가 남으면
     */
    private static function fill(string $template, array $params): string
    {
        $filled = preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', function (array $m) use ($params): string {
            if (! array_key_exists($m[1], $params) || $params[$m[1]] === null) {
                return $m[0];
            }

            return rawurlencode((string) $params[$m[1]]);
        }, $template);

        if (preg_match('/\{[A-Za-z0-9_]+\}/', (string) $filled, $left) === 1) {
            throw new \InvalidArgumentException(__('settings.custom_reserved.errors.screen_url_missing_param', ['placeholder' => $left[0], 'template' => $template]));
        }

        return (string) $filled;
    }
}
