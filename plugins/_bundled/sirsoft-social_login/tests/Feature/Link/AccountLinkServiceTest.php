<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Link;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginAttempt;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Services\OAuthFlowService;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 마이페이지 연결 관리 — [연결] 왕복, 다른 회원에 연결된 계정, 같은 제공자 재연결, 해제(권한 회수·훅·활동 로그),
 * 마지막 로그인 수단 가드.
 *
 * @since 1.0.0
 */
class AccountLinkServiceTest extends PluginTestCase
{
    /**
     * 카카오(어드민 키 포함)를 켜고 메일을 막습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->enableKakao(['admin_key' => 'kakao-admin']);
    }

    /**
     * 연결 행을 만듭니다.
     *
     * @param  User  $user  회원
     * @param  string  $uid  카카오 사용자 ID
     * @param  string  $provider  제공자 키
     * @return SocialAccount 연결
     */
    private function link(User $user, string $uid, string $provider = 'kakao'): SocialAccount
    {
        return SocialAccount::query()->create([
            'user_id' => $user->id, 'provider_key' => $provider, 'provider_user_id' => $uid,
            'access_token' => 'at', 'linked_at' => now(),
        ]);
    }

    /**
     * [연결]은 로그인 회원으로 왕복을 시작하고, 콜백이 연결을 만들어 마이페이지로 돌려보낸다 — 훅과 활동 로그가 남는다.
     *
     * @scenario intent=link, account=new
     *
     * @effects link_start_returns_authorize_url, link_callback_creates_account, after_link_fired, link_activity_logged
     *
     * @return void 반환값 없음
     */
    public function test_link_round_trip_creates_account(): void
    {
        $user = User::factory()->create(['email' => $this->uniqueEmail('link')]);
        Sanctum::actingAs($user);
        $fired = [];
        HookManager::addAction('sirsoft-social_login.account.after_link', function (SocialAccount $a, User $u, array $ctx) use (&$fired) {
            $fired[] = $ctx['method'];
        });

        $start = $this->postJson('/api/plugins/sirsoft-social_login/me/accounts/kakao/link-start')->assertOk();
        $this->assertStringStartsWith('https://kauth.kakao.com/oauth/authorize?', (string) $start->json('data.authorize_url'));

        $response = $this->kakaoRoundTrip($this->kakaoProfile('k-link'), AttemptIntent::Link, '/mypage/profile', $user->id);

        $this->assertSame('/mypage/profile', $this->redirectPath($response));
        $this->assertSame(['social' => 'linked', 'provider' => 'kakao'], $this->redirectQuery($response));
        $this->assertSame($user->id, SocialAccount::query()->where('provider_user_id', 'k-link')->value('user_id'));
        $this->assertSame(['link'], $fired);
        $this->assertDatabaseHas('activity_logs', ['loggable_id' => $user->id, 'action' => 'account_linked']);
    }

