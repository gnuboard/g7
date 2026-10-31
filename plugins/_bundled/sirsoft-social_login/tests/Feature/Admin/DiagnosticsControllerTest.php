<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\SocialLogin\Mock\MockGate;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginAttempt;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 진단 API — 요약 카드(제공자·큐·메일·모의·회원·웹훅) 계약, 앱 키가 바뀌어 풀 수 없는 자격증명 표시, 시도·웹훅 목록 필터, 권한.
 *
 * @since 1.0.0
 */
class DiagnosticsControllerTest extends PluginTestCase
{
    /** API 접두 */
    private const BASE = '/api/plugins/sirsoft-social_login/admin';

    /**
     * 카카오를 켭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableKakao();
    }

    /**
     * 요약은 제공자별 마지막 성공·실패와 웹훅 마지막 수신, 큐·메일·모의·회원 카드를 준다. 다른 앱 키로 만든 암호문은
     * 「풀 수 없음」으로 표시된다(목록은 그대로 열린다).
     *
     * @scenario credentials=readable|unreadable, mock=on
     *
     * @effects summary_contract, unreadable_credentials_flagged, mock_badge_expiry, member_counts, last_webhook_shown
     *
     * @return void 반환값 없음
     */
    public function test_summary_contract(): void
    {
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('diag')]));
        SocialLoginProvider::query()->where('key', 'kakao')->update(['last_error_code' => 'KOE010', 'last_error_at' => now()]);
        DB::table('social_login_providers')->where('key', 'naver')->update(['client_secret' => 'not-a-valid-ciphertext']);
        app(MockGate::class)->enable(30);
        SocialLoginWebhookEvent::query()->create([
            'provider_key' => 'kakao', 'event_key' => 'kakao:diag:'.uniqid(), 'event_type' => 'unlink',
            'payload' => [], 'status' => 'processed', 'attempts' => 1, 'received_at' => now(),
        ]);
        $member = User::factory()->passwordless()->create(['email' => $this->uniqueEmail('m')]);
        SocialAccount::query()->create(['user_id' => $member->id, 'provider_key' => 'kakao', 'provider_user_id' => 'd1', 'linked_at' => now()]);

        $data = $this->getJson(self::BASE.'/diagnostics')->assertOk()->json('data');

        foreach (['providers', 'queue', 'mail', 'mock', 'members', 'webhooks'] as $card) {
            $this->assertArrayHasKey($card, $data);
        }
        $providers = collect($data['providers'])->keyBy('key');
        $this->assertSame('KOE010', $providers['kakao']['last_error_code']);
        $this->assertTrue($providers['kakao']['configured']);
        $this->assertNotNull($providers['kakao']['last_webhook_at']);
        $this->assertFalse($providers['kakao']['credentials_unreadable']);
        $this->assertTrue($providers['naver']['credentials_unreadable']);
        $this->assertTrue($data['mock']['enabled']);
        $this->assertNotNull($data['mock']['expires_at']);
        $this->assertGreaterThanOrEqual(1, $data['members']['passwordless_users']);
        $this->assertArrayHasKey('delivering', $data['mail']);
        $this->assertArrayHasKey('pending', $data['queue']);

        // 앱 키가 바뀐 행이 있어도 설정 화면 목록은 열린다.
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('list')]));
        $this->getJson(self::BASE.'/providers')->assertOk();

        app(MockGate::class)->disable();
    }

    /**
     * 시도 기록·웹훅 이력은 최신순이고 제공자·결과·상태로 거른다.
     *
     * @scenario filter=provider|outcome|status
     *
     * @effects attempts_filtered, webhook_events_filtered, pagination_meta
     *
     * @return void 반환값 없음
     */
    public function test_logs_filtering(): void
    {
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('logs')]));
        $tag = 'diag'.uniqid();
        foreach ([['kakao', 'failed'], ['kakao', 'handoff'], ['naver', 'failed']] as [$provider, $outcome]) {
            SocialLoginAttempt::query()->create(['provider_key' => $provider, 'intent' => 'login', 'outcome' => $outcome, 'error_code' => $outcome === 'failed' ? $tag : null, 'created_at' => now()]);
        }
        foreach (['processed', 'ignored'] as $status) {
            SocialLoginWebhookEvent::query()->create([
                'provider_key' => 'naver', 'event_key' => $tag.$status, 'event_type' => 'unlink', 'payload' => ['n' => 1],
                'status' => $status, 'attempts' => 0, 'received_at' => now(),
            ]);
        }

        $failed = $this->getJson(self::BASE.'/attempts?outcome=failed&error_code='.$tag)->assertOk();
        $this->assertSame(2, $failed->json('data.pagination.total'));
        $kakao = $this->getJson(self::BASE.'/attempts?provider=kakao&outcome=failed&error_code='.$tag)->assertOk();
        $this->assertSame(['kakao'], array_unique(array_column($kakao->json('data.data'), 'provider_key')));

        $ignored = $this->getJson(self::BASE.'/webhook-events?provider=naver&status=ignored')->assertOk();
        $this->assertGreaterThanOrEqual(1, count($ignored->json('data.data')));
        $this->assertSame(['ignored'], array_values(array_unique(array_column($ignored->json('data.data'), 'status'))));

        $this->getJson(self::BASE.'/webhook-events?status=nope')->assertStatus(422);
    }

    /**
     * 진단·통계·기록 목록은 설정 열람 권한이 있어야 한다.
     *
     * @scenario actor=member|viewer
     *
     * @effects diagnostics_requires_view
     *
     * @return void 반환값 없음
     */
    public function test_permission(): void
    {
        Sanctum::actingAs(User::factory()->create(['email' => $this->uniqueEmail('plain')]));
        foreach (['/diagnostics', '/attempts', '/webhook-events', '/stats'] as $path) {
            $this->getJson(self::BASE.$path)->assertStatus(403);
        }

        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('unlinker')], ['sirsoft-social_login.accounts.unlink']));
        $this->getJson(self::BASE.'/diagnostics')->assertStatus(403);
    }
}
