<?php

namespace App\Seo;

use App\Contracts\Repositories\SitemapUrlRepositoryInterface;
use App\Enums\SitemapChangeFreq;
use App\Extension\HookManager;

/**
 * 사이트맵 리소스 색인기
 *
 * 리소스 변경 리스너가 Model/DB 에 직접 접근하지 않고(계층 위반 방지) 이 서비스를 경유해
 * 사이트맵 URL 델타를 반영합니다. 공개 리소스는 색인(upsert), 비공개/삭제 리소스는 색인 해제(remove)합니다.
 *
 * 리스너는 리소스의 URL 규칙(예: /board/{slug}/{id})으로 만든 항목을 넘기며,
 * 제3자 확장은 filter 훅(sitemap.index.collect_for_resource)으로 항목을 가공/추가할 수 있습니다.
 */
class SitemapIndexer
{
    /**
     * 리소스 항목 가공 filter 훅 이름 (제3자 확장용)
     */
    public const COLLECT_FILTER = 'sitemap.index.collect_for_resource';

    /**
     * SitemapIndexer 생성자
     *
     * @param  SitemapUrlRepositoryInterface  $repository  사이트맵 URL 저장소
     */
    public function __construct(
        private readonly SitemapUrlRepositoryInterface $repository,
    ) {}

    /**
     * 공개 리소스의 사이트맵 URL 을 색인합니다(멱등).
     *
     * @param  string  $type  리소스 유형 (예: product, board_post, page)
     * @param  string|int  $id  리소스 PK
     * @param  string  $contributor  기여자 식별자 (예: sirsoft-ecommerce)
     * @param  array<int, array{loc?: string, url?: string, lastmod?: mixed, changefreq?: ?string, priority?: ?float}>  $entries  URL 항목
     */
    public function indexResource(string $type, string|int $id, string $contributor, array $entries): void
    {
        $entries = HookManager::applyFilters(self::COLLECT_FILTER, $entries, $type, $id, $contributor);

        if (! is_array($entries) || $entries === []) {
            // 색인할 항목이 없으면(모두 제외됨) 기존 색인을 제거해 정합을 유지합니다.
            $this->repository->removeForResource($type, (string) $id);

            return;
        }

        $normalized = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $loc = $this->resolveLoc($entry);
            if ($loc === null) {
                continue;
            }

            $normalized[] = [
                'loc' => $loc,
                'lastmod' => $entry['lastmod'] ?? null,
                // 폐쇄 어휘로 정규화 — 비표준 값이 sitemap_urls 에 저장되는 것을 차단.
                'changefreq' => SitemapChangeFreq::normalize($entry['changefreq'] ?? null),
                'priority' => $entry['priority'] ?? null,
                'contributor' => $contributor,
            ];
        }

        if ($normalized === []) {
            $this->repository->removeForResource($type, (string) $id);

            return;
        }

        $this->repository->upsertForResource($type, (string) $id, $normalized);
    }

    /**
     * 비공개/삭제된 리소스의 사이트맵 URL 색인을 제거합니다.
     *
     * @param  string  $type  리소스 유형
     * @param  string|int  $id  리소스 PK
     */
    public function deindexResource(string $type, string|int $id): void
    {
        $this->repository->removeForResource($type, (string) $id);
    }

    /**
     * 항목의 절대 URL(loc)을 해석합니다.
     *
     * 'loc'(절대 URL)이 있으면 그대로, 없으면 'url'(상대 경로)을 url() 로 절대화합니다.
     *
     * @param  array<string, mixed>  $entry  URL 항목
     * @return string|null 절대 URL (해석 불가 시 null)
     */
    private function resolveLoc(array $entry): ?string
    {
        if (isset($entry['loc']) && is_string($entry['loc']) && $entry['loc'] !== '') {
            return $entry['loc'];
        }

        if (isset($entry['url']) && is_string($entry['url']) && $entry['url'] !== '') {
            return url($entry['url']);
        }

        return null;
    }
}
