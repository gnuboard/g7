<?php

namespace App\Seo;

use App\Enums\SitemapChangeFreq;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Seo\Contracts\SitemapContributorInterface;
use App\Services\TemplateService;
use App\Support\SiteUrl;

/**
 * Sitemap XML 생성기
 *
 * 정적 화면(SEO 선언 기반)과 등록된 SitemapContributorInterface 구현체로부터 URL을 수집하여
 * sitemap.xml을 생성합니다.
 *
 * 정적 화면은 봇 화면과 같은 기준으로 싣습니다 — 병합 레이아웃 meta.seo.enabled 가 참이고
 * meta.seo.toggle_setting 이 켜진, 고정 경로의 공개 화면입니다. 같은 주소는 정적 화면이 먼저 적히고
 * 기여자·저장소 항목은 건너뜁니다.
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
     * @param  SeoDeclarationCollector  $declarationCollector  SEO 선언 수집기 (정적 화면 후보)
     * @param  SeoToggleEvaluator  $toggleEvaluator  레이아웃 SEO 토글 판정기 (봇 화면과 공용)
     */
    public function __construct(
        private readonly TemplateRouteResolver $routeResolver,
        private readonly TemplateService $templateService,
        private readonly SeoDeclarationCollector $declarationCollector,
        private readonly SeoToggleEvaluator $toggleEvaluator,
    ) {}

    /**
     * Sitemap 기여자를 등록합니다.
     *
     * @param  SitemapContributorInterface  $contributor  Sitemap 기여자
     * @return void 반환값 없음
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

        $staticLocs = $this->writeStaticRoutes($writer);

        foreach ($this->getActiveContributors() as $contributor) {
            try {
                foreach ($this->drain($contributor) as $entry) {
                    $entry = $this->normalizeEntry($entry);
                    if (! $this->isStaticDuplicate($entry, $staticLocs)) {
                        $writer->addUrl($entry);
                    }
                }
            } catch (\Throwable $e) {
                $this->reportContributorFailure($contributor, $e);
            }
        }

        $writer->close();

        return $writer->commit();
    }

    /**
     * 미리 수집된 URL 항목(사이트맵 저장소 스트림 등)을 writer 로 스트리밍하여 파일을 생성합니다.
     *
     * 정적 화면을 먼저 기록하고, 이어서 전달된 항목을 한 건씩 writer 에 넘깁니다(정적 화면과 같은 주소는 건너뜀).
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

        $staticLocs = $this->writeStaticRoutes($writer);

        foreach ($entries as $entry) {
            $entry = $this->normalizeEntry($entry);
            if (! $this->isStaticDuplicate($entry, $staticLocs)) {
                $writer->addUrl($entry);
            }
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
            // 보안: 요청 Host 가 아니라 사이트 주소 기준 (익명 sitemap 요청 안에서 생성될 수 있다)
            $entry['loc'] = SiteUrl::absolute($entry['url']);
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
        // 1. 정적 화면 수집 (SEO 선언 기반 고정 경로)
        $urls = $this->collectStaticRoutes();
        $staticLocs = $this->locSet($urls);

        // 2. 기여자들의 동적 URL 수집 (꺼진 확장의 기여자는 제외, 정적 화면과 같은 주소는 건너뜀)
        foreach ($this->getActiveContributors() as $contributor) {
            try {
                // 기여자 URL의 'url' 키를 'loc'(절대 URL)으로 변환
                $contributorUrls = array_map(
                    fn (array $entry): array => $this->normalizeEntry($entry),
                    $contributor->getUrls(),
                );

                $urls = array_merge($urls, array_values(array_filter(
                    $contributorUrls,
                    fn (array $entry): bool => ! $this->isStaticDuplicate($entry, $staticLocs),
                )));
            } catch (\Throwable $e) {
                $this->reportContributorFailure($contributor, $e);
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
     * 켜져 있는 확장(또는 확장이 아닌 기여자)의 기여자 목록을 반환합니다.
     *
     * 모듈·플러그인의 서비스 프로바이더는 비활성 상태여도 등록되므로, 꺼진 확장의 기여자도 등록돼 있습니다.
     * 사이트맵에는 켜진 확장의 URL 만 실어야 해 여기서 거릅니다.
     *
     * @return SitemapContributorInterface[] 기여자 배열
     */
    public function getActiveContributors(): array
    {
        $inactive = $this->inactiveExtensionIdentifiers();

        return array_filter(
            $this->contributors,
            fn (SitemapContributorInterface $contributor): bool => ! in_array($contributor->getIdentifier(), $inactive, true),
        );
    }

    /**
     * 설치돼 있지만 꺼진 모듈·플러그인 식별자 목록을 반환합니다.
     *
     * 사이트맵 저장소에 이미 쌓인 행을 내보낼 때도 이 목록으로 거릅니다. 상태를 읽지 못하면 오류로 보고하고 빈 목록을
     * 돌려 지금처럼 모두 싣습니다(사이트맵이 통째로 비는 쪽보다 낫다).
     *
     * @return array<int, string> 꺼진 확장 식별자 목록
     */
    public function inactiveExtensionIdentifiers(): array
    {
        try {
            return array_values(array_merge(
                array_diff(ModuleManager::getInstalledModuleIdentifiers(), ModuleManager::getActiveModuleIdentifiers()),
                array_diff(PluginManager::getInstalledPluginIdentifiers(), PluginManager::getActivePluginIdentifiers()),
            ));
        } catch (\Throwable $e) {
            report(new \RuntimeException('[SEO] Sitemap extension status lookup failed', 0, $e));

            return [];
        }
    }

    /**
     * 정적 화면을 writer 에 먼저 기록하고, 중복 판정용 loc 집합을 돌려줍니다.
     *
     * @param  SitemapWriter  $writer  분할 기록기
     * @return array<string, true> 비교용으로 정규화한 정적 화면 loc 집합
     */
    private function writeStaticRoutes(SitemapWriter $writer): array
    {
        $entries = $this->collectStaticRoutes();

        foreach ($entries as $entry) {
            $writer->addUrl($entry);
        }

        return $this->locSet($entries);
    }

    /**
     * 정적 화면을 수집합니다 — 봇 화면과 같은 기준(SEO 선언 + 토글)을 통과한 고정 경로만.
     *
     * SEO 선언 수집기가 이미 auth_required·guest_only·레이아웃 없는 라우트와 meta.seo.enabled 가 거짓인
     * 레이아웃을 걸렀습니다. 여기서는 경로에 동적 파라미터(`:`)·템플릿 표현식(`{{`)이 있는 화면과 toggle_setting 이
     * 꺼진 화면을 빼고, priority·changefreq 는 레이아웃 값을 씁니다.
     *
     * @return array<int, array{loc: string, changefreq: string, priority: float}> 정적 URL 배열
     */
    private function collectStaticRoutes(): array
    {
        try {
            $urls = [];
            $seen = [];

            foreach ($this->declarationCollector->collect() as $declaration) {
                $routePath = $declaration['routePath'] ?? null;

                // 경로 없음·동적 파라미터(:id, :slug)·템플릿 표현식({{...}}) 포함 라우트 제외
                if (! is_string($routePath) || str_contains($routePath, ':') || str_contains($routePath, '{{')) {
                    continue;
                }

                $seo = is_array($declaration['seo'] ?? null) ? $declaration['seo'] : [];

                // 레이아웃 toggle_setting 이 꺼진 화면 제외 (봇 화면과 같은 판정)
                if (! $this->toggleEvaluator->isEnabled($declaration['moduleIdentifier'] ?? null, $declaration['pluginIdentifier'] ?? null, $seo)) {
                    continue;
                }

                // 와일드카드(*) 접두사(언어 접두사 자리) 제거
                $routePath = ltrim($routePath, '*/');
                if (! str_starts_with($routePath, '/')) {
                    $routePath = '/'.$routePath;
                }

                $loc = SiteUrl::absolute($routePath);

                // 필터 훅(core.seo.register_layouts)으로 같은 화면이 두 번 들어와도 한 번만 싣는다
                $key = $this->comparableLoc($loc);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $urls[] = [
                    'loc' => $loc,
                    'changefreq' => SitemapChangeFreq::normalize(is_string($seo['changefreq'] ?? null) ? $seo['changefreq'] : null)
                        ?? SitemapChangeFreq::Weekly->value,
                    'priority' => $this->layoutPriority($seo['priority'] ?? null),
                ];
            }

            return $urls;
        } catch (\Throwable $e) {
            report(new \RuntimeException('[SEO] Sitemap static route collection failed', 0, $e));

            return [];
        }
    }

    /**
     * 레이아웃 priority 가 0~1 사이 숫자면 쓰고, 아니면 0.5 를 씁니다.
     *
     * @param  mixed  $priority  레이아웃 meta.seo.priority
     * @return float 사이트맵 priority
     */
    private function layoutPriority(mixed $priority): float
    {
        if ((is_int($priority) || is_float($priority)) && $priority >= 0 && $priority <= 1) {
            return (float) $priority;
        }

        return 0.5;
    }

    /**
     * URL 항목 목록의 loc 를 비교용 집합으로 만듭니다.
     *
     * @param  array<int, array<string, mixed>>  $entries  URL 항목 목록
     * @return array<string, true> 정규화한 loc 집합
     */
    private function locSet(array $entries): array
    {
        $set = [];
        foreach ($entries as $entry) {
            if (is_string($entry['loc'] ?? null)) {
                $set[$this->comparableLoc($entry['loc'])] = true;
            }
        }

        return $set;
    }

    /**
     * 항목의 loc 가 이미 적힌 정적 화면과 같은 주소인지 확인합니다.
     *
     * 집합에는 정적 화면 loc 만 담으므로, 대용량 스트림을 흘려도 메모리는 정적 화면 수만큼만 씁니다.
     *
     * @param  array<string, mixed>  $entry  정규화된 URL 항목
     * @param  array<string, true>  $staticLocs  정적 화면 loc 집합
     * @return bool 같은 주소면 true
     */
    private function isStaticDuplicate(array $entry, array $staticLocs): bool
    {
        return $staticLocs !== []
            && is_string($entry['loc'] ?? null)
            && isset($staticLocs[$this->comparableLoc($entry['loc'])]);
    }

    /**
     * 같은 주소 판정용으로 loc 를 정규화합니다(기록되는 loc 는 바꾸지 않습니다).
     *
     * 스킴·호스트는 소문자로, 빈 경로는 `/` 로 둡니다 — `https://사이트` 와 `https://사이트/` 는 같은 홈입니다.
     * 그 밖의 경로 끝 `/` 와 쿼리는 그대로 두어 `/boards` 와 `/boards/` 는 다른 주소로 남깁니다.
     *
     * @param  string  $loc  절대 URL
     * @return string 비교용 키
     */
    private function comparableLoc(string $loc): string
    {
        $parts = parse_url($loc);
        if ($parts === false || ! isset($parts['host'])) {
            return $loc;
        }

        $key = strtolower($parts['scheme'] ?? '').'://'.strtolower($parts['host']);
        if (isset($parts['port'])) {
            $key .= ':'.$parts['port'];
        }
        $path = $parts['path'] ?? '';
        $key .= $path === '' ? '/' : $path;
        if (isset($parts['query'])) {
            $key .= '?'.$parts['query'];
        }

        return $key;
    }

    /**
     * 기여자 실패를 기여자 식별자와 함께 오류로 보고합니다(나머지 생성은 계속).
     *
     * @param  SitemapContributorInterface  $contributor  실패한 기여자
     * @param  \Throwable  $e  원인 예외
     * @return void 반환값 없음
     */
    private function reportContributorFailure(SitemapContributorInterface $contributor, \Throwable $e): void
    {
        report(new \RuntimeException('[SEO] Sitemap contributor failed: '.$contributor->getIdentifier(), 0, $e));
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
