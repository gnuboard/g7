<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\SocialLogin\Mock\MockGate;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginAttempt;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;
use Plugins\Sirsoft\SocialLogin\Services\OAuthFlowService;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;
use ReflectionMethod;

/**
 * 화면에 보이는 시각 칸은 코어 리소스와 같은 모양(보는 사람 시간대의 `Y-m-d H:i:s`)으로 나간다 — 관리자 회원 상세 탭,
 * 제공자 카드, 진단 세 표와 모의 제공자 배지, 통계 기준 시각, 마이페이지 카드, 연결 테스트 결과와 데이터 삭제 상태 페이지.
 * UTC ISO 8601 원문(`…T…+00:00`)이 그대로 보이면 사이트 시간대 화면에서 9시간 늦은 값처럼 읽힌다.
 *
 * @since 1.0.0
 */
class DateDisplayTest extends PluginTestCase
{
    /** 관리자 API 접두 */
    private const BASE = '/api/plugins/sirsoft-social_login/admin';

    /** 보는 사람(관리자·회원) 시간대 */
    private const VIEWER_TZ = 'Asia/Seoul';

    /** 화면 시각 모양 */
    private const DISPLAY = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    /**
     * 카카오를 켜고 시계를 UTC 2026-10-28 14:00:56 에 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-28 14:00:56', 'UTC'));
        $this->enableKakao();
    }

    /**
     * 시계를 풉니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 관리자 회원 상세 「소셜 로그인」 탭의 연결일·최근 로그인은 관리자 시간대 문자열이다 — 같은 화면 코어 가입일과 같은 모양.
     *
     * @scenario surface=user_detail_tab, timezone=viewer
     *
     * @effects dates_in_viewer_timezone_string, no_iso_offset_in_display_dates
     *
     * @return void 반환값 없음
     */
    public function test_admin_user_detail_tab_dates(): void
    {
        $member = User::factory()->create(['email' => $this->uniqueEmail('dt-member')]);
        SocialAccount::query()->create([
            'user_id' => $member->id, 'provider_key' => 'kakao', 'provider_user_id' => 'dt-1',
            'linked_at' => Carbon::parse('2026-10-28 14:00:56', 'UTC'),
            'last_login_at' => Carbon::parse('2026-10-28 14:05:15', 'UTC'),
        ]);
        Sanctum::actingAs($this->makeAdminUser(
            ['email' => $this->uniqueEmail('dt-viewer'), 'timezone' => self::VIEWER_TZ],
            ['sirsoft-social_login.accounts.view'],
        ));

        $account = $this->getJson(self::BASE.'/users/'.$member->uuid.'/accounts')->assertOk()->json('data.accounts.0');

        $this->assertSame('2026-10-28 23:00:56', $account['linked_at']);
        $this->assertSame('2026-10-28 23:05:15', $account['last_login_at']);
    }

