<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Signup;

use App\Enums\IdentityVerificationStatus;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\LoginHandoffService;
use Database\Seeders\IdentityPolicySeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginAttempt;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 소셜 가입 흐름 — 콜백에서 가입 대기를 만들고, 가입 모달이 부족한 항목을 채워 코어 가입으로 마친 뒤 로그인 인계를 받는다.
 * 비밀번호 없이 만들어지고, 동의·역할·가입 훅은 코어 가입과 같다.
 *
 * @since 1.0.0
 */
class SignupFlowTest extends PluginTestCase
{
    /**
     * 카카오를 켜고 메일·알림·큐를 막습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Queue::fake();
        Role::firstOrCreate(['identifier' => 'user'], ['name' => ['ko' => '사용자', 'en' => 'User']]);
        $this->enableKakao();
    }

    /**
     * 카카오 콜백까지 마치고 가입 대기 토큰을 꺼냅니다.
     *
     * @param  array<string, mixed>  $profile  카카오 프로필
     * @return array{token: string, step: string} 대기 토큰과 단계
     */
    private function beginSignup(array $profile): array
    {
        $response = $this->kakaoRoundTrip($profile);
        $this->assertSame('/register', $this->redirectPath($response), (string) $response->headers->get('Location'));
        $query = $this->redirectQuery($response);

        return ['token' => $query['social_pending'], 'step' => $query['social_step']];
    }

    /**
     * 가입 대기를 조회합니다.
     *
     * @param  string  $token  대기 토큰
     * @return TestResponse 응답
     */
    private function pending(string $token): TestResponse
    {
        return $this->getJson('/api/plugins/sirsoft-social_login/signup/pending?pending_token='.$token);
    }

