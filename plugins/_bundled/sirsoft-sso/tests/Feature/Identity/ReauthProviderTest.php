<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Identity;

use App\Contracts\Extension\UserScopedIdentityProviderInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\Role;
use App\Models\User;
use App\Services\IdentityVerificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Sso\Identity\SsoReauthIdentityProvider;
use Plugins\Sirsoft\Sso\Listeners\RegisterSsoReauthProviderListener;
use Plugins\Sirsoft\Sso\Services\StateStore;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Tests\Support\Identity\TestIdentityProvider;

/**
 * 「회사 계정으로 재확인」 본인인증 수단(W17) — 활성 연결 회원만 대상, IdP 왕복 증명이 있어야 확인이 통과한다.
 *
 * 코어가 받을 메일이 없는 회원에게 이 수단을 자동으로 고르는 회원별 폴백(K17)은 코어 몫이라 여기서는 수단을 지정해 부른다.
 *
 * @since 1.0.0
 */
class ReauthProviderTest extends PluginTestCase
{
    /**
     * 수단을 지정해 재확인 요청을 냅니다 — 요청 브라우저의 묶음 쿠키를 현재 요청에 둔다.
     *
     * @param  User  $user  회원
     * @param  string  $returnPath  복귀 경로
     * @return VerificationChallenge 요청
     */
    private function challenge(User $user, string $returnPath = '/mypage/profile/edit'): VerificationChallenge
    {
        $this->withBrowserBind();
        $this->app->instance('request', Request::create('/api/identity/challenges', 'POST', [], [StateStore::BIND_COOKIE => $this->browserBind]));

        return app(IdentityVerificationService::class)->start('sensitive_action', $user, [
            'origin_type' => 'route',
            'return_path' => $returnPath,
        ], SsoReauthIdentityProvider::PROVIDER_ID);
    }

    /**
     * 재확인 수단이 코어 레지스트리에 등록되고 활성 연결 회원만 대상인지 확인한다.
     *
     * @scenario provider=registered, member=linked|unlinked
     *
     * @effects registered_in_core_registry, eligibility_by_active_link
     *
     * @return void 반환값 없음
     */
    public function test_registered_and_scoped_to_linked_members(): void
    {
        $idp = $this->mockOidcIdp();
        $provider = app(IdentityVerificationManager::class)->get(SsoReauthIdentityProvider::PROVIDER_ID);
        $this->assertInstanceOf(SsoReauthIdentityProvider::class, $provider);
        $this->assertTrue($provider->supportsPurpose('sensitive_action'));
        $this->assertFalse($provider->supportsPurpose('login'));

        $linked = User::factory()->create();
        $this->link($linked, $idp, 'rp-1');
        $this->assertTrue($provider->canChallengeUser($linked));
        $this->assertFalse($provider->canChallengeUser(User::factory()->create()));

        $inactive = User::factory()->create();
        $this->link($inactive, $idp, 'rp-2', ['active' => false, 'deprovision_reason' => 'scim']);
        $this->assertFalse($provider->canChallengeUser($inactive));
    }

