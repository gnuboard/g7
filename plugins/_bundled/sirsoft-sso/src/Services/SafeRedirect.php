<?php

namespace Plugins\Sirsoft\Sso\Services;

use App\Support\SiteUrl;

/**
 * 같은 출처 경로만 남기는 복귀 주소 판정 (서버 쪽 `sanitizeNavPath` 와 같은 규칙).
 *
 * IdP 왕복의 `redirect`, IdP-initiated 의 RelayState, 로그인 뒤 복귀 주소에 쓴다. 다른 출처로 보내는 열린 리디렉션을 막는다.
 *
 * @since 1.0.0
 */
final class SafeRedirect
{
    /** 경로 최대 길이 */
    private const MAX_LENGTH = 2000;

    /**
     * 복귀 주소를 같은 출처 상대 경로로 제한합니다.
     *
     * `/` 로 시작하고 `//`(프로토콜 상대 주소), 역슬래시, 스킴, 제어 문자가 없는 경로만 받는다. 사이트 주소와 같은 출처의 절대
     * 주소는 경로로 바꿔 받는다. 아니면 `$fallback`.
     *
     * @param  string|null  $redirect  요청한 복귀 주소
     * @param  string  $fallback  거절 때 돌려줄 경로
     * @return string 안전한 경로
     */
    public static function path(?string $redirect, string $fallback = '/'): string
    {
        if (! is_string($redirect) || $redirect === '' || strlen($redirect) > self::MAX_LENGTH) {
            return $fallback;
        }

        $redirect = self::stripSameOrigin($redirect);

        if ($redirect[0] !== '/' || str_starts_with($redirect, '//') || str_contains($redirect, '\\')) {
            return $fallback;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $redirect) === 1 || preg_match('#^/[^/?\#]*:#', $redirect) === 1) {
            return $fallback;
        }

        return $redirect;
    }

    /**
     * 경로가 안전한지만 판정합니다.
     *
     * @param  string|null  $redirect  경로
     * @return bool 같은 출처 상대 경로면 true
     */
    public static function isSafe(?string $redirect): bool
    {
        return is_string($redirect) && $redirect !== '' && self::path($redirect, '') === $redirect;
    }

    /**
     * 사이트 주소와 같은 출처의 절대 주소면 경로 부분만 남깁니다.
     *
     * @param  string  $url  주소
     * @return string 경로 또는 원래 값
     */
    private static function stripSameOrigin(string $url): string
    {
        if (! preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $site = parse_url(SiteUrl::absolute('/'));
        $target = parse_url($url);
        if (! is_array($site) || ! is_array($target)) {
            return $url;
        }

        $sameHost = strcasecmp((string) ($site['host'] ?? ''), (string) ($target['host'] ?? '')) === 0;
        $sameScheme = strcasecmp((string) ($site['scheme'] ?? ''), (string) ($target['scheme'] ?? '')) === 0;
        $samePort = ($site['port'] ?? null) === ($target['port'] ?? null);
        if (! $sameHost || ! $sameScheme || ! $samePort || isset($target['user']) || isset($target['pass'])) {
            return $url;
        }

        $path = (string) ($target['path'] ?? '/');
        $path = $path === '' ? '/' : $path;

        return $path.(isset($target['query']) ? '?'.$target['query'] : '').(isset($target['fragment']) ? '#'.$target['fragment'] : '');
    }
}
