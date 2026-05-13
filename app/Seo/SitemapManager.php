<?php

namespace App\Seo;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;

/**
 * Sitemap 재생성 / 상태 조회 서비스.
 *
 * 큐 잡(GenerateSitemapJob)과 관리자 수동 트리거(SeoCacheController)에서 공통으로 사용합니다.
 * Sitemap XML 을 생성하여 캐시에 저장하고 마지막 업데이트 시각을
 * settings 의 seo 카테고리(sitemap_last_updated_at)에 기록합니다.
 */
class SitemapManager
{
    public function __construct(
        private SitemapGenerator $generator,
        private CacheInterface $cache,
        private ConfigRepositoryInterface $configRepository,
    ) {}

    /**
     * Sitemap 을 즉시 생성하여 캐시에 저장하고 last_updated_at 을 기록합니다.
     *
     * @return array{success: bool, status: string, message?: string, data?: array<string, mixed>}
     *         status: 'updated' | 'disabled' | 'failed'
     */
    public function regenerate(): array
    {
        $enabled = (bool) g7_core_settings('seo.sitemap_enabled', true);
        if (! $enabled) {
            return [
                'success' => false,
                'status' => 'disabled',
                'message' => 'Sitemap 생성이 비활성화되어 있습니다.',
            ];
        }

        HookManager::doAction('core.seo.sitemap.before_regenerate');

        try {
            $xml = $this->generator->generate();
            $ttl = (int) g7_core_settings('cache.seo_sitemap_ttl', g7_core_settings('seo.sitemap_cache_ttl', 86400));
            $this->cache->put('seo.sitemap', $xml, $ttl);

            $lastUpdatedAt = now()->toIso8601String();
            $this->updateLastUpdatedAt($lastUpdatedAt);

            $result = [
                'success' => true,
                'status' => 'updated',
                'message' => 'Sitemap 생성이 완료되었습니다.',
                'data' => [
                    'last_updated_at' => $lastUpdatedAt,
                    'size_bytes' => strlen($xml),
                    'ttl' => $ttl,
                ],
            ];

            HookManager::doAction('core.seo.sitemap.after_regenerate', $result);

            return $result;
        } catch (\Throwable $e) {
            Log::error('[SEO] Sitemap regeneration failed', [
                'error' => $e->getMessage(),
            ]);

            $result = [
                'success' => false,
                'status' => 'failed',
                'message' => 'Sitemap 생성에 실패했습니다: '.$e->getMessage(),
            ];

            HookManager::doAction('core.seo.sitemap.after_regenerate_failed', $result);

            return $result;
        }
    }

    /**
     * 현재 sitemap 의 메타데이터를 반환합니다.
     *
     * @return array{last_updated_at: ?string}
     */
    public function getStatus(): array
    {
        $lastUpdatedAt = (string) g7_core_settings('seo.sitemap_last_updated_at', '');

        return [
            'last_updated_at' => $lastUpdatedAt !== '' ? $lastUpdatedAt : null,
        ];
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