    /**
     * IdP 왕복 뒤 코어 확인까지 마치고, 발급된 verification_token 을 화면 쿼리를 보존한 복귀 주소에 싣는지 확인한다.
     *
     * @scenario reauth=roundtrip, return_path=plain|with_screen_query
     *
     * @effects external_redirect_start_url, verify_succeeds_after_roundtrip, return_url_carries_verification_token
     *
     * @return void 반환값 없음
     */
    public function test_roundtrip_returns_verification_token(): void
    {
        $idp = $this->mockOidcIdp();
        $user = User::factory()->create();
        $this->link($user, $idp, 'rp-3');

        foreach (['/mypage/profile/edit' => [], '/admin/settings?reauth=app_key' => ['reauth' => 'app_key']] as $returnPath => $screenQuery) {
            $challenge = $this->challenge($user, $returnPath);
            $this->assertSame('external_redirect', $challenge->renderHint);
            $startUrl = (string) $challenge->publicPayload['redirect_url'];
            $this->assertStringStartsWith('/plugins/sirsoft-sso/login/'.$idp->key.'?ticket=', $startUrl);
            $this->assertSame($startUrl, $challenge->redirectUrl, '코어 ChallengeResource 의 redirect_url 은 DTO redirectUrl 을 읽는다');
            $this->assertSame($startUrl, $challenge->toArray()['redirect_url']);

            $start = $this->withBrowserBind()->get($startUrl);
            $callback = $this->approveAtMock((string) $start->headers->get('Location'), 'full', ['sub' => 'rp-3']);
            $back = $this->withBrowserBind()->get('/plugins/sirsoft-sso/oidc/'.$idp->key.'/callback?'.http_build_query($callback));

            $this->assertSame(parse_url($returnPath, PHP_URL_PATH), $this->redirectPath($back));
            $query = $this->redirectQuery($back);
            $this->assertNotEmpty($query['verification_token'] ?? null);
            $this->assertArrayNotHasKey('sso_reauth', $query);
            foreach ($screenQuery as $key => $value) {
                $this->assertSame($value, $query[$key] ?? null, '복귀 경로의 화면 쿼리를 보존한다');
            }

            $this->assertDatabaseHas('identity_verification_logs', [
                'id' => $challenge->id,
                'status' => 'verified',
                'verification_token' => $query['verification_token'],
            ]);
            $this->assertFalse(app(IdentityVerificationService::class)->verify($challenge->id, [])->success, '이미 확인한 요청은 다시 확인되지 않는다');
        }
    }

    /**
     * 메일을 받을 수 없는 연결 회원이 수단을 비운 재확인 요청을 내면, 회원별 판정이 없는 수단(휴대폰 본인확인처럼 누구의 신원이든
     * 확인해 주는 수단)이 앞에 등록돼 있어도 코어가 「회사 계정으로 재확인」을 고르는지 확인한다. 연결이 없는 회원과 메일을 받는
     * 회원은 종전대로 메일 수단이다.
     *
     * @scenario member=undeliverable_linked|undeliverable_unlinked|deliverable_linked, registry=unscoped_provider_first, entry=resolve|api
     *
     * @effects core_fallback_picks_reauth_over_unscoped, api_challenge_redirects_to_idp, unlinked_or_deliverable_keeps_mail
     *
     * @return void 반환값 없음
     */
    public function test_undeliverable_member_is_sent_to_idp_ahead_of_unscoped_provider(): void
    {
        $idp = $this->mockOidcIdp();
        $manager = app(IdentityVerificationManager::class);
        $manager->register(app(TestIdentityProvider::class));

        $this->seed(RolePermissionSeeder::class);
        $member = User::factory()->create(['email' => Str::lower(Str::random(12)).'@sso.invalid', 'password' => null]);
        $member->roles()->syncWithoutDetaching([Role::query()->where('identifier', 'user')->value('id') => ['assigned_at' => now(), 'assigned_by' => null]]);
        $this->link($member, $idp, 'rp-6');
        $this->assertSame(SsoReauthIdentityProvider::PROVIDER_ID, $manager->resolveForPurpose('sensitive_action', null, $member)->getId());

        $response = $this->withBrowserBind()->actingAs($member)->postJson('/api/identity/challenges', [
            'purpose' => 'sensitive_action',
            'return_path' => '/mypage/profile/edit',
        ]);
        $response->assertCreated()
            ->assertJsonPath('data.provider_id', SsoReauthIdentityProvider::PROVIDER_ID)
            ->assertJsonPath('data.render_hint', 'external_redirect');
        $this->assertStringStartsWith('/plugins/sirsoft-sso/login/'.$idp->key.'?ticket=', (string) $response->json('data.redirect_url'));

        $unlinked = User::factory()->create(['email' => Str::lower(Str::random(12)).'@sso.invalid', 'password' => null]);
        $this->assertSame('g7:core.mail', $manager->resolveForPurpose('sensitive_action', null, $unlinked)->getId());

        $deliverable = User::factory()->create();
        $this->link($deliverable, $idp, 'rp-7');
        $this->assertSame('g7:core.mail', $manager->resolveForPurpose('sensitive_action', null, $deliverable)->getId());
    }

