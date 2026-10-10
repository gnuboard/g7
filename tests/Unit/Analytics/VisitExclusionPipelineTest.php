<?php

namespace Tests\Unit\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Analytics\ExclusionCounter;
use App\Analytics\TrackingRequest;
use App\Analytics\VisitExclusionPipeline;
use App\Enums\Analytics\ExclusionReason;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 제외 판정 — 사유 12종·토글·순서·카운터.
 *
 * 제외는 조용히 일어난다. 규칙 하나가 잘못 켜지면 방문이 통째로 빠지고, 잘못 꺼지면
 * 봇이 사람으로 섞인다 — 둘 다 화면에는 그럴듯한 숫자가 나온다. 그래서 사유마다
 * 「이 입력이면 이 사유」를 직접 단언하고, 순서가 계약임을 별도로 잠근다.
 */
class VisitExclusionPipelineTest extends TestCase
{
    private VisitExclusionPipeline $pipeline;

    /** 테스트가 만든 클라우드 대역 파일 (정리용) */
    private ?string $cloudFile = null;

    /**
     * 테스트 준비 — 판정기 해석.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->pipeline = app(VisitExclusionPipeline::class);
    }

    /**
     * 테스트 정리 — 대역 파일을 지운다.
     *
     * @return void 없음
     */
    protected function tearDown(): void
    {
        if ($this->cloudFile !== null && File::exists($this->cloudFile)) {
            File::delete($this->cloudFile);
        }

        parent::tearDown();
    }

    /**
     * 수집 요청 하나를 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return TrackingRequest 수집 요청
     */
    private function request(array $overrides = []): TrackingRequest
    {
        return new TrackingRequest(...array_merge([
            'type' => 'pageview',
            'url' => 'https://example.test/board/free',
            'ip' => '203.0.113.50',
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            'browserLanguage' => 'ko-KR',
            'receivedAt' => now()->format('Y-m-d H:i:s'),
        ], $overrides));
    }

    /**
     * 설정을 덮어쓰고 값 객체를 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  `analytics.*` 키별 값
     * @return AnalyticsConfig 설정 값 객체
     */
    private function config(array $overrides = []): AnalyticsConfig
    {
        foreach ($overrides as $key => $value) {
            config(['g7_settings.core.analytics.'.$key => $value]);
        }

        return AnalyticsConfig::fromSettings();
    }

    /**
     * 판정을 실행합니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  array<string, mixed>  $settings  설정 덮어쓰기
     * @param  string|null  $country  판정된 국가
     * @param  bool|null  $consent  동의 여부
     * @return ExclusionReason|null 제외 사유
     */
    private function evaluate(TrackingRequest $request, array $settings = [], ?string $country = null, ?bool $consent = null): ?ExclusionReason
    {
        return $this->pipeline->evaluate($request, $this->config($settings), $country, $consent);
    }

    /**
     * 기본 설정의 보통 방문은 제외되지 않는다.
     *
     * @scenario exclusion=none
     *
     * @effects excluded_requests_record_nothing
     *
     * @return void 없음
     */
    public function test_ordinary_visit_is_not_excluded(): void
    {
        $this->assertNull($this->evaluate($this->request()));
    }

    /**
     * 수집을 끄면 다른 무엇보다 먼저 disabled 다.
     *
     * @scenario exclusion=disabled
     *
     * @effects excluded_requests_record_nothing
     *
     * @return void 없음
     */
    public function test_disabled_wins_over_everything(): void
    {
        $request = $this->request(['headers' => ['dnt' => '1'], 'url' => 'https://example.test/admin/users']);

        $this->assertSame(ExclusionReason::Disabled, $this->evaluate($request, ['enabled' => false]));
    }

    /**
     * 미리 가져오기 헤더 4종은 토글이 켜져 있을 때만 제외된다.
     *
     * @scenario exclusion=prefetch
     *
     * @effects excluded_requests_record_nothing, client_and_server_exclusion_rules_agree
     *
     * @return void 없음
     */
    public function test_prefetch_headers_are_excluded_only_when_enabled(): void
    {
        $headers = [
            ['x-purpose' => 'preview'],
            ['x-purpose' => 'prefetch'],
            ['purpose' => 'prefetch'],
            ['sec-purpose' => 'prefetch'],
            ['x-moz' => 'prefetch'],
        ];

        foreach ($headers as $header) {
            $request = $this->request(['headers' => $header]);

            $this->assertSame(ExclusionReason::Prefetch, $this->evaluate($request, ['exclude_prefetch' => true]), json_encode($header));
            $this->assertNull($this->evaluate($request, ['exclude_prefetch' => false]), json_encode($header).' (토글 끔)');
        }
    }

