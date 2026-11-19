<?php

namespace App\Support;

/**
 * 확장이 돌려준 이동 주소를 이 사이트 안의 경로로만 남기는 판정.
 *
 * 로그인 진입 판정·자동 이동·로그아웃 이동·비밀번호 인증 거절 안내처럼 **확장이 정하고 화면이 그대로 따라가는**
 * 주소에 쓴다. 화면 쪽 이동 정책(`sanitizeNavPath`)과 같은 규칙이다 — `/` 로 시작하는 같은 출처 경로만 통과하고,
 * 이 사이트의 전체 주소는 경로로 바꿔 통과시키며, 그 밖(다른 출처·`//host`·`/\host`·스킴·상대 경로)은 null.
 * 외부 IdP 로 나가는 이동은 확장이 자기 같은 출처 라우트에서 서버 302 로 처리한다 — 이 판정은 그 라우트 경로를 받는다.
 *
 * @since 7.2.0
 */
final class SameOriginPath
{
    /** 경로 최대 길이 — 응답에 실어 돌려주는 값이라 상한을 둔다 */
    private const MAX_LENGTH = 2048;

    /**
     * 주소를 같은 출처 경로로 정규화합니다.
     *
     * @param  mixed  $value  확장이 돌려준 주소
     * @return string|null 같은 출처 경로(`/` 로 시작, 쿼리·조각 보존) 또는 null
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        if (str_starts_with($value, '/')) {
            return self::isSafeRelative($value) ? $value : null;
        }

        // 전체 주소는 이 사이트와 같은 출처일 때만 경로로 바꾼다 — 사이트 주소가 없으면 판정할 수 없어 거부.
        if (SiteUrl::base() === '' || ! SiteUrl::isSameOrigin($value)) {
            return null;
        }

        $parts = parse_url($value);

        if ($parts === false) {
            return null;
        }

        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        $path .= isset($parts['query']) ? '?'.$parts['query'] : '';
        $path .= isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return self::isSafeRelative($path) ? $path : null;
    }

    /**
     * `/` 로 시작하는 경로가 브라우저에서도 같은 출처로 읽히는지 판정합니다.
     *
     * @param  string  $path  `/` 로 시작하는 경로
     * @return bool 안전하면 true
     */
    private static function isSafeRelative(string $path): bool
    {
        // `//host`, `/\host` 는 브라우저가 다른 출처로 읽는다. 제어 문자·역슬래시는 PHP 와 브라우저의 해석이 갈린다.
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $path) === 1) {
            return false;
        }

        return ! str_starts_with($path, '//');
    }
}
