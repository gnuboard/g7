<?php

namespace App\Seo;

/**
 * SEO 캐시 설정 해석기 (D19 우선순위 규칙의 단일 출처)
 *
 * 캐시 설정은 두 곳에서 지정할 수 있다:
 *
 *   - 고급 탭 (`cache.*`)  — 모든 캐시 설정의 메인(기준값)
 *   - SEO 탭  (`seo.*`)    — 해당 영역에 별도 지정이 있으면 그것이 오버라이드
 *
 * "별도 지정" 판정은 **null 여부**로 한다 — SEO 탭 값이 null(미설정)이면 고급 탭 값을 따르고,
 * 값이 있으면(false / 0 포함) 그 값이 이긴다. `Config::get` 은 키가 존재하면 값이 null 이어도
 * 기본값으로 대체하지 않으므로 null 이 "미설정" 신호로 그대로 전달된다.
 *
 * 이 규칙이 호출처마다 흩어지면 도메인별로 우선순위가 갈라진다(과거 결함의 원인).
 * SEO 캐시 설정을 읽는 모든 지점은 이 클래스를 경유한다.
 */
final class SeoCacheSettings
{
    /**
     * SEO 페이지 캐시 사용 여부
     *
     * @return bool 캐시 사용 여부
     */
    public static function pageCacheEnabled(): bool
    {
        return (bool) self::resolve('seo.cache_enabled', 'cache.seo_enabled', true);
    }

    /**
     * SEO 페이지 캐시 유지 시간 (초)
     *
     * @return int 캐시 TTL
     */
    public static function pageCacheTtl(): int
    {
        return (int) self::resolve('seo.cache_ttl', 'cache.seo_ttl', 7200);
    }

    /**
     * Sitemap 캐시 유지 시간 (초)
     *
     * @return int 캐시 TTL
     */
    public static function sitemapCacheTtl(): int
    {
        return (int) self::resolve('seo.sitemap_cache_ttl', 'cache.seo_sitemap_ttl', 86400);
    }

    /**
     * 오버라이드 → 메인 → 기본값 순으로 설정값을 해석합니다.
     *
     * @param  string  $overrideKey  SEO 탭 키 (별도 지정 — null 이면 미설정)
     * @param  string  $mainKey  고급 탭 키 (메인)
     * @param  mixed  $fallback  둘 다 없을 때의 최종 기본값
     * @return mixed 해석된 설정값
     */
    private static function resolve(string $overrideKey, string $mainKey, mixed $fallback): mixed
    {
        return g7_core_settings($overrideKey) ?? g7_core_settings($mainKey, $fallback);
    }
}