    /**
     * 가입 완료를 요청합니다.
     *
     * @param  string  $token  대기 토큰
     * @param  array<string, mixed>  $input  추가 입력
     * @return TestResponse 응답
     */
    private function complete(string $token, array $input = []): TestResponse
    {
        return $this->postJson('/api/plugins/sirsoft-social_login/signup/complete', $input + [
            'pending_token' => $token,
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);
    }

    /**
     * 제공자가 검증한 이메일이면 동의만으로 가입하고, 비밀번호 없는 회원·연결·동의·시도 기록이 남고 로그인 인계를 받는다.
     *
     * @scenario email=verified, policy=default
     *
     * @effects signup_creates_passwordless_user, account_linked_on_signup, consents_recorded, handoff_after_signup, pending_deleted_after_signup, attempt_signup_recorded, confirmed_email_marked_verified
     *
     * @return void 반환값 없음
     */
    public function test_verified_email_signs_up_with_consent_only(): void
    {
        $email = $this->uniqueEmail('signup');
        $signup = $this->beginSignup($this->kakaoProfile('k-s1', $email));
        $this->assertSame('complete', $signup['step']);

        $view = $this->pending($signup['token'])->assertOk();
        $this->assertSame('locked', $view->json('data.email.mode'));
        $this->assertSame($email, $view->json('data.email.value'));
        $this->assertSame([], $view->json('data.missing'));

        $done = $this->complete($signup['token'])->assertOk();
        $handoff = (string) $done->json('data.handoff_url');
        $this->assertStringStartsWith('/login?login_handoff=', $handoff);

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertFalse($user->hasPassword());
        $this->assertNotNull($user->email_verified_at, '제공자가 검증했다고 한 이메일은 인증된 이메일로 기록한다');
        $this->assertSame('카카오닉', $user->name);
        $this->assertSame(1, SocialAccount::query()->where('user_id', $user->id)->where('provider_user_id', 'k-s1')->count());
        $this->assertSame(2, $user->consents()->count());
        $this->assertSame('signup', SocialLoginAttempt::query()->latest('id')->value('outcome'));
        $this->pending($signup['token'])->assertStatus(404)->assertJsonPath('errors.code', 'expired');

        // 가입 완료는 API 응답이라 인계 코드의 묶음 쿠키를 응답에 직접 싣는다(코어 7.2.0 브라우저 묶기). 그 쿠키로 교환하면 로그인된다.
        $bind = $done->getCookie(LoginHandoffService::BIND_COOKIE, false);
        $this->assertNotNull($bind, '가입 완료 응답이 인계 코드의 묶음 쿠키를 싣는다');

        $methods = [];
        HookManager::addAction('core.auth.after_login', function (User $u, array $context) use (&$methods) {
            $methods[] = $context['login_method'] ?? null;
        }, 99);
        config(['g7_settings.core.security.two_factor_auth' => true, 'g7_settings.core.security.two_factor_scope' => 'all']);

        parse_str((string) parse_url($handoff, PHP_URL_QUERY), $q);
        $this->app['auth']->forgetGuards();
        $this->withCredentials()
            ->withUnencryptedCookie(LoginHandoffService::BIND_COOKIE, (string) $bind->getValue())
            ->postJson('/api/auth/login/handoff', ['code' => $q['login_handoff']])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer'); // 가입 직후 로그인은 코어 일반 가입과 같게 2단계 인증 없이
        $this->assertSame(['social:kakao'], $methods);
    }

    /**
     * 제공자의 신규 가입을 끄면 연결 없는 계정은 로그인 화면 오류로 돌아간다.
     *
     * @scenario signup_allowed=off
     *
     * @effects signup_disabled_refused
     *
     * @return void 반환값 없음
     */
    public function test_signup_disabled_provider_refuses(): void
    {
        SocialLoginProvider::query()->where('key', 'kakao')->update(['signup_allowed' => false]);

        $response = $this->kakaoRoundTrip($this->kakaoProfile('k-off', $this->uniqueEmail()));

        $this->assertSame('signup_disabled', $this->redirectQuery($response)['social_error']);
        $this->assertSame('denied', SocialLoginAttempt::query()->latest('id')->value('outcome'));
    }

    /**
     * 사이트가 「일반 회원가입 받기」를 끄면(코어 7.2.0) 소셜 신규 가입도 닫힌다 — 연결 없는 처음 보는 계정은 가입 대기 없이
     * 로그인 화면 오류로 돌아가고 회원이 생기지 않는다.
     *
     * @scenario core_registration=closed, account=new
     *
     * @effects core_registration_closed_refuses_new_social_signup
     *
     * @return void 반환값 없음
     */
    public function test_core_registration_closed_refuses_new_social_signup(): void
    {
        config(['g7_settings.core.security.registration_enabled' => false]);
        $before = User::query()->count();

        $response = $this->kakaoRoundTrip($this->kakaoProfile('k-closed', $this->uniqueEmail()));

        $this->assertSame('/login', $this->redirectPath($response));
        $this->assertSame('registration_closed', $this->redirectQuery($response)['social_error']);
        $this->assertSame('denied', SocialLoginAttempt::query()->latest('id')->value('outcome'));
        $this->assertSame($before, User::query()->count());
    }

    /**
     * 가입 대기를 만든 뒤에 가입이 닫히면 가입 완료도 거절한다 — 대기 화면을 열어 둔 채 운영자가 닫은 경우.
     *
     * @scenario core_registration=closed_after_pending, account=new
     *
     * @effects core_registration_closed_refuses_pending_completion
     *
     * @return void 반환값 없음
     */
    public function test_core_registration_closed_refuses_pending_completion(): void
    {
        $email = $this->uniqueEmail('closed-later');
        $signup = $this->beginSignup($this->kakaoProfile('k-closed-later', $email));

        config(['g7_settings.core.security.registration_enabled' => false]);

        $this->complete($signup['token'])
            ->assertForbidden()
            ->assertJsonPath('message', __('sirsoft-social_login::exceptions.flow.registration_closed'));
        $this->assertFalse(User::query()->where('email', $email)->exists());
        $this->assertFalse(SocialAccount::query()->where('provider_user_id', 'k-closed-later')->exists());
    }

    /**
     * 가입이 닫혀도 이미 있는 회원은 그대로다 — 연결된 회원은 소셜로 로그인하고, 같은 이메일의 기존 회원은 연결 확인 단계로 간다.
     *
     * @scenario core_registration=closed, account=linked|existing_email
     *
     * @effects core_registration_closed_keeps_existing_members
     *
     * @return void 반환값 없음
     */
    public function test_core_registration_closed_keeps_existing_members(): void
    {
        config(['g7_settings.core.security.registration_enabled' => false]);

        $linked = User::factory()->create(['email' => $this->uniqueEmail('closed-linked')]);
        SocialAccount::query()->create(['user_id' => $linked->id, 'provider_key' => 'kakao', 'provider_user_id' => 'k-closed-linked', 'linked_at' => now()]);
        $login = $this->redirectQuery($this->kakaoRoundTrip($this->kakaoProfile('k-closed-linked', $this->uniqueEmail())));
        $this->assertArrayHasKey('login_handoff', $login);

        $existing = User::factory()->create(['email' => $this->uniqueEmail('closed-exist')]);
        $arrive = $this->redirectQuery($this->kakaoRoundTrip($this->kakaoProfile('k-closed-exist', $existing->email)));
        $this->assertSame('existing', $arrive['social_step']);
    }

    /**
     * 이메일 미제공·미검증은 제공자별 정책을 따른다 — 직접 입력, 로그인 거부, 대체 이메일 발급.
     *
     * @scenario email=missing|unverified, email_missing_policy=ask|deny|placeholder, email_trust=flag|trust|verify
     *
     * @effects email_policy_decides_signup_email, unconfirmed_email_not_marked_verified
     *
     * @param  array<string, mixed>  $row  카카오 행 값
     * @param  bool  $hasEmail  제공자가 이메일을 주는지
     * @param  bool  $verified  제공자 검증 표시
     * @param  string  $expect  기대 (input·locked·placeholder·deny)
     * @return void 반환값 없음
     */
    #[DataProvider('emailPolicies')]
    public function test_email_policy_decides_signup_email(array $row, bool $hasEmail, bool $verified, string $expect): void
    {
        SocialLoginProvider::query()->where('key', 'kakao')->update($row);
        $uid = 'k-'.Str::lower(Str::random(6));
        $email = $hasEmail ? $this->uniqueEmail('policy') : null;

        $response = $this->kakaoRoundTrip($this->kakaoProfile($uid, $email, $verified));

        if ($expect === 'deny') {
            $this->assertSame('email_required', $this->redirectQuery($response)['social_error']);
            $this->assertSame('kakao', $this->redirectQuery($response)['provider']);

            return;
        }

        $token = $this->redirectQuery($response)['social_pending'];
        $view = $this->pending($token)->assertOk();
        $this->assertSame($expect, $view->json('data.email.mode'));

        if ($expect === 'placeholder') {
            $placeholder = 'kakao_'.substr(sha1($uid), 0, 12).'@social.invalid';
            $this->assertSame($placeholder, $view->json('data.email.value'));
            $this->complete($token)->assertOk();
            $this->assertNull(User::query()->where('email', $placeholder)->firstOrFail()->email_verified_at, '대체 이메일은 인증된 이메일이 아니다');
        }
        if ($expect === 'locked' && ! $verified) {
            $this->complete($token)->assertOk();
            $this->assertNull(User::query()->where('email', $email)->firstOrFail()->email_verified_at, '검증 표시 없이 신뢰만 한 이메일은 인증으로 기록하지 않는다');
        }
        if ($expect === 'input' && $hasEmail) {
            $this->assertSame($email, $view->json('data.email.value'), '미검증 이메일은 입력란에 채워 두고 인증번호로 확인한다');
            $this->complete($token, ['email' => $email])->assertStatus(422)->assertJsonPath('errors.code', 'email_not_verified');
        }
    }

    /**
     * 이메일 정책 경우.
     *
     * @return array<string, array{0: array<string, mixed>, 1: bool, 2: bool, 3: string}> 경우
     */
    public static function emailPolicies(): array
    {
        return [
            '없음 + 직접 입력' => [['email_missing_policy' => 'ask'], false, false, 'input'],
            '없음 + 거부' => [['email_missing_policy' => 'deny'], false, false, 'deny'],
            '없음 + 대체 이메일' => [['email_missing_policy' => 'placeholder'], false, false, 'placeholder'],
            '미검증 + 검증 표시만 믿음' => [['email_trust' => 'flag', 'email_missing_policy' => 'ask'], true, false, 'input'],
            '미검증 + 제공자 신뢰' => [['email_trust' => 'trust'], true, false, 'locked'],
            '검증됨 + 늘 다시 확인' => [['email_trust' => 'verify', 'email_missing_policy' => 'ask'], true, true, 'input'],
        ];
    }

    /**
     * 본인인증 가입 정책이 켜진 사이트는 소셜 가입 완료도 428 로 본인인증을 요구하고, 확인 토큰을 실으면 통과한다.
     *
     * @scenario identity_policy=signup_before_submit
     *
     * @effects signup_requires_identity_token, signup_passes_with_identity_token
     *
     * @return void 반환값 없음
     */
    public function test_signup_identity_policy_requires_token(): void
    {
        if (! IdentityPolicy::query()->where('key', 'core.auth.signup_before_submit')->exists()) {
            $this->seed(IdentityPolicySeeder::class);
        }
        IdentityPolicy::query()->where('key', 'core.auth.signup_before_submit')->update(['enabled' => true]);

        $email = $this->uniqueEmail('idv');
        $signup = $this->beginSignup($this->kakaoProfile('k-idv', $email));

        $this->complete($signup['token'], ['email' => $email])->assertStatus(428);
        $this->assertFalse(User::query()->where('email', $email)->exists());

        $token = 'idv-'.Str::random(20);
        IdentityVerificationLog::query()->create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'signup',
            'channel' => 'email',
            'user_id' => null,
            'target_hash' => hash('sha256', mb_strtolower($email)),
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => $token,
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->complete($signup['token'], ['email' => $email, 'verification_token' => $token])->assertOk();
        $this->assertTrue(User::query()->where('email', $email)->exists());
    }

    /**
     * 가입 후 본인인증 정책이 켜져 있으면 회원이 인증 대기로 만들어지고 로그인 인계 대신 안내 주소를 받는다.
     *
     * @scenario identity_policy=signup_after_create
     *
     * @effects pending_verification_gets_notice
     *
     * @return void 반환값 없음
     */
    public function test_pending_verification_member_gets_notice_instead_of_handoff(): void
    {
        if (! IdentityPolicy::query()->where('key', 'core.auth.signup_after_create')->exists()) {
            $this->seed(IdentityPolicySeeder::class);
        }
        IdentityPolicy::query()->where('key', 'core.auth.signup_after_create')->update(['enabled' => true]);

        $email = $this->uniqueEmail('pv');
        $signup = $this->beginSignup($this->kakaoProfile('k-pv', $email));

        $done = $this->complete($signup['token'])->assertOk();

        $this->assertSame('/login?social_notice=pending_verification', $done->json('data.redirect_url'));
        $this->assertNull($done->json('data.handoff_url'));
        $this->assertSame(UserStatus::PendingVerification->value, (string) User::query()->where('email', $email)->value('status'));
    }

    /**
     * 가입 데이터 필터·가입 훅이 불리고, 시간당 가입이 기준에 닿으면 급증 훅이 하루 한 번만 발행된다.
     *
     * @scenario surge_threshold=1, filter=nickname
     *
     * @effects registration_filter_applied, after_social_signup_fired, surge_fired_once
     *
     * @return void 반환값 없음
     */
    public function test_filter_hooks_and_surge_notice(): void
    {
        $this->setPluginSettings(['signup_surge_threshold_per_hour' => 1]);
        HookManager::addFilter('sirsoft-social_login.signup.filter_registration_data', function (array $data) {
            $data['nickname'] = '필터닉';

            return $data;
        });
        $signups = 0;
        $surges = [];
        HookManager::addAction('sirsoft-social_login.auth.after_social_signup', function () use (&$signups) {
            $signups++;
        });
        HookManager::addAction('sirsoft-social_login.signup.surge', function (string $key, int $count, int $threshold) use (&$surges) {
            $surges[] = [$key, $count, $threshold];
        });

        foreach (['k-sg1', 'k-sg2'] as $uid) {
            $email = $this->uniqueEmail('surge');
            $this->complete($this->beginSignup($this->kakaoProfile($uid, $email))['token'])->assertOk();
            $this->assertSame('필터닉', User::query()->where('email', $email)->value('nickname'));
        }

        $this->assertSame(2, $signups);
        $this->assertCount(1, $surges);
        $this->assertSame('kakao', $surges[0][0]);
    }

    /**
     * 만료되었거나 모르는 가입 대기는 404 `expired`, 이름이 끝내 없으면 422 다.
     *
     * @scenario pending=expired|missing_name
     *
     * @effects expired_pending_404, name_required_422
     *
     * @return void 반환값 없음
     */
    public function test_expired_pending_and_missing_name(): void
    {
        $this->pending(Str::random(48))->assertStatus(404)->assertJsonPath('errors.code', 'expired');
        $this->complete(Str::random(48))->assertStatus(404);

        $signup = $this->beginSignup($this->kakaoProfile('k-noname', $this->uniqueEmail(), nickname: null));
        $view = $this->pending($signup['token'])->assertOk();
        $this->assertContains('name', $view->json('data.missing'));
        $this->assertTrue($view->json('data.show_profile_fields'));

        $this->complete($signup['token'])->assertStatus(422)->assertJsonPath('errors.code', 'name_required');
        $this->complete($signup['token'], ['name' => '홍길동'])->assertOk();
    }

    /**
     * 가입 대기는 설정한 시간(`pending_signup_ttl_minutes`)이 지나면 사라진다 — 그 전에는 조회되고, 지나면 404 `expired`.
     *
     * @scenario pending=ttl_elapsed
     *
     * @effects pending_expires_after_ttl
     *
     * @return void 반환값 없음
     */
    public function test_pending_expires_after_ttl(): void
    {
        $this->setPluginSettings(['pending_signup_ttl_minutes' => 5]);
        $signup = $this->beginSignup($this->kakaoProfile('k-ttl', $this->uniqueEmail('ttl')));

        $this->travel(4)->minutes();
        $this->pending($signup['token'])->assertOk();

        $this->travel(2)->minutes();
        $this->pending($signup['token'])->assertStatus(404)->assertJsonPath('errors.code', 'expired');
        $this->complete($signup['token'])->assertStatus(404)->assertJsonPath('errors.code', 'expired');
    }

    /**
     * 같은 제공자 계정으로 가입을 다시 시작하면 앞서 만든 가입 대기는 지워진다 — 남은 주소(방문 기록 등)로 앞 대기를 마칠 수 없다.
     * 다른 제공자 계정의 대기는 그대로다.
     *
     * @scenario pending=restarted_same_identity|other_identity
     *
     * @effects restarted_signup_discards_previous_pending
     *
     * @return void 반환값 없음
     */
    public function test_restarting_signup_discards_previous_pending(): void
    {
        $first = $this->beginSignup($this->kakaoProfile('k-restart', $this->uniqueEmail('restart')));
        $other = $this->beginSignup($this->kakaoProfile('k-other', $this->uniqueEmail('other')));
        $second = $this->beginSignup($this->kakaoProfile('k-restart', $this->uniqueEmail('restart')));

        $this->assertNotSame($first['token'], $second['token']);
        $this->pending($first['token'])->assertStatus(404)->assertJsonPath('errors.code', 'expired');
        $this->complete($first['token'])->assertStatus(404);
        $this->pending($second['token'])->assertOk();
        $this->pending($other['token'])->assertOk();
    }

    /**
     * 「연결 안 함」 정책에서 기존 회원과 같은 이메일로 들어오면 그 이메일을 쓰지 않고 다른 이메일을 받는다 — 겹친 이메일로
     * 인증번호를 받으려 하면 422 `email_taken`, 다른 이메일을 확인하면 가입된다(users.email UNIQUE 충돌 없음).
     *
     * @scenario policy=never_link, email=conflict
     *
     * @effects never_link_conflict_email_refused
     *
     * @return void 반환값 없음
     */
    public function test_never_link_conflicting_email_is_refused(): void
    {
        $this->setPluginSettings(['email_conflict_policy' => 'never_link']);
        $member = User::factory()->create(['email' => $this->uniqueEmail('nl-member')]);

        $signup = $this->beginSignup($this->kakaoProfile('k-nl', strtoupper($member->email)));
        $this->assertSame('complete', $signup['step']);
        $view = $this->pending($signup['token'])->assertOk();
        $this->assertSame('input', $view->json('data.email.mode'));
        $this->assertNull($view->json('data.email.value'));

        $this->postJson('/api/plugins/sirsoft-social_login/signup/email/request', ['pending_token' => $signup['token'], 'email' => $member->email])
            ->assertStatus(422)->assertJsonPath('errors.code', 'email_taken');
        $this->complete($signup['token'], ['email' => $member->email])->assertStatus(422);
        $this->assertSame(1, User::query()->where('email', $member->email)->count());
    }
}
