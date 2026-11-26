<?php

namespace Tests\Feature\Auth;

use App\Extension\Auth\CredentialVerifierManager;
use App\Extension\Auth\ExternalCredentialResult;
use App\Extension\HookManager;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\Support\Auth\FakeCredentialVerifier;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 외부 자격 증명 검증자 `core.auth.registered_credential_verifiers`
 *
 * 로그인 칸의 식별자를 담당하는 검증자가 있으면 비밀번호를 G7 이 아니라 그 검증자가 확인한다. 인증 성공은 `loginVerifiedUser()` 로 합류해
 * 비밀번호 로그인과 같은 세 응답, 거절은 일반 실패와 같은 401(실패 횟수는 검증자가 고른다 — 비밀번호 없는 회원도), 추가 단계는 409 + 이동 경로.
 * 잠긴 회원은 디렉터리에 묻지 않고, 사용 불가 검증자는 건너뛰며, 아이디를 받는 검증자가 있으면 로그인 칸의 이메일 형식 검사를 푼다.
 */
class ExternalCredentialVerifierTest extends TestCase
{
    use CapturesSentMail;
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /**
     * 메일을 막고 시도 제한을 켭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();
        $this->prepareIdentityNotifications();
        $this->setSecurity(['login_attempt_enabled' => true, 'max_login_attempts' => 5, 'login_lockout_time' => 5]);
    }

    /**
     * 검증자를 등록합니다.
     *
     * @param  FakeCredentialVerifier  $verifier  검증자
     * @return FakeCredentialVerifier 같은 검증자
     */
    private function register(FakeCredentialVerifier $verifier): FakeCredentialVerifier
    {
        HookManager::addFilter('core.auth.registered_credential_verifiers', function (array $verifiers) use ($verifier) {
            $verifiers[$verifier->getId()] = $verifier;

            return $verifiers;
        });

        return $verifier;
    }

    /**
     * 디렉터리 도메인 회원을 만듭니다 (비밀번호 없음).
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @param  bool  $admin  관리자로 만들지
     * @return User 회원
     */
    private function dirMember(array $attributes = [], bool $admin = false): User
    {
        $email = 'ldap.'.Str::lower(Str::random(8)).'@dir.test';
        $user = $admin ? $this->makeAdmin(array_merge(['email' => $email], $attributes)) : $this->makeMember(array_merge(['email' => $email], $attributes));
        $user->forceFill(['password' => null])->save();

        return $user->fresh();
    }

