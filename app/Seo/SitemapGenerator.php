<?php

namespace App\Seo;

use App\Extension\TemplateManager;
use App\Seo\Contracts\SitemapContributorInterface;
use App\Services\TemplateService;
use Illuminate\Support\Facades\Log;

/**
 * Sitemap XML 생성기
 *
 * 정적 라우트(TemplateRouteResolver 기반)와
 * 등록된 SitemapContributorInterface 구현체로부터 URL을 수집하여
 * sitemap.xml을 생성합니다.
 */
class SitemapGenerator
{
    /** @var SitemapContributorInterface[] */
    private array $contributors = [];

    /**
     * SitemapGenerator 생성자
     *
     * @param  TemplateRouteResolver  $routeResolver  템플릿 라우트 해석기
     * @param  TemplateService  $templateService  템플릿 서비스
     */
    public function __construct(
        private readonly TemplateRouteResolver $routeResolver,
        private readonly TemplateService $templateService,
    ) {}

    /**
     * Sitemap 기여자를 등록합니다.
     *
     * @param  SitemapContributorInterface  $contributor  Sitemap 기여자
     */
    public function registerContributor(SitemapContributorInterface $contributor): void
    {
        $this->contributors[$contributor->getIdentifier()] = $contributor;
    }

    /**
     * 전체 Sitemap URL을 수집하여 XML을 생성합니다.
     *
     * @return string sitemap XML 문자열
     */
    public function generate(): string
    {
        $urls = [];

        // 1. 정적 라우트 수집 (파라미터 없는 공개 라우트)
        $urls = array_merge($urls, $this->collectStaticRoutes());

        // 2. 기여자들의 동적 URL 수집
        foreach ($this->contributors as $contributor) {
            try {
                $contributorUrls = $contributor->getUrls();

                // 기여자 URL의 'url' 키를 'loc'(절대 URL)으로 변환
                foreach ($contributorUrls as &$entry) {
                    if (isset($entry['url']) && ! isset($entry['loc'])) {
                        $entry['loc'] = url($entry['url']);
                        unset($entry['url']);
                    }
                }
                unset($entry);

                $urls = array_merge($urls, $contributorUrls);
            } catch (\Throwable $e) {
                Log::warning('[SEO] Sitemap contributor failed', [
                    'contributor' => $contributor->getIdentifier(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->buildXml($urls);
    }

    /**
     * 등록된 기여자 목록을 반환합니다.
     *
     * @return SitemapContributorInterface[] 기여자 배열
     */
    public function getContributors(): array
    {
        return $this->contributors;
    }

    /**
     * 정적 라우트를 수집합니다 (파라미터 없는 공개 라우트만).
     *
     * @return array<int, array{loc: string, changefreq?: string, priority?: float}> 정적 URL 배열
     */
    private function collectStaticRoutes(): array
    {
        try {
            $activeTemplate = TemplateManager::getActiveTemplate('user');
            if (! $activeTemplate) {
                return [];
            }

            $templateIdentifier = $activeTemplate['identifier'] ?? null;
            if (! $templateIdentifier) {
                return [];
            }

            $routesResult = $this->templateService->getRoutesDataWithModules($templateIdentifier);
            if (! ($routesResult['success'] ?? false) || empty($routesResult['data']['routes'])) {
                return [];
            }

            $urls = [];
            foreach ($routesResult['data']['routes'] as $route) {
                // auth_required 라우트 제외
                if ($route['auth_required'] ?? false) {
                    continue;
                }

                // guest_only 라우트 제외
                if ($route['guest_only'] ?? false) {
                    continue;
                }

                $routePath = $route['path'] ?? '';

                // 동적 파라미터(:id, :slug) 포함 라우트 제외
                if (str_contains($routePath, ':')) {
                    continue;
                }

                // 템플릿 표현식({{...}}) 포함 라우트 제외
                if (str_contains($routePath, '{{')) {
                    continue;
                }

                // 와일드카드(*) 접두사 제거
                $routePath = ltrim($routePath, '*/');
                if (! str_starts_with($routePath, '/')) {
                    $routePath = '/'.$routePath;
                }

                $urls[] = [
                    'loc' => url($routePath),
                    'changefreq' => 'weekly',
                    'priority' => 0.5,
                ];
            }

            return $urls;
        } catch (\Throwable $e) {
            Log::warning('[SEO] Static route collection failed', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * URL 배열을 다국어 sitemap XML로 변환합니다.
     *
     * supported_locales가 2개 이상이면 각 기본 URL에 대해 로케일별 <url> 항목을 생성하고,
     * 각 항목에 xhtml:link hreflang alternate 태그를 포함합니다.
     * 로케일이 1개뿐이면 기존 단일 언어 형식을 유지합니다.
     *
     * @param  array  $urls  URL 배열 (loc 키에 기본 로케일 절대 URL 포함)
     * @return string XML 문자열
     */
    private function buildXml(array $urls): string
    {
        $defaultLocale = config('app.locale');
        $supportedLocales = config('app.supported_locales', [$defaultLocale]);
        $isMultilingual = count($supportedLocales) > 1;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";

        if ($isMultilingual) {
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'."\n";
            $xml .= '        xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";
        } else {
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        }

        foreach ($urls as $entry) {
            $baseLoc = $entry['loc'];

            if ($isMultilingual) {
                // 각 로케일별 <url> 항목 생성
                foreach ($supportedLocales as $locale) {
                    $localeLoc = $locale === $defaultLocale
                        ? $baseLoc
                        : $baseLoc.'?locale='.$locale;

                    $xml .= '  <url>'."\n";
                    $xml .= '    <loc>'.htmlspecialchars($localeLoc, ENT_XML1, 'UTF-8').'</loc>'."\n";

                    if (! empty($entry['lastmod'])) {
                        $xml .= '    <lastmod>'.htmlspecialchars($entry['lastmod'], ENT_XML1, 'UTF-8').'</lastmod>'."\n";
                    }

                    if (! empty($entry['changefreq'])) {
                        $xml .= '    <changefreq>'.htmlspecialchars($entry['changefreq'], ENT_XML1, 'UTF-8').'</changefreq>'."\n";
                    }

                    if (isset($entry['priority'])) {
                        $xml .= '    <priority>'.number_format((float) $entry['priority'], 1).'</priority>'."\n";
                    }

                    // 모든 로케일의 hreflang alternate 링크
                    foreach ($supportedLocales as $altLocale) {
                        $altHref = $altLocale === $defaultLocale
                            ? $baseLoc
                            : $baseLoc.'?locale='.$altLocale;
                        $xml .= '    <xhtml:link rel="alternate" hreflang="'.htmlspecialchars($altLocale, ENT_XML1, 'UTF-8').'" href="'.htmlspecialchars($altHref, ENT_XML1, 'UTF-8').'"/>'."\n";
                    }

                    // x-default = 기본 로케일 URL
                    $xml .= '    <xhtml:link rel="alternate" hreflang="x-default" href="'.htmlspecialchars($baseLoc, ENT_XML1, 'UTF-8').'"/>'."\n";

                    $xml .= '  </url>'."\n";
                }
            } else {
                // 단일 언어: 기존 형식 유지
                $xml .= '  <url>'."\n";
                $xml .= '    <loc>'.htmlspecialchars($baseLoc, ENT_XML1, 'UTF-8').'</loc>'."\n";

                if (! empty($entry['lastmod'])) {
                    $xml .= '    <lastmod>'.htmlspecialchars($entry['lastmod'], ENT_XML1, 'UTF-8').'</lastmod>'."\n";
                }

                if (! empty($entry['changefreq'])) {
                    $xml .= '    <changefreq>'.htmlspecialchars($entry['changefreq'], ENT_XML1, 'UTF-8').'</changefreq>'."\n";
                }

                if (isset($entry['priority'])) {
                    $xml .= '    <priority>'.number_format((float) $entry['priority'], 1).'</priority>'."\n";
                }

                $xml .= '  </url>'."\n";
            }
        }

        $xml .= '</urlset>';

        return $xml;
    }
}
