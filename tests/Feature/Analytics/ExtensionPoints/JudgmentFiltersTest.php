<?php

namespace Tests\Feature\Analytics\ExtensionPoints;

use App\Analytics\AnalyticsTables;
use App\Analytics\ExclusionCounter;
use App\Analytics\ReferrerClassifier;
use App\Analytics\TrackingRequest;
use App\Analytics\UrlNormalizer;
use App\Analytics\UserAgentClassifier;
use App\Enums\Analytics\CollectFailureReason;
use App\Enums\Analytics\ReferrerAnonymization;
use App\Enums\Analytics\ReferrerType;
use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsCollectFailure;
use App\Models\Analytics\AnalyticsVisit;
use App\Services\Analytics\VisitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Analytics\Concerns\FakesAnalyticsExtensions;
use Tests\TestCase;

/**
 * 판정·제외 개방 — 확장이 판정표에 항목을 보태고 제외 규칙을 더한다.
 *
 * 판정표가 코어 상수에 묶여 있으면 국내 인앱 브라우저·새 광고 매체가 나올 때마다 코어 릴리스를
 * 기다려야 한다. 대신 확장은 **더 빼기만** 할 수 있어야 한다 — 코어 방어(봇·DNT·수집 거부)를
 * 확장이 뒤집으면 운영자가 켠 개인정보 설정이 무력해진다.
 */
class JudgmentFiltersTest extends TestCase
{
    use FakesAnalyticsExtensions;
    use RefreshDatabase;

    /**
     * 테스트 준비 — 확장 없음.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->activateAnalyticsExtensions([]);
    }

    /**
     * 확장이 더한 검색엔진이 유입 판정에 쓰인다.
     *
     * @scenario point=judgment, extension_state=active, input=registered
     *
     * @effects search_engine_table_accepts_extension_entries
     *
     * @return void 없음
     */
    public function test_search_engine_filter(): void
    {
        HookManager::addFilter('core.analytics.search_engines', function (array $engines): array {
            $engines[] = ['name' => 'Vendor Search', 'hosts' => ['search.vendor.test'], 'params' => ['kw']];
            $engines[] = ['name' => 'Broken', 'hosts' => 'not-a-list'];

            return $engines;
        });

        $result = ReferrerClassifier::classify('https://search.vendor.test/?kw=그누보드', [], ReferrerAnonymization::None);

        $this->assertSame(ReferrerType::Search, $result['type']);
        $this->assertSame('Vendor Search', $result['name']);
        $this->assertSame('그누보드', $result['keyword']);
    }

    /**
     * 캠페인 파라미터 이름을 바꾸고 추적 파라미터를 보탤 수 있다.
     *
     * @scenario point=judgment, extension_state=active, input=registered
     *
     * @effects campaign_param_names_accept_extension_override, stripped_query_params_accept_extension_entries
     *
     * @return void 없음
     */
    public function test_campaign_and_stripped_params_filters(): void
    {
        HookManager::addFilter('core.analytics.campaign_params', fn (array $params): array => array_merge($params, ['name' => 'cmp']));
        HookManager::addFilter('core.analytics.stripped_query_params', fn (array $params): array => array_merge($params, ['vclid']));

        $result = ReferrerClassifier::classify(null, ['cmp' => '가을세일'], ReferrerAnonymization::None);
        $this->assertSame('가을세일', $result['campaign_name']);

        $normalized = UrlNormalizer::normalize('https://example.test/shop?vclid=abc&page=2');
        $this->assertStringNotContainsString('vclid', $normalized['name']);
        $this->assertStringContainsString('page=2', $normalized['name']);
    }

    /**
     * 확장이 앞에 끼운 브라우저 패턴이 먼저 맞는다.
     *
     * @scenario point=judgment, extension_state=active, input=registered
     *
     * @effects ua_rules_accept_extension_patterns_in_order
     *
     * @return void 없음
     */
    public function test_ua_rules_filter(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/128.0 Mobile Safari/537.36 VendorApp/3.1';

        $this->assertSame('Chrome', UserAgentClassifier::classify($ua)['browser_name']);

        HookManager::addFilter('core.analytics.ua_rules', function (array $rules): array {
            array_unshift($rules['browsers'], ['VendorApp', 'Vendor App']);
            $rules['browsers'][] = ['only-one-element'];

            return $rules;
        });

        $this->assertSame('Vendor App', UserAgentClassifier::classify($ua)['browser_name']);
    }

