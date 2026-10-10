<?php

namespace Tests\Feature\Analytics\ExtensionPoints;

use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsConversion;
use App\Models\Analytics\AnalyticsVisit;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\IdentityPolicySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Analytics\Concerns\FakesAnalyticsExtensions;
use Tests\TestCase;

/**
 * 코어 전환 두 건 — 회원가입·로그인이 실제 API 에서 전환으로 남는다.
 *
 * 확장 지점이 「있다」와 「돈다」는 다르다. 코어가 자기 목표를 선언·기록하는 것으로 선언 →
 * 등록부 → 기록 → 원장 경로가 끝까지 이어져 있음을 증명한다.
 */
class CoreGoalsTest extends TestCase
{
    use FakesAnalyticsExtensions;
    use RefreshDatabase;

    /**
     * 브라우저처럼 보이는 헤더 — 테스트 기본 UA 는 봇으로 판정될 수 있다.
     *
     * @var array<string, string>
     */
    private const BROWSER_HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.6613.84 Safari/537.36',
        'Accept-Language' => 'ko-KR,ko;q=0.9',
        'Referer' => 'http://localhost/login',
    ];

    /**
     * 테스트 준비 — 확장 없음(코어 목표만), 가입에 필요한 역할·정책 시드.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Queue::fake();
        $this->activateAnalyticsExtensions([]);
    }

    /**
     * 로그인 성공이 `core.user_logged_in` 전환을 남기고, 같은 방문에서는 한 번만 센다.
     *
     * @scenario point=goal, extension_state=active, input=registered
     *
     * @effects core_login_goal_is_recorded_on_successful_login, core_goal_dedupe_once_per_visit
     *
     * @return void 없음
     */
    public function test_login_records_core_goal_once_per_visit(): void
    {
        User::factory()->create(['email' => 'goal@example.com', 'password' => bcrypt('password123')]);

        foreach ([1, 2] as $attempt) {
            $this->withHeaders(self::BROWSER_HEADERS)
                ->withUnencryptedCookie('g7_vid', str_repeat('d', 32))
                ->postJson('/api/auth/login', ['email' => 'goal@example.com', 'password' => 'password123'])
                ->assertOk();
        }

        $conversions = AnalyticsConversion::query()->where('goal_key', 'core.user_logged_in')->get();
        $this->assertCount(1, $conversions, '같은 방문의 두 번째 로그인은 세지 않는다(once_per_visit)');
        $this->assertNull($conversions->first()->extension_identifier);

        // 전환이 일어난 화면은 API 주소가 아니라 사용자가 보던 로그인 화면이다.
        $goalAction = AnalyticsAction::query()->where('action_type', 'goal')->first();
        $this->assertNotNull($goalAction);
        $this->assertStringContainsString('/login', (string) $goalAction->actionName?->name);
    }

    /**
     * 로그인 전환이 연 회원 방문은 새로고침 없는 다음 화면 비콘이 합류하면 브라우저 값을 받는다.
     *
     * 로그인 응답 안에서 기록한 전환은 서버 출처로 회원 방문을 먼저 연다. 로그인 뒤 화면은
     * 새로고침 없이 넘어가므로 합류하는 비콘은 첫 화면 비콘이 아니다 — 그래도 출처가 비콘으로
     * 바뀌고 해상도가 채워져야 로그인한 회원이 「서버로만 잡힌 방문」·해상도 공란으로 남지 않는다.
     *
     * @scenario point=goal, extension_state=active, input=registered
     *
     * @effects login_visit_takes_browser_values_from_the_next_beacon
     *
     * @return void 없음
     */
    public function test_login_visit_takes_browser_values_from_the_next_beacon(): void
    {
        User::factory()->create(['email' => 'goal@example.com', 'password' => bcrypt('password123')]);
        $cookie = str_repeat('e', 32);

        // 게스트로 로그인 화면을 연다(첫 화면 비콘).
        $this->withHeaders(self::BROWSER_HEADERS)->withCredentials()->withUnencryptedCookie('g7_vid', $cookie)
            ->postJson('/api/analytics/collect', ['t' => 'pageview', 'url' => 'http://localhost/login', 'res' => '1920x1080', 'init' => true])
            ->assertNoContent();

        $token = $this->withHeaders(self::BROWSER_HEADERS)->withCredentials()->withUnencryptedCookie('g7_vid', $cookie)
            ->postJson('/api/auth/login', ['email' => 'goal@example.com', 'password' => 'password123'])
            ->assertOk()
            ->json('data.token');

        $memberVisit = AnalyticsVisit::query()->whereNotNull('user_id')->sole();
        $this->assertSame('server', $memberVisit->source->value, '전제: 로그인 전환이 서버 출처로 회원 방문을 연다');
        $this->assertNull($memberVisit->screen_resolution);

        $this->app['auth']->forgetGuards();

        // 새로고침 없이 다음 화면으로 — 이동 비콘(init 아님)이 회원 방문에 합류한다.
        $this->withHeaders(array_merge(self::BROWSER_HEADERS, ['Authorization' => 'Bearer '.$token]))
            ->withCredentials()->withUnencryptedCookie('g7_vid', $cookie)
            ->postJson('/api/analytics/collect', ['t' => 'pageview', 'url' => 'http://localhost/', 'res' => '1920x1080', 'init' => false])
            ->assertNoContent();

        $memberVisit->refresh();
        $this->assertSame(1, AnalyticsVisit::query()->whereNotNull('user_id')->count(), '로그인 뒤 화면은 같은 회원 방문에 이어 붙는다');
        $this->assertSame('beacon', $memberVisit->source->value, '브라우저가 합류한 방문은 서버 전용 방문이 아니다');
        $this->assertSame('1920x1080', $memberVisit->screen_resolution);
        $this->assertSame(2, $memberVisit->actions_count);
    }

    /**
     * 익명 모드에서도 로그인 전환은 그 방문자의 방문에 붙는다.
     *
     * 익명 모드의 방문자 키는 환경 해시라 쿠키가 없다. 브라우저 비콘은 설정 언어(`ko-KR`)를,
     * 서버가 기록하는 로그인 요청은 언어 헤더 첫 항목(`ko`)을 주는데 이 차이로 키가 갈려
     * 로그인 전환이 다른 방문자의 「서버로만 잡힌 방문」으로 따로 남았다(#682 S4 라운드 8,
     * PO 브라우저 실측). 두 경로가 같은 키를 얻어 전환·이동 비콘이 한 회원 방문에 모여야 한다.
     *
     * 브라우저의 설정 언어(화면 언어)와 선호 언어 목록은 서로 다른 설정이라 자세함뿐 아니라
     * 언어 자체가 다를 수 있다 — 두 경우를 모두 잰다.
     *
     * @scenario point=goal, extension_state=active, input=registered
     *
     * @effects login_visit_takes_browser_values_from_the_next_beacon
     *
     * @param  string  $screenLanguage  비콘이 보내는 설정 언어(`navigator.language`)
     * @param  string  $acceptLanguage  모든 요청에 붙는 선호 언어 헤더
     * @return void 없음
     */
    #[DataProvider('browserLanguagePairs')]
    public function test_anonymous_login_conversion_joins_the_visitors_own_visit(string $screenLanguage, string $acceptLanguage): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'anonymous']);
        User::factory()->create(['email' => 'goal@example.com', 'password' => bcrypt('password123')]);
        $headers = array_merge(self::BROWSER_HEADERS, ['Accept-Language' => $acceptLanguage]);

        // 게스트로 로그인 화면 — 브라우저는 설정 언어를 지역 표기까지 보낸다.
        $this->withHeaders($headers)
            ->postJson('/api/analytics/collect', ['t' => 'pageview', 'url' => 'http://localhost/login', 'res' => '1920x1080', 'lang' => $screenLanguage, 'init' => true])
            ->assertNoContent();

        $token = $this->withHeaders($headers)
            ->postJson('/api/auth/login', ['email' => 'goal@example.com', 'password' => 'password123'])
            ->assertOk()
            ->json('data.token');

        $this->app['auth']->forgetGuards();

        $this->withHeaders(array_merge($headers, ['Authorization' => 'Bearer '.$token]))
            ->postJson('/api/analytics/collect', ['t' => 'pageview', 'url' => 'http://localhost/', 'res' => '1920x1080', 'lang' => $screenLanguage, 'init' => false])
            ->assertNoContent();

        $this->assertSame(1, AnalyticsVisit::query()->distinct()->count('visitor_key'), '익명 모드에서 같은 브라우저는 한 방문자 키');
        $memberVisit = AnalyticsVisit::query()->whereNotNull('user_id')->sole();
        $conversion = AnalyticsConversion::query()->where('goal_key', 'core.user_logged_in')->sole();
        $this->assertSame($memberVisit->id, $conversion->visit_id, '로그인 전환은 그 회원 방문에 붙는다');
        $this->assertSame('beacon', $memberVisit->source->value);
        $this->assertSame('1920x1080', $memberVisit->screen_resolution);
        $this->assertSame($screenLanguage, $memberVisit->browser_language, '방문에 저장하는 언어는 브라우저가 알린 값 그대로');
    }

    /**
     * 설정 언어 ↔ 선호 언어 헤더 쌍.
     *
     * @return array<string, array{0: string, 1: string}> 사례 이름 → [설정 언어, 헤더]
     */
    public static function browserLanguagePairs(): array
    {
        return [
            '자세함만 다름(실측)' => ['ko-KR', 'ko,en-US;q=0.9,en;q=0.8'],
            '언어 자체가 다름' => ['en-US', 'ko,en;q=0.8'],
        ];
    }

    /**
     * 틀린 비밀번호는 전환을 남기지 않는다.
     *
     * @scenario point=goal, extension_state=active, input=unregistered
     *
     * @effects failed_login_records_no_goal
     *
     * @return void 없음
     */
    public function test_failed_login_records_no_goal(): void
    {
        User::factory()->create(['email' => 'goal@example.com', 'password' => bcrypt('password123')]);

        $this->withHeaders(self::BROWSER_HEADERS)
            ->postJson('/api/auth/login', ['email' => 'goal@example.com', 'password' => 'wrong-password'])
            ->assertStatus(401);

        $this->assertSame(0, AnalyticsConversion::count());
    }

    /**
     * 회원가입 완료가 `core.user_registered` 전환을 남긴다.
     *
     * @scenario point=goal, extension_state=active, input=registered
     *
     * @effects core_signup_goal_is_recorded_on_registration
     *
     * @return void 없음
     */
    public function test_registration_records_core_goal(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Role::firstOrCreate(['identifier' => 'user'], ['name' => ['ko' => '사용자', 'en' => 'User']]);
        $this->seed(IdentityPolicySeeder::class);

        $this->withHeaders(array_merge(self::BROWSER_HEADERS, ['Referer' => 'http://localhost/register']))
            ->postJson('/api/auth/register', [
                'name' => '전환 테스트',
                'email' => 'signup-goal@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'agree_terms' => true,
                'agree_privacy' => true,
            ])
            ->assertStatus(201);

        $this->assertSame(1, AnalyticsConversion::query()->where('goal_key', 'core.user_registered')->count());
    }
}
