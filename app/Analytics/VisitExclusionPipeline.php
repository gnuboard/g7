<?php

namespace App\Analytics;

use App\Enums\Analytics\CountryFilterMode;
use App\Enums\Analytics\ExclusionReason;
use Illuminate\Support\Facades\File;

/**
 * 수집 제외 판정
 *
 * 방문을 셀지 말지를 정해진 순서대로 검사합니다. 순서가 계약입니다 — 허용 목록이
 * 뒤쪽 규칙 세 개를 건너뛰게 만들므로, 그 자리가 바뀌면 운영자가 「이 IP 는 통과」라고
 * 적어 둔 주소가 국가 필터에 걸려 사라집니다.
 *
 * 제외될 때마다 사유를 셉니다. 세지 않으면 운영자는 방문이 왜 비는지 알 길이 없습니다.
 */
class VisitExclusionPipeline
{
    /**
     * 제외 판정기를 생성합니다.
     *
     * @param  AnalyticsBotClassifier  $botClassifier  봇 판별기
     */
    public function __construct(private readonly AnalyticsBotClassifier $botClassifier) {}

    /**
     * 수집 요청을 검사해 제외 사유를 돌려줍니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  AnalyticsConfig  $config  설정
     * @param  string|null  $countryCode  판정된 국가 코드 (국가 필터용)
     * @param  bool|null  $hasConsent  동의 여부 (동의 모드에서만 의미가 있다)
     * @return ExclusionReason|null 제외 사유 (수집 대상이면 null)
     */
    public function evaluate(
        TrackingRequest $request,
        AnalyticsConfig $config,
        ?string $countryCode = null,
        ?bool $hasConsent = null,
    ): ?ExclusionReason {
        if (! $config->enabled) {
            return ExclusionReason::Disabled;
        }

        if ($config->excludePrefetch && $this->isPrefetch($request)) {
            return ExclusionReason::Prefetch;
        }

        if ($config->respectDnt && $this->hasDoNotTrack($request)) {
            return ExclusionReason::Dnt;
        }

        if ($this->hasOptOutCookie($request)) {
            return ExclusionReason::OptOut;
        }

        if ($this->matchesExcludedUrl($request, $config)) {
            return ExclusionReason::UrlPattern;
        }

        // 허용 목록에 있으면 IP·UA·헤드리스·클라우드·국가 규칙을 건너뛴다.
        // 운영자가 「이 주소는 무조건 센다」고 적어 둔 것이므로 그 뒤 규칙보다 우선한다.
        $allowlisted = $request->ip !== null
            && $config->ipAllowlist !== []
            && IpAddress::matchesAny($request->ip, $config->ipAllowlist);

        if (! $allowlisted) {
            if ($this->matchesExcludedIp($request, $config)) {
                return ExclusionReason::IpRule;
            }

            if ($this->matchesExcludedUserAgent($request, $config)) {
                return ExclusionReason::UaRule;
            }

            if ($config->blockHeadless && $this->isHeadless($request)) {
                return ExclusionReason::Headless;
            }

            if ($config->blockCloudIpRanges && $this->isCloudIp($request)) {
                return ExclusionReason::CloudIp;
            }

            if ($this->isFilteredCountry($config, $countryCode)) {
                return ExclusionReason::Country;
            }
        }

        // 봇은 허용 목록과 무관하게 판정한다 — 허용 목록은 「사람 방문으로 세라」가
        // 아니라 「차단 규칙을 적용하지 말라」는 뜻이고, 봇 방문은 별도 원장으로 간다.
        if ($this->botClassifier->isBotUserAgent($request, $config)) {
            return ExclusionReason::Bot;
        }

        // 동의 모드에서 동의 출처가 있는데 동의를 받지 못했으면 수집하지 않는다.
        // 출처 자체가 없을 때는 익명 모드로 내려가므로 여기 오지 않는다.
        if ($hasConsent === false) {
            return ExclusionReason::Consent;
        }

        return null;
    }

