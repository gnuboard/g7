<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Totp\Identity\TotpIdentityProvider;
use Plugins\Sirsoft\Totp\Repositories\Contracts\TotpCredentialRepositoryInterface;
use Plugins\Sirsoft\Totp\Repositories\TotpCredentialRepository;
use Plugins\Sirsoft\Totp\Services\TotpEnrollmentService;
use Plugins\Sirsoft\Totp\Support\Totp;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * [case:backend-30] 인증 앱(TOTP)으로 관리자 로그인 2단계 인증을 마치는 경로 테스트.
 *
 * 메일 2FA 경로는 `TwoFactorAuthTest` 가 이미 덮으므로 여기서는 **TOTP 축만** 다룹니다.
 *
 * 가장 중요한 것은 **교차 사용 회귀**입니다. 관리자 판정은 코드 확인 **뒤**에 이뤄지므로
 * (설계 확정 2026-09-07, 2026-09-17 재확정), 사용자 경로에서 받은 challenge 를 관리자
 * 엔드포인트로 완료해도 관리자가 아닌 사람은 403 을 받고 **그 사이 발급된 토큰이 회수**
 * 되어야 합니다. 프로바이더가 늘어나도 이 성질이 유지되는지가 이 테스트의 목적입니다.
 */
class AdminTotpLoginTest extends TestCase
{
    use CapturesSentMail;
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd!2fa';

    /**
     * setUp 직전의 HookManager 필터 스냅샷 — tearDown 에서 복원한다.
     *
     * @var array<string, mixed>|null
     */
    private ?array $filterSnapshot = null;

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->prepareIdentityNotifications();
        $this->seed(RolePermissionSeeder::class);

        config(['g7_settings.core.security.two_factor_auth' => true]);
        config(['g7_settings.core.security.two_factor_scope' => 'all']);

        // 이 테스트는 코어 TestCase 를 상속하므로 플러그인 ServiceProvider 의 Repository
        // 바인딩이 걸려 있지 않다. TotpServiceProvider 와 같은 바인딩을 직접 건다 —
        // 없으면 컨테이너 해석 실패가 로그인 게이트 실패로 위장된다.
        $this->app->bind(TotpCredentialRepositoryInterface::class, TotpCredentialRepository::class);

