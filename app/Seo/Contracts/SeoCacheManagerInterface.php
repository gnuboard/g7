<?php

namespace App\Seo\Contracts;

interface SeoCacheManagerInterface
{
    /**
     * 캐시된 SEO HTML을 조회합니다.
     *
     * @param  string  $url  요청 URL
     * @param  string  $locale  로케일
     * @return string|null 캐시된 HTML 또는 null
     */
    public function get(string $url, string $locale): ?string;

    /**
     * SEO HTML을 캐시에 저장합니다.
     *
     * @param  string  $url  요청 URL
     * @param  string  $locale  로케일
     * @param  string  $html  렌더링된 HTML
     */
    public function put(string $url, string $locale, string $html): void;

    /**
     * 레이아웃명과 함께 SEO 캐시에 렌더링된 HTML 을 저장합니다.
     *
     * `invalidateByLayout()` 동작을 위해 레이아웃명을 함께 저장합니다.
     *
     * @param  string  $url  URL
     * @param  string  $locale  로케일
     * @param  string  $html  렌더링된 HTML
     * @param  string  $layoutName  레이아웃명 (빈 문자열 허용)
     */
    public function putWithLayout(string $url, string $locale, string $html, string $layoutName): void;

    /**
     * 특정 URL 패턴의 SEO 캐시를 무효화합니다.
     *
     * @param  string  $urlPattern  URL 패턴 (와일드카드 지원)
     * @return int 무효화된 캐시 수
     */
    public function invalidateByUrl(string $urlPattern): int;

    /**
     * 특정 레이아웃의 SEO 캐시를 무효화합니다.
     *
     * @param  string  $layoutName  레이아웃명
     * @return int 무효화된 캐시 수
     */
    public function invalidateByLayout(string $layoutName): int;

    /**
     * 전체 SEO 캐시를 삭제합니다.
     */
    public function clearAll(): void;

    /**
     * 캐시된 URL 목록을 반환합니다.
     *
     * @return array<string>
     */
    public function getCachedUrls(): array;
}
