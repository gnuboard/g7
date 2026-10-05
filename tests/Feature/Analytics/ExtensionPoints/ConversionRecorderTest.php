<?php

namespace Tests\Feature\Analytics\ExtensionPoints;

use App\Analytics\TrackingRequest;
use App\Contracts\Analytics\ConversionRecorderInterface;
use App\Enums\Analytics\CollectFailureReason;
use App\Enums\Analytics\ExclusionReason;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsCollectFailure;
use App\Models\Analytics\AnalyticsConversion;
use App\Models\Analytics\AnalyticsVisit;
use App\Services\Analytics\VisitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Feature\Analytics\Concerns\FakesAnalyticsExtensions;
use Tests\TestCase;

/**
 * 전환 기록 — 등록된 목표만, 선언된 공급자·중복 규칙대로.
 *
 * 비콘(`trackGoal`)과 서버(`ConversionRecorderInterface`) 두 입구가 같은 판정을 거치는지,
 * 그리고 등록부에 없는 목표가 「조용히 새 목표로 쌓이는」 대신 사유와 함께 버려지는지 잰다.
 */
class ConversionRecorderTest extends TestCase
{
    use FakesAnalyticsExtensions;
    use RefreshDatabase;

    /**
     * 테스트 준비 — 방문당 1회 목표·방문자당 1회 목표를 선언한 플러그인을 켠다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 방문자 통계 설정을 기본값(config/settings/defaults.json)으로 되돌린다 — 운영자가 관리자 화면에서 저장한
        // 값(storage/app/settings/analytics.json)이 부팅 시 config 로 실려 스며든다. 동의 모드(`privacy_mode: consent`)면
        // 쿠키 대신 익명 키가 쓰이고, `respect_dnt: false` 면 DNT 제외가 꺼져 이 파일의 단언이 환경에 따라 갈린다
        // (mem:core-settings-boot-config-leaks-into-unit-tests, AnalyticsApiTestCase 와 같은 고정).
        $defaults = json_decode((string) file_get_contents(base_path('config/settings/defaults.json')), true);
        config(['g7_settings.core.analytics' => array_replace(
            (array) config('g7_settings.core.analytics', []),
            $defaults['defaults']['analytics'] ?? []
        )]);

        $this->activateAnalyticsExtensions([], [
            $this->fakeAnalyticsExtension('vendor-shop', [
                ['key' => 'order_completed', 'label' => '주문', 'category' => 'purchase', 'dedupe' => 'every_time', 'attributes' => [
                    'revenue' => ['type' => 'decimal', 'required' => true, 'min' => 0],
                    'currency' => ['type' => 'enum', 'values' => ['KRW', 'USD']],
                ]],
                ['key' => 'cart_viewed', 'label' => '장바구니', 'dedupe' => 'once_per_visit'],
                ['key' => 'first_purchase', 'label' => '첫 구매', 'dedupe' => 'once_per_visitor'],
            ]),
        ]);
    }

    /**
     * 수집 요청을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return TrackingRequest 수집 요청
     */
    private function goal(array $overrides = []): TrackingRequest
    {
        return new TrackingRequest(...array_merge([
            'type' => 'goal',
            'url' => 'https://example.test/checkout/done',
            'ip' => '203.0.113.70',
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.6613.84 Safari/537.36',
            'browserLanguage' => 'ko-KR',
            'cookies' => ['g7_vid' => str_repeat('e', 32)],
            'receivedAt' => now()->format('Y-m-d H:i:s'),
        ], $overrides));
    }

