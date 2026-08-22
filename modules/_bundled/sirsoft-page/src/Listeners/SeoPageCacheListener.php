<?php

namespace Modules\Sirsoft\Page\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\HookListenerInterface;
use App\Enums\SitemapChangeFreq;
use App\Jobs\GenerateSitemapJob;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SitemapIndexer;
use Illuminate\Support\Facades\Log;

/**
 * 페이지 변경 시 SEO 캐시 무효화 리스너
 *
 * 페이지의 생성, 수정, 삭제 시 관련 SEO 캐시를 자동으로 무효화합니다.
 * 페이지 상세, 홈 페이지 등의 캐시가 대상입니다.
 */
class SeoPageCacheListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-page.page.after_create' => [
                'method' => 'onPageChange',
                'priority' => 20,
            ],
            'sirsoft-page.page.after_update' => [
                'method' => 'onPageChange',
                'priority' => 20,
            ],
            'sirsoft-page.page.after_delete' => [
                'method' => 'onPageDelete',
                'priority' => 20,
            ],
            // 버전 복원도 콘텐츠(title/content/seo_meta) 변경이므로 수정과 동일하게 무효화
            'sirsoft-page.page.after_restore' => [
                'method' => 'onPageChange',
                'priority' => 20,
            ],
            // 발행/미발행 전환(단건 setPublished·일괄 bulk-publish)은 after_update 를 거치지
            // 않고 after_publish 만 발화한다 — 미구독 시 발행 상태를 바꿔도 봇 캐시가 이전
            // 상태(미발행 soft-404 / 발행 콘텐츠)로 남고 사이트맵 증분 색인도 빠진다.
            // onPageChange 가 모델의 published 최신 상태로 색인(upsert)/해제(remove)를 판정한다.
            'sirsoft-page.page.after_publish' => [
                'method' => 'onPageChange',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 페이지 변경 시 SEO 캐시를 무효화합니다.
     *
     * 페이지 상세 URL과 홈 페이지 캐시를 무효화합니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Page 모델, 두 번째: 데이터 배열)
     */
    public function onPageChange(...$args): void
    {
        $page = $args[0] ?? null;

        try {
            $cache = app(SeoCacheManagerInterface::class);

            // 페이지 상세 URL 캐시 무효화 (공개 URL은 단수형 /page/{slug})
            if ($page && isset($page->slug)) {
                $cache->invalidateByUrl("*/page/{$page->slug}");
            }

            // 페이지 상세 레이아웃 캐시 무효화
            $cache->invalidateByLayout('page/show');

            // 홈 페이지 캐시 무효화 (페이지 링크가 네비게이션에 포함될 수 있음)
            $cache->invalidateByLayout('home');

            // 검색 결과 페이지 캐시 무효화
            $cache->invalidateByLayout('search/index');

            // Sitemap 캐시 무효화
            app(CacheInterface::class)->forget('seo.sitemap');

            Log::debug('[SEO] Page change cache invalidated', [
                'page_id' => $page->id ?? null,
                'page_slug' => $page->slug ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEO] Page cache invalidation failed', [
                'error' => $e->getMessage(),
                'page_id' => is_object($page) ? ($page->id ?? null) : null,
            ]);
        }

        $this->syncSitemapIndex($page, false);
    }

    /**
     * 페이지 삭제 시 SEO 캐시를 무효화하고 사이트맵 색인을 제거합니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Page 모델)
     */
    public function onPageDelete(...$args): void
    {
        $this->onPageChange(...$args);

        $this->syncSitemapIndex($args[0] ?? null, true);
    }

    /**
     * 페이지의 사이트맵 색인을 증분 갱신합니다.
     *
     * 발행(published) 상태이면 색인(upsert), 미발행/삭제이면 색인 해제(remove)한 뒤
     * 사이트맵 재생성 잡을 디바운스 디스패치합니다.
     * 색인 규칙은 PageSitemapContributor 의 페이지 URL 규칙과 일치해야 합니다.
     *
     * @param  mixed  $page  Page 모델
     * @param  bool  $deleted  삭제 이벤트 여부
     */
    private function syncSitemapIndex(mixed $page, bool $deleted): void
    {
        if (! is_object($page) || ! isset($page->id) || ! isset($page->slug)) {
            return;
        }

        try {
            $indexer = app(SitemapIndexer::class);

            $visible = ! $deleted && (bool) ($page->published ?? false);

            if ($visible) {
                $indexer->indexResource('page', $page->id, 'sirsoft-page', [[
                    'url' => "/page/{$page->slug}",
                    'lastmod' => $page->updated_at?->toW3cString(),
                    'changefreq' => SitemapChangeFreq::Monthly->value,
                    'priority' => 0.5,
                ]]);
            } else {
                $indexer->deindexResource('page', $page->id);
            }

            GenerateSitemapJob::dispatch();
        } catch (\Throwable $e) {
            Log::warning('[SEO] Page sitemap index sync failed', [
                'error' => $e->getMessage(),
                'page_id' => is_object($page) ? ($page->id ?? null) : null,
            ]);
        }
    }
}