    /**
     * 확장이 판정표에 넣은 이름이 원장 열보다 길어도 방문은 저장되고 이름은 열 폭으로 잘린다.
     *
     * 이름을 그대로 넣으면 방문 저장이 DB 오류로 실패해, 그 브라우저·검색엔진으로 온 방문이
     * 전부 사라지고 수집 실패 목록에는 `db_error` 만 남는다. 모집단은 `AnalyticsTables::NAME_COLUMNS`
     * 이고 폭은 실제 스키마에서 읽는다 — 마이그레이션 폭과 어긋나거나 한 경로라도 자르지 않으면 red.
     *
     * @scenario point=judgment, extension_state=active, input=registered
     *
     * @effects judgment_table_names_fit_the_ledger_columns
     *
     * @return void 없음
     */
    public function test_judgment_table_names_fit_the_ledger_columns(): void
    {
        $schema = collect(Schema::getColumns('analytics_visits'))->keyBy('name');
        $this->assertNotEmpty(AnalyticsTables::NAME_COLUMNS);

        foreach (AnalyticsTables::NAME_COLUMNS as $column => $width) {
            $this->assertTrue($schema->has($column), "{$column} 열이 없다");
            $this->assertSame(1, preg_match('/\((\d+)\)/', (string) $schema[$column]['type'], $match), $column);
            $this->assertSame((int) $match[1], $width, "{$column} 폭이 마이그레이션과 다르다");
        }

        $long = fn (string $column): string => str_repeat('가', AnalyticsTables::NAME_COLUMNS[$column] + 10);

        HookManager::addFilter('core.analytics.ua_rules', function (array $rules) use ($long): array {
            array_unshift($rules['browsers'], ['VendorApp', $long('browser_name')]);
            array_unshift($rules['os'], ['VendorOS', $long('os_name')]);
            $rules['hint_brands'] = ['Vendor Hint' => $long('browser_name')] + $rules['hint_brands'];

            return $rules;
        });
        HookManager::addFilter('core.analytics.search_engines', fn (array $engines): array => array_merge($engines, [
            ['name' => $long('referrer_name'), 'hosts' => ['search.vendor.test'], 'params' => ['kw']],
        ]));

        // UA 판정표(브라우저·OS) + 검색엔진 표 경로
        app(VisitService::class)->track($this->request([
            'userAgent' => 'Mozilla/5.0 (VendorOS 1.0) Chrome/128.0 VendorApp/3.1',
            'referrer' => 'https://search.vendor.test/?kw=board',
            'init' => true,
        ]));
        // 클라이언트 힌트 판정표(hint_brands) 경로 — 다른 방문자
        app(VisitService::class)->track($this->request([
            'cookies' => ['g7_vid' => str_repeat('b', 32)],
            'clientHints' => ['brands' => [['brand' => 'Vendor Hint', 'version' => '3']]],
        ]));

        $this->assertSame(0, AnalyticsCollectFailure::count(), '방문 저장이 실패했다');
        $this->assertSame(2, AnalyticsVisit::count());

        $byUa = AnalyticsVisit::where('visitor_key', str_repeat('a', 32))->sole();
        $byHint = AnalyticsVisit::where('visitor_key', str_repeat('b', 32))->sole();

        foreach (AnalyticsTables::NAME_COLUMNS as $column => $width) {
            $this->assertSame(mb_substr($long($column), 0, $width), $byUa->{$column}, "{$column} (UA·검색엔진 경로)");
        }
        $this->assertSame(mb_substr($long('browser_name'), 0, AnalyticsTables::NAME_COLUMNS['browser_name']), $byHint->browser_name, 'browser_name (힌트 경로)');
    }

    /**
     * 수집 요청을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return TrackingRequest 수집 요청
     */
    private function request(array $overrides = []): TrackingRequest
    {
        return new TrackingRequest(...array_merge([
            'type' => 'pageview',
            'url' => 'https://example.test/staff-only',
            'ip' => '203.0.113.80',
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.6613.84 Safari/537.36',
            'browserLanguage' => 'ko-KR',
            'cookies' => ['g7_vid' => str_repeat('a', 32)],
            'receivedAt' => now()->format('Y-m-d H:i:s'),
        ], $overrides));
    }