    /**
     * 담당 검증자가 없으면 지금처럼 비밀번호 로그인이다.
     *
     * @scenario verifier=no_match
     *
     * @effects no_match_falls_back_to_password
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 일치가_없으면_비밀번호_로그인이다(): void
    {
        $verifier = $this->register(new FakeCredentialVerifier('@dir.test', fn () => ExternalCredentialResult::rejected('x')));
        $user = $this->makeMember(['email' => 'pw.'.Str::lower(Str::random(8)).'@example.test']);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Passw0rd!old'])->assertOk();
        $this->assertSame([], $verifier->verifyCalls);
    }

    /**
     * 인증 성공은 비밀번호 로그인과 같은 세 응답(완료 · 2단계 · 휴면 해제)으로 합류한다.
     *
     * @scenario result=authenticated, two_factor=off|on
     *
     * @effects authenticated_joins_login_verified_user
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 인증_성공은_로그인_관문으로_합류한다(): void
    {
        $user = $this->dirMember();
        $this->register(new FakeCredentialVerifier('@dir.test', fn () => ExternalCredentialResult::authenticated($user, ['login_method' => 'directory:test']), $user));

        $done = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'dir-secret'])->assertOk();
        $this->assertNotEmpty($done->json('data.token'));

        $this->setSecurity(['two_factor_auth' => true, 'two_factor_scope' => 'all']);
        $this->app['auth']->forgetGuards();
        $step = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'dir-secret'])->assertOk();
        $this->assertTrue((bool) $step->json('data.two_factor_required'));
    }

    /**
     * 거절은 일반 실패와 같은 401 이고, 실패로 세는 거절은 비밀번호 없는 회원도 세며 장애 거절은 세지 않는다.
     *
     * @scenario result=rejected, counts=true|false, password=none
     *
     * @effects rejected_counts_external_failure, rejected_unavailable_not_counted, rejected_same_message
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 거절은_실패_횟수_규칙을_따른다(): void
    {
        $user = $this->dirMember();
        $counts = true;
        $this->register(new FakeCredentialVerifier('@dir.test', function () use (&$counts) {
            return ExternalCredentialResult::rejected($counts ? 'directory.invalid' : 'directory.unavailable', $counts);
        }, $user));

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'bad'])
            ->assertUnauthorized()
            ->assertJsonPath('message', __('auth.login_failed'));
        $this->assertSame(1, (int) $user->fresh()->failed_login_attempts, '외부 자격 증명 실패는 비밀번호 없는 회원도 센다');

        $counts = false;
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'bad'])->assertUnauthorized();
        $this->assertSame(1, (int) $user->fresh()->failed_login_attempts, '디렉터리 장애는 세지 않는다');
    }

    /**
     * 추가 단계가 필요하면 409 와 이동 경로를 돌려준다.
     *
     * @scenario result=step_required
     *
     * @effects step_required_returns_409
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 추가_단계는_409_와_이동_경로(): void
    {
        $this->register(new FakeCredentialVerifier('@dir.test', fn () => ExternalCredentialResult::stepRequired('/plugins/test/consent?t=1', 'directory.consent')));

        $this->postJson('/api/auth/login', ['email' => 'new.person@dir.test', 'password' => 'dir-secret'])
            ->assertStatus(409)
            ->assertJsonPath('errors.code', 'auth.external_step_required')
            ->assertJsonPath('data.redirect_url', '/plugins/test/consent?t=1');
    }

    /**
     * 잠긴 회원은 디렉터리에 비밀번호를 보내지 않고 423 이다.
     *
     * @scenario user=locked
     *
     * @effects locked_user_not_verified
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 잠긴_회원은_검증하지_않는다(): void
    {
        $user = $this->dirMember(['locked_until' => now()->addMinutes(5)]);
        $verifier = $this->register(new FakeCredentialVerifier('@dir.test', fn () => ExternalCredentialResult::authenticated($user, ['login_method' => 'directory:test']), $user));

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'dir-secret'])->assertStatus(423);
        $this->assertSame([], $verifier->verifyCalls);
    }

    /**
     * 관리자 경로도 같은 검증자로 로그인하고, 비관리자는 403 이다.
     *
     * @scenario guard=admin
     *
     * @effects admin_guard_uses_verifier
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 관리자_경로도_검증자를_쓴다(): void
    {
        $admin = $this->dirMember([], true);
        $verifier = $this->register(new FakeCredentialVerifier('@dir.test', fn ($m, $p, $guard) => ExternalCredentialResult::authenticated($admin, ['login_method' => 'directory:test']), $admin));

        $this->postJson('/api/auth/admin/login', ['email' => $admin->email, 'password' => 'dir-secret'])->assertOk();
        $this->assertSame('admin', $verifier->verifyCalls[0][1]);
    }

    /**
     * 아이디를 받는 검증자가 있으면 로그인 칸의 이메일 형식 검사를 풀고, 사용 불가 검증자는 건너뛴다.
     *
     * @scenario rule=username_accepted|unavailable_skipped
     *
     * @effects login_rule_relaxed_for_usernames, unavailable_verifier_skipped
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 아이디_규칙과_사용_불가_검증자(): void
    {
        $rules = fn () => (new LoginRequest)->rules();
        $this->assertTrue(Validator::make(['email' => 'hong', 'password' => 'x'], $rules())->fails(), '검증자가 없으면 이메일 형식');

        $this->register(new FakeCredentialVerifier('@off.test', fn () => ExternalCredentialResult::rejected('x'), null, available: false, usernames: true));
        $this->assertFalse(app(CredentialVerifierManager::class)->acceptsUsernames('user'), '사용 불가 검증자는 세지 않는다');
        $this->assertNull(app(CredentialVerifierManager::class)->match('dir:hong', 'user'));

        $this->register(new FakeCredentialVerifier('@dir.test', fn () => ExternalCredentialResult::rejected('x'), null, usernames: true));
        $this->assertTrue(Validator::make(['email' => 'hong', 'password' => 'x'], $rules())->passes(), '아이디를 받는다');
    }
}
