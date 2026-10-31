<?php

namespace Tests\Feature\Auth;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\UserStatus;
use App\Exceptions\Auth\AccountLockedException;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\Support\Identity\TestIdentityProvider;
use Tests\TestCase;

/**
 * 외부 인증 로그인 공개 API `AuthService::loginVerifiedUser()` (#40 K1 · K1-b · R2 · R3 · R7 · R10)
 *
 * 비밀번호 대신 다른 수단으로 본인이 확인된 회원이 비밀번호 로그인과 같은 관문(잠금 → 상태 → 관리자 경로 → 2단계 인증 →
 * 비밀번호 변경 → 휴면 해제 + 토큰)을 같은 응답 모양으로 지나는지 잠근다. 로그인 수단 이름은 단계(2단계 인증·비밀번호 변경)를
 * 거쳐도 로그인 완료 훅까지 이어진다.
 */
class LoginVerifiedUserTest extends TestCase
{
    use CapturesSentMail;
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /** 로그인 완료 훅이 받은 컨텍스트 */
    private array $afterLogin = [];

    /**
     * 메일 발송을 막고 본인인증 알림을 준비하며 로그인 완료 훅을 기록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();
        $this->prepareIdentityNotifications();

        $this->afterLogin = [];
        HookManager::addAction('core.auth.after_login', function (User $user, array $context) {
            $this->afterLogin[] = ['user_id' => $user->id, 'login_method' => $context['login_method'] ?? null];
        }, 99);
    }

    /**
     * 서비스를 반환합니다.
     *
     * @return AuthService 인증 서비스
     */
    private function service(): AuthService
    {
        return app(AuthService::class);
    }

    /**
     * 실행마다 겹치지 않는 이메일을 만듭니다.
     *
     * @param  string  $prefix  접두어
     * @param  string  $domain  도메인
     * @return string 이메일
     */
    private function email(string $prefix, string $domain = 'example.test'): string
    {
        return $prefix.'.'.Str::lower(Str::random(8)).'@'.$domain;
    }

    /**
     * 2단계 인증 challenge 에 알려진 코드를 심습니다.
     *
     * @param  string  $challengeId  2단계 인증 식별자
     * @return string 심은 인증번호
     */
    private function plantCode(string $challengeId): string
    {
        $log = IdentityVerificationLog::find($challengeId);
        $metadata = $log->metadata ?? [];
        $metadata['code_hash'] = Hash::make('135790');
        $log->metadata = $metadata;
        $log->save();

        return '135790';
    }