    /**
     * DNT 와 Sec-GPC 는 존중 토글이 켜져 있을 때만 제외된다.
     *
     * @scenario exclusion=dnt
     *
     * @effects excluded_requests_record_nothing, tracker_honours_do_not_track_and_opt_out
     *
     * @return void 없음
     */
    public function test_dnt_and_gpc_respect_toggle(): void
    {
        foreach ([['dnt' => '1'], ['sec-gpc' => '1']] as $header) {
            $request = $this->request(['headers' => $header]);

            $this->assertSame(ExclusionReason::Dnt, $this->evaluate($request, ['respect_dnt' => true]), json_encode($header));
            $this->assertNull($this->evaluate($request, ['respect_dnt' => false]), json_encode($header).' (토글 끔)');
        }

        $this->assertNull($this->evaluate($this->request(['headers' => ['dnt' => '0']])), 'DNT:0 은 거부가 아니다');
    }

    /**
     * 제외 쿠키가 1 이면 방문자 본인의 선택으로 제외된다.
     *
     * @scenario exclusion=opt_out
     *
     * @effects excluded_requests_record_nothing, tracker_honours_do_not_track_and_opt_out
     *
     * @return void 없음
     */
    public function test_opt_out_cookie_excludes(): void
    {
        $this->assertSame(ExclusionReason::OptOut, $this->evaluate($this->request(['cookies' => ['g7_analytics_optout' => '1']])));
        $this->assertNull($this->evaluate($this->request(['cookies' => ['g7_analytics_optout' => '0']])));
    }

    /**
     * 경로 글로브는 `*` 로 끝나면 앞부분 일치, 아니면 정확히 같아야 한다.
     *
     * @scenario exclusion=url_pattern
     *
     * @effects excluded_requests_record_nothing, client_and_server_exclusion_rules_agree
     *
     * @return void 없음
     */
    public function test_url_glob_exact_and_prefix(): void
    {
        $settings = ['excluded_url_patterns' => ['/admin*', '/health']];

        $this->assertSame(ExclusionReason::UrlPattern, $this->evaluate($this->request(['url' => 'https://example.test/admin/users']), $settings));
        $this->assertSame(ExclusionReason::UrlPattern, $this->evaluate($this->request(['url' => 'https://example.test/health']), $settings));
        $this->assertNull($this->evaluate($this->request(['url' => 'https://example.test/health/live']), $settings), '정확 일치 규칙은 하위 경로를 잡지 않는다');
        $this->assertNull($this->evaluate($this->request(['url' => 'https://example.test/board/admin']), $settings), '전방 일치는 앞부분만 본다');
    }

    /**
     * 제외 IP 는 단일 주소와 CIDR(v4·v6) 모두 받는다.
     *
     * @scenario exclusion=ip_rule
     *
     * @effects excluded_requests_record_nothing
     *
     * @return void 없음
     */
    public function test_ip_rule_matches_single_and_cidr(): void
    {
        $settings = ['excluded_ips' => ['198.51.100.7', '203.0.113.0/24', '2001:db8::/32']];

        $this->assertSame(ExclusionReason::IpRule, $this->evaluate($this->request(['ip' => '198.51.100.7']), $settings));
        $this->assertSame(ExclusionReason::IpRule, $this->evaluate($this->request(['ip' => '203.0.113.200']), $settings));
        $this->assertSame(ExclusionReason::IpRule, $this->evaluate($this->request(['ip' => '2001:db8:1::1']), $settings));
        $this->assertNull($this->evaluate($this->request(['ip' => '192.0.2.1']), $settings));
    }

    /**
     * 제외 User-Agent 는 대소문자 무시 부분 일치다.
     *
     * @scenario exclusion=ua_rule
     *
     * @effects excluded_requests_record_nothing
     *
     * @return void 없음
     */
    public function test_ua_rule_is_case_insensitive_substring(): void
    {
        $settings = ['excluded_user_agents' => ['uptimemonitor']];
        $request = $this->request(['userAgent' => 'Mozilla/5.0 UptimeMonitor/2.1 (+https://monitor.example)']);

        $this->assertSame(ExclusionReason::UaRule, $this->evaluate($request, $settings));
        $this->assertNull($this->evaluate($this->request(), $settings));
    }

    /**
     * 자동화 브라우저는 UA 표기나 webdriver 표식으로 제외되고, 토글로 끌 수 있다.
     *
     * @scenario exclusion=headless
     *
     * @effects excluded_requests_record_nothing
     *
     * @return void 없음
     */
    public function test_headless_by_user_agent_or_webdriver_flag(): void
    {
        $headless = $this->request(['userAgent' => 'Mozilla/5.0 (X11; Linux x86_64) HeadlessChrome/128.0.0.0 Safari/537.36']);
        $webdriver = $this->request(['webdriver' => true]);

        // 토글을 명시한다 — 운영자가 저장해 둔 설정이 config 미러로 올라오므로 기본값에 기대면
        // 그 사이트에서만 빨갛게 된다(이 저장소 실측에서 실제로 겪었다).
        $this->assertSame(ExclusionReason::Headless, $this->evaluate($headless, ['block_headless' => true]));
        $this->assertSame(ExclusionReason::Headless, $this->evaluate($webdriver, ['block_headless' => true]));
        $this->assertNull($this->evaluate($webdriver, ['block_headless' => false]), '토글을 끄면 세어야 한다');
    }

