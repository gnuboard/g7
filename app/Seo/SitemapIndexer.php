<?php

namespace App\Seo;

use App\Contracts\Repositories\SitemapUrlRepositoryInterface;
use App\Enums\SitemapChangeFreq;
use App\Extension\HookManager;
use App\Support\SiteUrl;

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
     * @return void
     */
    public function indexResource(string $type, string|int $id, string $contributor, array $entries): void
    {
        $normalized = $this->collectEntries($type, $id, $contributor, $entries);

        if ($normalized === []) {
            // 색인할 항목이 없으면(모두 제외됨) 기존 색인을 제거해 정합을 유지합니다.
            $this->repository->removeForResource($type, (string) $id);

            return;
        }

        $this->repository->upsertForResource($type, (string) $id, $normalized);
    }

    /**
     * 같은 유형의 여러 공개 리소스를 한 번에 색인합니다(멱등).
     *
     * 부모 리소스(예: 게시판)가 다시 공개되어 자식(예: 게시글) 수만 건을 되살릴 때 쓴다 — 리소스마다
     * indexResource() 를 부르면 삭제·삽입 트랜잭션이 N 번 돌아 큐 작업 시간 제한을 넘는다.
     * 항목 가공 filter 훅은 indexResource() 와 같이 리소스마다 호출하고, 저장만 묶음으로 처리한다.
     * 항목이 모두 제외된 리소스는 기존 색인이 제거된다.
     *
     * @param  string  $type  리소스 유형
     * @param  string  $contributor  기여자 식별자
     * @param  array<string|int, array<int, array{loc?: string, url?: string, lastmod?: mixed, changefreq?: ?string, priority?: ?float}>>  $entriesById  리소스 PK => URL 항목 목록
     * @return int 저장된 URL 행 수
     */
    public function indexResources(string $type, string $contributor, array $entriesById): int
    {
        if ($entriesById === []) {
            return 0;
        }

        $normalizedById = [];
        foreach ($entriesById as $id => $entries) {
            $normalizedById[(string) $id] = $this->collectEntries($type, $id, $contributor, $entries);
        }

        return $this->repository->replaceManyForResources($type, $normalizedById);
    }

    /**
     * 비공개/삭제된 리소스의 사이트맵 URL 색인을 제거합니다.
     *
     * @param  string  $type  리소스 유형
     * @param  string|int  $id  리소스 PK
     * @return void
     */
    public function deindexResource(string $type, string|int $id): void
    {
        $this->repository->removeForResource($type, (string) $id);
    }

    /**
     * 같은 유형의 여러 리소스 사이트맵 URL 색인을 한 번에 제거합니다.
     *
     * 부모 리소스(예: 게시판)가 삭제·비공개되어 자식(예: 게시글)이 한꺼번에 공개 대상에서 빠질 때
     * 자식별 훅을 재발화하지 않고 ID 목록으로 정리합니다.
     *
     * @param  string  $type  리소스 유형
     * @param  array<int, string|int>  $ids  리소스 PK 목록
     * @return int 제거된 행 수
     */
    public function deindexResources(string $type, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return $this->repository->removeManyForResource($type, $ids);
    }

    /**
     * 리소스 항목에 filter 훅을 적용하고 저장 형식으로 정규화합니다.
     *
     * @param  string  $type  리소스 유형
     * @param  string|int  $id  리소스 PK
     * @param  string  $contributor  기여자 식별자
     * @param  array<int, mixed>  $entries  URL 항목
     * @return array<int, array{loc: string, lastmod: mixed, changefreq: ?string, priority: mixed, contributor: string}> 정규화된 항목 (없으면 빈 배열)
     */
    private function collectEntries(string $type, string|int $id, string $contributor, array $entries): array
    {
        $entries = HookManager::applyFilters(self::COLLECT_FILTER, $entries, $type, $id, $contributor);

        if (! is_array($entries) || $entries === []) {
            return [];
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

        return $normalized;
    }

    /**
     * 항목의 절대 URL(loc)을 해석합니다.
     *
     * 'loc'(절대 URL)이 있으면 그대로, 없으면 'url'(상대 경로)을 사이트 주소 기준으로 절대화합니다.
     * 보안: 요청 Host 로 만들면 위조 Host 요청 안에서 색인된 주소가 사이트맵에 영구히 남는다.
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
            return SiteUrl::absolute($entry['url']);
        }

        return null;
    }
}