    /**
     * 회사 계정과 소셜 계정이 둘 다 연결된(메일을 받을 수 없는) 회원은 「본인 확인 계속하기」가 회사 계정 다시 로그인으로 가는지
     * 확인한다. 실제 사이트처럼 플러그인 적재 순서(소셜 로그인 → sso)대로 레지스트리 필터를 걸고 — 소셜 재확인 수단은 우선순위 30 —
     * 이 플러그인 리스너가 선언한 우선순위로 sso 수단을 건다.
     *
     * @scenario member=undeliverable_linked_with_social, registry=plugin_load_order
     *
     * @effects reauth_registered_ahead_of_social_reauth
     *
     * @return void 반환값 없음
     */
    public function test_company_account_reauth_is_chosen_ahead_of_social_reauth(): void
    {
        $idp = $this->mockOidcIdp();
        $member = User::factory()->create(['email' => Str::lower(Str::random(12)).'@sso.invalid', 'password' => null]);
        $this->link($member, $idp, 'rp-8');

        $social = new class(app(IdentityVerificationLogRepositoryInterface::class)) extends TestIdentityProvider implements UserScopedIdentityProviderInterface
        {
            /**
             * 소셜 재확인 수단 식별자를 돌려줍니다.
             *
             * @return string 식별자
             */
            public function getId(): string
            {
                return 'sirsoft-social_login';
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
             * 소셜 계정도 연결된 회원이라 쓸 수 있습니다.
             *
             * @param  User  $user  회원
             * @return bool 항상 true
             */
            public function canChallengeUser(User $user): bool
            {
                return true;
            }
        };

        $hook = 'core.identity.registered_providers';
        HookManager::clearFilter($hook);
        HookManager::addFilter($hook, function (array $providers) use ($social): array {
            $providers[$social->getId()] = $social;

            return $providers;
        }, 30);
        $config = RegisterSsoReauthProviderListener::getSubscribedHooks()[$hook];
        HookManager::addFilter($hook, fn (array $providers): array => app(RegisterSsoReauthProviderListener::class)->register($providers), $config['priority']);

        $this->assertSame(SsoReauthIdentityProvider::PROVIDER_ID, app(IdentityVerificationManager::class)->resolveForPurpose('sensitive_action', null, $member)->getId());
    }

    /**
     * 증명 없이 또는 다른 회원 신원으로는 재확인이 통과하지 않는지 확인한다.
     *
     * @scenario reauth=verify_without_roundtrip|other_member_identity
     *
     * @effects verify_fails
     *
     * @return void 반환값 없음
     */
    public function test_verify_without_proof_or_with_other_identity_fails(): void
    {
        $idp = $this->mockOidcIdp();
        $user = User::factory()->create();
        $this->link($user, $idp, 'rp-4');
        $this->link(User::factory()->create(), $idp, 'rp-5');

        $direct = $this->challenge($user);
        $this->assertFalse(app(IdentityVerificationService::class)->verify($direct->id, ['code' => '000000'])->success, '증명 없이 코어 확인 API 로 통과하지 못한다');

        $challenge = $this->challenge($user);
        $start = $this->withBrowserBind()->get((string) $challenge->publicPayload['redirect_url']);
        $callback = $this->approveAtMock((string) $start->headers->get('Location'), 'full', ['sub' => 'rp-5']);
        $back = $this->withBrowserBind()->get('/plugins/sirsoft-sso/oidc/'.$idp->key.'/callback?'.http_build_query($callback));

        $this->assertSame('ticket_invalid', $this->redirectQuery($back)['sso_error']);
        $this->assertFalse(app(IdentityVerificationService::class)->verify($challenge->id, [])->success);
    }
}
