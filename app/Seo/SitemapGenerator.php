<?php

namespace App\Seo;

use App\Enums\SitemapChangeFreq;
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
     * 수집한 URL을 SitemapWriter로 스트리밍하여 분할 sitemap 파일을 생성합니다.
     *
     * 기여자를 하나씩 순회하며 URL을 writer 에 한 건씩 넘기므로
     * 전체 URL 배열을 메모리에 병합(array_merge)하지 않습니다.
     *
     * @param  SitemapWriter  $writer  분할 기록기
     * @return array{generated_at: string, child_count: int, url_count: int, size_bytes: int, gzip: bool, children: array}
     *                                                                                                                     커밋된 sitemap 메타데이터
     */
    public function generateToWriter(SitemapWriter $writer): array
    {
        $writer->open();

        foreach ($this->collectStaticRoutes() as $entry) {
            $writer->addUrl($entry);
        }

        foreach ($this->contributors as $contributor) {
            try {
                foreach ($this->drain($contributor) as $entry) {
                    $writer->addUrl($this->normalizeEntry($entry));
                }
            } catch (\Throwable $e) {
                Log::warning('[SEO] Sitemap contributor failed', [
                    'contributor' => $contributor->getIdentifier(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $writer->close();

        return $writer->commit();
    }

    /**
     * 미리 수집된 URL 항목(사이트맵 저장소 스트림 등)을 writer 로 스트리밍하여 파일을 생성합니다.
     *
     * 정적 라우트를 먼저 기록하고, 이어서 전달된 항목을 한 건씩 writer 에 넘깁니다.
     * 증분/전체 재생성 모두 이 경로로 파일을 작성하며, 항목의 출처(도메인 재쿼리 vs 테이블 스트림)는
     * 호출자가 결정합니다.
     *
     * @param  SitemapWriter  $writer  분할 기록기
     * @param  iterable<array{loc?: string, lastmod?: string, changefreq?: string, priority?: float}>  $entries  URL 항목 스트림
     * @return array{generated_at: string, child_count: int, url_count: int, size_bytes: int, gzip: bool, children: array}
     *                                                                                                                     커밋된 sitemap 메타데이터
     */
    public function generateToWriterFromEntries(SitemapWriter $writer, iterable $entries): array
    {
        $writer->open();

        foreach ($this->collectStaticRoutes() as $entry) {
            $writer->addUrl($entry);
        }

        foreach ($entries as $entry) {
            $writer->addUrl($this->normalizeEntry($entry));
        }

        $writer->close();

        return $writer->commit();
    }

    /**
     * 한 기여자의 URL 항목을 절대 URL(loc)로 정규화하여 지연 스트리밍합니다.
     *
     * 전체 재생성 시 사이트맵 저장소에 적재할 항목의 단일 출처이며,
     * 기여자가 emit 한 resource_type/resource_id 등 부가 키는 그대로 통과시킵니다.
     *
     * @param  SitemapContributorInterface  $contributor  Sitemap 기여자
     * @return iterable<array<string, mixed>> 정규화된 URL 항목 순회자
     */
    public function streamContributorEntries(SitemapContributorInterface $contributor): iterable
    {
        foreach ($this->drain($contributor) as $entry) {
            yield $this->normalizeEntry($entry);
        }
    }

    /**
     * 기여자로부터 URL 항목을 순회 가능한 형태로 가져옵니다.
     *
     * 지연 스트리밍 capability(AbstractSitemapContributor 상속 또는 getUrlsLazy() 보유)를
     * 감지해, 있으면 한 건씩 yield 되는 제너레이터를 사용합니다. 없으면 기존 getUrls()
     * 배열을 그대로 순회합니다(제3자 raw 구현체 하위호환).
     *
     * @param  SitemapContributorInterface  $contributor  Sitemap 기여자
     * @return iterable<array<string, mixed>> URL 항목 순회자
     */
    private function drain(SitemapContributorInterface $contributor): iterable
    {
        if ($contributor instanceof AbstractSitemapContributor || method_exists($contributor, 'getUrlsLazy')) {
            return $contributor->getUrlsLazy();
        }

        return $contributor->getUrls();
    }

    /**
     * 기여자 URL 항목의 'url' 키를 'loc'(절대 URL)으로 변환합니다.
     *
     * @param  array<string, mixed>  $entry  URL 항목
     * @return array<string, mixed> 변환된 URL 항목
     */
    private function normalizeEntry(array $entry): array
    {
        if (isset($entry['url']) && ! isset($entry['loc'])) {
            $entry['loc'] = url($entry['url']);
            unset($entry['url']);
        }

        return $entry;
    }

    /**
     * URL 항목 하나를 <url> 블록 XML로 렌더링합니다.
     *
     * XML 이스케이프의 단일 출처는 SitemapXmlRenderer 이며 이 메서드는 위임합니다.
     *
     * @param  array{loc?: string, lastmod?: string, changefreq?: string, priority?: float}  $entry  URL 항목
     * @return string <url> 블록 문자열
     */
    public function renderUrlBlock(array $entry): string
    {
        return SitemapXmlRenderer::fromConfig()->urlBlock($entry);
    }

    /**
     * urlset 여는 태그(XML 선언 포함)를 반환합니다.
     *
     * @return string urlset 헤더 문자열
     */
    public function buildUrlsetHeader(): string
    {
        return SitemapXmlRenderer::fromConfig()->urlsetHeader();
    }

    /**
     * urlset 닫는 태그를 반환합니다.
     *
     * @return string urlset 푸터 문자열
     */
    public function buildUrlsetFooter(): string
    {
        return SitemapXmlRenderer::fromConfig()->urlsetFooter();
    }

    /**
     * 전체 Sitemap URL을 수집하여 XML을 생성합니다.
     *
     * 전체 URL을 메모리에 적재하므로 소규모 사이트 / 하위호환 용도로만 사용합니다.
     * 대용량은 generateToWriter() 를 사용합니다.
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
                // 기여자 URL의 'url' 키를 'loc'(절대 URL)으로 변환
                $contributorUrls = array_map(
                    fn (array $entry): array => $this->normalizeEntry($entry),
                    $contributor->getUrls(),
                );

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
                    'changefreq' => SitemapChangeFreq::Weekly->value,
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
        $renderer = SitemapXmlRenderer::fromConfig();

        $xml = $renderer->urlsetHeader();

        foreach ($urls as $entry) {
            $xml .= $renderer->urlBlock($entry);
        }

        $xml .= $renderer->urlsetFooter();

        return $xml;
    }
}
