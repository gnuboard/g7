<?php

namespace App\Jobs;

use App\Seo\SitemapGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sitemap XML 생성 큐 잡
 *
 * Sitemap을 비동기로 생성하여 캐시에 저장합니다.
 * 스케줄러 또는 Artisan 커맨드에서 디스패치됩니다.
 */
class GenerateSitemapJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int 최대 재시도 횟수
     */
    public int $tries = 3;

    /**
     * @var int 타임아웃 (초)
     */
    public int $timeout = 300;

    /**
     * Sitemap을 생성하고 캐시에 저장합니다.
     *
     * @param  SitemapGenerator  $generator  Sitemap 생성기
     */
    public function handle(SitemapGenerator $generator): void
    {
        $enabled = (bool) g7_core_settings('seo.sitemap_enabled', true);
        if (! $enabled) {
            Log::info('[SEO] Sitemap generation skipped (disabled)');

            return;
        }

        $xml = $generator->generate();
        $ttl = (int) g7_core_settings('seo.sitemap_cache_ttl', 86400);
        Cache::put('seo:sitemap', $xml, $ttl);

        Log::info('[SEO] Sitemap generated and cached', [
            'size' => strlen($xml),
            'ttl' => $ttl,
        ]);
    }

    /**
     * 잡 실패 시 로그를 기록합니다.
     *
     * @param  \Throwable  $exception  발생한 예외
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[SEO] Sitemap generation failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