    /**
     * 등록된 확장 목표가 공급자 종류·식별자를 달고 기록된다.
     *
     * @scenario point=goal, extension_state=active, input=registered
     *
     * @effects registered_goal_records_conversion_with_owner_type, goal_attribute_schema_passes_declared_values
     *
     * @return void 없음
     */
    public function test_registered_goal_records_owner(): void
    {
        app(VisitService::class)->track($this->goal([
            'goalKey' => 'vendor-shop.order_completed',
            'goalAttributes' => ['revenue' => 12000, 'currency' => 'KRW'],
        ]));

        $conversion = AnalyticsConversion::first();
        $this->assertNotNull($conversion);
        $this->assertSame('plugin', $conversion->extension_type, '레지스트리가 생기면서 종류가 채워진다');
        $this->assertSame('vendor-shop', $conversion->extension_identifier);
        $this->assertSame('12000.00', $conversion->revenue);
        $this->assertSame('KRW', $conversion->currency);
    }

    /**
     * 등록되지 않은 목표는 액션·전환 모두 남기지 않고 `unknown_goal` 로 남는다.
     *
     * @scenario point=goal, extension_state=active, input=unregistered
     *
     * @effects unregistered_goal_is_rejected_as_unknown_goal
     *
     * @return void 없음
     */
    public function test_unregistered_goal_is_logged_as_unknown_goal(): void
    {
        $result = app(VisitService::class)->track($this->goal(['goalKey' => 'vendor-shop.typo_goal']));

        $this->assertNull($result['action_id']);
        $this->assertSame(0, AnalyticsConversion::count());
        $this->assertSame(0, AnalyticsAction::count());

        $failure = AnalyticsCollectFailure::first();
        $this->assertSame(CollectFailureReason::UnknownGoal, $failure->reason);
        $this->assertStringContainsString('vendor-shop.typo_goal', $failure->message);
    }

    /**
     * 목표를 공급한 확장이 꺼지면 그 목표 비콘도 거부된다.
     *
     * @scenario point=goal, extension_state=inactive, input=registered
     *
     * @effects inactive_extension_goal_is_rejected
     *
     * @return void 없음
     */
    public function test_goal_of_inactive_extension_is_rejected(): void
    {
        $this->activateAnalyticsExtensions([]);

        app(VisitService::class)->track($this->goal(['goalKey' => 'vendor-shop.cart_viewed']));

        $this->assertSame(0, AnalyticsConversion::count());
        $this->assertSame(CollectFailureReason::UnknownGoal, AnalyticsCollectFailure::first()?->reason);
    }

    /**
     * 방문당 1회 목표는 같은 방문에서 한 번만 남는다.
     *
     * @scenario point=goal, extension_state=active, input=registered
     *
     * @effects goal_dedupe_once_per_visit
     *
     * @return void 없음
     */
    public function test_once_per_visit_dedupe(): void
    {
        $service = app(VisitService::class);
        $service->track($this->goal(['goalKey' => 'vendor-shop.cart_viewed']));
        $second = $service->track($this->goal(['goalKey' => 'vendor-shop.cart_viewed']));

        $this->assertSame(1, AnalyticsConversion::count());
        $this->assertNull($second['action_id'], '중복은 액션도 남기지 않는다');
        $this->assertSame(0, AnalyticsCollectFailure::count(), '선언된 규칙이지 실패가 아니다');
    }

    /**
     * 방문자당 1회 목표는 다음 방문에서도 다시 세지 않는다.
     *
     * @scenario point=goal, extension_state=active, input=registered
     *
     * @effects goal_dedupe_once_per_visitor
     *
     * @return void 없음
     */
    public function test_once_per_visitor_dedupe_spans_visits(): void
    {
        $service = app(VisitService::class);
        $service->track($this->goal(['goalKey' => 'vendor-shop.first_purchase']));

        // 방문 만료 뒤 새 방문
        $this->travel(2)->hours();
        $service->track($this->goal(['goalKey' => 'vendor-shop.first_purchase']));
        $service->track($this->goal(['goalKey' => 'vendor-shop.order_completed', 'goalAttributes' => ['revenue' => 1]]));

        $this->assertSame(1, AnalyticsConversion::query()->where('goal_key', 'vendor-shop.first_purchase')->count());
        $this->assertSame(1, AnalyticsConversion::query()->where('goal_key', 'vendor-shop.order_completed')->count());
    }

