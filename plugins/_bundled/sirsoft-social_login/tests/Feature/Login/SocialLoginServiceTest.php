<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Login;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\User;
use App\Services\Auth\LoginHandoffService;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginAttempt;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;
use Plugins\Sirsoft\SocialLogin\Tests\Support\AuthenticatorAppProvider;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\Support\Concerns\CapturesSentMail;

/**
 * 연결된 회원의 소셜 로그인 — 플러그인은 관리자 차단과 인계 코드 발급까지만 하고, 잠금·상태·2단계 인증·비밀번호 변경·
 * 휴면은 로그인 화면이 코드를 교환할 때 코어가 처리한다. 콜백 → 인계 주소 → 교환 API 까지 이어서 잰다.
 *
 * @since 1.0.0
 */
class SocialLoginServiceTest extends PluginTestCase
{
    use CapturesSentMail;
    use PasswordPolicyFixtures;

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
     * 카카오 계정이 연결된 회원을 만듭니다.
     *
     * @param  User  $user  회원
     * @param  string  $uid  카카오 사용자 ID
     * @return SocialAccount 연결
     */
    private function link(User $user, string $uid): SocialAccount
    {
        return SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider_key' => 'kakao',
            'provider_user_id' => $uid,
            'linked_at' => now(),
        ]);
    }

    /**
     * 콜백을 마치고 인계 코드를 꺼냅니다.
     *
     * @param  string  $uid  카카오 사용자 ID
     * @param  string|null  $email  카카오 이메일
     * @return string 인계 코드
     */
    private function handoffCode(string $uid, ?string $email = null): string
    {
        $response = $this->kakaoRoundTrip($this->kakaoProfile($uid, $email));
        $this->assertSame('/login', $this->redirectPath($response));

        return $this->redirectQuery($response)['login_handoff'];
    }

    /**
     * 연결된 회원은 로그인 인계 주소로 가고, 교환하면 로그인된다 — 마지막 로그인 시각·시도 기록·훅이 남는다.
     *
     * @scenario link=existing, admin=no, two_factor=off
     *
     * @effects handoff_url_issued, exchange_logs_in, last_login_recorded, attempt_handoff_recorded, after_social_login_fired
     *
     * @return void 반환값 없음
     */
    public function test_linked_member_gets_handoff_and_exchange_logs_in(): void
    {
        $user = User::factory()->create(['email' => $this->uniqueEmail()]);
        $account = $this->link($user, 'k-1001');
        $fired = [];
        HookManager::addAction('sirsoft-social_login.auth.after_social_login', function (User $u, SocialAccount $a) use (&$fired) {
            $fired[] = [$u->id, $a->id];
        });

        $response = $this->kakaoRoundTrip($this->kakaoProfile('k-1001', 'other@example.test'), redirect: '/mypage/orders');
        $query = $this->redirectQuery($response);

        $this->assertSame('/login', $this->redirectPath($response));
        $this->assertSame('/mypage/orders', $query['redirect']);
        $this->assertNotNull($account->fresh()->last_login_at);
        $this->assertNotNull($account->fresh()->access_token, '토큰은 연결 행에 암호화해 둔다');
        $this->assertSame([[$user->id, $account->id]], $fired);
        $this->assertSame('handoff', SocialLoginAttempt::query()->latest('id')->value('outcome'));

        $exchange = $this->postJson('/api/auth/login/handoff', ['code' => $query['login_handoff']])->assertOk();
        $this->assertNotEmpty($exchange->json('data.token'));
        $this->assertSame($user->uuid, $exchange->json('data.user.uuid'));
    }

    /**
     * 관리 권한 회원은 기본으로 소셜 로그인이 막히고, 설정으로 허용하면 인계된다.
     *
     * @scenario admin=yes, allow_admin=off|on
     *
     * @effects admin_blocked_by_default, admin_allowed_by_setting
     *
     * @return void 반환값 없음
     */
    public function test_admin_is_blocked_unless_allowed(): void
    {
        $admin = $this->makeAdminUser(['email' => $this->uniqueEmail('admin')]);
        $this->link($admin, 'k-admin');

        $blocked = $this->kakaoRoundTrip($this->kakaoProfile('k-admin'));
        $this->assertSame('admin_blocked', $this->redirectQuery($blocked)['social_error']);
        $this->assertSame('denied', SocialLoginAttempt::query()->latest('id')->value('outcome'));

        $this->setPluginSettings(['allow_admin_social_login' => true]);
        $allowed = $this->kakaoRoundTrip($this->kakaoProfile('k-admin'));
        $this->assertArrayHasKey('login_handoff', $this->redirectQuery($allowed));
    }

    /**
     * 사이트 2단계 인증이 켜져 있으면 소셜 로그인도 교환 때 2단계 인증 단계를 받고, 플러그인 설정으로 끄면 건너뛴다.
     *
     * @scenario two_factor=on, two_factor_method=mail, plugin_two_factor=on|off
     *
     * @effects two_factor_step_on_exchange, two_factor_skipped_by_setting
     *
     * @return void 반환값 없음
     */
    public function test_two_factor_follows_site_and_plugin_setting(): void
    {
        $this->prepareIdentityNotifications();
        $this->setSecurity(['two_factor_auth' => true, 'two_factor_scope' => 'all']);
        $user = User::factory()->create(['email' => $this->uniqueEmail()]);
        $this->link($user, 'k-2fa');

        $step = $this->postJson('/api/auth/login/handoff', ['code' => $this->handoffCode('k-2fa')])->assertOk();
        $this->assertTrue((bool) $step->json('data.two_factor_required'));
        $this->assertNull($step->json('data.token'));

        $this->setPluginSettings(['two_factor_enabled' => false]);
        $code = $this->handoffCode('k-2fa');
        $this->assertTrue(app(LoginHandoffService::class)->consume($code)['context']['skip_two_factor']);
    }

    /**
     * 인증 앱(TOTP 처럼 회원별 등록이 필요한 수단)을 등록한 회원은 소셜 로그인도 교환 때 그 수단의 2단계 인증 단계를 받는다.
     * 메일을 받을 수 없는 대체 이메일 회원도 등록했으면 같다. 등록하지 않은 대체 이메일 회원은 닿지 않는 메일로 인증번호를 보낼 수
     * 없으므로 2단계 인증 없이 로그인한다(코어 로그인과 같은 판정).
     *
     * @scenario two_factor=on, two_factor_method=app, email=placeholder, enrolled=yes|no
     *
     * @effects app_two_factor_step_on_exchange
     *
     * @return void 반환값 없음
     */
    public function test_authenticator_app_member_gets_app_step_on_social_login(): void
    {
        $this->setSecurity(['two_factor_auth' => true, 'two_factor_scope' => 'all']);
        $provider = new AuthenticatorAppProvider(app(IdentityVerificationLogRepositoryInterface::class));
        app(IdentityVerificationManager::class)->register($provider);
        config(['settings.identity.purpose_providers.login' => AuthenticatorAppProvider::ID]);

        $enrolled = User::factory()->passwordless()->create(['email' => 'kakao_'.substr(sha1(uniqid('', true)), 0, 12).'@social.invalid']);
        $provider->enroll($enrolled);
        $this->link($enrolled, 'k-app');

        $step = $this->postJson('/api/auth/login/handoff', ['code' => $this->handoffCode('k-app')])->assertOk();
        $this->assertTrue((bool) $step->json('data.two_factor_required'));
        $this->assertSame(AuthenticatorAppProvider::ID, $step->json('data.provider_id'));
        $this->assertNull($step->json('data.token'));

        $notEnrolled = User::factory()->passwordless()->create(['email' => 'kakao_'.substr(sha1(uniqid('', true)), 0, 12).'@social.invalid']);
        $this->link($notEnrolled, 'k-app-none');

        $login = $this->postJson('/api/auth/login/handoff', ['code' => $this->handoffCode('k-app-none')])->assertOk();
        $this->assertNotEmpty($login->json('data.token'));
    }

    /**
     * 로그인 정책 매트릭스 — 교환 결과가 회원 상태·비밀번호·이메일에 따라 비밀번호 로그인과 같게 갈리는지 확인한다.
     *
     * @scenario status=blocked|locked|dormant, password=expired|none, email=placeholder
     *
     * @effects exchange_follows_core_gates
     *
     * @param  string  $case  경우 이름
     * @return void 반환값 없음
     */
    #[DataProvider('gateCases')]
    public function test_exchange_follows_core_gates(string $case): void
    {
        $attributes = ['email' => $this->uniqueEmail($case)];
        if ($case === 'placeholder_two_factor') {
            $attributes = ['email' => 'kakao_'.substr(sha1(uniqid()), 0, 12).'@social.invalid'];
        }

        $factory = User::factory();
        if (in_array($case, ['passwordless_expired', 'placeholder_two_factor'], true)) {
            $factory = $factory->passwordless();
        }
        $user = $factory->create($attributes);
        $this->link($user, 'k-'.$case);

        match ($case) {
            'blocked' => $user->forceFill(['status' => UserStatus::Blocked->value])->save(),
            'dormant' => $user->forceFill(['status' => UserStatus::Dormant->value])->save(),
            'locked' => app(UserRepositoryInterface::class)->lockAccount($user, 30),
            'password_expired', 'passwordless_expired' => $this->enableExpiry(),
            'placeholder_two_factor' => $this->setSecurity(['two_factor_auth' => true, 'two_factor_scope' => 'all']),
        };
        if ($case === 'password_expired') {
            $user->forceFill(['password_changed_at' => now()->subDays(200)])->save();
        }

        $response = $this->postJson('/api/auth/login/handoff', ['code' => $this->handoffCode('k-'.$case)]);

        match ($case) {
            'blocked', 'locked' => $this->assertNull($response->json('data.token'), $case.' 회원은 토큰을 받지 못한다'),
            'dormant' => $this->assertTrue((bool) $response->assertOk()->json('data.dormancy_restored')),
            'password_expired' => $this->assertTrue((bool) $response->assertOk()->json('data.password_change_required')),
            'passwordless_expired', 'placeholder_two_factor' => $this->assertNotEmpty($response->assertOk()->json('data.token')),
        };
        $this->assertNotSame(500, $response->status());
    }

    /**
     * 교환 결과 경우 목록.
     *
     * @return array<string, array{0: string}> 경우
     */
    public static function gateCases(): array
    {
        return [
            '차단' => ['blocked'],
            '잠김' => ['locked'],
            '휴면' => ['dormant'],
            '비밀번호 만료' => ['password_expired'],
            '비밀번호 없음 + 만료 정책' => ['passwordless_expired'],
            '대체 이메일 + 2단계 인증' => ['placeholder_two_factor'],
        ];
    }
}
