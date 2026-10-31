<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Link;

use App\Contracts\Extension\UserScopedIdentityProviderInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\Role;
use App\Models\User;
use App\Services\IdentityVerificationService;
use Database\Seeders\IdentityPolicySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Services\ProviderRegistry;
use Plugins\Sirsoft\SocialLogin\Services\SocialReauthService;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;
use Tests\Support\Identity\TestIdentityProvider;

/**
 * 대체 이메일 회원의 본인 재확인(R16) — 비밀번호도, 받을 수 있는 메일도 없는 회원은 연결된 제공자로 다시 로그인해 민감 작업
 * (마이페이지 정보 수정 진입, 비밀번호 첫 설정)을 통과한다. 코어 본인인증 수단으로 붙으므로 코어 관문은 바뀌지 않는다.
 *
 * @since 1.0.0
 */
class SocialReauthenticationTest extends PluginTestCase
{
    /**
     * 카카오를 켜고 메일을 막습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->enableKakao();
    }

    /**
     * 대체 이메일·비밀번호 없는 회원에 카카오를 연결합니다.
     *
     * @param  string  $uid  카카오 사용자 ID
     * @return User 회원
     */
    private function placeholderMember(string $uid): User
    {
        $user = User::factory()->passwordless()->create(['email' => 'kakao_'.substr(sha1($uid), 0, 12).'@social.invalid']);
        SocialAccount::query()->create(['user_id' => $user->id, 'provider_key' => 'kakao', 'provider_user_id' => $uid, 'linked_at' => now()]);

        return $user;
    }

    /**
     * 재확인 왕복을 시작하고 콜백까지 마칩니다.
     *
     * @param  string  $returnedUid  제공자가 돌려줄 사용자 ID
     * @param  string  $return  돌아갈 경로
     * @return TestResponse 콜백 응답
     */
    private function reauthRoundTrip(string $returnedUid, string $return = '/mypage/profile-edit'): TestResponse
    {
        $start = $this->postJson('/api/plugins/sirsoft-social_login/me/reauth/kakao/start', ['return' => $return])->assertOk();
        parse_str((string) parse_url((string) $start->json('data.authorize_url'), PHP_URL_QUERY), $q);
        $this->fakeKakaoProfile($this->kakaoProfile($returnedUid, null));

        return $this->get('/plugins/sirsoft-social_login/oauth/kakao/callback?code=c&state='.$q['state']);
    }

    /**
     * 같은 제공자 계정으로 다시 로그인하면 돌아온 화면이 수령 코드로 확인 토큰을 받고, 그 토큰으로 정보 수정 관문을 통과한다.
     * 토큰·수령 코드는 한 번만 쓴다.
     *
     * @scenario member=placeholder_passwordless, provider=same_link
     *
     * @effects reauth_passes_profile_gate, reauth_token_single_use, claim_code_single_use, accounts_marks_reauth_via_social
     *
     * @return void 반환값 없음
     */
    public function test_social_reauth_passes_profile_edit_gate(): void
    {
        $user = $this->placeholderMember('k-re1');
        Sanctum::actingAs($user);

        $this->getJson('/api/plugins/sirsoft-social_login/me/accounts')->assertOk()->assertJsonPath('data.reauth_via_social', true);

        $callback = $this->reauthRoundTrip('k-re1');
        $this->assertSame('/mypage/profile-edit', $this->redirectPath($callback));
        $code = $this->redirectQuery($callback)['social_reauth'];
        $this->assertStringNotContainsString('verification_token', (string) $callback->headers->get('Location'), '토큰은 주소에 싣지 않는다');

        $token = (string) $this->postJson('/api/plugins/sirsoft-social_login/me/reauth/claim', ['code' => $code])->assertOk()->json('data.verification_token');
        $this->postJson('/api/plugins/sirsoft-social_login/me/reauth/claim', ['code' => $code])->assertStatus(404);

        $this->postJson('/api/me/verify-password', ['verification_token' => $token])->assertOk();
        $this->postJson('/api/me/verify-password', ['verification_token' => $token])->assertStatus(422);
    }

