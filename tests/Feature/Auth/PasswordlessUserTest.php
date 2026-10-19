<?php

namespace Tests\Feature\Auth;

use App\Contracts\Repositories\PasswordResetTokenRepositoryInterface;
use App\Extension\HookManager;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 비밀번호 없는 회원(외부 인증 전용) 테스트 (#40 K3·K5·K8)
 *
 * 소셜 로그인처럼 외부 인증으로만 가입한 회원은 비밀번호가 NULL 이다. 비밀번호 로그인은 반드시 실패하고,
 * 틀린 비밀번호 시도는 세지 않아 남이 잠글 수 없으며, 비밀번호 재설정 메일로 처음 비밀번호를 정할 수 있다.
 */
class PasswordlessUserTest extends TestCase
{
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /**
     * 잠금 설정을 켜고 메일 발송을 막습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();

        $this->setSecurity([
            'login_attempt_enabled' => true,
            'max_login_attempts' => 3,
            'login_lockout_time' => 5,
        ]);
    }

    /**
     * 실행마다 겹치지 않는 이메일을 만듭니다.
     *
     * @param  string  $prefix  접두어
     * @return string 이메일
     */
    private function uniqueEmail(string $prefix): string
    {
        return $prefix.'.'.Str::lower(Str::random(8)).'@example.test';
    }

    /**
     * 비밀번호 없는 회원을 만들 수 있고 비밀번호 열은 NULL 로 저장된다.
     *
     * @scenario password=none
     *
     * @effects passwordless_row_stored_null
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비밀번호_없는_회원을_만들_수_있다(): void
    {
        $user = User::factory()->passwordless()->create(['email' => $this->uniqueEmail('pwless')]);

        $this->assertNull($user->fresh()->getRawOriginal('password'));
        $this->assertFalse($user->fresh()->hasPassword());
    }

    /**
     * 비밀번호 없는 회원은 어떤 비밀번호로도 로그인할 수 없고, 틀린 시도를 세지 않아 잠기지 않는다.
     *
     * @scenario password=none, attempts=over_threshold
     *
     * @effects password_login_rejected, failed_attempts_not_incremented, account_not_locked
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비밀번호_없는_회원은_오입력으로_잠기지_않는다(): void
    {
        $user = User::factory()->passwordless()->create(['email' => $this->uniqueEmail('pwless')]);

        foreach (['', 'anything', 'password'] as $i => $guess) {
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $guess ?: 'x'])->assertStatus(401);
        }
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'again'])->assertStatus(401);

        $fresh = $user->fresh();
        $this->assertSame(0, (int) $fresh->failed_login_attempts, '비밀번호 없는 회원의 오입력을 세면 남이 계정을 잠근다');
        $this->assertNull($fresh->locked_until);
        $this->assertFalse((bool) $fresh->locked_permanently);
    }

    /**
     * 비밀번호가 있는 회원의 오입력은 종전대로 세어 잠긴다.
     *
     * @scenario password=set, attempts=over_threshold
     *
     * @effects password_holder_locked
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비밀번호_있는_회원_오입력은_종전대로_잠긴다(): void
    {
        $user = User::factory()->create([
            'email' => $this->uniqueEmail('pwset'),
            'password' => Hash::make('Correct!pass1'),
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(401);
        }

        $this->assertNotNull($user->fresh()->locked_until);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Correct!pass1'])->assertStatus(423);
    }

    /**
     * 확장이 비밀번호 없이·토큰 없이 가입시키면 NULL 로 저장되고 토큰을 만들지 않으며 가입 훅은 그대로 발화한다.
     *
     * @scenario password=none, issue_token=false
     *
     * @effects register_stores_null_password, register_skips_token, after_register_fires, change_date_not_recorded
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 확장_가입은_비밀번호와_토큰_없이_만든다(): void
    {
        $fired = [];
        HookManager::addAction('core.auth.after_register', function (User $user, array $context) use (&$fired) {
            $fired[] = $context['registration_data'] ?? null;
        }, 10);

        $email = $this->uniqueEmail('social');
        $result = app(AuthService::class)->register([
            'name' => '소셜 회원',
            'email' => $email,
            'agree_terms' => true,
            'agree_privacy' => true,
        ], ['issue_token' => false]);

        $this->assertNull($result['token']);
        $user = User::where('email', $email)->firstOrFail();
        $this->assertNull($user->getRawOriginal('password'));
        $this->assertNull($user->password_changed_at, '비밀번호가 없으면 만료 기준일을 남기지 않는다');
        $this->assertSame(0, $user->tokens()->count());
        $this->assertCount(1, $fired);
        $this->assertArrayNotHasKey('password', $fired[0]);
    }

    /**
     * 비밀번호 재설정 메일 경로로 비밀번호를 처음 정하면 그 비밀번호로 로그인된다.
     *
     * @scenario password=none, path=reset_mail
     *
     * @effects first_password_set_via_reset, password_login_succeeds_after_set
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 재설정_메일로_비밀번호를_처음_정할_수_있다(): void
    {
        $user = User::factory()->passwordless()->create(['email' => $this->uniqueEmail('pwless')]);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        app(PasswordResetTokenRepositoryInterface::class)->updateOrCreateByEmail($user->email, [
            'token' => Hash::make('known-token'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/auth/reset-password', [
            'token' => 'known-token',
            'email' => $user->email,
            'password' => 'First!Pass123',
            'password_confirmation' => 'First!Pass123',
        ])->assertOk();

        $this->assertTrue($user->fresh()->hasPassword());
        $this->assertNotNull($user->fresh()->password_changed_at);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'First!Pass123'])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer');
    }

    /**
     * 회원 응답 4종에 비밀번호 설정 여부만 실린다 (해시 값은 싣지 않는다).
     *
     * @scenario password=none|set, resource=toArray|toAuthArray|toProfileArray|withAdminInfo
     *
     * @effects has_password_exposed_without_hash
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 회원_응답에_비밀번호_설정_여부가_실린다(): void
    {
        $passwordless = User::factory()->passwordless()->create(['email' => $this->uniqueEmail('pwless')]);
        $withPassword = User::factory()->create(['email' => $this->uniqueEmail('pwset')]);

        $this->actingAs($passwordless);

        foreach ([[$passwordless, false], [$withPassword, true]] as [$user, $expected]) {
            $resource = new UserResource($user->fresh());
            $arrays = [
                'toArray' => $resource->resolve(request()),
                'toAuthArray' => $resource->toAuthArray(request()),
                'toProfileArray' => $resource->toProfileArray(),
                'withAdminInfo' => $resource->withAdminInfo(),
            ];

            foreach ($arrays as $name => $array) {
                $this->assertArrayHasKey('has_password', $array, $name);
                $this->assertSame($expected, $array['has_password'], $name);
                $this->assertArrayNotHasKey('password', $array, $name);
            }
        }

        $status = (new UserResource($passwordless->fresh()))->passwordStatusArray();
        $this->assertFalse($status['has_password']);
        $this->assertFalse($status['applies']);
        $this->assertNull($status['expires_at']);
        $this->assertFalse($status['change_required']);
    }
}