    /**
     * 정상 회원은 바로 토큰을 받고 로그인 완료 훅에 로그인 수단이 실린다.
     *
     * @scenario status=active, two_factor=off, password=set
     *
     * @effects token_issued, after_login_carries_login_method
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 정상_회원은_토큰을_받고_로그인_수단이_기록된다(): void
    {
        $user = $this->makeMember(['email' => $this->email('member')]);

        $result = $this->service()->loginVerifiedUser($user, ['login_method' => 'social:kakao']);

        $this->assertSame('Bearer', $result['token_type']);
        $this->assertNotEmpty($result['token']);
        $this->assertSame([['user_id' => $user->id, 'login_method' => 'social:kakao']], $this->afterLogin);
    }

    /**
     * 비밀번호 로그인의 로그인 완료 훅에는 `password` 가 실린다 (기존 키는 그대로).
     *
     * @scenario method=password
     *
     * @effects after_login_password_method_default
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비밀번호_로그인은_password_로_기록된다(): void
    {
        $user = $this->makeMember(['email' => $this->email('member')]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Passw0rd!old'])->assertOk();

        $this->assertSame('password', $this->afterLogin[0]['login_method'] ?? null);
    }

    /**
     * 잠긴 회원은 외부 인증으로도 들어오지 못한다 (R7).
     *
     * @scenario status=locked
     *
     * @effects locked_user_blocked
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 잠긴_회원은_막힌다(): void
    {
        $this->setSecurity(['login_attempt_enabled' => true]);
        $user = $this->makeMember(['email' => $this->email('locked'), 'locked_until' => now()->addMinutes(10)]);

        $this->expectException(AccountLockedException::class);

        try {
            $this->service()->loginVerifiedUser($user, ['login_method' => 'social:kakao']);
        } finally {
            $this->assertSame([], $this->afterLogin);
            $this->assertSame(0, $user->tokens()->count());
        }
    }

    /**
     * 차단·탈퇴·비활성 회원은 비밀번호 로그인과 같은 코어 문구로 거절된다.
     *
     * @scenario status=blocked|withdrawn|inactive
     *
     * @effects status_rejected_with_core_message
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 로그인을_허용하지_않는_상태는_코어_문구로_거절된다(): void
    {
        $cases = [
            UserStatus::Blocked->value => 'auth.account_blocked',
            UserStatus::Withdrawn->value => 'auth.account_withdrawn',
            UserStatus::Inactive->value => 'auth.account_inactive',
        ];

        foreach ($cases as $status => $key) {
            $user = $this->makeMember(['email' => $this->email($status), 'status' => $status]);

            try {
                $this->service()->loginVerifiedUser($user, ['login_method' => 'social:kakao']);
                $this->fail("{$status} 회원이 로그인됐다");
            } catch (ValidationException $e) {
                $this->assertSame(__($key), $e->errors()['email'][0] ?? null, $status);
            }
        }

        $this->assertSame([], $this->afterLogin);
    }

    /**
     * 휴면 회원은 로그인과 함께 활성으로 돌아오고 응답에 해제 표지가 실린다.
     *
     * @scenario status=dormant
     *
     * @effects dormancy_restored
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 휴면_회원은_해제된다(): void
    {
        $user = $this->makeMember(['email' => $this->email('dormant'), 'status' => UserStatus::Dormant->value, 'dormant_at' => now()->subDays(3)]);

        $result = $this->service()->loginVerifiedUser($user, ['login_method' => 'social:kakao']);

        $this->assertTrue($result['dormancy_restored'] ?? false);
        $this->assertSame(UserStatus::Active->value, $user->fresh()->status);
    }

    /**
     * 2단계 인증이 켜져 있으면 토큰 대신 challenge 를 받고, 코드를 마치면 로그인 수단이 그대로 이어진다.
     *
     * @scenario two_factor=on, provider=mail
     *
     * @effects two_factor_challenge_without_token, login_method_survives_two_factor
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 이단계_인증은_challenge_를_받고_수단이_이어진다(): void
    {
        $this->setSecurity(['two_factor_auth' => true, 'two_factor_scope' => 'all']);
        $user = $this->makeMember(['email' => $this->email('tfa')]);

        $result = $this->service()->loginVerifiedUser($user, ['login_method' => 'social:google']);

        $this->assertTrue($result['two_factor_required'] ?? false);
        $this->assertArrayNotHasKey('token', $result);
        $this->assertSame(0, $user->tokens()->count());

        $done = $this->service()->completeTwoFactor($result['challenge_id'], ['code' => $this->plantCode($result['challenge_id'])]);

        $this->assertNotEmpty($done['token']);
        $this->assertSame('social:google', $this->afterLogin[0]['login_method'] ?? null);
    }

    /**
     * 인증번호를 다시 받아 새 challenge 로 마쳐도 로그인 수단이 이어진다.
     *
     * @scenario two_factor=on, provider=mail, resend=once
     *
     * @effects login_method_survives_two_factor_resend
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 인증번호를_다시_받아도_수단이_이어진다(): void
    {
        $this->setSecurity(['two_factor_auth' => true, 'two_factor_scope' => 'all']);
        $user = $this->makeMember(['email' => $this->email('resend')]);

        $first = $this->service()->loginVerifiedUser($user, ['login_method' => 'social:kakao']);
        $second = $this->service()->resendTwoFactorChallenge($first['challenge_id']);

        $this->assertNotSame($first['challenge_id'], $second['challenge_id']);

        $done = $this->service()->completeTwoFactor($second['challenge_id'], ['code' => $this->plantCode($second['challenge_id'])]);

        $this->assertNotEmpty($done['token']);
        $this->assertSame('social:kakao', $this->afterLogin[0]['login_method'] ?? null);
    }

    /**
     * 로그인 수단으로 다른 프로바이더(인증 앱 등)가 지정돼 있으면 그 수단으로 challenge 를 낸다.
     *
     * @scenario two_factor=on, provider=app
     *
     * @effects two_factor_uses_login_purpose_provider
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 로그인_수단_프로바이더가_지정되면_그_수단으로_요구한다(): void
    {
        $this->setSecurity(['two_factor_auth' => true, 'two_factor_scope' => 'all']);
        app(IdentityVerificationManager::class)->register(new TestIdentityProvider(app(IdentityVerificationLogRepositoryInterface::class)));
        config(['settings.identity.purpose_providers.login' => TestIdentityProvider::ID]);

        $user = $this->makeMember(['email' => $this->email('app')]);

        $result = $this->service()->loginVerifiedUser($user, ['login_method' => 'social:google']);

        $this->assertTrue($result['two_factor_required'] ?? false);
        $this->assertSame(TestIdentityProvider::ID, $result['provider_id']);
    }

    /**
     * 운영자가 그 수단에 2단계 인증을 끄면(`skip_two_factor`) 2단계 인증 단계만 건너뛴다.
     *
     * @scenario two_factor=on, skip_two_factor=true
     *
     * @effects skip_two_factor_issues_token
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 이단계_인증_건너뜀은_바로_토큰을_준다(): void
    {
        $this->setSecurity(['two_factor_auth' => true, 'two_factor_scope' => 'all']);
        $user = $this->makeMember(['email' => $this->email('skip')]);

        $result = $this->service()->loginVerifiedUser($user, ['login_method' => 'social:naver', 'skip_two_factor' => true]);

        $this->assertNotEmpty($result['token']);
    }

    /**
     * 대체 이메일(`.invalid`) 회원은 메일 2단계 인증 대상이 아니라 바로 로그인된다 (R10).
     *
     * @scenario two_factor=on, email=placeholder
     *
     * @effects placeholder_email_skips_mail_two_factor
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 대체_이메일_회원은_메일_2단계를_건너뛴다(): void
    {
        $this->setSecurity(['two_factor_auth' => true, 'two_factor_scope' => 'all']);
        $requested = 0;
        HookManager::addAction('core.auth.two_factor_requested', function () use (&$requested) {
            $requested++;
        }, 10);

        $user = $this->makeMember(['email' => $this->email('kakao_1a2b3c', 'social.invalid'), 'password' => null]);

        $result = $this->service()->loginVerifiedUser($user, ['login_method' => 'social:kakao']);

        $this->assertNotEmpty($result['token']);
        $this->assertSame(0, $requested, '닿지 않는 주소로 인증번호를 보내면 영영 로그인할 수 없다');
    }

    /**
     * 이메일이 아예 없는 회원도 메일 2단계 인증 대상이 아니라 바로 로그인된다 (R10 — 이메일 없음).
     *
     * @scenario two_factor=on, email=missing
     *
     * @effects missing_email_skips_mail_two_factor
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 이메일_없는_회원은_메일_2단계를_건너뛴다(): void
    {
        $this->setSecurity(['two_factor_auth' => true, 'two_factor_scope' => 'all']);
        $requested = 0;
        HookManager::addAction('core.auth.two_factor_requested', function () use (&$requested) {
            $requested++;
        }, 10);

        $user = $this->makeMember(['email' => $this->email('noemail'), 'password' => null]);
        // users.email 은 NOT NULL·UNIQUE 라 저장하지 않고 메모리에서만 비운다 — 판정은 회원 객체의 값을 읽는다.
        $user->setRawAttributes(array_merge($user->getAttributes(), ['email' => '']), true);

        $result = $this->service()->loginVerifiedUser($user, ['login_method' => 'social:naver']);

        $this->assertNotEmpty($result['token']);
        $this->assertSame(0, $requested);
    }

    /**
     * 로그인 수단 이름 없이 부르면 거절한다 — 비밀번호 로그인으로 잘못 기록되는 것을 막는다.
     *
     * @scenario method=missing
     *
     * @effects login_method_required
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 로그인_수단_이름이_없으면_거절한다(): void
    {
        $user = $this->makeMember(['email' => $this->email('nomethod')]);

        foreach ([[], ['login_method' => ''], ['login_method' => 123]] as $context) {
            try {
                $this->service()->loginVerifiedUser($user, $context);
                $this->fail('login_method 없이 로그인되면 안 된다');
            } catch (\InvalidArgumentException) {
                // 기대한 거절
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        app(\App\Services\Auth\LoginHandoffService::class)->issue($user, ['skip_two_factor' => true]);
    }

    /**
     * 비밀번호가 있는 회원의 비밀번호가 만료됐거나 관리자 지정이면 변경 단계를 거치고, 마치면 수단이 이어진다 (R3).
     *
     * @scenario password=set, reason=expired|admin_assigned
     *
     * @effects password_change_gate_applies, login_method_survives_password_change
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비밀번호_만료_회원은_변경_단계를_거친다(): void
    {
        $this->enableExpiry();
        $expired = $this->makeMember(['email' => $this->email('expired'), 'password_changed_at' => now()->subDays(100)]);

        $result = $this->service()->loginVerifiedUser($expired, ['login_method' => 'social:kakao']);

        $this->assertTrue($result['password_change_required'] ?? false);
        $this->assertSame('expired', $result['reason']);

        $done = $this->service()->completePasswordChange($result['challenge_id'], 'Passw0rd!next', 'user');
        $this->assertNotEmpty($done['token']);
        $this->assertSame('social:kakao', $this->afterLogin[0]['login_method'] ?? null);

        $assigned = $this->makeMember(['email' => $this->email('assigned'), 'password_change_required' => true]);
        $result = $this->service()->loginVerifiedUser($assigned, ['login_method' => 'social:kakao']);
        $this->assertSame('admin_assigned', $result['reason'] ?? null);
    }

    /**
     * 비밀번호가 없는 회원은 만료·관리자 지정 표지가 있어도 변경 단계 없이 로그인된다 (R3).
     *
     * @scenario password=none, reason=expired|admin_assigned
     *
     * @effects passwordless_skips_password_change_gate
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비밀번호_없는_회원은_변경_단계가_없다(): void
    {
        $this->enableExpiry();
        $user = $this->makeMember([
            'email' => $this->email('pwless'),
            'password' => null,
            'password_changed_at' => now()->subDays(100),
            'password_change_required' => true,
        ]);

        $result = $this->service()->loginVerifiedUser($user, ['login_method' => 'social:kakao']);

        $this->assertNotEmpty($result['token']);
    }

    /**
     * 관리자 경로에 관리자가 아닌 회원은 세션을 열기 전에 거절된다 (K11).
     *
     * @scenario guard=admin, role=member, status=dormant
     *
     * @effects admin_guard_rejects_before_session
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 관리자_경로의_비관리자는_세션_전에_거절된다(): void
    {
        $user = $this->makeMember(['email' => $this->email('nonadmin'), 'status' => UserStatus::Dormant->value, 'dormant_at' => now()->subDays(3)]);

        try {
            $this->service()->loginVerifiedUser($user, ['login_method' => 'social:kakao'], 'admin');
            $this->fail('관리자가 아닌 회원이 관리자 경로로 로그인됐다');
        } catch (AuthorizationException $e) {
            $this->assertSame(__('auth.admin_required'), $e->getMessage());
        }

        $this->assertSame(UserStatus::Dormant->value, $user->fresh()->status);
        $this->assertSame([], $this->afterLogin);
        $this->assertSame(0, $user->tokens()->count());
    }
}