    /**
     * 서버 기록기가 현재 요청의 방문에 붙고, 화면 주소는 같은 사이트 Referer 를 쓴다.
     *
     * @scenario point=goal, extension_state=active, input=registered
     *
     * @effects server_recorder_attaches_to_the_current_visit, server_recorder_uses_same_site_referer_as_page
     *
     * @return void 없음
     */
    public function test_server_recorder_uses_the_current_request(): void
    {
        config(['app.url' => 'https://example.test', 'g7_settings.core.general.site_url' => 'https://example.test']);

        $request = Request::create('https://example.test/api/shop/orders', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.70',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.6613.84 Safari/537.36',
            'HTTP_REFERER' => 'https://example.test/checkout',
            'HTTP_ACCEPT_LANGUAGE' => 'ko-KR',
        ], cookies: ['g7_vid' => str_repeat('f', 32)]);
        $this->app->instance('request', $request);

        app(ConversionRecorderInterface::class)->record('vendor-shop.order_completed', ['revenue' => 500, 'currency' => 'USD']);

        $conversion = AnalyticsConversion::first();
        $this->assertNotNull($conversion);
        $this->assertSame(str_repeat('f', 32), $conversion->visitor_key);
        $this->assertSame('USD', $conversion->currency);

        $goalAction = AnalyticsAction::query()->where('action_type', 'goal')->first();
        $this->assertStringContainsString('example.test/checkout', (string) $goalAction?->actionName?->name);
    }

    /**
     * 브라우저 비콘은 코어 목표를 기록하지 못한다(서버만 안다).
     *
     * 가입·로그인 전환은 서버가 완료를 확인한 순간에만 남는다. 비콘으로도 받으면 방문자가 콘솔에서
     * `trackGoal('core.user_registered')` 를 불러 가입 수를 부풀릴 수 있다 — 내부 검색어를 비콘으로
     * 위조하지 못하게 막은 것과 같은 부류다(브라우저 실측에서 발견, #682 S4).
     *
     * @scenario point=goal, extension_state=active, input=unregistered
     *
     * @effects beacon_cannot_forge_core_goals
     *
     * @return void 없음
     */
    public function test_beacon_cannot_forge_core_goals(): void
    {
        $result = app(VisitService::class)->track($this->goal(['goalKey' => 'core.user_registered', 'source' => 'beacon']));

        $this->assertNull($result['action_id']);
        $this->assertSame(0, AnalyticsConversion::count());
        $failure = AnalyticsCollectFailure::first();
        $this->assertSame(CollectFailureReason::Validation, $failure?->reason);
        $this->assertStringContainsString('core.user_registered', (string) $failure?->message);

        // 서버 경로는 그대로 받는다.
        app(VisitService::class)->track($this->goal(['goalKey' => 'core.user_registered', 'source' => 'server']));
        $this->assertSame(1, AnalyticsConversion::count());
    }

    /**
     * 서버 기록기는 등록되지 않은 목표도 예외 없이 사유만 남긴다.
     *
     * @scenario point=goal, extension_state=active, input=unregistered
     *
     * @effects server_recorder_never_throws
     *
     * @return void 없음
     */
    public function test_server_recorder_never_throws(): void
    {
        app(ConversionRecorderInterface::class)->record('nobody.nothing');

        $this->assertSame(0, AnalyticsConversion::count());
        $this->assertSame(CollectFailureReason::UnknownGoal, AnalyticsCollectFailure::first()?->reason);
    }

    /**
     * 서버 측 전환 기록도 코어 제외 판정을 전부 거친다.
     *
     * 서버 기록기는 트래커를 거치지 않으므로 브라우저 쪽 선제 제외가 없다. 모집단은 코어 제외
     * 사유(`ExclusionReason`) 전체다 — 요청 하나로 만들 수 있는 사유는 실제로 재현하고, 요청만으로
     * 만들 수 없는 사유는 이유와 함께 따로 적는다. 사유가 늘면 어느 쪽에도 없어 red 다.
     *
     * @scenario point=goal, extension_state=active, input=registered
     *
     * @effects server_recorder_obeys_every_core_exclusion
     *
     * @return void 없음
     */
    public function test_server_recorder_obeys_every_core_exclusion(): void
    {
        $browser = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.6613.84 Safari/537.36';
        $prefetchHeader = array_key_first((array) config('analytics.prefetch_headers'));
        $prefetchValue = ((array) config('analytics.prefetch_headers'))[$prefetchHeader][0] ?? null;
        $headless = ((array) config('analytics.headless_patterns'))[0] ?? null;
        $this->assertNotNull($prefetchValue, '미리 가져오기 헤더 설정이 비었다');
        $this->assertNotNull($headless, '자동화 브라우저 패턴 설정이 비었다');

        // [설정, 서버 변수, 쿠키]
        $reproducible = [
            ExclusionReason::Disabled->value => [['enabled' => false], [], []],
            ExclusionReason::Prefetch->value => [[], ['HTTP_'.strtoupper(str_replace('-', '_', $prefetchHeader)) => $prefetchValue], []],
            ExclusionReason::Dnt->value => [[], ['HTTP_DNT' => '1'], []],
            ExclusionReason::OptOut->value => [[], [], [(string) config('analytics.cookies.opt_out', 'g7_analytics_optout') => '1']],
            ExclusionReason::UrlPattern->value => [['excluded_url_patterns' => ['/checkout*']], [], []],
            ExclusionReason::IpRule->value => [['excluded_ips' => ['203.0.113.0/24']], [], []],
            ExclusionReason::UaRule->value => [['excluded_user_agents' => ['Chrome/128']], [], []],
            ExclusionReason::Headless->value => [[], ['HTTP_USER_AGENT' => $browser.' '.$headless], []],
            ExclusionReason::Bot->value => [[], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'], []],
        ];
        $notFromRequest = [
            ExclusionReason::CloudIp->value => '클라우드 대역 목록은 외부에서 받아 둔 파일이 있어야 판정한다',
            ExclusionReason::Country->value => '국가 필터는 GeoIP 데이터베이스가 있어야 판정한다',
            ExclusionReason::Consent->value => '동의 판정은 동의 관리 플러그인이 답해야 생긴다',
        ];
        $all = array_map(fn (ExclusionReason $r): string => $r->value, ExclusionReason::cases());
        $this->assertSame([], array_values(array_diff($all, array_keys($reproducible), array_keys($notFromRequest))), '재현도 사유도 없는 제외 사유가 있다');

        $record = function (array $settings, array $server, array $cookies) use ($browser): int {
            AnalyticsConversion::query()->delete();
            AnalyticsVisit::query()->delete();
            foreach ($settings + ['enabled' => true, 'excluded_url_patterns' => [], 'excluded_ips' => [], 'excluded_user_agents' => []] as $key => $value) {
                config(["g7_settings.core.analytics.{$key}" => $value]);
            }

            $request = Request::create('/api/orders/complete', 'POST', [], ['g7_vid' => str_repeat('e', 32)] + $cookies, [], array_merge([
                'REMOTE_ADDR' => '203.0.113.71',
                'HTTP_USER_AGENT' => $browser,
                'HTTP_REFERER' => 'http://localhost/checkout/done',
            ], $server));
            $this->app->instance('request', $request);
            app(ConversionRecorderInterface::class)->record('vendor-shop.order_completed', ['revenue' => 1000, 'currency' => 'KRW']);

            return AnalyticsConversion::query()->count() + AnalyticsVisit::query()->count();
        };

        $this->assertSame(2, $record([], [], []), '대조군: 제외 조건이 없으면 방문 1·전환 1');

        foreach ($reproducible as $reason => [$settings, $server, $cookies]) {
            $this->assertSame(0, $record($settings, $server, $cookies), "{$reason}: 서버 기록기가 코어 제외를 우회했다");
        }
    }
}
