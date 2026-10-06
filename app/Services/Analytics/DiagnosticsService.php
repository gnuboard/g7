<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Analytics\ExclusionCounter;
use App\Contracts\Analytics\DimensionRegistryInterface;
use App\Contracts\Analytics\GoalRegistryInterface;
use App\Contracts\Repositories\Analytics\ArchiveRunRepositoryInterface;
use App\Contracts\Repositories\Analytics\CollectFailureRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Enums\Analytics\ArchiveRunType;
use App\Enums\Analytics\PrivacyMode;
use App\Extension\HookManager;
use App\Helpers\TimezoneHelper;
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
     * @param  StaleRunService  $staleRuns  멈춘 실행 판정 서비스
     * @param  GoalRegistryInterface  $goals  목표 등록부 (선언 경고 노출용)
     * @param  DimensionRegistryInterface  $dimensions  차원 등록부 (선언 경고 노출용)
     * @param  ArchiveFreshnessService  $freshness  스케줄러 생존 판정
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
        private readonly StaleRunService $staleRuns,
        private readonly GoalRegistryInterface $goals,
        private readonly DimensionRegistryInterface $dimensions,
        private readonly ArchiveFreshnessService $freshness,
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
        $diagnostics = [
            'collection' => $this->collection($config),
            'queue' => $this->queueStatus(),
            'archive' => $this->archive(),
            'storage' => $this->storage->stats($config),
            'geoip' => $this->geoIpStatus(),
            'trusted_proxy' => TrustedProxyDiagnostic::forRequest($request),
            'broadcasting' => [
                // 화면은 이 값으로 폴링 폴백을 켤지 정한다 — 서버가 실제로 방송하는 조건과 같은 식이어야
                // 「소켓이 있다고 믿고 폴링을 껐는데 방송은 없는」 구멍이 생기지 않는다(#682 S3 라운드 3).
                'configured' => HookManager::broadcastingEnabled(),
            ],
            'cloud_ranges' => $this->cloudRangeStatus($config),
            'failures_24h' => $this->failures->countSince(24),
            // 화면이 「이 시각은 사이트 기준」을 알릴 때 쓴다. 비교는 **서버가** 한다 —
            // 표현식 화이트리스트에 `Intl` 이 없어 화면이 견주면 조건이 조용히 거짓이 되고,
            // 사용자 시간대 해석은 이미 `SetTimezone` 미들웨어의 4단계 규약이 갖고 있다
            // (#682 S3 라운드 6, 설계 결정 「보는 사람과 다를 때만」).
            'timezone' => AnalyticsClock::siteTimezone(),
            'timezone_differs' => $this->viewerTimezone($request) !== AnalyticsClock::siteTimezone(),
            // 확장이 잘못 선언해 버려진 목표·차원의 사유. 여기 말고는 볼 곳이 없다 — 비워 두면
            // 운영자는 「전환이 왜 안 쌓이는지」를 나중에 `unknown_goal` 실패로만 간접적으로 안다.
            'declaration_warnings' => array_values(array_merge($this->goals->warnings(), $this->dimensions->warnings())),
        ];

        // 확장이 자기 수집 상태를 진단 화면에 붙이는 자리. 코어 섹션을 덮지 못하게
        // `extensions.{식별자}` 아래로만 받는다 — 화면은 확장이 레이아웃 확장 지점에 둔
        // 자기 조각으로 그 값을 읽는다.
        $diagnostics['extensions'] = self::extensionSections(
            HookManager::applyFilters('core.analytics.diagnostics_sections', [], $config),
        );

        return $diagnostics;
    }

    /**
     * 확장이 돌려준 진단 섹션에서 형식이 맞는 것만 남깁니다.
     *
     * @param  mixed  $sections  필터 결과 (식별자 → 섹션 배열)
     * @return array<string, array<string, mixed>> 식별자 → 섹션
     */
    private static function extensionSections(mixed $sections): array
    {
        $valid = [];

        foreach (is_array($sections) ? $sections : [] as $identifier => $section) {
            if (is_string($identifier) && $identifier !== '' && is_array($section)) {
                $valid[$identifier] = $section;
            }
        }

        return $valid;
    }

    /**
     * 이 응답을 볼 사람의 시간대를 구합니다.
     *
     * `SetTimezone` 미들웨어는 그룹 미들웨어라 Sanctum 인증(라우트 미들웨어)보다 먼저 돕니다 —
     * API 경로에서는 `Auth::check()` 가 아직 거짓이라 계정에 저장한 시간대가 읽히지 않습니다.
     * 그래서 인증이 끝난 뒤의 요청 사용자를 직접 보고, 없으면 공통 해석에 맡깁니다
     * (#682 S3 라운드 6 — 이 자리를 헬퍼에만 맡겼다가 안내가 영영 안 뜨는 것을 실측으로 잡았다).
     *
     * @param  Request|null  $request  현재 요청
     * @return string 보는 사람의 시간대
     */
    private function viewerTimezone(?Request $request): string
    {
        $accountTimezone = $request?->user()?->timezone;

        return is_string($accountTimezone) && $accountTimezone !== ''
            ? $accountTimezone
            : TimezoneHelper::getUserTimezone();
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
        $selection = $this->privacy->consentSelection();

        return [
            'enabled' => $config->enabled,
            'mode' => $config->privacyMode->value,
            // 운영자가 고른 모드와 실제로 도는 모드가 다를 수 있다 — 사유 없이 숫자만 보면
            // 「설정은 IP 저장인데 왜 재방문이 0 인가」를 알 길이 없다.
            'effective_mode' => $this->privacy->isAnonymousForEveryone($config)
                ? PrivacyMode::Anonymous->value
                : $config->privacyMode->value,
            'anonymous_fallback_reason' => $this->privacy->anonymousFallbackReason($config),
            'consent_source' => $selection->governsConsent() ? PrivacyService::SOURCE_PROVIDER : PrivacyService::SOURCE_NONE,
            // 동의 출처 배지 — 제공자를 고르지 못한 상태(ambiguous·missing)는 운영자가 환경설정 개인정보
            // 탭에서 골라야 한다. 출처(`provider`)만 보면 그 상태가 정상 연동과 구별되지 않는다.
            'consent_selection' => $selection->state,
            'consent_provider_label' => $selection->provider?->getLabel(),
            // 실패 로그의 주소도 방문 원장과 같은 마스킹 규칙을 받는다(마이그레이션 주석). 그 사실을
            // 함께 싣지 않으면 운영자가 뒤 옥텟이 지워진 값을 원래 주소로 읽고 그 주소로 차단·문의한다
            // — 형제 경로(`VisitorController::show`·`PrivacyController::subjects`)와 같은 키다.
            'ip_mask_octets' => $config->ipMaskOctets,
            'last_tracked_at' => AnalyticsClock::toSiteDateTimeString($this->visits->lastTrackedAt()),
            'tracked_today' => $this->visits->uniqueVisitorsOnDate($today),
            // 제외 카운터는 캐시에 산다 — 캐시를 비우거나 서버를 다시 띄우면 사라진다.
            // 화면이 그 사실을 함께 적는다.
            'excluded_today' => $this->exclusions->forDate($today),
            'excluded_yesterday' => $this->exclusions->forDate($yesterday),
            // 확장이 등록한 제외 사유는 화면 번역 파일에 없으므로 이름을 함께 싣는다.
            'excluded_reason_labels' => ExclusionCounter::extensionReasons(),
        ];
    }

    /**
     * 큐 상태를 모읍니다.
     *
     * @return array<string, mixed> 큐 상태
     */
    private function queueStatus(): array
    {
        $stats = $this->queue->stats();

        return array_merge($stats, [
            'connection' => (string) config('queue.default'),
            'backlogged' => $stats['pending_jobs'] >= self::QUEUE_BACKLOG_THRESHOLD,
            // 방문 수집은 큐를 타지 않는다 — 여기 쌓이는 것은 집계·익명화 같은 배치다.
            // 워커가 안 돌면 그 배치가 영영 안 돌아 화면 숫자가 멈춘 채 남는다
            // (#682 S3 라운드 6 — 예전에는 이 힌트가 「수집이 통째로 유실 중」을 뜻했다).
            'worker_hint' => $stats['pending_jobs'] > 0,
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
        // 기준 시간을 넘긴 「실행 중」은 워커가 도중에 죽어 닫히지 못한 행이다 — 진행 중으로 치면
        // 수동 집계 버튼이 잠기고 폴링이 멈추지 않는다. 행을 닫는 일은 매시 집계가 한다(여기는 읽기만).
        $running = $this->runs->findRunning($this->staleRuns->cutoff());

        $reference = $lastHour?->finished_at ?? $lastDay?->finished_at;

        return [
            'last_hour_run' => AnalyticsClock::toSiteDateTimeString($lastHour?->finished_at),
            'last_day_run' => AnalyticsClock::toSiteDateTimeString($lastDay?->finished_at),
            // 집계가 밀리면 화면 숫자가 그 시점에 멈춘 채 그럴듯하게 남는다.
            'lag_hours' => $reference !== null
                ? (int) floor($reference->diffInHours(AnalyticsClock::now()))
                : null,
            'running_run_id' => $running?->id,
            // 스케줄러가 매시 집계를 최근에 돌렸는가 — 화면 열 때 집계가 숫자를 맞춰도 어제 확정·정리·
            // 메일 보고서는 스케줄러만 돌린다.
            'scheduler' => $this->freshness->schedulerStatus(),
            'on_view_enabled' => AnalyticsConfig::fromSettings()->archiveOnViewEnabled,
        ];
    }

    /**
     * 클라우드 대역 목록 상태를 모읍니다.
     *
     * 목록 파일의 갱신 시각은 ISO8601 로 적혀 있습니다 — 설정 응답과 **같은 기준**으로
     * 사이트 표준시로 바꿔 내보냅니다(#682 S3 라운드 7). 두 응답이 같은 값을 다른 형식으로
     * 내면 설정 화면과 진단 화면에 서로 다른 시각이 보입니다.
     *
     * @param  AnalyticsConfig  $config  설정
     * @return array<string, mixed> 대역 목록 상태
     */
    private function cloudRangeStatus(AnalyticsConfig $config): array
    {
        $status = $this->cloudRanges->status();
        $status['updated_at'] = AnalyticsClock::storedToSiteDateTimeString($status['updated_at'] ?? null);

        return array_merge(['enabled' => $config->blockCloudIpRanges], $status);
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
