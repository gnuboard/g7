<?php

namespace App\Seo;

use App\Enums\SitemapChangeFreq;
use Illuminate\Support\Facades\Log;

/**
 * Sitemap XML 조각 렌더러
 *
 * urlset 헤더/푸터, URL 단위 <url> 블록, sitemapindex 를 생성합니다.
 * Sitemap XML 의 이스케이프 로직을 이 클래스 한 곳에서만 수행하며(단일 출처),
 * SitemapGenerator(수집측)와 SitemapWriter(기록측)가 이 렌더러를 공유합니다.
 *
 * 다국어(supported_locales 2개 이상)면 URL 하나당 로케일별 <url> 블록을 생성하고
 * 각 블록에 xhtml:link hreflang alternate 를 포함합니다. alternate 링크 집합은
 * base URL 당 한 번만 계산해 로케일별 <url> 에 재사용하므로, alternate 생성 비용은
 * 로케일 수의 제곱이 아니라 선형입니다.
 */
final class SitemapXmlRenderer
{
    /**
     * hreflang alternate 를 방출하는 지원 로케일 수 상한
     *
     * 로케일 수가 이 값을 넘으면 URL 하나당 alternate 링크가 과도하게 팽창하므로
     * alternate 를 생략합니다(로케일별 <url> 자체는 유지). 정상 다국어 구성(2~수개)은
     * 이 상한에 걸리지 않습니다.
     */
    public const MAX_HREFLANG = 50;

    /**
     * 다국어 여부 (지원 로케일 2개 이상)
     */
    private readonly bool $multilingual;

    /**
     * hreflang alternate 를 방출하는지 여부
     *
     * 다국어이면서 hreflang 이 켜져 있고 로케일 수가 상한 이하일 때만 true.
     */
    private readonly bool $hreflangActive;

    /**
     * SitemapXmlRenderer 생성자
     *
     * @param  array<int, string>  $supportedLocales  지원 로케일 목록
     * @param  string  $defaultLocale  기본 로케일
     * @param  bool  $hreflangEnabled  hreflang alternate 방출 여부 (기본 true)
     * @param  int  $maxHreflang  hreflang 을 방출할 로케일 수 상한
     */
    public function __construct(
        private readonly array $supportedLocales,
        private readonly string $defaultLocale,
        bool $hreflangEnabled = true,
        int $maxHreflang = self::MAX_HREFLANG,
    ) {
        $this->multilingual = count($this->supportedLocales) > 1;
        $this->hreflangActive = $this->multilingual
            && $hreflangEnabled
            && count($this->supportedLocales) <= $maxHreflang;
    }

    /**
     * 애플리케이션 로케일 설정으로 렌더러를 생성합니다.
     *
     * hreflang 방출 여부는 seo.sitemap_hreflang_enabled 설정(기본 true)을 따릅니다.
     * 로케일 수가 상한을 초과하면 alternate 를 생략하고 경고를 남깁니다.
     *
     * @return self 설정 기반 렌더러 인스턴스
     */
    public static function fromConfig(): self
    {
        $defaultLocale = (string) config('app.locale');
        $supportedLocales = (array) config('app.supported_locales', [$defaultLocale]);
        $hreflangEnabled = (bool) g7_core_settings('seo.sitemap_hreflang_enabled', true);

        if ($hreflangEnabled && count($supportedLocales) > self::MAX_HREFLANG) {
            Log::warning('[SEO] Sitemap hreflang alternate 링크가 로케일 수 상한을 초과하여 생략됩니다.', [
                'locale_count' => count($supportedLocales),
                'max_hreflang' => self::MAX_HREFLANG,
            ]);
        }

        return new self($supportedLocales, $defaultLocale, $hreflangEnabled);
    }

    /**
     * 다국어 모드 여부를 반환합니다.
     *
     * @return bool 지원 로케일이 2개 이상이면 true
     */
    public function isMultilingual(): bool
    {
        return $this->multilingual;
    }

    /**
     * URL 항목 하나가 생성하는 <url> 블록 개수를 반환합니다.
     *
     * 분할 임계(파일당 URL 수) 계산에 사용됩니다.
     *
     * @return int 다국어면 로케일 수, 단일 언어면 1
     */
    public function urlBlockCount(): int
    {
        return $this->multilingual ? count($this->supportedLocales) : 1;
    }

    /**
     * urlset 여는 태그(XML 선언 포함)를 반환합니다.
     *
     * @return string urlset 헤더 문자열
     */
    public function urlsetHeader(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";

        if ($this->hreflangActive) {
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'."\n";
            $xml .= '        xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";
        } else {
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        }

        return $xml;
    }

    /**
     * urlset 닫는 태그를 반환합니다.
     *
     * @return string urlset 푸터 문자열
     */
    public function urlsetFooter(): string
    {
        return '</urlset>';
    }

    /**
     * URL 항목 하나를 <url> 블록으로 렌더링합니다.
     *
     * 다국어면 로케일 수만큼의 <url> 블록을 이어붙여 반환합니다.
     *
     * @param  array{loc?: string, lastmod?: string, changefreq?: string, priority?: float}  $entry  URL 항목 (loc 은 절대 URL)
     * @return string <url> 블록 문자열 (loc 이 없으면 빈 문자열)
     */
    public function urlBlock(array $entry): string
    {
        $baseLoc = $entry['loc'] ?? '';
        if ($baseLoc === '') {
            return '';
        }

        if (! $this->multilingual) {
            return $this->renderSingleUrl($baseLoc, $entry);
        }

        // hreflang alternate 집합은 base URL 당 한 번만 계산해 로케일별 <url> 에 재사용합니다.
        // (로케일별로 다시 계산하면 alternate 생성이 로케일 수의 제곱이 됩니다.)
        $alternateLinks = $this->hreflangActive ? $this->buildAlternateLinks($baseLoc) : '';

        $xml = '';
        foreach ($this->supportedLocales as $locale) {
            $xml .= $this->renderLocalizedUrl($baseLoc, $entry, (string) $locale, $alternateLinks);
        }

        return $xml;
    }