    /**
     * 다른 회원에 연결된 제공자 계정은 연결하지 않고, 같은 회원의 같은 계정을 다시 연결하면 행이 늘지 않고 토큰만 새로 받은
     * 것으로 바뀐다. 이미 연결된 제공자는 [연결]을 다시 시작하지 않는다.
     *
     * @scenario account=other_user|same_user|provider_already_linked
     *
     * @effects other_user_link_refused, relink_keeps_one_row, relink_refreshes_tokens, already_linked_provider_refused
     *
     * @return void 반환값 없음
     */
    public function test_link_conflicts(): void
    {
        $owner = User::factory()->create(['email' => $this->uniqueEmail('owner')]);
        $this->link($owner, 'k-taken');
        $user = User::factory()->create(['email' => $this->uniqueEmail('me')]);

        $other = $this->kakaoRoundTrip($this->kakaoProfile('k-taken'), AttemptIntent::Link, '/mypage/profile', $user->id);
        $this->assertSame('already_linked_other_user', $this->redirectQuery($other)['social_error']);
        $this->assertSame('failed', SocialLoginAttempt::query()->latest('id')->value('outcome'));

        $this->kakaoRoundTrip($this->kakaoProfile('k-mine'), AttemptIntent::Link, '/mypage/profile', $user->id);
        $firstToken = SocialAccount::query()->where('user_id', $user->id)->firstOrFail()->access_token;
        $again = $this->kakaoRoundTrip($this->kakaoProfile('k-mine'), AttemptIntent::Link, '/mypage/profile', $user->id);
        $this->assertSame('linked', $this->redirectQuery($again)['social']);
        $this->assertSame(1, SocialAccount::query()->where('user_id', $user->id)->count());
        $refreshed = SocialAccount::query()->where('user_id', $user->id)->firstOrFail()->access_token;
        $this->assertStringStartsWith('at-', (string) $refreshed);
        $this->assertNotSame($firstToken, $refreshed);

        Sanctum::actingAs($user);
        $this->postJson('/api/plugins/sirsoft-social_login/me/accounts/kakao/link-start')
            ->assertStatus(409)->assertJsonPath('errors.code', 'provider_already_linked');
    }

    /**
     * 목록은 연결·연결 가능 제공자·비밀번호 유무·해제 가능 여부를 주고, 이메일은 가려서 보인다.
     *
     * @scenario password=set|none, links=1
     *
     * @effects summary_lists_accounts_and_available, summary_masks_email, can_unlink_reflects_guard
     *
     * @return void 반환값 없음
     */
    public function test_summary_lists_links_and_guard(): void
    {
        $user = User::factory()->passwordless()->create(['email' => $this->uniqueEmail('sum')]);
        $account = $this->link($user, 'k-sum');
        $account->forceFill(['email' => 'summary.person@example.test'])->save();
        Sanctum::actingAs($user);

        $summary = $this->getJson('/api/plugins/sirsoft-social_login/me/accounts')->assertOk();

        $this->assertSame('kakao', $summary->json('data.accounts.0.provider_key'));
        $this->assertSame('su************@example.test', $summary->json('data.accounts.0.email'));
        $this->assertFalse($summary->json('data.has_password'));
        $this->assertFalse($summary->json('data.can_unlink.kakao.allowed'));
        $this->assertSame('last_method', $summary->json('data.can_unlink.kakao.reason'));
        $this->assertSame([], $summary->json('data.available'), '연결 가능한 다른 켜진 제공자가 없다');
        $this->assertStringNotContainsString('k-sum', (string) $summary->getContent(), '제공자 사용자 ID 는 싣지 않는다');
    }

    /**
     * 해제는 제공자 권한 회수를 시도하고 연결을 지운 뒤 훅·활동 로그를 남긴다 — 회수가 실패해도 연결은 지운다.
     *
     * @scenario password=set, revoke=ok|failed
     *
     * @effects unlink_revokes_and_deletes, revoke_failure_still_unlinks, unlink_hooks_and_activity
     *
     * @return void 반환값 없음
     */
    public function test_unlink_revokes_and_deletes(): void
    {
        $user = User::factory()->create(['email' => $this->uniqueEmail('unlink')]);
        $this->link($user, 'k-un');
        Sanctum::actingAs($user);
        $reasons = [];
        HookManager::addAction('sirsoft-social_login.account.after_unlink', function (SocialAccount $a, User $u, string $reason) use (&$reasons) {
            $reasons[] = $reason;
        });
        Http::fake(['kapi.kakao.com/v1/user/unlink' => Http::response(['id' => 1])]);

        $this->deleteJson('/api/plugins/sirsoft-social_login/me/accounts/kakao')->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/user/unlink') && $request->header('Authorization')[0] === 'KakaoAK kakao-admin');
        $this->assertSame(0, SocialAccount::query()->where('user_id', $user->id)->count());
        $this->assertSame(['user'], $reasons);
        $this->assertDatabaseHas('activity_logs', ['loggable_id' => $user->id, 'action' => 'account_unlinked']);

        $this->link($user, 'k-un2');
        SocialLoginProvider::query()->where('key', 'kakao')->first()->forceFill(['admin_key' => ''])->save();
        Http::fake(['*' => Http::response([], 500)]);
        $this->deleteJson('/api/plugins/sirsoft-social_login/me/accounts/kakao')->assertOk();
        $this->assertSame(0, SocialAccount::query()->where('user_id', $user->id)->count());
    }

