<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\OAuth;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Plugins\Sirsoft\SocialLogin\Database\Seeders\ProviderSeeder;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginAttempt;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Services\OAuthFlowService;
use Plugins\Sirsoft\SocialLogin\Services\ProviderRegistry;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * OAuth 왕복 시험 — 시작, 콜백, 관리자 연결 테스트 결과 화면, 실패 경로, 관리자 API 권한.
 *
 * 외부 HTTP 는 `Http::fake()` 로 막는다. 로그인·연결 의도의 분기는 다음 단계 산출물이라 여기서는 「아직 처리하지 않음」으로
 * 로그인 화면에 돌아오는 것만 본다.
 *
 * @since 1.0.0
 */
class CallbackFlowTest extends PluginTestCase
{
    /**
     * 카카오를 켜고 자격증명을 넣습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        (new ProviderSeeder)->run();
        SocialLoginProvider::query()->where('key', 'kakao')->update(['enabled' => true, 'client_id' => 'kakao-client']);
        SocialLoginProvider::query()->where('key', 'kakao')->first()->forceFill(['client_secret' => 'kakao-secret'])->save();
        app(ProviderRegistry::class)->flush();
    }

    /**
     * 테스트 의도로 왕복을 시작하고 state 를 돌려줍니다.
     *
     * @return string 시작한 왕복의 state 값
     */
    private function startTest(): string
    {
        $url = app(OAuthFlowService::class)->start('kakao', AttemptIntent::Test, null, null);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return $q['state'];
    }

    /**
     * 카카오 토큰·프로필 응답을 가짜로 둡니다.
     *
     * @param  array<string, mixed>|null  $profile  프로필 (null 이면 완비 픽스처)
     * @return void 반환값 없음
     */
    private function fakeKakao(?array $profile = null): void
    {
        Http::fake([
            'kauth.kakao.com/oauth/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600]),
            'kapi.kakao.com/v2/user/me' => Http::response($profile ?? $this->fixture('kakao_full')),
        ]);
    }

