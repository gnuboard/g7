<?php

namespace Tests\Feature\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Services\SettingsService;
use Tests\TestCase;

/**
 * 설정 스키마 — 45키 전수·기본값 범위·공개 노출 통제.
 *
 * `frontend_schema` 에서 `expose` 를 빠뜨리면 보존일·임계치·차단 목록까지 공개 페이지의
 * `G7Config.settings` 로 샌다. 새 키를 더할 때 가장 잊기 쉬운 자리라 목록을 고정한다.
 */
class SettingsSchemaTest extends TestCase
{
    /** 계획서 §4.1 이 ✅ 로 표시한 공개 키 11개 */
    private const EXPOSED = [
        'enabled', 'privacy_mode', 'respect_dnt', 'excluded_url_patterns', 'exclude_prefetch',
        'track_downloads', 'track_outlinks', 'download_extensions',
        'realtime_poll_seconds', 'realtime_window_minutes', 'public_summary_enabled',
    ];

    /** 계획서 §4.1 의 정수 범위 */
    private const RANGES = [
        'ip_mask_octets' => [0, 3],
        'visit_timeout_minutes' => [5, 120],
        'max_actions_per_visit' => [50, 10000],
        'retention_days' => [7, 3650],
        'hourly_retention_days' => [7, 3650],
        'bot_hits_retention_days' => [1, 365],
        'failure_log_retention_days' => [1, 365],
        'archive_chunk_size' => [500, 50000],
        'archive_on_view_max_visits' => [1000, 1000000],
        'realtime_poll_seconds' => [10, 300],
        'realtime_window_minutes' => [1, 60],
        'top_n_overview' => [3, 100],
        'top_n_dashboard' => [3, 100],
        'top_n_detail' => [3, 100],
        'visitor_cookie_days' => [1, 730],
    ];

    /**
     * 기본값 파일을 읽습니다.
     *
     * @return array<string, mixed> 기본값 파일 내용
     */
    private function defaultsFile(): array
    {
        return json_decode(file_get_contents(base_path('config/settings/defaults.json')), true);
    }

    /**
     * 기본값 45키가 스키마 45키와 정확히 같고 카테고리에 등록돼 있다.
     *
     * @scenario mode=full
     *
     * @effects settings_schema_exposes_only_public_keys
     *
     * @return void 없음
     */
    public function test_defaults_and_schema_cover_the_same_45_keys(): void
    {
        $file = $this->defaultsFile();
        $defaults = array_keys($file['defaults']['analytics']);
        $schema = array_keys($file['frontend_schema']['analytics']['fields']);

        $this->assertCount(45, $defaults);
        $this->assertEqualsCanonicalizing($defaults, $schema, '기본값과 스키마의 키 집합이 다르다');
        $this->assertContains('analytics', $file['_meta']['categories']);
        $this->assertArrayNotHasKey('merge_into', $file['frontend_schema']['analytics'], '자기 이름으로 실려야 한다');
    }

    /**
     * 정수 기본값이 계획서 범위 안에 있다.
     *
     * 저장 시점의 범위 검증은 S2 의 FormRequest 몫이지만 기본값이 범위 밖이면 그 검증이
     * 설치 직후의 값을 거부한다.
     *
     * @scenario mode=full
     *
     * @effects settings_schema_exposes_only_public_keys
     *
     * @return void 없음
     */
    public function test_integer_defaults_are_within_planned_ranges(): void
    {
        $defaults = $this->defaultsFile()['defaults']['analytics'];

        foreach (self::RANGES as $key => [$min, $max]) {
            $this->assertIsInt($defaults[$key], $key);
            $this->assertGreaterThanOrEqual($min, $defaults[$key], $key);
            $this->assertLessThanOrEqual($max, $defaults[$key], $key);
        }

        $this->assertContains($defaults['privacy_mode'], ['full', 'consent', 'anonymous']);

        // 구간 경계 기본값은 저장 규칙(순증가 정수)을 그대로 통과해야 한다 — 기본값이 규칙에 걸리면
        // 설치 직후 설정 화면에서 다른 칸을 저장해도 이 칸 때문에 422 가 난다.
        $this->assertSame([10, 30, 60, 180, 600, 1800], AnalyticsConfig::parseBounds($defaults['duration_bucket_bounds'], ''));
        $this->assertSame([1, 2, 3, 4, 5, 7, 10, 14, 20], AnalyticsConfig::parseBounds($defaults['pages_bucket_bounds'], ''));
        $this->assertTrue($defaults['archive_on_view_enabled']);
        // 적재 방식 설정은 없앴다 — 수집은 언제나 요청 안에서 끝난다(#682 S3 라운드 6).
        $this->assertArrayNotHasKey('dispatch_mode', $defaults);
    }

    /**
     * 공개 설정에는 ✅ 11키만 실리고 나머지 34키는 명시적으로 막혀 있다(모두 45키).
     *
     * @scenario mode=full
     *
     * @effects settings_schema_exposes_only_public_keys
     *
     * @return void 없음
     */
    public function test_frontend_exposes_exactly_the_public_keys(): void
    {
        $fields = $this->defaultsFile()['frontend_schema']['analytics']['fields'];

        foreach ($fields as $key => $schema) {
            if (in_array($key, self::EXPOSED, true)) {
                $this->assertNotSame(false, $schema['expose'] ?? null, $key.' 은 공개 키다');
            } else {
                $this->assertSame(false, $schema['expose'] ?? null, $key.' 은 expose:false 를 명시해야 한다(비워 두면 샌다)');
            }
        }

        $exposed = app(SettingsService::class)->getFrontendSettings()['analytics'] ?? [];

        $this->assertEqualsCanonicalizing(self::EXPOSED, array_keys($exposed), 'G7Config.settings.analytics 에 실리는 키');
        $this->assertArrayNotHasKey('retention_days', $exposed);
        $this->assertArrayNotHasKey('excluded_ips', $exposed);
        $this->assertArrayNotHasKey('ledger_warning_actions', $exposed);
    }

    /**
     * 런타임 값 객체가 45키 중 표시 전용 1키를 뺀 전부를 읽는다.
     *
     * @scenario mode=full
     *
     * @effects settings_schema_exposes_only_public_keys
     *
     * @return void 없음
     */
    public function test_runtime_config_reads_every_operational_key(): void
    {
        $source = file_get_contents(app_path('Analytics/AnalyticsConfig.php'));
        $keys = array_keys($this->defaultsFile()['defaults']['analytics']);

        // 갱신 시각은 화면 표시용이라 런타임 값 객체가 읽지 않는다(계획서 §12 라운드 2 D4).
        $displayOnly = ['cloud_ip_ranges_updated_at'];

        foreach (array_diff($keys, $displayOnly) as $key) {
            $this->assertStringContainsString("'".$key."'", $source, $key.' 를 AnalyticsConfig 가 읽지 않는다');
        }

        $this->assertInstanceOf(AnalyticsConfig::class, AnalyticsConfig::fromSettings());
    }
}