    /**
     * [연결] 왕복이 제공자 단계에서 끝나면(동의 취소·코드 교환 실패·다른 제공자 콜백) 로그인 화면이 아니라 마이페이지로
     * 돌아가 실패 사유를 보인다 — 로그인 화면은 로그인한 회원을 홈으로 되돌려 사유가 사라진다.
     *
     * @scenario intent=link, provider_step=denied|exchange_failed|wrong_callback
     *
     * @effects link_provider_failure_returns_to_mypage
     *
     * @return void 반환값 없음
     */
    public function test_link_provider_failure_returns_to_mypage(): void
    {
        $user = User::factory()->create(['email' => $this->uniqueEmail('linkfail')]);
        $flow = app(OAuthFlowService::class);
        $state = function () use ($flow, $user): string {
            parse_str((string) parse_url($flow->start('kakao', AttemptIntent::Link, '/mypage/profile', $user->id), PHP_URL_QUERY), $q);

            return $q['state'];
        };

        $denied = $this->get('/plugins/sirsoft-social_login/oauth/kakao/callback?error=access_denied&state='.$state());
        $this->assertSame('/mypage/profile', $this->redirectPath($denied));
        $this->assertSame(['social_error' => 'access_denied', 'provider' => 'kakao'], $this->redirectQuery($denied));

        Http::fake(['kauth.kakao.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $failed = $this->get('/plugins/sirsoft-social_login/oauth/kakao/callback?code=c&state='.$state());
        $this->assertSame('/mypage/profile', $this->redirectPath($failed));
        $this->assertSame('kakao', $this->redirectQuery($failed)['provider']);

        $wrong = $this->get('/plugins/sirsoft-social_login/oauth/naver/callback?code=c&state='.$state());
        $this->assertSame('/mypage/profile', $this->redirectPath($wrong));
        $this->assertSame('state_invalid', $this->redirectQuery($wrong)['social_error']);

        $this->assertSame(0, SocialAccount::query()->where('user_id', $user->id)->count());
        $this->assertSame(3, SocialLoginAttempt::query()->where('user_id', $user->id)->where('intent', 'link')->where('outcome', 'failed')->count());
    }

    /**
     * 비밀번호가 없고 연결이 하나뿐이면 해제를 막고(409 `last_method`), 설정으로 허용하면 해제한다. 비밀번호가 생기면 해제된다.
     *
     * @scenario password=none, links=1, allow_last=off|on
     *
     * @effects last_method_blocked, last_method_allowed_by_setting
     *
     * @return void 반환값 없음
     */
    public function test_last_method_guard(): void
    {
        $user = User::factory()->passwordless()->create(['email' => $this->uniqueEmail('last')]);
        $this->link($user, 'k-last');
        Sanctum::actingAs($user);
        Http::fake(['*' => Http::response(['id' => 1])]);

        $this->deleteJson('/api/plugins/sirsoft-social_login/me/accounts/kakao')
            ->assertStatus(409)->assertJsonPath('errors.code', 'last_method');
        $this->assertSame(1, SocialAccount::query()->where('user_id', $user->id)->count());

        $this->setPluginSettings(['allow_unlink_last_method' => true]);
        $this->deleteJson('/api/plugins/sirsoft-social_login/me/accounts/kakao')->assertOk();
        $this->assertSame(0, SocialAccount::query()->where('user_id', $user->id)->count());

        $this->deleteJson('/api/plugins/sirsoft-social_login/me/accounts/kakao')->assertStatus(404)->assertJsonPath('errors.code', 'not_linked');
    }
}