    /**
     * 등록한 사유로 확장이 제외하면 수집되지 않고 사유별로 세어진다.
     *
     * @scenario point=judgment, extension_state=active, input=registered
     *
     * @effects extension_exclusion_rule_excludes_with_registered_reason, extension_exclusion_reason_is_counted_and_labeled
     *
     * @return void 없음
     */
    public function test_registered_extension_exclusion(): void
    {
        HookManager::addFilter('core.analytics.registered_exclusion_reasons', fn (array $reasons): array => array_merge($reasons, [
            'vendor-hr:staff' => ['ko' => '직원 방문', 'en' => 'Staff visit'],
        ]));
        HookManager::addFilter('core.analytics.exclusion_rules', fn ($verdict, array $dto) => str_contains($dto['url'], 'staff') ? 'vendor-hr:staff' : $verdict);

        $result = app(VisitService::class)->track($this->request());

        $this->assertSame('ext:vendor-hr:staff', $result['excluded']);
        $this->assertSame(0, AnalyticsVisit::count());

        $counts = app(ExclusionCounter::class)->forDate();
        $this->assertSame(1, $counts['ext:vendor-hr:staff']);
        $this->assertSame('직원 방문', ExclusionCounter::extensionReasons()['ext:vendor-hr:staff']);
    }

    /**
     * 등록하지 않은 사유로는 제외할 수 없다.
     *
     * @scenario point=judgment, extension_state=active, input=unregistered
     *
     * @effects unregistered_exclusion_reason_does_not_exclude
     *
     * @return void 없음
     */
    public function test_unregistered_reason_does_not_exclude(): void
    {
        HookManager::addFilter('core.analytics.exclusion_rules', fn () => 'vendor-hr:secret');

        $result = app(VisitService::class)->track($this->request());

        $this->assertNull($result['excluded']);
        $this->assertSame(1, AnalyticsVisit::count());
    }

    /**
     * 코어가 제외한 요청은 확장 규칙이 되살리지 못한다(확장은 뒤에서 더 빼기만 한다).
     *
     * @scenario point=judgment, extension_state=active, input=registered
     *
     * @effects extension_exclusion_runs_after_core_defenses
     *
     * @return void 없음
     */
    public function test_extension_rules_run_after_core_defenses(): void
    {
        $called = 0;
        HookManager::addFilter('core.analytics.exclusion_rules', function ($verdict) use (&$called) {
            $called++;

            return null;
        });

        $result = app(VisitService::class)->track($this->request(['headers' => ['dnt' => '1']]));

        $this->assertSame('dnt', $result['excluded']);
        $this->assertSame(0, $called, '코어가 이미 제외한 요청에는 확장 규칙을 부르지 않는다');
        $this->assertSame(0, AnalyticsVisit::count());
    }

    /**
     * 수집 경로의 확장 훅이 던져도 코어 방문은 남고, 실패는 확장 리스너 오류로 분류된다.
     *
     * 확장 훅 세 자리(제외 규칙·차원 해석·적재 알림)는 수집 요청 안에서 동기로 돈다. 그 예외를
     * 데이터베이스 오류로 적으면 운영자가 엉뚱한 곳을 찾고, 방문까지 버리면 확장 하나의 결함이
     * 사이트 통계 전체를 멈춘다.
     *
     * @scenario point=judgment, extension_state=active, input=registered
     *
     * @effects extension_hook_failure_is_isolated_as_listener_exception
     *
     * @return void 없음
     */
    public function test_extension_hook_failure_is_isolated(): void
    {
        $boom = function (): never {
            throw new \RuntimeException('vendor bug');
        };

        $hooks = [
            'core.analytics.exclusion_rules' => 'filter',
            'core.analytics.resolve_dimensions' => 'filter',
            'core.analytics.after_track' => 'action',
        ];

        foreach ($hooks as $hook => $kind) {
            AnalyticsCollectFailure::query()->delete();
            $before = AnalyticsVisit::count();
            $kind === 'filter' ? HookManager::addFilter($hook, $boom) : HookManager::addAction($hook, $boom);

            try {
                $result = app(VisitService::class)->track($this->request(['cookies' => ['g7_vid' => md5($hook)]]));
            } finally {
                $kind === 'filter' ? HookManager::removeFilter($hook, $boom) : HookManager::removeAction($hook, $boom);
            }

            $this->assertNotNull($result['visit_id'], "{$hook}: 확장 실패가 코어 방문을 버렸다");
            $this->assertSame($before + 1, AnalyticsVisit::count(), $hook);

            $failure = AnalyticsCollectFailure::sole();
            $this->assertSame(CollectFailureReason::ListenerException, $failure->reason, $hook);
            $this->assertStringContainsString($hook, $failure->message, '어느 훅인지 실패 로그가 말해야 한다');
        }
    }

