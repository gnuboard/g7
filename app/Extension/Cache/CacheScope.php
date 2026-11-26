<?php

namespace App\Extension\Cache;

/**
 * 캐시 키의 범위 — 검수 서버(실행 역할 stage)는 Code 범위 키를 `g7:stage:` 아래에 따로 둔다.
 *
 * - Code: 코드(레이아웃·템플릿·라우트·언어·SEO 설정 등)에서 만든 값과 그 서버의 레이아웃 표·환경에서 나온 값(레이아웃 해석·
 *   역변환 마크업·시스템 정보). 검수 서버와 운영 서버의 코드·레이아웃 표·환경이 다를 수 있으므로 나눈다.
 * - Data: 운영 데이터(활성 목록·설정·잠금·로그인 등). 서버 간에 공유한다.
 *
 * 새 키를 추가할 때: 그 값이 「이 서버의 코드·레이아웃 판에 따라 달라지는가」 면 Code, 「모든 서버가 같은 값을 봐야 하는가」 면 Data.
 * Code 키 목록은 {@see self::CODE_PATTERNS} 한 곳에 둔다(docs/extension/cache-driver.md 「캐시 범위」).
 *
 * @since 7.2.0
 */
enum CacheScope: string
{
    /**
     * 코드 범위 — 검수 서버는 따로 둔다
     */
    case Code = 'code';

    /**
     * 데이터 범위 — 서버 간 공유
     */
    case Data = 'data';

    /**
     * Code 범위 키 정규식.
     */
    public const CODE_PATTERNS = [
        '/^ext\.cache_version$/',
        '/^layout\./',
        // 레이아웃 해석 캐시 — 값이 이 서버 역할의 레이아웃 표 행 ID 다(검수 서버 전용 행은 10억 이상)
        '/^layout_resolver\./',
        // 역변환 마크업 캐시 — 검수 서버 표는 운영 행을 ID·lock_version 그대로 복사한 뒤 따로 고친다
        '/^layout:markup:/',
        // 시스템 정보 — 판·설치 경로·PHP 등 그 서버의 값이다
        '/^settings\.system_info\./',
        '/^template\.\d+\.(layout\.|components_manifest)/',
        '/^template\.(routes|language|config|editor_css)\./',
        '/^template-lang/',
        '/^seo\.(config|page)\./',
        '/^seo\.sitemap\.meta$/',
        '/^extension\.middleware_index$/',
        '/^ext\.(version_check|recovery_check)\./',
        '/^ext\.custom_signature$/',
        '/^custom_routes\.patterns\./',
    ];

    /**
     * 키의 범위를 판정합니다.
     *
     * @param  string  $key  캐시 키 (접두사 제외)
     * @return self 범위
     */
    public static function classify(string $key): self
    {
        foreach (self::CODE_PATTERNS as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return self::Code;
            }
        }

        return self::Data;
    }
}
