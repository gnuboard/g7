<?php

namespace App\Jobs;

use App\Enums\SitemapGenerationMode;
use App\Extension\HookManager;
use App\Seo\SitemapManager;
use App\Seo\SitemapProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sitemap XML 생성 큐 잡
 *
 * 스케줄러, Artisan 커맨드, 관리자 수동 재생성, 봇 요청 캐시 미스에서 디스패치되며,
 * 실제 생성 로직은 SitemapManager 서비스에 위임합니다.
 *
 * 대용량(수백만 URL) 생성은 수 분~수십 분이 걸리므로 동시 실행을 막고(ShouldBeUnique)
 * 타임아웃/재시도 한계를 넉넉히 잡되, 재시도가 무한히 누적되지 않도록 retryUntil 로 제한합니다.
 */
class GenerateSitemapJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var string 전용 큐 이름 — 대용량·장시간 사이트맵 생성을 default 큐에서 분리한다.
     *
     * 이 큐에는 반드시 워커를 1개만 배치한다(동시 중복 생성 방지). 그러면
     * 긴 생성 작업이 default 큐의 방송/알림을 막지 않아 진행상황이 실시간으로 흐르고,
     * 워커가 1개뿐이라 잡이 동시에 두 번 실행되지 않는다.
     */
    public const QUEUE = 'sitemap';

    /**
     * @var int 최대 재시도 횟수
     */
    public int $tries = 3;

    /**
     * @var int 타임아웃 (초) — 대용량 스트리밍 생성 대응
     */
    public int $timeout = 1800;

    /**
     * @var int 재시도 대기 시간 (초)
     */
    public int $backoff = 120;

    /**
     * @var int 유니크 락 유지 시간 (초) — 잡이 비정상 종료해도 이 시간 후 자동 해제
     */
    public int $uniqueFor = 900;

    /**
     * GenerateSitemapJob 생성자
     *
     * @param  SitemapGenerationMode  $mode  재생성 모드 — SitemapManager 로 전달
     * @param  int|null  $triggeredBy  관리자 수동 재생성을 실행한 사용자 ID(스케줄러/증분/봇 = null).
     *                                 완료/실패 알림의 수신자(수동 실행자) 판정에 사용됩니다.
     */
    public function __construct(
        public SitemapGenerationMode $mode = SitemapGenerationMode::Auto,
        public ?int $triggeredBy = null,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /**
     * 유니크 락 식별자를 반환합니다.
     *
     * 봇 요청 캐시 미스마다 디스패치되어도 큐에는 한 건만 남도록 고정 키를 사용합니다.
     *
     * @return string 유니크 락 키
     */
    public function uniqueId(): string
    {
        return 'seo-sitemap';
    }

    /**
     * 재시도 마감 시각을 반환합니다.
     *
     * timeout(1800초) × tries(3) 만큼 재시도가 누적되는 것을 막습니다.
     *
     * @return \DateTime 재시도 마감 시각
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(30)->toDateTime();
    }

    /**
     * Sitemap 을 생성하고 캐시에 저장합니다.
     *
     * @param  SitemapManager  $manager  Sitemap 매니저 서비스
     */
    public function handle(SitemapManager $manager): void
    {
        $result = $manager->regenerate($this->mode, $this->triggeredBy);

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
        // 잡 최종 실패(재시도 소진/타임아웃) 시 진행상황을 'failed' 로 고정 — 무한 running 방지
        app(SitemapProgress::class)->fail($exception->getMessage());

        // 최종 실패 전용 훅 — 매 시도마다 발화하는 core.seo.sitemap.after_regenerate_failed 와 달리
        // 재시도가 모두 소진된 뒤 딱 한 번만 발화한다. 재시도 중 성공하면 발화하지 않으므로
        // 실패 알림이 중복되거나(각 시도) 결국 성공했는데도 발송되는 일이 없다.
        HookManager::doAction('core.seo.sitemap.regenerate_failed_final', [
            'success' => false,
            'status' => 'failed',
            'message' => $exception->getMessage(),
        ], $this->triggeredBy);

        Log::error('[SEO] Sitemap generation failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