    /**
     * 재확인 직후(유예 시간 안)에는 비밀번호 첫 설정이 본인인증 정책을 통과하고, 그 뒤 비밀번호 로그인이 된다.
     *
     * @scenario member=placeholder_passwordless, identity_policy=password_change
     *
     * @effects reauth_allows_first_password
     *
     * @return void 반환값 없음
     */
    public function test_social_reauth_allows_first_password(): void
    {
        if (! IdentityPolicy::query()->where('key', 'core.profile.password_change')->exists()) {
            $this->seed(IdentityPolicySeeder::class);
        }
        IdentityPolicy::query()->where('key', 'core.profile.password_change')->update(['enabled' => true, 'grace_minutes' => 5]);

        $user = $this->placeholderMember('k-re2');
        Sanctum::actingAs($user);

        $this->putJson('/api/me/password', ['password' => 'Fresh#Pass9x', 'password_confirmation' => 'Fresh#Pass9x'])->assertStatus(428);

        $this->reauthRoundTrip('k-re2', '/mypage/change-password');

        $this->putJson('/api/me/password', ['password' => 'Fresh#Pass9x', 'password_confirmation' => 'Fresh#Pass9x'])->assertOk();
        $this->assertTrue(Hash::check('Fresh#Pass9x', (string) $user->fresh()->getAuthPassword()));
    }

    /**
     * 다른 회원의 소셜 계정·연결 없는 계정으로 돌아오면 거절하고, 다른 회원은 수령 코드를 쓰지 못한다.
     *
     * @scenario provider=other_user|unlinked, claim=other_user
     *
     * @effects reauth_mismatch_refused, claim_by_other_user_refused
     *
     * @return void 반환값 없음
     */
    public function test_mismatched_account_and_foreign_claim_are_refused(): void
    {
        $user = $this->placeholderMember('k-re3');
        $this->placeholderMember('k-re3-other');
        Sanctum::actingAs($user);

        $this->assertSame('reauth_mismatch', $this->redirectQuery($this->reauthRoundTrip('k-re3-other'))['social_error']);
        $this->assertSame('reauth_mismatch', $this->redirectQuery($this->reauthRoundTrip('k-nobody'))['social_error']);

        $code = $this->redirectQuery($this->reauthRoundTrip('k-re3'))['social_reauth'];
        Sanctum::actingAs($this->placeholderMember('k-re3-thief'));
        $this->postJson('/api/plugins/sirsoft-social_login/me/reauth/claim', ['code' => $code])->assertStatus(404)->assertJsonPath('errors.code', 'reauth_claim_invalid');
    }

    /**
     * 연결되지 않은 다른 제공자로는 재확인을 시작할 수 없고, 시작한 재확인을 다른 제공자의 콜백으로 돌려 받아도 통과하지
     * 못한다. 제공자 단계에서 취소하면 시작한 화면으로 돌아가 사유를 보이고 본인인증 요청을 거둔다.
     *
     * @scenario provider=other_provider|provider_denied
     *
     * @effects reauth_other_provider_refused, reauth_provider_failure_returns_to_screen
     *
     * @return void 반환값 없음
     */
    public function test_other_provider_and_provider_failure(): void
    {
        $user = $this->placeholderMember('k-re5');
        Sanctum::actingAs($user);
        SocialLoginProvider::query()->where('key', 'naver')->firstOrFail()
            ->forceFill(['enabled' => true, 'client_id' => 'naver-client', 'client_secret' => 'naver-secret'])->save();
        app(ProviderRegistry::class)->flush();

        $this->postJson('/api/plugins/sirsoft-social_login/me/reauth/naver/start', ['return' => '/mypage/profile-edit'])
            ->assertStatus(404)->assertJsonPath('errors.code', 'not_linked');

        $state = function (): string {
            $start = $this->postJson('/api/plugins/sirsoft-social_login/me/reauth/kakao/start', ['return' => '/mypage/profile-edit'])->assertOk();
            parse_str((string) parse_url((string) $start->json('data.authorize_url'), PHP_URL_QUERY), $q);

            return $q['state'];
        };

        $wrong = $this->get('/plugins/sirsoft-social_login/oauth/naver/callback?code=c&state='.$state());
        $this->assertSame('/mypage/profile-edit', $this->redirectPath($wrong));
        $this->assertSame(['social_error' => 'state_invalid'], $this->redirectQuery($wrong));

        $denied = $this->get('/plugins/sirsoft-social_login/oauth/kakao/callback?error=access_denied&state='.$state());
        $this->assertSame('/mypage/profile-edit', $this->redirectPath($denied));
        $this->assertSame(['social_error' => 'access_denied'], $this->redirectQuery($denied));

        $this->assertSame(0, IdentityVerificationLog::query()->where('user_id', $user->id)->where('status', IdentityVerificationStatus::Processing->value)->count());
    }