    /**
     * 클라우드 대역은 목록 파일이 있을 때만 판정한다.
     *
     * @scenario exclusion=cloud_ip
     *
     * @effects excluded_requests_record_nothing
     *
     * @return void 없음
     */
    public function test_cloud_ip_requires_range_file(): void
    {
        $relative = 'analytics/test-cloud-ranges-'.uniqid().'.json';
        config(['analytics.cloud_ip_ranges.storage_path' => $relative]);
        $this->cloudFile = storage_path('app/'.$relative);

        $request = $this->request(['ip' => '203.0.113.50']);

        // 파일 없음 — 근거 없이 버리지 않는다.
        $this->assertNull($this->evaluate($request, ['block_cloud_ip_ranges' => true]));

        File::ensureDirectoryExists(dirname($this->cloudFile));
        File::put($this->cloudFile, json_encode(['ranges' => ['203.0.113.0/24']]));

        $this->assertSame(ExclusionReason::CloudIp, $this->evaluate($request, ['block_cloud_ip_ranges' => true]));
        $this->assertNull($this->evaluate($request, ['block_cloud_ip_ranges' => false]), '토글을 끄면 파일이 있어도 세어야 한다');
    }

    /**
     * 국가 필터는 include 면 목록 밖을, exclude 면 목록 안을 버리고 모르면 통과시킨다.
     *
     * @scenario exclusion=country
     *
     * @effects excluded_requests_record_nothing
     *
     * @return void 없음
     */
    public function test_country_include_and_exclude(): void
    {
        $include = ['country_filter_mode' => 'include', 'country_filter_codes' => ['KR']];
        $exclude = ['country_filter_mode' => 'exclude', 'country_filter_codes' => ['KR']];

        $this->assertNull($this->evaluate($this->request(), $include, 'KR'));
        $this->assertSame(ExclusionReason::Country, $this->evaluate($this->request(), $include, 'JP'));
        $this->assertSame(ExclusionReason::Country, $this->evaluate($this->request(), $exclude, 'KR'));
        $this->assertNull($this->evaluate($this->request(), $exclude, 'JP'));
        $this->assertNull($this->evaluate($this->request(), $include, null), '국가를 모르면 버리지 않는다');
    }

    /**
     * 허용 목록 IP 는 IP·UA·헤드리스·클라우드·국가 규칙을 건너뛰되 봇 판정은 받는다.
     *
     * @scenario exclusion=none, visitor=bot
     *
     * @effects allowlisted_ip_skips_ip_ua_headless_cloud_country_rules, bot_requests_never_enter_the_visit_ledger
     *
     * @return void 없음
     */
    public function test_allowlist_skips_block_rules_but_not_bot(): void
    {
        $settings = [
            'ip_allowlist' => ['203.0.113.0/24'],
            'excluded_ips' => ['203.0.113.50'],
            'excluded_user_agents' => ['Chrome'],
            'country_filter_mode' => 'exclude',
            'country_filter_codes' => ['KR'],
        ];

        $this->assertNull($this->evaluate($this->request(['webdriver' => true]), $settings, 'KR'), '허용 목록은 차단 규칙 전부를 면제한다');

        $bot = $this->request(['userAgent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']);
        $this->assertSame(ExclusionReason::Bot, $this->evaluate($bot, $settings), '허용 목록은 「사람으로 세라」가 아니다');
    }