    /**
     * 미리 가져오기·미리 그리기 요청인지 판정합니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @return bool 미리 가져오기 여부
     */
    private function isPrefetch(TrackingRequest $request): bool
    {
        foreach ((array) config('analytics.prefetch_headers', []) as $header => $values) {
            $actual = $request->header((string) $header);

            if ($actual === null) {
                continue;
            }

            foreach ((array) $values as $value) {
                if (strcasecmp(trim($actual), (string) $value) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 추적 거부 신호가 있는지 판정합니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @return bool 추적 거부 여부
     */
    private function hasDoNotTrack(TrackingRequest $request): bool
    {
        return $request->header('DNT') === '1' || $request->header('Sec-GPC') === '1';
    }

    /**
     * 방문자가 직접 제외를 선택했는지 판정합니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @return bool 제외 선택 여부
     */
    private function hasOptOutCookie(TrackingRequest $request): bool
    {
        $name = (string) config('analytics.cookies.opt_out', 'g7_analytics_optout');

        return $request->cookie($name) === '1';
    }

    /**
     * 제외 경로 규칙에 걸리는지 판정합니다.
     *
     * 규칙은 글로브입니다 — `*` 로 끝나면 앞부분 일치, 아니면 정확히 같아야 합니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  AnalyticsConfig  $config  설정
     * @return bool 제외 여부
     */
    private function matchesExcludedUrl(TrackingRequest $request, AnalyticsConfig $config): bool
    {
        $path = parse_url($request->url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        return self::pathMatchesAny($path, $config->excludedUrlPatterns);
    }

    /**
     * 경로가 글로브 목록 중 하나에 걸리는지 판정합니다.
     *
     * 트래커도 같은 규칙으로 선제 제외하므로 판정식을 공개 정적 메서드로 둡니다.
     *
     * @param  string  $path  검사할 경로
     * @param  array<int, string>  $patterns  글로브 목록
     * @return bool 일치 여부
     */
    public static function pathMatchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($path, rtrim($pattern, '*'))) {
                    return true;
                }

                continue;
            }

            if ($path === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * 제외 IP 규칙에 걸리는지 판정합니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  AnalyticsConfig  $config  설정
     * @return bool 제외 여부
     */
    private function matchesExcludedIp(TrackingRequest $request, AnalyticsConfig $config): bool
    {
        return $request->ip !== null
            && $config->excludedIps !== []
            && IpAddress::matchesAny($request->ip, $config->excludedIps);
    }

    /**
     * 제외 User-Agent 규칙에 걸리는지 판정합니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  AnalyticsConfig  $config  설정
     * @return bool 제외 여부
     */
    private function matchesExcludedUserAgent(TrackingRequest $request, AnalyticsConfig $config): bool
    {
        $ua = $request->userAgent;

        if ($ua === null || $ua === '' || $config->excludedUserAgents === []) {
            return false;
        }

        foreach ($config->excludedUserAgents as $needle) {
            if ($needle !== '' && stripos($ua, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 자동화 브라우저인지 판정합니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @return bool 자동화 여부
     */
    private function isHeadless(TrackingRequest $request): bool
    {
        if ($request->webdriver) {
            return true;
        }

        $ua = $request->userAgent ?? '';

        foreach ((array) config('analytics.headless_patterns', []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && stripos($ua, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 클라우드 사업자 대역인지 판정합니다.
     *
     * 목록 파일이 없으면 판정하지 않습니다 — 목록 없이 차단하면 아무 근거 없이
     * 방문을 버리는 셈이고, 진단 화면이 파일 부재를 경고합니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @return bool 클라우드 대역 여부
     */
    private function isCloudIp(TrackingRequest $request): bool
    {
        if ($request->ip === null) {
            return false;
        }

        $ranges = self::cloudRanges();

        return $ranges !== [] && IpAddress::matchesAny($request->ip, $ranges);
    }

    /**
     * 저장된 클라우드 IP 대역 목록을 읽습니다.
     *
     * @return array<int, string> 대역 목록 (파일이 없으면 빈 배열)
     */
    private static function cloudRanges(): array
    {
        $path = storage_path('app/'.config('analytics.cloud_ip_ranges.storage_path', 'analytics/cloud-ip-ranges.json'));

        if (! File::exists($path)) {
            return [];
        }

        try {
            $decoded = json_decode(File::get($path), true);
        } catch (\Throwable $e) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $ranges = $decoded['ranges'] ?? $decoded;

        return is_array($ranges) ? array_values(array_filter($ranges, 'is_string')) : [];
    }

    /**
     * 국가 필터에 걸리는지 판정합니다.
     *
     * 국가를 모르면(GeoIP 꺼짐·조회 실패) 필터를 적용하지 않습니다 — 모르는 것을
     * 근거로 방문을 버리지 않습니다.
     *
     * @param  AnalyticsConfig  $config  설정
     * @param  string|null  $countryCode  판정된 국가 코드
     * @return bool 제외 여부
     */
    private function isFilteredCountry(AnalyticsConfig $config, ?string $countryCode): bool
    {
        if ($config->countryFilterMode === CountryFilterMode::None || $config->countryFilterCodes === []) {
            return false;
        }

        if ($countryCode === null || $countryCode === '') {
            return false;
        }

        $listed = in_array(strtoupper($countryCode), $config->countryFilterCodes, true);

        return $config->countryFilterMode === CountryFilterMode::Include ? ! $listed : $listed;
    }
}