    /**
     * 메일을 받을 수 있는 회원·비밀번호가 있는 회원은 대상이 아니다(화면 불변). 코어 확인 API 를 직접 불러도 증명 없이는 통과하지 못한다.
     *
     * @scenario member=real_email|password_set, direct_verify=yes
     *
     * @effects not_eligible_refused, direct_verify_without_proof_fails
     *
     * @return void 반환값 없음
     */
    public function test_non_eligible_members_and_direct_verify(): void
    {
        $real = User::factory()->passwordless()->create(['email' => $this->uniqueEmail('real')]);
        SocialAccount::query()->create(['user_id' => $real->id, 'provider_key' => 'kakao', 'provider_user_id' => 'k-real', 'linked_at' => now()]);
        Sanctum::actingAs($real);
        $this->getJson('/api/plugins/sirsoft-social_login/me/accounts')->assertOk()->assertJsonPath('data.reauth_via_social', false);
        $this->postJson('/api/plugins/sirsoft-social_login/me/reauth/kakao/start')->assertStatus(403)->assertJsonPath('errors.code', 'reauth_not_available');

        $placeholder = $this->placeholderMember('k-re4');
        $challenge = app(IdentityVerificationService::class)->start('sensitive_action', $placeholder, [], SocialReauthService::PROVIDER_ID);
        $this->assertSame(SocialReauthService::PROVIDER_ID, $challenge->providerId);

        $result = app(IdentityVerificationService::class)->verify($challenge->id, ['code' => '000000']);
        $this->assertFalse($result->success);
    }

    /**
     * 정보 수정 화면의 「본인 확인 계속하기」(수단을 비운 코어 재확인 요청)가 소셜 재확인 수단으로 정해지면, 응답에 같은 출처 시작 주소
     * (`redirect_url`)가 실려 그 주소가 연결한 제공자(연결 순서 첫째) 인가 화면으로 보낸다. 다시 로그인하고 돌아오면 수령 코드로
     * 토큰을 받아 정보 수정 관문을 통과한다. 시작 주소는 한 번만 쓴다.
     *
     * @scenario member=placeholder_passwordless, entry=core_challenge_continue, links='2'
     *
     * @effects continue_challenge_carries_start_path, continue_start_path_redirects_to_first_linked_provider, continue_roundtrip_passes_profile_gate, continue_start_path_single_use
     *
     * @return void 반환값 없음
     */
    public function test_continue_challenge_redirects_to_linked_provider(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = $this->placeholderMember('k-re7');
        $user->roles()->syncWithoutDetaching([Role::query()->where('identifier', 'user')->value('id') => ['assigned_at' => now(), 'assigned_by' => null]]);
        SocialLoginProvider::query()->where('key', 'naver')->firstOrFail()
            ->forceFill(['enabled' => true, 'client_id' => 'naver-client', 'client_secret' => 'naver-secret'])->save();
        app(ProviderRegistry::class)->flush();
        SocialAccount::query()->create(['user_id' => $user->id, 'provider_key' => 'naver', 'provider_user_id' => 'n-re7', 'linked_at' => now()->addMinute()]);
        Sanctum::actingAs($user);

        $challenge = $this->postJson('/api/identity/challenges', ['purpose' => 'sensitive_action', 'return_path' => '/mypage/profile/edit'])
            ->assertCreated()
            ->assertJsonPath('data.provider_id', SocialReauthService::PROVIDER_ID)
            ->assertJsonPath('data.render_hint', 'external_redirect');
        $startPath = (string) $challenge->json('data.redirect_url');
        $this->assertMatchesRegularExpression('#^/plugins/sirsoft-social_login/reauth/[A-Za-z0-9]{40}$#', $startPath);

        $start = $this->get($startPath);
        $start->assertStatus(302);
        $authorize = (string) $start->headers->get('Location');
        $this->assertStringContainsString('kakao', parse_url($authorize, PHP_URL_HOST) ?? $authorize, '연결 순서 첫째(카카오)로 보낸다');
        parse_str((string) parse_url($authorize, PHP_URL_QUERY), $q);

        $this->fakeKakaoProfile($this->kakaoProfile('k-re7', null));
        $callback = $this->get('/plugins/sirsoft-social_login/oauth/kakao/callback?code=c&state='.$q['state']);
        $this->assertSame('/mypage/profile/edit', $this->redirectPath($callback));
        $code = $this->redirectQuery($callback)['social_reauth'];

        $token = (string) $this->postJson('/api/plugins/sirsoft-social_login/me/reauth/claim', ['code' => $code])->assertOk()->json('data.verification_token');
        $this->postJson('/api/me/verify-password', ['verification_token' => $token])->assertOk();

        $again = $this->get($startPath);
        $this->assertSame(['social_error' => 'reauth_failed'], $this->redirectQuery($again), '시작 주소는 한 번만 쓴다');
    }