    /**
     * 설정 변경 권한을 가진 관리자를 만듭니다 (공용 admin 역할은 건드리지 않는다).
     *
     * @return User 관리자
     */
    private function settingsOperator(): User
    {
        $role = Role::query()->create([
            'identifier' => 'sl-test-'.Str::lower(Str::random(8)),
            'name' => ['ko' => '시험', 'en' => 'Test'],
            'description' => ['ko' => '시험', 'en' => 'Test'],
        ]);
        $permission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-social_login.settings.update'],
            ['name' => ['ko' => '설정 변경', 'en' => 'Change Settings'], 'type' => PermissionType::Admin]
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * 켜진 제공자로 로그인을 시작하면 state 를 실어 제공자 인가 화면으로 보내는지 확인한다.
     *
     * @scenario intent=login, provider=enabled
     *
     * @effects start_redirects_to_provider
     *
     * @return void 반환값 없음
     */
    public function test_login_start_redirects_to_provider_with_state(): void
    {
        $response = $this->get('/plugins/sirsoft-social_login/oauth/kakao/start?redirect=/mypage');

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://kauth.kakao.com/oauth/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $this->assertSame(OAuthFlowService::callbackUrl('kakao'), $q['redirect_uri']);
        $this->assertSame(40, strlen($q['state']));
    }

    /**
     * 꺼졌거나 없는 제공자는 로그인 화면 오류로 돌려보내는지 확인한다.
     *
     * @scenario intent=login, provider=disabled|unknown
     *
     * @effects start_refused_back_to_login
     *
     * @return void 반환값 없음
     */
    public function test_disabled_or_unknown_provider_returns_to_login(): void
    {
        $this->get('/plugins/sirsoft-social_login/oauth/naver/start')
            ->assertRedirect(url('/login').'?social_error=provider_disabled');

        $this->get('/plugins/sirsoft-social_login/oauth/nobody/start')
            ->assertRedirect(url('/login').'?social_error=provider_unknown');
    }

    /**
     * 관리자 연결 테스트가 로그인하지 않고 한 번만 보이는 결과 화면을 여는지 확인한다.
     *
     * @scenario intent=test, response=success
     *
     * @effects test_result_page_shown_once
     *
     * @return void 반환값 없음
     */
    public function test_test_intent_shows_one_time_result_page(): void
    {
        $this->fakeKakao();
        $state = $this->startTest();

        $callback = $this->get('/plugins/sirsoft-social_login/oauth/kakao/callback?code=c1&state='.$state);
        $callback->assertStatus(302);
        $resultUrl = (string) $callback->headers->get('Location');
        $this->assertMatchesRegularExpression('#/plugins/sirsoft-social_login/test-result/[A-Za-z0-9]{40}$#', $resultUrl);

        $page = $this->get(parse_url($resultUrl, PHP_URL_PATH));
        $page->assertOk();
        $page->assertHeader('Cache-Control', 'no-store, private');
        $page->assertSee('ka********@example.test');
        $page->assertDontSee('kakao.user@example.test');
        $page->assertSee('41******89');
        $page->assertDontSee('4123456789');
        $page->assertSee('provider_user_id, email, email_verified, nickname, avatar_url');
        $page->assertSee('id, kakao_account, kakao_account.email_needs_agreement, kakao_account.email');
        $page->assertSee('<dt>'.e(__('sirsoft-social_login::messages.test_result.missing_required')).'</dt><dd>'.e(__('sirsoft-social_login::messages.test_result.missing_none')).'</dd>', false);

        $this->get(parse_url($resultUrl, PHP_URL_PATH))->assertNotFound();

        $attempt = SocialLoginAttempt::query()->latest('id')->first();
        $this->assertSame(['kakao', 'test', 'login'], [$attempt->provider_key, $attempt->intent, $attempt->outcome]);
        $this->assertNotNull(SocialLoginProvider::query()->where('key', 'kakao')->value('last_success_at'));
    }

    /**
     * 연결 테스트 결과는 60초만 보관해 결과 주소가 오래 살아 있지 않고, 이메일 미동의 같은 빠진 가입 항목을 결과에 보여 주는지 확인한다.
     *
     * @scenario intent=test, response=success, profile=email_not_agreed
     *
     * @effects test_result_expires_after_sixty_seconds
     *
     * @return void 반환값 없음
     */
    public function test_test_result_lists_missing_fields_and_expires_after_sixty_seconds(): void
    {
        $this->assertSame(60, OAuthFlowService::TEST_RESULT_TTL);

        $this->fakeKakao($this->fixture('kakao_email_not_agreed'));
        $state = $this->startTest();
        $first = (string) $this->get('/plugins/sirsoft-social_login/oauth/kakao/callback?code=c1&state='.$state)->headers->get('Location');

        $page = $this->get(parse_url($first, PHP_URL_PATH));
        $page->assertOk();
        // 이메일 미동의 + 이름 없음(별명은 있음) → 빠진 항목은 이메일 하나
        $page->assertSee('<dt>'.e(__('sirsoft-social_login::messages.test_result.missing_required')).'</dt><dd>email</dd>', false);

        $this->fakeKakao();
        $state = $this->startTest();
        $second = (string) $this->get('/plugins/sirsoft-social_login/oauth/kakao/callback?code=c2&state='.$state)->headers->get('Location');

        $this->travel(61)->seconds();
        $this->get(parse_url($second, PHP_URL_PATH))->assertNotFound();
    }

    /**
     * 같은 state 로 콜백을 두 번 열면(인가 코드 재사용) 두 번째는 실패한다.
     *
     * @scenario intent=test, replay=state
     *
     * @effects state_replay_rejected
     *
     * @return void 반환값 없음
     */
    public function test_replayed_callback_is_rejected(): void
    {
        $this->fakeKakao();
        $state = $this->startTest();

        $this->get('/plugins/sirsoft-social_login/oauth/kakao/callback?code=c1&state='.$state)->assertStatus(302);
        $this->get('/plugins/sirsoft-social_login/oauth/kakao/callback?code=c1&state='.$state)
            ->assertRedirect(url('/login').'?social_error=state_invalid');
    }

    /**
     * 위조 state 와 다른 제공자의 state 를 거절하는지 확인한다.
     *
     * @scenario state=mismatch
     *
     * @effects forged_state_rejected
     *
     * @return void 반환값 없음
     */
    public function test_forged_or_cross_provider_state_is_rejected(): void
    {
        $state = $this->startTest();

        $this->get('/plugins/sirsoft-social_login/oauth/kakao/callback?code=c&state=forged')
            ->assertRedirect(url('/login').'?social_error=state_invalid');
        $this->get('/plugins/sirsoft-social_login/oauth/naver/callback?code=c&state='.$state)
            ->assertRedirect(url('/login').'?social_error=state_invalid');

        $this->assertSame('failed', SocialLoginAttempt::query()->latest('id')->value('outcome'));
    }

    /**
     * 사용자가 제공자 화면에서 거부하면 결과 화면에 그 코드가 보이는지 확인한다.
     *
     * @scenario intent=test, response=access_denied
     *
     * @effects provider_error_shown_on_result_page
     *
     * @return void 반환값 없음
     */
    public function test_provider_denial_is_reported_on_result_page(): void
    {
        $state = $this->startTest();

        $location = (string) $this->get('/plugins/sirsoft-social_login/oauth/kakao/callback?error=access_denied&state='.$state)->headers->get('Location');
        $this->get(parse_url($location, PHP_URL_PATH))->assertOk()->assertSee('access_denied');

        Http::assertNothingSent();
    }

    /**
     * 코드 교환 실패가 진단용 기록(제공자 마지막 오류, 시도 기록)에 남는지 확인한다.
     *
     * @scenario intent=test, response=exchange_failed
     *
     * @effects exchange_failure_recorded
     *
     * @return void 반환값 없음
     */
    public function test_exchange_failure_is_recorded_for_diagnostics(): void
    {
        Http::fake(['kauth.kakao.com/oauth/token' => Http::response(['error' => 'invalid_grant', 'error_code' => 'KOE320'], 400)]);
        $state = $this->startTest();

        $location = (string) $this->get('/plugins/sirsoft-social_login/oauth/kakao/callback?code=bad&state='.$state)->headers->get('Location');

        $this->get(parse_url($location, PHP_URL_PATH))->assertOk()->assertSee('KOE320');
        $this->assertSame('KOE320', SocialLoginProvider::query()->where('key', 'kakao')->value('last_error_code'));
        $this->assertSame('KOE320', SocialLoginAttempt::query()->latest('id')->value('error_code'));
    }

    /**
     * 제공자 오류가 아닌 예외(자격증명 복호화 실패 등)도 오류 화면 대신 실패 결과·시도 기록으로 끝나는지 확인한다.
     *
     * 앱 키를 바꾼 뒤처럼 저장된 비밀값을 풀 수 없는 상황을 만든다.
     *
     * @scenario intent=test, response=unexpected_exception
     *
     * @effects unexpected_exception_becomes_provider_error
     *
     * @return void 반환값 없음
     */
    public function test_unexpected_exception_is_wrapped_and_recorded(): void
    {
        $this->fakeKakao();
        $state = $this->startTest();

        SocialLoginProvider::query()->where('key', 'kakao')->toBase()->update(['client_secret' => 'not-a-valid-ciphertext']);
        app(ProviderRegistry::class)->flush();

        $response = $this->get('/plugins/sirsoft-social_login/oauth/kakao/callback?code=c&state='.$state);

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->get(parse_url($location, PHP_URL_PATH))->assertOk()->assertSee('unexpected_error');
        $this->assertSame('unexpected_error', SocialLoginAttempt::query()->latest('id')->value('error_code'));
        $this->assertStringNotContainsString('not-a-valid-ciphertext', (string) SocialLoginAttempt::query()->latest('id')->value('error_message'));
    }

    /**
     * 제공자 사용자 ID 가 없는 프로필은 실패로 처리하는지 확인한다.
     *
     * @scenario intent=test, response=missing_id
     *
     * @effects missing_provider_id_fails
     *
     * @return void 반환값 없음
     */
    public function test_profile_without_id_fails(): void
    {
        $this->fakeKakao(['kakao_account' => ['email_needs_agreement' => true]]);
        $state = $this->startTest();

        $location = (string) $this->get('/plugins/sirsoft-social_login/oauth/kakao/callback?code=c&state='.$state)->headers->get('Location');

        $this->get(parse_url($location, PHP_URL_PATH))->assertOk()->assertSee('profile_missing_id');
    }

    /**
     * 연결된 회원이 없는 로그인 의도는 가입 흐름(가입 화면 + 가입 대기)으로 넘어가는지 확인한다.
     *
     * @scenario intent=login, response=success
     *
     * @effects login_without_link_goes_to_signup
     *
     * @return void 반환값 없음
     */
    public function test_login_intent_without_link_goes_to_signup(): void
    {
        $this->fakeKakao();
        $location = (string) $this->get('/plugins/sirsoft-social_login/oauth/kakao/start?redirect=/mypage')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        $response = $this->get('/plugins/sirsoft-social_login/oauth/kakao/callback?code=c&state='.$q['state']);

        $this->assertSame('/register', $this->redirectPath($response));
        $query = $this->redirectQuery($response);
        $this->assertSame('complete', $query['social_step']);
        $this->assertSame(48, strlen($query['social_pending']));
        $this->assertSame('pending', SocialLoginAttempt::query()->latest('id')->value('outcome'));
    }

    /**
     * 연결 테스트 API 가 설정 변경 권한을 요구하는지 확인한다.
     *
     * @scenario api=test_login, role=operator|member
     *
     * @effects test_login_requires_settings_update
     *
     * @return void 반환값 없음
     */
    public function test_admin_test_login_api_requires_settings_update_permission(): void
    {
        $this->actingAs($this->settingsOperator(), 'sanctum')
            ->postJson('/api/plugins/sirsoft-social_login/admin/providers/naver/test-login')
            ->assertOk()
            ->assertJsonPath('data.authorize_url', fn (string $url) => str_starts_with($url, 'https://nid.naver.com/oauth2.0/authorize?'));

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/plugins/sirsoft-social_login/admin/providers/naver/test-login')
            ->assertForbidden();
    }
}