    /**
     * 제공자 카드의 마지막 성공·오류와 진단 제공자 상태 표(마지막 웹훅 포함), 모의 제공자 끝나는 시각은 관리자 시간대 문자열이다.
     *
     * @scenario surface=provider_card|diagnostics, timezone=viewer
     *
     * @effects dates_in_viewer_timezone_string, no_iso_offset_in_display_dates
     *
     * @return void 반환값 없음
     */
    public function test_provider_card_and_diagnostics_summary_dates(): void
    {
        SocialLoginProvider::query()->where('key', 'kakao')->update([
            'last_success_at' => Carbon::parse('2026-10-28 14:00:56', 'UTC'),
            'last_error_at' => Carbon::parse('2026-10-28 13:00:00', 'UTC'),
            'last_error_code' => 'KOE010',
        ]);
        SocialLoginWebhookEvent::query()->create([
            'provider_key' => 'kakao', 'event_key' => 'kakao:dt:'.uniqid(), 'event_type' => 'unlink',
            'payload' => [], 'status' => 'processed', 'attempts' => 1,
            'received_at' => Carbon::parse('2026-10-28 12:30:00', 'UTC'),
        ]);
        $until = app(MockGate::class)->enable(30);
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('dt-admin'), 'timezone' => self::VIEWER_TZ]));

        $card = collect($this->getJson(self::BASE.'/providers')->assertOk()->json('data.providers'))->keyBy('key')['kakao'];
        $this->assertSame('2026-10-28 23:00:56', $card['last_success_at']);
        $this->assertSame('2026-10-28 22:00:00', $card['last_error_at']);

        $summary = $this->getJson(self::BASE.'/diagnostics')->assertOk()->json('data');
        $row = collect($summary['providers'])->keyBy('key')['kakao'];
        $this->assertSame('2026-10-28 23:00:56', $row['last_success_at']);
        $this->assertSame('2026-10-28 22:00:00', $row['last_error_at']);
        $this->assertSame('2026-10-28 21:30:00', $row['last_webhook_at']);
        $this->assertSame(
            Carbon::createFromTimestamp($until)->setTimezone(self::VIEWER_TZ)->format('Y-m-d H:i:s'),
            $summary['mock']['expires_at'],
        );
    }

    /**
     * 진단 시도 기록·웹훅 이력 표와 통계 기준 시각은 관리자 시간대 문자열이다.
     *
     * @scenario surface=diagnostics|stats, timezone=viewer
     *
     * @effects dates_in_viewer_timezone_string, no_iso_offset_in_display_dates
     *
     * @return void 반환값 없음
     */
    public function test_diagnostics_tables_and_stats_since(): void
    {
        SocialLoginAttempt::query()->create([
            'provider_key' => 'kakao', 'intent' => 'login', 'outcome' => 'failed', 'error_code' => 'dt_attempt',
            'created_at' => Carbon::parse('2026-10-28 14:00:56', 'UTC'),
        ]);
        SocialLoginWebhookEvent::query()->create([
            'provider_key' => 'kakao', 'event_key' => 'kakao:dt-list:'.uniqid(), 'event_type' => 'unlink',
            'payload' => [], 'status' => 'processed', 'attempts' => 1,
            'received_at' => Carbon::parse('2026-10-28 14:00:56', 'UTC'),
            'processed_at' => Carbon::parse('2026-10-28 14:01:00', 'UTC'),
        ]);
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('dt-diag'), 'timezone' => self::VIEWER_TZ]));

        $attempt = collect($this->getJson(self::BASE.'/attempts?provider=kakao')->assertOk()->json('data.data'))
            ->firstWhere('error_code', 'dt_attempt');
        $this->assertSame('2026-10-28 23:00:56', $attempt['created_at']);

        $event = $this->getJson(self::BASE.'/webhook-events?provider=kakao')->assertOk()->json('data.data.0');
        $this->assertSame('2026-10-28 23:00:56', $event['received_at']);
        $this->assertSame('2026-10-28 23:01:00', $event['processed_at']);

        // 기준 시각은 집계 경계(7일 전 사이트 시간대 자정)를 사이트 시간대 문자열로 보인 값이다.
        $since = $this->getJson(self::BASE.'/stats?days=7')->assertOk()->json('data.since');
        $this->assertSame('2026-10-21 00:00:00', $since);
    }

    /**
     * 마이페이지 「연결된 소셜 계정」 카드도 같은 모양을 받는다 — 화면의 `| date` 는 앞 날짜만 고른다.
     *
     * @scenario surface=mypage_card, timezone=viewer
     *
     * @effects dates_in_viewer_timezone_string, no_iso_offset_in_display_dates
     *
     * @return void 반환값 없음
     */
    public function test_mypage_card_dates(): void
    {
        $user = User::factory()->create(['email' => $this->uniqueEmail('dt-me'), 'timezone' => self::VIEWER_TZ]);
        SocialAccount::query()->create([
            'user_id' => $user->id, 'provider_key' => 'kakao', 'provider_user_id' => 'dt-me-1',
            'linked_at' => Carbon::parse('2026-10-28 16:00:00', 'UTC'),
        ]);
        Sanctum::actingAs($user);

        $account = $this->getJson('/api/plugins/sirsoft-social_login/me/accounts')->assertOk()->json('data.accounts.0');

        // UTC 로는 10월 28일이지만 회원 시간대로는 이미 29일이다.
        $this->assertSame('2026-10-29 01:00:00', $account['linked_at']);
        $this->assertNull($account['last_login_at']);
    }

    /**
     * 서버가 그리는 두 페이지(관리자 연결 테스트 결과, 페이스북 데이터 삭제 상태)도 UTC 원문 대신 사이트 시간대 시각을 쓴다.
     *
     * @scenario surface=html_pages, timezone=site
     *
     * @effects dates_in_viewer_timezone_string, no_iso_offset_in_display_dates
     *
     * @return void 반환값 없음
     */
    public function test_server_rendered_pages_dates(): void
    {
        $store = new ReflectionMethod(OAuthFlowService::class, 'storeTestResult');
        $url = (string) $store->invoke(app(OAuthFlowService::class), 'kakao', false, null, 'KOE010');

        $page = (string) $this->get((string) parse_url($url, PHP_URL_PATH))->assertOk()->getContent();
        $this->assertStringContainsString('2026-10-28 23:00:56', $page);
        $this->assertStringNotContainsString('2026-10-28T14:00:56', $page);

        $code = 'DT'.strtoupper(substr(md5(uniqid()), 0, 18));
        SocialLoginWebhookEvent::query()->create([
            'provider_key' => 'facebook', 'event_key' => 'facebook:dt:'.uniqid(), 'event_type' => 'data_deletion',
            'payload' => [], 'status' => 'processed', 'attempts' => 1, 'confirmation_code' => $code,
            'received_at' => Carbon::parse('2026-10-28 14:00:56', 'UTC'),
            'processed_at' => Carbon::parse('2026-10-28 14:01:00', 'UTC'),
        ]);

        $status = (string) $this->get('/plugins/sirsoft-social_login/data-deletion/'.$code)->assertOk()->getContent();
        $this->assertStringContainsString('2026-10-28 23:00:56', $status);
        $this->assertStringContainsString('2026-10-28 23:01:00', $status);
        $this->assertStringNotContainsString('2026-10-28 14:00:56', $status);
    }

    /**
     * 남은 시간 계산에 쓰는 기계용 만료 시각(가입 모달 인증번호 등)은 여기서 바꾸지 않는다 — 화면 칸만 화면 모양을 쓴다.
     *
     * @scenario surface=diagnostics, timezone=viewer
     *
     * @effects no_iso_offset_in_display_dates
     *
     * @return void 반환값 없음
     */
    public function test_display_dates_carry_no_offset(): void
    {
        SocialLoginProvider::query()->where('key', 'kakao')->update(['last_success_at' => now()]);
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('dt-shape'), 'timezone' => self::VIEWER_TZ]));

        $row = collect($this->getJson(self::BASE.'/diagnostics')->assertOk()->json('data.providers'))->keyBy('key')['kakao'];

        $this->assertMatchesRegularExpression(self::DISPLAY, (string) $row['last_success_at']);
    }
}