    /**
     * 대체 이메일 회원의 수단을 비운 재확인 요청은, 회원별 판정이 없는 수단(휴대폰 본인확인처럼 누구의 신원이든 확인해 주는 수단)이
     * 앞에 등록돼 있어도 「소셜 계정으로 다시 확인」으로 간다. 소셜 연결이 없는 대체 이메일 회원은 이 수단의 대상이 아니다.
     *
     * @scenario member=placeholder_passwordless|placeholder_unlinked, registry=unscoped_provider_first
     *
     * @effects core_fallback_picks_social_over_unscoped
     *
     * @return void 반환값 없음
     */
    public function test_core_fallback_picks_social_reauth_over_unscoped_provider(): void
    {
        $manager = app(IdentityVerificationManager::class);
        $manager->register(app(TestIdentityProvider::class));

        $this->assertSame(SocialReauthService::PROVIDER_ID, $manager->resolveForPurpose('sensitive_action', null, $this->placeholderMember('k-re5'))->getId());

        $unlinked = User::factory()->passwordless()->create(['email' => 'kakao_'.substr(sha1('k-re6'), 0, 12).'@social.invalid']);
        $this->assertSame('g7:core.mail', $manager->resolveForPurpose('sensitive_action', null, $unlinked)->getId());
    }

    /**
     * 내 연결 목록의 「소셜 계정으로 다시 확인」 대상 표시(`reauth_via_social`)는 코어가 이 회원의 민감 작업 재확인에 실제로 고르는 수단과
     * 같은 판정이다. 회사 계정(회원별 판정 수단, 소셜보다 앞 순서)과 소셜을 둘 다 연결한 회원은 「본인 확인 계속하기」가 회사 계정으로
     * 가므로 소셜 안내·버튼 대상이 아니다. 소셜만 연결한 회원은 그대로 대상이다.
     *
     * @scenario member=placeholder_social_only|placeholder_company_and_social, registry=company_reauth_before_social
     *
     * @effects accounts_reauth_via_social_matches_core_choice
     *
     * @return void 반환값 없음
     */
    public function test_reauth_via_social_matches_core_choice_when_company_account_is_linked(): void
    {
        $socialOnly = $this->placeholderMember('k-re7');
        $both = $this->placeholderMember('k-re8');

        // 사이트 적재 순서처럼 회사 계정 재확인 수단을 소셜 재확인 수단보다 앞에 둔다 — 회사 계정은 $both 에게만 쓸 수 있다
        $manager = app(IdentityVerificationManager::class);
        $social = $manager->get(SocialReauthService::PROVIDER_ID);
        $manager->unregister(SocialReauthService::PROVIDER_ID);
        $manager->register(new class(app(IdentityVerificationLogRepositoryInterface::class), (int) $both->id) extends TestIdentityProvider implements UserScopedIdentityProviderInterface
        {
            /**
             * 이력 저장소와 회원 판정 값을 받아 시험 수단을 만듭니다.
             *
             * @param  IdentityVerificationLogRepositoryInterface  $logRepository  본인인증 이력 저장소
             * @param  int  $linkedUserId  회사 계정을 연결한 회원 ID
             */
            public function __construct(IdentityVerificationLogRepositoryInterface $logRepository, private int $linkedUserId)
            {
                parent::__construct($logRepository);
            }

            /**
             * 수단 식별자를 돌려줍니다.
             *
             * @return string 식별자
             */
            public function getId(): string
            {
                return 'test:company_reauth';
            }

            /**
             * 민감 작업 재확인만 지원합니다.
             *
             * @param  string  $purpose  목적
             * @return bool 지원하면 true
             */
            public function supportsPurpose(string $purpose): bool
            {
                return $purpose === 'sensitive_action';
            }

            /**
             * 회사 계정을 연결한 회원에게만 쓸 수 있습니다.
             *
             * @param  User  $user  회원
             * @return bool 쓸 수 있으면 true
             */
            public function canChallengeUser(User $user): bool
            {
                return (int) $user->id === $this->linkedUserId;
            }
        });
        $manager->register($social);

        $this->assertSame('test:company_reauth', $manager->resolveForPurpose('sensitive_action', null, $both)->getId());
        Sanctum::actingAs($both);
        $this->getJson('/api/plugins/sirsoft-social_login/me/accounts')->assertOk()->assertJsonPath('data.reauth_via_social', false);

        $this->assertSame(SocialReauthService::PROVIDER_ID, $manager->resolveForPurpose('sensitive_action', null, $socialOnly)->getId());
        Sanctum::actingAs($socialOnly);
        $this->getJson('/api/plugins/sirsoft-social_login/me/accounts')->assertOk()->assertJsonPath('data.reauth_via_social', true);
    }
}