    /**
     * 자식 sitemap 목록을 sitemapindex XML 로 렌더링합니다.
     *
     * @param  array<int, array{loc: string, lastmod?: ?string}>  $children  자식 sitemap 목록
     * @return string sitemapindex XML 문자열
     */
    public function sitemapIndex(array $children): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($children as $child) {
            $xml .= '  <sitemap>'."\n";
            $xml .= '    <loc>'.$this->escape($child['loc']).'</loc>'."\n";

            if (! empty($child['lastmod'])) {
                $xml .= '    <lastmod>'.$this->escape($child['lastmod']).'</lastmod>'."\n";
            }

            $xml .= '  </sitemap>'."\n";
        }

        $xml .= '</sitemapindex>';

        return $xml;
    }

    /**
     * 단일 언어 <url> 블록을 렌더링합니다.
     *
     * @param  string  $loc  절대 URL
     * @param  array  $entry  URL 항목
     * @return string <url> 블록 문자열
     */
    private function renderSingleUrl(string $loc, array $entry): string
    {
        $xml = '  <url>'."\n";
        $xml .= '    <loc>'.$this->escape($loc).'</loc>'."\n";
        $xml .= $this->renderMetaFields($entry);
        $xml .= '  </url>'."\n";

        return $xml;
    }

    /**
     * 특정 로케일의 <url> 블록을 렌더링합니다.
     *
     * hreflang alternate 링크 문자열은 호출부에서 base URL 당 한 번 계산해 전달합니다.
     *
     * @param  string  $baseLoc  기본 로케일 절대 URL
     * @param  array  $entry  URL 항목
     * @param  string  $locale  대상 로케일
     * @param  string  $alternateLinks  미리 계산된 hreflang alternate 링크 문자열 (비활성 시 빈 문자열)
     * @return string <url> 블록 문자열
     */
    private function renderLocalizedUrl(string $baseLoc, array $entry, string $locale, string $alternateLinks): string
    {
        $xml = '  <url>'."\n";
        $xml .= '    <loc>'.$this->escape($this->localizedLoc($baseLoc, $locale)).'</loc>'."\n";
        $xml .= $this->renderMetaFields($entry);
        $xml .= $alternateLinks;
        $xml .= '  </url>'."\n";

        return $xml;
    }

    /**
     * base URL 하나에 대한 hreflang alternate 링크 집합을 렌더링합니다.
     *
     * 모든 지원 로케일의 alternate + x-default 를 포함하며, 이 문자열은 base URL 의
     * 모든 로케일 <url> 블록에 동일하게 재사용됩니다.
     *
     * @param  string  $baseLoc  기본 로케일 절대 URL
     * @return string xhtml:link alternate 링크 문자열
     */
    private function buildAlternateLinks(string $baseLoc): string
    {
        $xml = '';

        // 모든 로케일의 hreflang alternate 링크
        foreach ($this->supportedLocales as $altLocale) {
            $altHref = $this->localizedLoc($baseLoc, (string) $altLocale);
            $xml .= '    <xhtml:link rel="alternate" hreflang="'.$this->escape((string) $altLocale).'" href="'.$this->escape($altHref).'"/>'."\n";
        }

        // x-default = 기본 로케일 URL
        $xml .= '    <xhtml:link rel="alternate" hreflang="x-default" href="'.$this->escape($baseLoc).'"/>'."\n";

        return $xml;
    }

    /**
     * lastmod / changefreq / priority 필드를 렌더링합니다.
     *
     * @param  array  $entry  URL 항목
     * @return string 메타 필드 문자열
     */
    private function renderMetaFields(array $entry): string
    {
        $xml = '';

        if (! empty($entry['lastmod'])) {
            $xml .= '    <lastmod>'.$this->escape((string) $entry['lastmod']).'</lastmod>'."\n";
        }

        // changefreq 는 sitemaps.org 폐쇄 어휘 — Enum 으로 정규화해 비표준 값은 출력하지 않는다.
        $changefreq = SitemapChangeFreq::normalize($entry['changefreq'] ?? null);
        if ($changefreq !== null) {
            $xml .= '    <changefreq>'.$changefreq.'</changefreq>'."\n";
        }

        if (isset($entry['priority'])) {
            $xml .= '    <priority>'.number_format((float) $entry['priority'], 1).'</priority>'."\n";
        }

        return $xml;
    }

    /**
     * 로케일별 URL 을 생성합니다.
     *
     * @param  string  $baseLoc  기본 로케일 절대 URL
     * @param  string  $locale  대상 로케일
     * @return string 로케일 URL (기본 로케일이면 원본 그대로)
     */
    private function localizedLoc(string $baseLoc, string $locale): string
    {
        return $locale === $this->defaultLocale
            ? $baseLoc
            : $baseLoc.'?locale='.$locale;
    }

    /**
     * XML 특수문자를 이스케이프합니다.
     *
     * @param  string  $value  원본 문자열
     * @return string 이스케이프된 문자열
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1, 'UTF-8');
    }
}
