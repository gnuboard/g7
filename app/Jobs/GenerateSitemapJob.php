<?php

namespace App\Jobs;

use App\Seo\SitemapManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sitemap XML 생성 큐 잡
 *
 * 스케줄러 또는 Artisan 커맨드에서 디스패치되며,
 * 실제 생성 로직은 SitemapManager 서비스에 위임합니다.
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
     * Sitemap 을 생성하고 캐시에 저장합니다.
     *
     * @param  SitemapManager  $manager  Sitemap 매니저 서비스
     */
    public function handle(SitemapManager $manager): void
    {
        $result = $manager->regenerate();

        if (($result['status'] ?? null) === 'disabled') {
            Log::info('[SEO] Sitemap generation skipped (disabled)');

            return;
        }

        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException($result['message'] ?? 'Sitemap regeneration failed');
        }

        Log::info('[SEO] Sitemap generated and cached', [
            'size' => $result['data']['size_bytes'] ?? null,
            'ttl' => $result['data']['ttl'] ?? null,
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