    /**
     * 판정표·등록부 필터가 던져도 코어 기본값으로 이어 가 방문이 남고, 실패는 확장 리스너
     * 오류로 분류된다.
     *
     * 대상 필터는 판정표(`AnalyticsTables`)·등록부 두 파일이 부르는 `core.analytics.*` 필터를
     * 호출 방식과 무관하게 전부 파생한다 — 한 자리라도 격리 없이 부르면 이 시험이 빨개진다.
     *
     * @scenario point=judgment, extension_state=active, input=registered
     *
     * @effects judgment_and_registry_filter_failures_are_isolated
     *
     * @return void 없음
     */
    public function test_judgment_and_registry_filter_failures_are_isolated(): void
    {
        $hooks = [];
        foreach ([
            app_path('Analytics/AnalyticsTables.php'),
            app_path('Services/Analytics/GoalRegistry.php'),
            app_path('Services/Analytics/DimensionRegistry.php'),
        ] as $path) {
            if (preg_match_all("/(?:ExtensionFilter::apply|applyFilters)\\(\\s*'(core\\.analytics\\.[a-z_.]+)'/", (string) file_get_contents($path), $m) > 0) {
                array_push($hooks, ...$m[1]);
            }
        }
        $hooks = array_values(array_unique($hooks));
        sort($hooks);

        $this->assertNotEmpty($hooks, '격리 대상 필터를 소스에서 찾지 못했다');

        $boom = function (): never {
            throw new \RuntimeException('vendor bug');
        };

        foreach ($hooks as $hook) {
            AnalyticsCollectFailure::query()->delete();
            $this->app->forgetScopedInstances();
            HookManager::addFilter($hook, $boom);

            try {
                // 검색 유입 — 캠페인 표식이 있으면 검색엔진 판정 전에 캠페인으로 끝나므로 따로 보낸다.
                $pageview = app(VisitService::class)->track($this->request([
                    'url' => 'https://example.test/landing?gclid=abc',
                    'referrer' => 'https://www.google.com/search?q=g7',
                    'dimensions' => ['vendor-x:thing' => '1'],
                    'cookies' => ['g7_vid' => md5($hook)],
                ]));
                app(VisitService::class)->track($this->request([
                    'url' => 'https://example.test/promo?utm_source=news&utm_medium=mail',
                    'cookies' => ['g7_vid' => md5($hook.'campaign')],
                ]));
                app(VisitService::class)->track($this->request([
                    'type' => 'goal',
                    'goalKey' => 'vendor-x.buy',
                    'cookies' => ['g7_vid' => md5($hook)],
                ]));
            } finally {
                HookManager::removeFilter($hook, $boom);
            }

            $this->assertNotNull($pageview['visit_id'], "{$hook}: 확장 실패가 코어 방문을 버렸다");
            $this->assertSame(0, AnalyticsCollectFailure::query()->where('reason', CollectFailureReason::DbError->value)->count(), "{$hook}: 데이터베이스 오류로 잘못 분류됐다");
            $this->assertTrue(
                AnalyticsCollectFailure::query()
                    ->where('reason', CollectFailureReason::ListenerException->value)
                    ->where('message', 'like', $hook.':%')
                    ->exists(),
                "{$hook}: 실패 목록에 확장 리스너 오류로 남지 않았다",
            );
        }
    }
}