    /**
     * 봇은 방문 원장에 들어가지 않고, 판정 토글을 끄면 판정하지 않는다.
     *
     * @scenario exclusion=bot, visitor=bot
     *
     * @effects bot_requests_never_enter_the_visit_ledger
     *
     * @return void 없음
     */
    public function test_bot_user_agent_is_excluded(): void
    {
        $bot = $this->request(['userAgent' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)']);

        $this->assertSame(ExclusionReason::Bot, $this->evaluate($bot));
        $this->assertNull($this->evaluate($bot, ['bot_detector_enabled' => false]));
    }

    /**
     * 동의 판정이 false 일 때만 consent 로 제외되고 null(판정 대상 아님)은 통과한다.
     *
     * @scenario exclusion=consent, mode=consent
     *
     * @effects consent_mode_with_declined_consent_records_nothing, consent_mode_without_consent_source_falls_back_to_anonymous
     *
     * @return void 없음
     */
    public function test_consent_false_excludes_but_unknown_passes(): void
    {
        $settings = ['privacy_mode' => 'consent'];

        $this->assertSame(ExclusionReason::Consent, $this->evaluate($this->request(), $settings, null, false));
        $this->assertNull($this->evaluate($this->request(), $settings, null, null), '동의 출처가 없으면 익명으로 세지 제외하지 않는다');
        $this->assertNull($this->evaluate($this->request(), $settings, null, true));
    }

    /**
     * 판정 순서가 고정돼 있다 — 앞 단계 사유가 뒤 단계보다 먼저 나온다.
     *
     * @scenario exclusion=dnt
     *
     * @effects each_exclusion_reason_is_counted_separately
     *
     * @return void 없음
     */
    public function test_order_is_fixed(): void
    {
        // DNT 와 제외 경로가 동시에 걸리면 DNT 가 먼저다.
        $both = $this->request(['headers' => ['dnt' => '1'], 'url' => 'https://example.test/admin/users']);
        $this->assertSame(ExclusionReason::Dnt, $this->evaluate($both));

        // 제외 IP 와 봇이 동시에 걸리면 IP 규칙이 먼저다.
        $ipAndBot = $this->request([
            'ip' => '198.51.100.7',
            'userAgent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        ]);
        $this->assertSame(ExclusionReason::IpRule, $this->evaluate($ipAndBot, ['excluded_ips' => ['198.51.100.7']]));
    }

    /**
     * 모든 제외 사유가 파이프라인에서 실제로 나올 수 있다.
     *
     * 열거형에 사유를 더했는데 판정 코드가 없으면 카운터 칸만 늘고 영원히 0 이다.
     *
     * @scenario exclusion=none
     *
     * @effects each_exclusion_reason_is_counted_separately
     *
     * @return void 없음
     */
    public function test_every_reason_is_reachable(): void
    {
        $source = file_get_contents(app_path('Analytics/VisitExclusionPipeline.php'));

        foreach (ExclusionReason::cases() as $reason) {
            $this->assertStringContainsString('ExclusionReason::'.$reason->name, $source, $reason->value.' 사유를 돌려주는 자리가 없다');
        }

        $this->assertCount(12, ExclusionReason::cases());
    }

    /**
     * 제외 사유는 사유별·일자별 키로 세어지고 철회 계수는 별도 칸이다.
     *
     * @scenario exclusion=dnt
     *
     * @effects each_exclusion_reason_is_counted_separately
     *
     * @return void 없음
     */
    public function test_counter_keys_are_per_reason_and_day(): void
    {
        $counter = app(ExclusionCounter::class);
        $date = '2026-09-17';

        $counter->increment(ExclusionReason::Dnt, $date);
        $counter->increment(ExclusionReason::Dnt, $date);
        $counter->increment(ExclusionReason::Bot, $date);
        $counter->incrementKey(ExclusionCounter::CONSENT_REVOKED, $date);

        $today = $counter->forDate($date);

        $this->assertSame(2, $today['dnt']);
        $this->assertSame(1, $today['bot']);
        $this->assertSame(0, $today['consent'], '철회는 「동의 없음」 칸에 섞이지 않는다');
        $this->assertSame(1, $today[ExclusionCounter::CONSENT_REVOKED]);
        $this->assertSame(0, $counter->forDate('2026-09-16')['dnt'], '어제 칸은 따로 센다');
        $this->assertCount(13, $today, '사유 12 + 철회 1');
    }

    /**
     * 정확 일치 제외 패턴은 끝 슬래시를 따지지 않는다.
     *
     * 서버 폴백은 요청 주소의 끝 슬래시를 떼고(`fullUrl()`), 비콘은 브라우저 주소를 그대로 보낸다.
     * 따지면 `/board/` 로 적은 제외가 비콘에만 걸리고 서버 폴백 기록에는 안 걸린다.
     *
     * @return void 없음
     *
     * @scenario exclusion=url_pattern
     *
     * @effects client_and_server_exclusion_rules_agree
     */
    public function test_exact_url_patterns_ignore_trailing_slash(): void
    {
        $this->assertTrue(VisitExclusionPipeline::pathMatchesAny('/board', ['/board/']));
        $this->assertTrue(VisitExclusionPipeline::pathMatchesAny('/board/', ['/board']));
        $this->assertTrue(VisitExclusionPipeline::pathMatchesAny('/', ['/']));
        $this->assertFalse(VisitExclusionPipeline::pathMatchesAny('/board/1', ['/board/']));
        $this->assertTrue(VisitExclusionPipeline::pathMatchesAny('/board/1', ['/board/*']));
    }
}