        $this->registerTotpProvider();
    }

    /**
     * 테스트를 정리합니다 — setUp 이 건 필터를 걷어내 다음 테스트로 새지 않게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if ($this->filterSnapshot !== null) {
            (new \ReflectionClass(HookManager::class))
                ->getProperty('filters')
                ->setValue(null, $this->filterSnapshot);

            $this->filterSnapshot = null;
        }

        parent::tearDown();
    }

    /**
     * TOTP 프로바이더를 코어 IDV 레지스트리에 등록합니다.
     *
     * `PluginManager` 는 `plugins` 테이블의 활성 행을 보고 리스너를 붙이는데, 테스트는
     * `RefreshDatabase` 로 매번 빈 테이블에서 부팅하므로 그 경로가 닫혀 있습니다. 등록하지
     * 않으면 코어가 메일로 폴백해서, 측정 대상인 "앱으로 분기하는가" 가 아니라 "플러그인이
     * 꺼져 있다" 를 측정하게 됩니다.
     *
     * 등록 방식은 `RegisterTotpProviderListener` 와 같은 filter 훅을 씁니다 — 운영 경로와
     * 다른 문을 열면 테스트가 통과해도 실제로는 붙지 않을 수 있습니다.
     *
     * @param  bool  $allowMailFallback  플러그인 설정의 메일 폴백 허용값
     * @return void 반환값 없음
     */
    private function registerTotpProvider(bool $allowMailFallback = true): void
    {
        if ($this->filterSnapshot === null) {
            $this->filterSnapshot = (new \ReflectionClass(HookManager::class))
                ->getProperty('filters')
                ->getValue();
        }

        HookManager::addFilter(
            'core.identity.registered_providers',
            static function (array $providers) use ($allowMailFallback): array {
                $providers[TotpIdentityProvider::PROVIDER_ID] = app(TotpIdentityProvider::class)
                    ->withConfig(['allow_mail_fallback' => $allowMailFallback]);

                return $providers;
            },
            20
        );

        // 운영에서는 install() 이 심는 매핑 — 테스트 DB 에는 없으므로 같은 값을 넣는다.
        config(['settings.identity.purpose_providers.login' => TotpIdentityProvider::PROVIDER_ID]);
    }

    /**
     * 마이그레이션 경로를 반환합니다.
     *
     * TOTP 자격증명 테이블은 번들 플러그인 소유라 코어 경로에 없습니다.
     *
     * @return array<string, mixed> migrate:fresh 옵션
     */
    protected function migrateFreshUsing(): array
    {
        $paths = ['database/migrations'];

        foreach (glob(base_path('modules/_bundled/*/database/migrations'), GLOB_ONLYDIR) as $p) {
            $paths[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $p);
        }

        foreach (glob(base_path('plugins/_bundled/*/database/migrations'), GLOB_ONLYDIR) as $p) {
            $paths[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $p);
        }

        return [
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => false,
            '--path' => $paths,
        ];
    }

    /**
     * 사용자를 만듭니다.
     *
     * @param  string  $email  이메일
     * @param  bool  $admin  관리자 여부
     * @return User 생성된 사용자
     */
    private function makeUser(string $email, bool $admin = false): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'status' => UserStatus::Active->value,
        ]);

        if ($admin) {
            // isAdmin() 은 역할 존재가 아니라 admin 타입 권한 보유로 판정한다.
            $role = Role::where('identifier', 'admin')->firstOrFail();
            $user->roles()->syncWithoutDetaching([$role->id => ['assigned_at' => now(), 'assigned_by' => null]]);
            $user->refresh();
        }

        return $user;
    }

    /**
     * 사용자에게 인증 앱을 등록시키고 시크릿을 돌려줍니다.
     *
     * @param  User  $user  대상 사용자
     * @return string 평문 Base32 시크릿
     */
    private function enroll(User $user): string
    {
        $enrollment = app(TotpEnrollmentService::class);
        $secret = $enrollment->start($user, 'G7 Test')['secret'];
        $enrollment->confirm($user, Totp::codeAt($secret, Totp::timestepAt()));

        return $secret;
    }

    /**
     * 관리자 로그인을 시도합니다.
     *
     * @param  User  $user  대상 사용자
     * @return TestResponse 로그인 응답
     */
    private function adminLogin(User $user)
    {
        return $this->postJson('/api/auth/admin/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);
    }

    /**
     * 사용자 로그인을 시도합니다.
     *
     * @param  User  $user  대상 사용자
     * @return TestResponse 로그인 응답
     */
    private function userLogin(User $user)
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);
    }

    /**
     * 리플레이 방지를 피한 유효 코드를 만듭니다.
     *
     * 등록 확정에 쓴 timestep 은 이미 소진되므로 다음 칸의 코드를 씁니다.
     *
     * @param  string  $secret  평문 시크릿
     * @return string 6자리 코드
     */
    private function freshCode(string $secret): string
    {
        return Totp::codeAt($secret, Totp::timestepAt() + 1);
    }

    /**
     * 인증 앱을 등록한 관리자의 로그인이 메일이 아니라 앱 수단으로 분기하고 안내 문구도 함께 바뀌는지 확인합니다.
     *
     * @scenario two_factor=on, provider=totp, controller=admin, actor=admin
     *
     * @effects admin_totp_challenge_reports_app_provider
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function an_enrolled_admin_gets_an_app_challenge_instead_of_a_mail_one(): void
    {
        $admin = $this->makeUser('totp-admin@test.com', admin: true);
        $this->enroll($admin);

        $response = $this->adminLogin($admin);

        $response->assertStatus(200);
        $this->assertTrue((bool) $response->json('data.two_factor_required'));
        $this->assertSame('totp', $response->json('data.provider_id'), '등록된 관리자인데 인증 앱으로 분기하지 않았습니다.');
        $this->assertNull($response->json('data.token'), '코드 확인 전에 토큰이 발급되면 2단계 인증이 없는 것과 같습니다.');

        // 안내 문구도 수단에 맞아야 한다 — 메일 문구를 쓰면 오지 않는 메일을 기다리게 된다.
        $this->assertSame(__('auth.two_factor_required_app'), $response->json('message'));
    }

    /**
     * 올바른 앱 코드로 관리자 로그인이 완료되고 토큰이 발급되는지 확인합니다.
     *
     * @scenario two_factor=on, provider=totp, controller=admin, code=valid
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_valid_app_code_completes_the_admin_login(): void
    {
        $admin = $this->makeUser('totp-admin@test.com', admin: true);
        $secret = $this->enroll($admin);

        $challengeId = $this->adminLogin($admin)->json('data.challenge_id');

        $response = $this->postJson('/api/auth/admin/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => $this->freshCode($secret),
        ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.token'));
    }

    /**
     * 단말을 잃은 관리자가 백업 코드로 로그인을 마칠 수 있는지 확인합니다.
     *
     * @scenario two_factor=on, provider=totp, controller=admin, code=backup
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_backup_code_completes_the_admin_login(): void
    {
        $admin = $this->makeUser('totp-admin@test.com', admin: true);
        $secret = $this->enroll($admin);

        $backupCodes = app(TotpEnrollmentService::class)
            ->regenerateBackupCodes($admin, $this->freshCode($secret));

        $challengeId = $this->adminLogin($admin)->json('data.challenge_id');

        $response = $this->postJson('/api/auth/admin/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => $backupCodes[0],
        ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.token'), '단말을 잃은 관리자의 유일한 탈출구가 막혔습니다.');
    }

    /**
     * 틀린 코드에 401 단일 응답을 주고 토큰을 발급하지 않는지 확인합니다.
     *
     * @scenario two_factor=on, provider=totp, controller=admin, code=invalid
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_wrong_app_code_issues_no_token(): void
    {
        $admin = $this->makeUser('totp-admin@test.com', admin: true);
        $this->enroll($admin);

        $challengeId = $this->adminLogin($admin)->json('data.challenge_id');

        $response = $this->postJson('/api/auth/admin/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => '000000',
        ]);

        // 실패 사유는 수단과 무관하게 401 단일 응답이다 — 사유를 구분해 주면 어떤 입력이
        // "형식은 맞다" 인지 흘러 나간다 (메일 경로와 같은 계약, TwoFactorAuthTest 참조).
        $response->assertStatus(401);
        $this->assertNull($response->json('data.token'));
        $this->assertSame(0, $admin->tokens()->count());
    }

    /**
     * 사용자 경로 challenge 를 관리자 경로로 완료해도 비관리자는 거절되고 그 사이 발급된 토큰이 회수되는지 확인합니다.
     *
     * @scenario two_factor=on, provider=totp, cross_use=user_challenge_to_admin, actor=non_admin
     *
     * @effects non_admin_cross_use_revokes_token
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_non_admin_cannot_turn_a_user_challenge_into_an_admin_session(): void
    {
        $member = $this->makeUser('totp-member@test.com');
        $secret = $this->enroll($member);

        // 사용자 경로에서 받은 challenge 를 관리자 엔드포인트로 완료해 본다.
        $challengeId = $this->userLogin($member)->json('data.challenge_id');

        $response = $this->postJson('/api/auth/admin/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => $this->freshCode($secret),
        ]);

        $response->assertStatus(403);
        // 관리자 판정은 코드 확인 **뒤**에 이뤄지므로 이 시점에는 토큰이 이미 발급돼 있다 —
        // 회수하지 않으면 관리자가 아닌 사용자가 응답만 403 을 받고 유효한 세션을 손에 쥔다.
        $this->assertSame(0, $member->tokens()->count(), '교차 사용을 거절하면서 발급된 토큰을 회수하지 않았습니다.');
    }

    /**
     * 같은 교차 경로라도 주체가 관리자면 통과하는지 확인합니다 — 거절의 근거는 경로가 아니라 권한입니다.
     *
     * @scenario two_factor=on, provider=totp, cross_use=user_challenge_to_admin, actor=admin
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function an_admin_may_complete_a_user_issued_challenge(): void
    {
        $admin = $this->makeUser('totp-admin@test.com', admin: true);
        $secret = $this->enroll($admin);

        // 같은 교차 경로라도 주체가 관리자면 통과한다 — 거절의 근거는 경로가 아니라 권한이다.
        $challengeId = $this->userLogin($admin)->json('data.challenge_id');

        $response = $this->postJson('/api/auth/admin/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => $this->freshCode($secret),
        ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.token'));
    }

    /**
     * 인증 앱 미등록 관리자가 메일 폴백으로 로그인할 수 있어 콜드스타트 락아웃이 생기지 않는지 확인합니다.
     *
     * @scenario two_factor=on, provider=mail_fallback, actor=admin, enrolled=false
     *
     * @effects unenrolled_admin_falls_back_to_mail
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function an_unenrolled_admin_falls_back_to_mail_instead_of_being_locked_out(): void
    {
        $admin = $this->makeUser('unenrolled-admin@test.com', admin: true);

        $response = $this->adminLogin($admin);

        $response->assertStatus(200);
        $this->assertTrue((bool) $response->json('data.two_factor_required'));
        // 인증 앱을 등록하지 않았다는 이유로 관리자가 로그인하지 못하면 설정을 되돌릴 수단이 사라진다.
        $this->assertSame('g7:core.mail', $response->json('data.provider_id'));
        $this->assertSame(__('auth.two_factor_required'), $response->json('message'));
    }

    /**
     * 운영자가 폴백을 끄면 미등록 관리자의 로그인이 실제로 차단되는지 확인합니다 — 통과시키면 끈 것과 정반대가 됩니다.
     *
     * @scenario two_factor=on, provider=totp, mail_fallback=off, actor=admin, enrolled=false
     *
     * @effects unenrolled_admin_is_blocked_when_fallback_is_off
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function turning_off_the_fallback_blocks_an_unenrolled_admin(): void
    {
        // 운영자가 폴백을 끄면 미등록 관리자는 로그인할 수 없어야 한다. 그냥 통과시키면
        // 끈 결과가 "2단계 인증이 아예 없는 로그인" 이 되어 정반대가 된다 —
        // 구제는 등록이나 관리자 초기화·`totp:reset` artisan 이다.
        $this->registerTotpProvider(allowMailFallback: false);

        $admin = $this->makeUser('unenrolled-admin@test.com', admin: true);

        $response = $this->adminLogin($admin);

        $response->assertStatus(401);
        $this->assertNull(
            $response->json('data.token'),
            '폴백을 껐는데 미등록 관리자가 2단계 인증 없이 로그인했습니다.'
        );
        $this->assertSame(0, $admin->tokens()->count());
    }

    /**
     * 폴백을 꺼도 이미 등록을 마친 관리자의 로그인에는 영향이 없는지 확인합니다.
     *
     * @scenario two_factor=on, provider=totp, mail_fallback=off, actor=admin, enrolled=true
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function turning_off_the_fallback_does_not_affect_an_enrolled_admin(): void
    {
        $this->registerTotpProvider(allowMailFallback: false);

        $admin = $this->makeUser('totp-admin@test.com', admin: true);
        $secret = $this->enroll($admin);

        $challengeId = $this->adminLogin($admin)->json('data.challenge_id');

        $this->postJson('/api/auth/admin/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => $this->freshCode($secret),
        ])->assertStatus(200);
    }

    /**
     * 잠금 전에 받은 앱 challenge 로도 잠긴 관리자가 로그인을 마칠 수 없는지 확인합니다.
     *
     * `TwoFactorAccountLockTest` 가 같은 성질을 **사용자 경로**에서 잠그지만 admin 경로는
     * 덮지 않습니다. 관리자 판정이 코드 확인 **뒤**에 일어나는 구조라 admin 엔드포인트는
     * 잠금 재검사(`assertNotLocked()`)를 독립적으로 통과해야 하고, 수단이 앱으로 바뀌어도
     * 그 성질이 유지되는지는 이 경로에서만 드러납니다.
     *
     * @scenario two_factor=on, provider=totp, controller=admin, actor=admin, locked=true
     *
     * @effects admin_totp_locked_account_cannot_complete
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_locked_admin_cannot_complete_an_app_challenge(): void
    {
        $this->registerTotpProvider();

        config(['g7_settings.core.security.login_attempt_enabled' => true]);
        config(['g7_settings.core.security.max_login_attempts' => 3]);

        $admin = $this->makeUser('totp-admin@test.com', admin: true);
        $secret = $this->enroll($admin);

        // 1. 아직 잠기지 않은 상태에서 앱 challenge 를 확보한다.
        $challengeId = $this->adminLogin($admin)->json('data.challenge_id');
        $this->assertNotEmpty($challengeId, '전제 조건 실패 — 앱 challenge 가 발급되지 않았습니다.');

        // 2. 임계값까지 실패시켜 계정을 잠근다.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/admin/login', [
                'email' => $admin->email,
                'password' => 'wrong-password',
            ]);
        }

        $locked = $admin->fresh();
        $this->assertTrue(
            $locked->locked_until !== null || (bool) $locked->locked_permanently,
            '전제 조건 실패 — 계정이 잠기지 않았습니다.'
        );

        // 3. 통제군 — 정상 로그인 경로는 잠금을 인지해 423 을 돌려준다.
        $this->adminLogin($admin)->assertStatus(423);

        // 4. 잠금 전 앱 challenge 로 완료를 시도해도 같은 판정이어야 한다.
        $response = $this->postJson('/api/auth/admin/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => $this->freshCode($secret),
        ]);

        $response->assertStatus(423);
        $this->assertNull(
            $response->json('data.token'),
            '잠긴 관리자에게 토큰이 발급되면 계정 잠금이 통째로 우회됩니다.'
        );
        $this->assertSame(0, $admin->tokens()->count(), '잠금 우회 시도에 토큰이 남았습니다.');
    }
}
