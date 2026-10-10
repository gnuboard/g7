<?php

namespace App\Analytics;

use App\Enums\Analytics\CountryFilterMode;
use App\Enums\Analytics\PrivacyMode;
use App\Enums\Analytics\ReferrerAnonymization;

/**
 * 방문자 통계 설정 값 객체
 *
 * 운영자가 관리자 화면에서 정한 값을 요청당 한 번 읽어 담습니다. 수집 파이프라인은
 * 한 요청 안에서 같은 설정을 수십 번 참조하는데, 지점마다 `g7_core_settings()` 를
 * 직접 부르면 같은 요청 안에서도 값이 갈릴 수 있고 기본값 처리가 제각각이 됩니다.
 * 읽는 곳은 이 객체 하나만 봅니다.
 */
readonly class AnalyticsConfig
{
    /** 익명 폴백 사유 — 동의 모드인데 동의를 물어볼 곳이 없다 */
    public const ANONYMOUS_REASON_NO_CONSENT_SOURCE = 'consent_source_missing';

    /** 익명 폴백 사유 — 동의 관리 플러그인이 동의 전 방문자 쿠키를 막는다 */
    public const ANONYMOUS_REASON_CONSENT_PLUGIN_BLOCKS_COOKIES = 'consent_plugin_blocks_cookies';

    /** 체류시간 구간 경계 기본값 (초) */
    public const DEFAULT_DURATION_BUCKET_BOUNDS = '10,30,60,180,600,1800';

    /** 본 페이지 수 구간 경계 기본값 */
    public const DEFAULT_PAGES_BUCKET_BOUNDS = '1,2,3,4,5,7,10,14,20';

    /**
     * 설정 값 객체를 생성합니다.
     *
     * @param  bool  $enabled  수집 마스터 스위치
     * @param  PrivacyMode  $privacyMode  개인정보 수집 모드
     * @param  bool  $respectDnt  추적 거부 신호 존중 여부
     * @param  int  $ipMaskOctets  IP 마스킹 옥텟 수 (0~3)
     * @param  bool  $geoBeforeMask  마스킹 전에 지역을 조회할지 여부
     * @param  ReferrerAnonymization  $anonymizeReferrer  유입 URL 익명화 방식
     * @param  bool  $pseudonymizeUserId  회원 ID 가명화 여부
     * @param  int  $visitTimeoutMinutes  방문 이어짐 판정 시간 (분)
     * @param  int  $maxActionsPerVisit  방문당 최대 액션 수
     * @param  array<int, string>  $excludedUrlPatterns  제외 경로 글로브 목록
     * @param  array<int, string>  $excludedIps  제외 IP·대역 목록
     * @param  array<int, string>  $excludedUserAgents  제외 User-Agent 부분 문자열 목록
     * @param  bool  $blockHeadless  자동화 브라우저 제외 여부
     * @param  bool  $blockCloudIpRanges  클라우드 사업자 IP 대역 제외 여부
     * @param  CountryFilterMode  $countryFilterMode  국가 필터 방식
     * @param  array<int, string>  $countryFilterCodes  국가 필터 대상 코드 목록
     * @param  array<int, string>  $ipAllowlist  규칙을 건너뛸 IP·대역 목록
     * @param  bool  $excludePrefetch  미리 가져오기 요청 제외 여부
     * @param  bool  $botDetectorEnabled  봇 판별 사용 여부
     * @param  bool  $botTrackingEnabled  봇 방문 별도 집계 여부
     * @param  bool  $siteSearchEnabled  사이트 내부 검색어 수집 여부
     * @param  bool  $trackDownloads  파일 내려받기 수집 여부
     * @param  bool  $trackOutlinks  외부 링크 수집 여부
     * @param  array<int, string>  $downloadExtensions  내려받기로 볼 확장자 목록
     * @param  int  $retentionDays  원장 보존일
     * @param  int  $hourlyRetentionDays  시간 집계 보존일
     * @param  int  $botHitsRetentionDays  봇 요청 보존일
     * @param  int  $failureLogRetentionDays  실패 로그·실행 이력 보존일
     * @param  int  $ledgerWarningActions  액션 원장 경고 임계치
     * @param  int  $ledgerWarningVisits  방문 원장 경고 임계치
     * @param  int  $archiveChunkSize  집계 청크 크기
     * @param  int  $realtimePollSeconds  실시간 화면 폴링 주기 (초)
     * @param  int  $realtimeWindowMinutes  현재 접속자 시간 창 (분)
     * @param  int  $topNOverview  개요 상위 목록 건수
     * @param  int  $topNDashboard  대시보드 위젯 상위 목록 건수
     * @param  int  $topNDetail  상세 화면 상위 목록 건수
     * @param  bool  $reportWeeklyEnabled  주간 리포트 발송 여부
     * @param  bool  $reportMonthlyEnabled  월간 리포트 발송 여부
     * @param  bool  $publicSummaryEnabled  공개 요약 API·위젯 사용 여부
     * @param  int  $visitorCookieDays  방문자 쿠키 보관일
     * @param  bool  $archiveOnViewEnabled  화면을 열 때 오래된 집계를 다시 계산할지 여부
     * @param  int  $archiveOnViewMaxVisits  화면 열 때 집계를 건너뛸 오늘 방문 건수 상한
     * @param  array<int, int>  $durationBucketBounds  체류시간 구간 경계 (초, 순증가)
     * @param  array<int, int>  $pagesBucketBounds  본 페이지 수 구간 경계 (순증가)
     */
    public function __construct(
        public bool $enabled,
        public PrivacyMode $privacyMode,
        public bool $respectDnt,
        public int $ipMaskOctets,
        public bool $geoBeforeMask,
        public ReferrerAnonymization $anonymizeReferrer,
        public bool $pseudonymizeUserId,
        public int $visitTimeoutMinutes,
        public int $maxActionsPerVisit,
        public array $excludedUrlPatterns,
        public array $excludedIps,
        public array $excludedUserAgents,
        public bool $blockHeadless,
        public bool $blockCloudIpRanges,
        public CountryFilterMode $countryFilterMode,
        public array $countryFilterCodes,
        public array $ipAllowlist,
        public bool $excludePrefetch,
        public bool $botDetectorEnabled,
        public bool $botTrackingEnabled,
        public bool $siteSearchEnabled,
        public bool $trackDownloads,
        public bool $trackOutlinks,
        public array $downloadExtensions,
        public int $retentionDays,
        public int $hourlyRetentionDays,
        public int $botHitsRetentionDays,
        public int $failureLogRetentionDays,
        public int $ledgerWarningActions,
        public int $ledgerWarningVisits,
        public int $archiveChunkSize,
        public int $realtimePollSeconds,
        public int $realtimeWindowMinutes,
        public int $topNOverview,
        public int $topNDashboard,
        public int $topNDetail,
        public bool $reportWeeklyEnabled,
        public bool $reportMonthlyEnabled,
        public bool $publicSummaryEnabled,
        public int $visitorCookieDays,
        public bool $archiveOnViewEnabled = true,
        public int $archiveOnViewMaxVisits = 50000,
        public array $durationBucketBounds = [10, 30, 60, 180, 600, 1800],
        public array $pagesBucketBounds = [1, 2, 3, 4, 5, 7, 10, 14, 20],
    ) {}

    /**
     * 저장된 설정에서 값 객체를 만듭니다.
     *
     * 설정 파일이 없거나 키가 비어 있어도 코어 기본값이 병합되므로 여기서는 타입만
     * 맞춥니다. 범위 검증은 저장 시점의 FormRequest 가 책임집니다.
     *
     * @return self 설정 값 객체
     */
    public static function fromSettings(): self
    {
        $get = static fn (string $key, mixed $default): mixed => g7_core_settings('analytics.'.$key, $default);
        $list = static function (string $key) use ($get): array {
            $value = $get($key, []);

            return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
        };

        return new self(
            enabled: (bool) $get('enabled', true),
            privacyMode: PrivacyMode::tryFrom((string) $get('privacy_mode', 'full')) ?? PrivacyMode::Full,
            respectDnt: (bool) $get('respect_dnt', true),
            ipMaskOctets: max(0, min(3, (int) $get('ip_mask_octets', 0))),
            geoBeforeMask: (bool) $get('geo_before_mask', true),
            anonymizeReferrer: ReferrerAnonymization::tryFrom((string) $get('anonymize_referrer', 'none')) ?? ReferrerAnonymization::None,
            pseudonymizeUserId: (bool) $get('pseudonymize_user_id', false),
            visitTimeoutMinutes: (int) $get('visit_timeout_minutes', 30),
            maxActionsPerVisit: (int) $get('max_actions_per_visit', 500),
            excludedUrlPatterns: $list('excluded_url_patterns'),
            excludedIps: $list('excluded_ips'),
            excludedUserAgents: $list('excluded_user_agents'),
            blockHeadless: (bool) $get('block_headless', true),
            blockCloudIpRanges: (bool) $get('block_cloud_ip_ranges', false),
            countryFilterMode: CountryFilterMode::tryFrom((string) $get('country_filter_mode', 'none')) ?? CountryFilterMode::None,
            countryFilterCodes: array_map('strtoupper', $list('country_filter_codes')),
            ipAllowlist: $list('ip_allowlist'),
            excludePrefetch: (bool) $get('exclude_prefetch', true),
            botDetectorEnabled: (bool) $get('bot_detector_enabled', true),
            botTrackingEnabled: (bool) $get('bot_tracking_enabled', true),
            siteSearchEnabled: (bool) $get('site_search_enabled', true),
            trackDownloads: (bool) $get('track_downloads', true),
            trackOutlinks: (bool) $get('track_outlinks', true),
            downloadExtensions: array_map('strtolower', $list('download_extensions')),
            retentionDays: (int) $get('retention_days', 90),
            hourlyRetentionDays: (int) $get('hourly_retention_days', 90),
            botHitsRetentionDays: (int) $get('bot_hits_retention_days', 30),
            failureLogRetentionDays: (int) $get('failure_log_retention_days', 30),
            ledgerWarningActions: (int) $get('ledger_warning_actions', 2000000),
            ledgerWarningVisits: (int) $get('ledger_warning_visits', 500000),
            archiveChunkSize: (int) $get('archive_chunk_size', 5000),
            realtimePollSeconds: (int) $get('realtime_poll_seconds', 30),
            realtimeWindowMinutes: (int) $get('realtime_window_minutes', 5),
            topNOverview: (int) $get('top_n_overview', 5),
            topNDashboard: (int) $get('top_n_dashboard', 5),
            topNDetail: (int) $get('top_n_detail', 25),
            reportWeeklyEnabled: (bool) $get('report_weekly_enabled', false),
            reportMonthlyEnabled: (bool) $get('report_monthly_enabled', false),
            publicSummaryEnabled: (bool) $get('public_summary_enabled', true),
            visitorCookieDays: (int) $get('visitor_cookie_days', 395),
            archiveOnViewEnabled: (bool) $get('archive_on_view_enabled', true),
            archiveOnViewMaxVisits: max(1, (int) $get('archive_on_view_max_visits', 50000)),
            durationBucketBounds: self::parseBounds($get('duration_bucket_bounds', self::DEFAULT_DURATION_BUCKET_BOUNDS), self::DEFAULT_DURATION_BUCKET_BOUNDS),
            pagesBucketBounds: self::parseBounds($get('pages_bucket_bounds', self::DEFAULT_PAGES_BUCKET_BOUNDS), self::DEFAULT_PAGES_BUCKET_BOUNDS),
        );
    }

    /**
     * 쉼표로 구분한 구간 경계를 정수 목록으로 풉니다.
     *
     * 저장 시점 규칙(`AscendingBounds`)이 순증가를 보장하지만, 설정 파일을 손으로 고쳤거나 옛 값이
     * 남아 순서가 깨졌으면 기본값으로 돌아간다 — 거꾸로 된 경계로 집계하면 어떤 방문은 구간을 못 찾는다.
     *
     * @param  mixed  $value  저장된 값 (문자열 또는 배열)
     * @param  string  $default  기본 경계 문자열
     * @return array<int, int> 순증가 경계 목록
     */
    public static function parseBounds(mixed $value, string $default): array
    {
        $parts = is_array($value) ? $value : explode(',', (string) $value);
        $bounds = [];

        foreach ($parts as $part) {
            $part = trim((string) $part);

            if ($part === '' || ! ctype_digit($part)) {
                return $default === '' ? [] : self::parseBounds($default, '');
            }

            $bound = (int) $part;

            if ($bound < 1 || ($bounds !== [] && $bound <= end($bounds))) {
                return $default === '' ? [] : self::parseBounds($default, '');
            }

            $bounds[] = $bound;
        }

        return $bounds;
    }

    /**
     * 원장 보존일을 하한 아래로 내려가지 않게 보정해 돌려줍니다.
     *
     * 설정 화면은 하한을 막지만 커맨드 옵션·과거 설정으로 더 작은 값이 들어올 수
     * 있습니다. 보존일이 0 이 되면 정리 배치가 오늘 방문까지 지웁니다.
     *
     * @return int 보정된 보존일
     */
    public function effectiveRetentionDays(): int
    {
        return max((int) config('analytics.limits.retention_floor_days', 7), $this->retentionDays);
    }

    /**
     * 익명 모드로 동작해야 하는지 판정합니다.
     *
     * 동의 모드인데 동의 출처가 없으면(동의 필터에 답하는 확장이 없으면) 아무것도 모으지 않는 대신
     * 익명으로 모읍니다 — 개인정보를 남기지 않으므로 법적으로 가장 안전한 쪽이고,
     * 운영자가 이유도 모른 채 데이터를 잃는 상황을 막습니다.
     *
     * @param  string|null  $consentSource  동의 출처 (없으면 null 또는 'none')
     * @return bool 익명 모드 동작 여부
     */
    public function resolvesToAnonymous(?string $consentSource): bool
    {
        return $this->privacyMode === PrivacyMode::Anonymous
            || $this->anonymousFallbackReason($consentSource) !== null;
    }

    /**
     * 운영자가 고른 모드 대신 익명으로 내려간 사유를 돌려줍니다.
     *
     * 화면이 「왜 익명으로 수집되고 있는가」를 운영자에게 설명할 수 있어야 합니다 —
     * 사유 없이 모드만 바뀌면 설정과 실제 동작이 달라 보이고 그 이유를 알 길이 없습니다.
     *
     * @param  string|null  $consentSource  동의 출처 (없으면 null 또는 'none')
     * @return string|null 사유 키 (내려가지 않으면 null)
     */
    public function anonymousFallbackReason(?string $consentSource): ?string
    {
        // 동의 모드인데 동의를 물어볼 곳이 없다 — 아무것도 모으지 않는 대신 익명으로 모은다.
        if ($this->privacyMode === PrivacyMode::Consent
            && ($consentSource === null || $consentSource === 'none')
        ) {
            return self::ANONYMOUS_REASON_NO_CONSENT_SOURCE;
        }

        // IP 저장 모드인데 동의 관리 제공자가 있다(동의 출처 `provider`) — 그런 제공자는 방문자가
        // 기능·분석 쿠키를 허용하기 전에는 쿠키를 막는다. 방문자
        // 쿠키가 브라우저에 남지 않으므로 비콘마다 새 방문자 키가 생겨 방문·순방문자가 페이지뷰만큼
        // 부풀고 이탈률이 100% 가 된다. 허용 전 방문자는 처음부터 일자 회전 해시로 묶는다.
        if ($this->privacyMode === PrivacyMode::Full && $consentSource === 'provider') {
            return self::ANONYMOUS_REASON_CONSENT_PLUGIN_BLOCKS_COOKIES;
        }

        return null;
    }
}
