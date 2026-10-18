<?php

namespace App\Seo;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\StorageInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Contracts\Repositories\SitemapUrlRepositoryInterface;
use App\Enums\SitemapGenerationMode;
use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;

/**
 * Sitemap 재생성 / 상태 조회 서비스.
 *
 * 큐 잡(GenerateSitemapJob)과 관리자 수동 트리거(SeoCacheController)에서 공통으로 사용합니다.
 * Sitemap 을 SitemapWriter 로 스트리밍 생성하여 비공개 디스크에 분할 파일로 커밋하고,
 * 마지막 업데이트 시각을 settings 의 seo 카테고리(sitemap_last_updated_at)에 기록합니다.
 * 캐시에는 XML 본문이 아니라 메타데이터만 저장합니다.
 */
class SitemapManager
{
    /**
     * Sitemap 메타데이터 캐시 키
     */
    public const META_CACHE_KEY = 'seo.sitemap.meta';

    /**
     * 구 버전 Sitemap XML 본문 캐시 키 (디스크 서빙 전환으로 폐기 — 잔여분 정리용)
     */
    private const LEGACY_XML_CACHE_KEY = 'seo.sitemap';

    public function __construct(
        private SitemapGenerator $generator,
        private CacheInterface $cache,
        private ConfigRepositoryInterface $configRepository,
        private StorageInterface $storage,
        private SitemapUrlRepositoryInterface $repository,
        private SitemapProgress $progress,
    ) {}

    /**
     * Sitemap 을 즉시 생성하여 디스크에 커밋하고 last_updated_at 을 기록합니다.
     *
     * 모드에 따라 사이트맵 저장소(sitemap_urls) 활용 방식이 달라집니다:
     * - full: 각 기여자를 스트리밍해 저장소를 전량 대체한 뒤 저장소 → 파일 재작성.
     * - incremental: 저장소의 현재 상태(리스너가 반영한 델타)를 그대로 파일로 재작성.
     * - auto: 저장소가 비어 있으면 full, 아니면 incremental.
     *
     * @param  SitemapGenerationMode  $mode  재생성 모드
     * @param  int|null  $triggeredBy  이 재생성을 유발한 관리자 사용자 ID(관리자 수동 재생성만 지정, 스케줄러/증분/봇 = null).
     *                                 완료/실패 훅에 함께 전달되어 알림 발송 대상(수동 실행자) 판정에 쓰입니다.
     * @return array{success: bool, status: string, message?: string, data?: array<string, mixed>}
     *                                                                                             status: 'updated' | 'disabled' | 'failed'
     */
    public function regenerate(SitemapGenerationMode $mode = SitemapGenerationMode::Auto, ?int $triggeredBy = null): array
    {
        $enabled = (bool) g7_core_settings('seo.sitemap_enabled', true);
        if (! $enabled) {
            return [
                'success' => false,
                'status' => 'disabled',
                'message' => __('seo.sitemap_disabled'),
            ];
        }

        HookManager::doAction('core.seo.sitemap.before_regenerate');

        try {
            $effectiveMode = $mode->resolve($this->repository->countVisible());

            if ($effectiveMode === SitemapGenerationMode::Full) {
                $this->rebuildStore();
            }

            $this->progress->writing($this->repository->countVisible());

            $meta = $this->generator->generateToWriterFromEntries(
                $this->makeWriter(),
                $this->streamStoreEntries(),
            );

            // 고급 탭(cache.seo_sitemap_ttl)이 메인, SEO 탭에 별도 지정이 있으면 그것이 우선 (D19)
            $ttl = SeoCacheSettings::sitemapCacheTtl();

            // 디스크 서빙 전환 — 캐시에는 메타만 저장하고 구 XML 본문 캐시는 정리합니다.
            $this->cache->forget(self::LEGACY_XML_CACHE_KEY);
            $this->cache->put(self::META_CACHE_KEY, $meta, $ttl);

            $lastUpdatedAt = $meta['generated_at'];
            $this->updateLastUpdatedAt($lastUpdatedAt);

            $result = [
                'success' => true,
                'status' => 'updated',
                'message' => __('seo.sitemap_regenerated'),
                'data' => [
                    'last_updated_at' => $lastUpdatedAt,
                    'size_bytes' => $meta['size_bytes'],
                    'url_count' => $meta['url_count'],
                    'child_count' => $meta['child_count'],
                    'ttl' => $ttl,
                ],
            ];

            $this->progress->complete($meta);

            HookManager::doAction('core.seo.sitemap.after_regenerate', $result, $triggeredBy);

            return $result;
        } catch (\Throwable $e) {
            Log::error('[SEO] Sitemap regeneration failed', [
                'error' => $e->getMessage(),
            ]);

            $this->progress->fail($e->getMessage());

            $result = [
                'success' => false,
                'status' => 'failed',
                'message' => __('seo.sitemap_generate_failed', ['error' => $e->getMessage()]),
            ];

            HookManager::doAction('core.seo.sitemap.after_regenerate_failed', $result, $triggeredBy);

            return $result;
        }
    }

