<?php

namespace App\Support\ContentMedia;

/**
 * 본문 미디어 주소 서명·정규화 (#883 4단계)
 *
 * 본문에 저장하는 주소는 서명 없는 정식 주소다. 응답을 만들 때 정식 주소를 그 요청자에게만 유효한 서명 주소로 바꾸고(`sign`),
 * 편집 폼이 돌려보낸 본문은 저장 전에 서명 쿼리를 떼어 정식 주소로 되돌린다(`normalize`) — 서명 주소가 저장되면 만료 뒤 깨진다.
 * 정식 경로 문자열만 정규식으로 치환한다(DOM 재작성 없음 — 본문의 다른 부분을 건드리지 않는다).
 *
 * @since 7.1.5
 */
final class ContentMediaUrlRewriter
{
    /** 서명 유효 시간 기본값(분) — D15 */
    public const DEFAULT_TTL_MINUTES = 240;

    /** 서명 유효 시간 하한(분) */
    public const MIN_TTL_MINUTES = 5;

    /** 서명 유효 시간 상한(분) */
    public const MAX_TTL_MINUTES = 1440;

    /**
     * 본문 미디어 서명 유효 시간(분) — 코어 환경설정 「본문 콘텐츠」 `content.media_url_ttl_minutes` (D15).
     *
     * @return int 분 (5~1440, 기본 240)
     */
    public static function ttlMinutes(): int
    {
        $value = (int) g7_core_settings('content.media_url_ttl_minutes', self::DEFAULT_TTL_MINUTES);

        return max(self::MIN_TTL_MINUTES, min(self::MAX_TTL_MINUTES, $value > 0 ? $value : self::DEFAULT_TTL_MINUTES));
    }

    /**
     * 정식 주소를 서명 주소로 바꿉니다.
     *
     * @param  string|null  $html  본문 HTML
     * @param  string  $pattern  서빙 경로 정규식 본문(구분자 없음, `(?<hash>…)` 그룹 필수)
     * @param  callable(string $hash, string $canonical): string  $signer  해시 → 서명 주소
     * @return string|null 바꾼 본문 (null 이면 null)
     */
    public static function sign(?string $html, string $pattern, callable $signer): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        return preg_replace_callback(self::regex($pattern), function (array $m) use ($signer) {
            $canonical = $m['url'];

            return $m['quote'].$signer($m['hash'], $canonical).$m['quote'];
        }, $html) ?? $html;
    }

    /**
     * 서명 쿼리를 떼어 정식 주소로 되돌립니다.
     *
     * @param  string|null  $html  본문 HTML
     * @param  string  $pattern  서빙 경로 정규식 본문
     * @return string|null 정규화한 본문
     */
    public static function normalize(?string $html, string $pattern): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        return preg_replace_callback(self::regex($pattern), fn (array $m) => $m['quote'].self::stripOrigin($m['url']).$m['quote'], $html) ?? $html;
    }

    /**
     * 속성 값(따옴표 안) 전체가 서빙 주소(+쿼리)인 곳을 잡는 정규식.
     *
     * @param  string  $pattern  서빙 경로 정규식 본문
     * @return string 정규식
     */
    private static function regex(string $pattern): string
    {
        $path = str_replace('#', '\#', $pattern);

        return '#(?<quote>["\'])(?<url>(?:https?://[^"\'/\s]+)?'.$path.')(?:\?[^"\']*)?(?P=quote)#';
    }

    /**
     * 같은 사이트 절대 주소면 경로만 남깁니다(저장은 상대 정식 주소).
     *
     * @param  string  $url  주소
     * @return string 정식 주소
     */
    private static function stripOrigin(string $url): string
    {
        if (! preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $siteHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($host !== null && $siteHost !== null && strcasecmp($host, $siteHost) === 0) {
            return (string) (parse_url($url, PHP_URL_PATH) ?? $url);
        }

        return $url;
    }
}
