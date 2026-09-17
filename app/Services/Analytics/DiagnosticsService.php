<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Analytics\ExclusionCounter;
use App\Contracts\Repositories\Analytics\ArchiveRunRepositoryInterface;
use App\Contracts\Repositories\Analytics\CollectFailureRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Enums\Analytics\ArchiveRunType;
use App\Services\GeoIpService;
use App\Support\TrustedProxyDiagnostic;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 수집 진단
 *
 * 「비콘은 204 를 받는데 원장이 비어 있다」는 상황의 원인을 한 화면에 모읍니다.
 * 방문자 통계의 실패는 전부 조용하므로, 무엇이 왜 안 세어지는지 물어볼 곳이 없으면
 * 운영자는 기능이 고장 난 줄만 압니다.
 *
 * 여기서 보이는 것들: 큐 워커가 도는가 · 프록시 뒤에서 주소가 뭉치는가 · 집계가
 * 밀렸는가 · 무엇이 몇 건 제외됐는가 · GeoIP·브로드캐스트·차단 목록이 준비됐는가.
 */
class DiagnosticsService
{
    /**
     * 큐에 일이 이만큼 쌓이면 경고로 본다
     */
    private const QUEUE_BACKLOG_THRESHOLD = 1000;

    /**
     * 진단기를 생성합니다.
     *
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소
     * @param  ArchiveRunRepositoryInterface  $runs  집계 실행 이력 저장소
     * @param  CollectFailureRepositoryInterface  $failures  적재 실패 저장소
     * @param  ExclusionCounter  $exclusions  제외 사유 카운터
     * @param  StorageStatsService  $storage  저장 용량 현황 서비스
     * @param  CloudIpRangeService  $cloudRanges  클라우드 대역 목록 서비스
     * @param  PrivacyService  $privacy  개인정보 처리기
     * @param  GeoIpService  $geoIp  지역 조회 서비스
     * @param  QueueStatsService  $queue  큐 현황 서비스
     */
    public function __construct(
        private readonly VisitRepositoryInterface $visits,
        private readonly ArchiveRunRepositoryInterface $runs,
        private readonly CollectFailureRepositoryInterface $failures,
        private readonly ExclusionCounter $exclusions,
        private readonly StorageStatsService $storage,
        private readonly CloudIpRangeService $cloudRanges,
        private readonly PrivacyService $privacy,
        private readonly GeoIpService $geoIp,
        private readonly QueueStatsService $queue,
    ) {}

    /**
     * 진단 결과를 만듭니다.
     *
     * @param  AnalyticsConfig  $config  설정
     * @param  Request|null  $request  현재 요청 (프록시 판정용)
     * @return array<string, mixed> 진단 결과
     */
    public function diagnostics(AnalyticsConfig $config, ?Request $request = null): array
    {
        return [
            'collection' => $this->collection($config),
            'queue' => $this->queueStatus($config),
            'archive' => $this->archive(),
            'storage' => $this->storage->stats($config),
            'geoip' => $this->geoIpStatus(),
            'trusted_proxy' => TrustedProxyDiagnostic::forRequest($request),
            'broadcasting' => [
                'configured' => (string) config('broadcasting.connections.reverb.key', '') !== '',
            ],
            'cloud_ranges' => array_merge(
                ['enabled' => $config->blockCloudIpRanges],
                $this->cloudRanges->status(),
            ),
            'failures_24h' => $this->failures->countSince(24),
        ];
    }

    /**
     * 수집 상태를 모읍니다.
     *
     * @param  AnalyticsConfig  $config  설정
     * @return array<string, mixed> 수집 상태
     */
    private function collection(AnalyticsConfig $config): array
    {
        $today = AnalyticsClock::today();
        $yesterday = Carbon::parse($today)->subDay()->toDateString();

        return [
            'enabled' => $config->enabled,
            'mode' => $config->privacyMode->value,
            'consent_source' => $this->privacy->consentSource(),
            'dispatch_mode' => $config->dispatchMode->value,
            'last_tracked_at' => $this->visits->lastTrackedAt()?->toIso8601String(),
            'tracked_today' => $this->visits->uniqueVisitorsOnDate($today),
            // 제외 카운터는 캐시에 산다 — 캐시를 비우거나 서버를 다시 띄우면 사라진다.
            // 화면이 그 사실을 함께 적는다.
            'excluded_today' => $this->exclusions->forDate($today),
            'excluded_yesterday' => $this->exclusions->forDate($yesterday),
        ];
    }

    /**
     * 큐 상태를 모읍니다.
     *
     * @param  AnalyticsConfig  $config  설정
     * @return array<string, mixed> 큐 상태
     */
    private function queueStatus(AnalyticsConfig $config): array
    {
        $stats = $this->queue->stats();

        return array_merge($stats, [
            'connection' => (string) config('queue.default'),
            'backlogged' => $stats['pending_jobs'] >= self::QUEUE_BACKLOG_THRESHOLD,
            // 큐 방식인데 워커가 안 돌면 비콘은 204 를 받고도 원장에 닿지 않는다.
            'worker_hint' => $config->dispatchMode->value === 'queue' && $stats['pending_jobs'] > 0,
        ]);
    }

    /**
     * 집계 진행 상태를 모읍니다.
     *
     * @return array<string, mixed> 집계 상태
     */
    private function archive(): array
    {
        $lastHour = $this->runs->findLastSuccessful(ArchiveRunType::Hour->value);
        $lastDay = $this->runs->findLastSuccessful(ArchiveRunType::Day->value);
        $running = $this->runs->findRunning();

        $reference = $lastHour?->finished_at ?? $lastDay?->finished_at;

        return [
            'last_hour_run' => $lastHour?->finished_at?->toIso8601String(),
            'last_day_run' => $lastDay?->finished_at?->toIso8601String(),
            // 집계가 밀리면 화면 숫자가 그 시점에 멈춘 채 그럴듯하게 남는다.
            'lag_hours' => $reference !== null
                ? (int) floor($reference->diffInHours(AnalyticsClock::now()))
                : null,
            'running_run_id' => $running?->id,
        ];
    }

    /**
     * 지역 조회 상태를 모읍니다.
     *
     * @return array<string, mixed> 지역 조회 상태
     */
    private function geoIpStatus(): array
    {
        return [
            'enabled' => (bool) g7_core_settings('geoip.feature_enabled', false),
            'db_present' => $this->geoIp->isAvailable(),
        ];
    }
}