    /**
     * 현재 sitemap 의 메타데이터와 진행상황을 반환합니다.
     *
     * realtime_enabled 는 설정 SSoT(drivers.websocket_enabled)와 실제 적용 config
     * (broadcasting.default) 를 함께 확인해 판정합니다 — 프론트가 실시간(구독)/폴링을
     * 분기하는 근거입니다.
     *
     * @return array{last_updated_at: ?string, progress: ?array<string, mixed>, realtime_enabled: bool}
     */
    public function getStatus(): array
    {
        $lastUpdatedAt = (string) g7_core_settings('seo.sitemap_last_updated_at', '');

        return [
            'last_updated_at' => $lastUpdatedAt !== '' ? $lastUpdatedAt : null,
            'progress' => $this->progress->get(),
            'realtime_enabled' => (bool) g7_core_settings('drivers.websocket_enabled', false)
                && config('broadcasting.default') !== 'null',
        ];
    }

    /**
     * 각 기여자를 스트리밍하여 사이트맵 저장소를 전량 대체합니다(전체 재생성).
     *
     * 기여자별로 격리하여, 한 기여자가 실패해도 나머지 기여자의 재적재는 계속됩니다.
     */
    private function rebuildStore(): void
    {
        // 스트림 삽입 건수를 누적해 running 단계의 "누적 URL 수" 를 표기합니다(D4).
        // countVisible() 같은 사전 count 쿼리 없이 삽입 결과만 합산하므로 1.4M 에서도 부담이 없습니다.
        $total = 0;
        foreach ($this->generator->getContributors() as $contributor) {
            try {
                // 현재 기여자 진입 시점의 누적치(직전 기여자들까지)를 표시합니다.
                $this->progress->phase($contributor->getIdentifier(), $total);

                $total += $this->repository->replaceAllForContributor(
                    $contributor->getIdentifier(),
                    $this->generator->streamContributorEntries($contributor),
                );
            } catch (\Throwable $e) {
                Log::warning('[SEO] Sitemap 저장소 재적재 실패', [
                    'contributor' => $contributor->getIdentifier(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 사이트맵 저장소의 공개 URL 을 writer 항목 형태로 지연 스트리밍합니다.
     *
     * @return iterable<array{loc: string, lastmod: ?string, changefreq: ?string, priority: ?float}> writer 항목 순회자
     */
    private function streamStoreEntries(): iterable
    {
        foreach ($this->repository->streamVisible() as $row) {
            yield [
                'loc' => (string) $row->loc,
                'lastmod' => $row->lastmod?->toW3cString(),
                'changefreq' => $row->changefreq,
                'priority' => $row->priority !== null ? (float) $row->priority : null,
            ];
        }
    }

    /**
     * 설정값을 반영한 SitemapWriter 를 생성합니다.
     *
     * @return SitemapWriter 분할 기록기
     */
    private function makeWriter(): SitemapWriter
    {
        return new SitemapWriter(
            $this->storage,
            SitemapXmlRenderer::fromConfig(),
            (int) g7_core_settings('seo.sitemap_urls_per_file', SitemapWriter::HARD_URL_CAP),
            (bool) g7_core_settings('seo.sitemap_gzip', false),
        );
    }

    /**
     * settings 의 seo 카테고리에 sitemap_last_updated_at 을 기록합니다.
     *
     * @param  string  $iso8601  ISO8601 형식 타임스탬프
     */
    private function updateLastUpdatedAt(string $iso8601): void
    {
        try {
            $current = $this->configRepository->getCategory('seo');
            $current['sitemap_last_updated_at'] = $iso8601;
            $this->configRepository->saveCategory('seo', $current);
        } catch (\Throwable $e) {
            Log::warning('Sitemap last_updated_at 갱신 실패', ['error' => $e->getMessage()]);
        }
    }
}
