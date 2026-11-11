<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Listeners\NotificationHookListener;
use App\Models\PasswordResetToken;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Services\AuthService;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 비밀번호 찾기·재설정이 계정 존재 여부를 알려 주지 않고, 요청 횟수를 제한한다.
 *
 * 가입 여부에 따라 응답(상태·필드·문구)이 갈리면 주소 목록을 넣어 가입 계정을 골라낼 수 있고, 제한이
 * 없으면 그것을 자동으로 돌리거나 한 주소에 재설정 메일을 계속 보낼 수 있다.
 *
 * @scenario-manifest tests/scenarios/auth-account-enumeration.yaml
 */
class PasswordResetEnumerationTest extends TestCase
{
    use RefreshDatabase;

    private const HOOK = 'core.auth.after_reset_password_request';

    /**
     * 메일 채널을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 개발 사이트에 저장된 보안 설정 값이 시험에 새지 않게 두 한도를 기본값으로 고정한다.
        config([
            'g7_settings.core.security.password_reset_rate_limit_per_minute' => 10,
            'g7_settings.core.security.register_rate_limit_per_minute' => 10,
        ]);

        $this->enableMailChannelReadiness();
    }

    /**
     * 시험이 건 훅을 걷어냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearAction(self::HOOK);

        parent::tearDown();
    }

    /**
     * 알림 정의를 시드하고 동적 훅을 다시 등록합니다.
     *
     * @return void 반환값 없음
     */
    private function seedNotificationDefinitions(): void
    {
        $this->artisan('db:seed', ['--class' => 'NotificationDefinitionSeeder']);
        app(NotificationHookListener::class)->registerDynamicHooks();
    }

    /**
     * 비밀번호 찾기 API 를 호출합니다.
     *
     * @param  array<string, mixed>  $payload  요청 본문
     * @return TestResponse 응답
     */
    private function forgot(array $payload): TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json'])->postJson('/api/auth/forgot-password', $payload);
    }

    /**
     * 관리자 권한을 가진 회원을 만듭니다.
     *
     * @param  string  $email  이메일
     * @return User 관리자 회원
     */
    private function makeAdmin(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);

        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.dashboard.read'],
            ['name' => ['ko' => '대시보드', 'en' => 'Dashboard'], 'type' => PermissionType::Admin->value],
        );
        $role = Role::create([
            'identifier' => 'reset_admin_'.uniqid(),
            'name' => ['ko' => '관리자', 'en' => 'Admin'],
            'is_active' => true,
        ]);
        $role->permissions()->sync([$permission->id]);
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user->fresh();
    }

    /**
     * 재설정 요청 훅이 받은 재설정 주소를 모읍니다.
     *
     * @return \ArrayObject<int, string> 재설정 주소 목록 (훅이 발화할 때마다 늘어남)
     */
    private function captureResetUrls(): \ArrayObject
    {
        $urls = new \ArrayObject;
        HookManager::addAction(self::HOOK, function (User $user, array $context = []) use ($urls) {
            $urls->append((string) ($context['reset_url'] ?? ''));
        });

        return $urls;
    }

    /**
     * 가입되지 않은 주소도 가입 주소와 같은 200·같은 문구를 받고, 토큰·알림은 만들어지지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=forgot_password__unknown
     *
     * @effects unknown_and_known_account_get_identical_response
     */
    public function test_unknown_email_gets_the_same_response_as_a_registered_one(): void
    {
        $this->seedNotificationDefinitions();
        Notification::fake();
        User::factory()->create(['email' => 'known@example.com']);

        $known = $this->forgot(['email' => 'known@example.com']);
        $unknown = $this->forgot(['email' => 'nobody@example.com']);

        $known->assertStatus(200);
        $unknown->assertStatus(200);
        $this->assertSame($known->json(), $unknown->json());

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'known@example.com']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'nobody@example.com']);
        $this->assertSame(1, PasswordResetToken::count());
    }

    /**
     * 형식이 틀리거나 빈 주소만 422 다(가입 여부와 무관한 형식 오류).
     *
     * @return void 반환값 없음
     *
     * @scenario case=forgot_password__malformed
     *
     * @effects only_format_errors_return_422
     */
    public function test_only_malformed_input_is_rejected(): void
    {
        $this->forgot(['email' => 'a@'])->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->forgot(['email' => ''])->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->forgot(['email' => str_repeat('a', 250).'@example.com'])->assertStatus(422);
    }

    /**
     * 같은 주소로 60초 안에 다시 요청하면 같은 200 을 주되 토큰을 바꾸지 않고 메일을 다시 보내지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=forgot_password__known_within_cooldown
     *
     * @effects repeat_request_within_cooldown_sends_nothing
     */
    public function test_repeat_request_within_the_cooldown_keeps_the_token_and_sends_once(): void
    {
        $this->seedNotificationDefinitions();
        Notification::fake();
        $user = User::factory()->create(['email' => 'known@example.com']);

        $first = $this->forgot(['email' => 'known@example.com']);
        $tokenAfterFirst = PasswordResetToken::where('email', 'known@example.com')->first();

        $this->travel(30)->seconds();
        $second = $this->forgot(['email' => 'KNOWN@example.com']);
        $third = $this->forgot(['email' => 'known@example.com']);

        $second->assertStatus(200);
        $this->assertSame($first->json(), $second->json());
        $this->assertSame($first->json(), $third->json());

        $tokenAfterThird = PasswordResetToken::where('email', 'known@example.com')->first();
        $this->assertSame($tokenAfterFirst->token, $tokenAfterThird->token);
        $this->assertSame(1, PasswordResetToken::count());

        // reset_password 정의는 ['mail', 'database'] 2채널 — 첫 요청 1회분만 발송된다.
        Notification::assertSentToTimes($user, GenericNotification::class, 2);
    }

    /**
     * 60초가 지난 뒤의 요청은 토큰을 갱신하고 메일을 다시 보낸다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=forgot_password__known_after_cooldown
     *
     * @effects request_after_cooldown_renews_token
     */
    public function test_request_after_the_cooldown_renews_the_token(): void
    {
        $this->seedNotificationDefinitions();
        Notification::fake();
        $user = User::factory()->create(['email' => 'known@example.com']);

        $this->forgot(['email' => 'known@example.com']);
        $first = PasswordResetToken::where('email', 'known@example.com')->first()->token;

        $this->travel(61)->seconds();
        $this->forgot(['email' => 'known@example.com'])->assertStatus(200);

        $this->assertNotSame($first, PasswordResetToken::where('email', 'known@example.com')->first()->token);
        Notification::assertSentToTimes($user, GenericNotification::class, 4);
    }

    /**
     * 관리자 화면에서 요청해도 관리자가 아닌 계정에는 일반 재설정 주소를 보낸다(응답은 같다).
     *
     * @return void 반환값 없음
     *
     * @scenario case=forgot_password__non_admin_via_admin_screen
     *
     * @effects non_admin_gets_user_reset_link_from_admin_screen
     */
    public function test_admin_prefix_is_ignored_for_a_non_admin_account(): void
    {
        $urls = $this->captureResetUrls();
        User::factory()->create(['email' => 'member@example.com']);
        $this->makeAdmin('boss@example.com');

        $member = $this->forgot(['email' => 'member@example.com', 'redirect_prefix' => 'admin']);
        $admin = $this->forgot(['email' => 'boss@example.com', 'redirect_prefix' => 'admin']);

        $member->assertStatus(200);
        $this->assertSame($member->json(), $admin->json());

        $this->assertCount(2, $urls);
        $this->assertStringContainsString('/reset-password?', $urls[0]);
        $this->assertStringNotContainsString('/admin/', $urls[0]);
        $this->assertStringContainsString('/admin/reset-password?', $urls[1]);
    }

    /**
     * 재설정·토큰 확인은 가입되지 않은 주소와 토큰이 틀린 가입 주소에 같은 상태·필드·문구로 답한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=reset_and_validate__unknown
     *
     * @effects unknown_and_known_account_get_identical_response
     */
    public function test_reset_and_validate_do_not_distinguish_unknown_from_wrong_token(): void
    {
        User::factory()->create(['email' => 'known@example.com']);
        PasswordResetToken::create([
            'email' => 'known@example.com',
            'token' => Hash::make('the-real-token'),
            'created_at' => now(),
        ]);

        $headers = ['Accept' => 'application/json'];
        $body = fn (string $email) => [
            'token' => 'wrong-token',
            'email' => $email,
            'password' => 'New-Password-123!',
            'password_confirmation' => 'New-Password-123!',
        ];

        $validateKnown = $this->withHeaders($headers)->postJson('/api/auth/validate-reset-token', $body('known@example.com'));
        $validateUnknown = $this->withHeaders($headers)->postJson('/api/auth/validate-reset-token', $body('nobody@example.com'));
        $resetKnown = $this->withHeaders($headers)->postJson('/api/auth/reset-password', $body('known@example.com'));
        $resetUnknown = $this->withHeaders($headers)->postJson('/api/auth/reset-password', $body('nobody@example.com'));

        $validateUnknown->assertStatus(422)->assertJsonValidationErrors(['token'])->assertJsonMissingValidationErrors(['email']);
        $this->assertSame($validateKnown->status(), $validateUnknown->status());
        $this->assertSame($validateKnown->json(), $validateUnknown->json());

        $resetUnknown->assertStatus(422)->assertJsonValidationErrors(['token'])->assertJsonMissingValidationErrors(['email']);
        $this->assertSame($resetKnown->status(), $resetUnknown->status());
        $this->assertSame($resetKnown->json(), $resetUnknown->json());
    }

    /**
     * 재설정·토큰 확인은 가입 주소(토큰 기록 있음)와 가입되지 않은 주소에 같은 횟수의 해시 비교를 한다.
     *
     * 응답 본문이 같아도 해시 비교 횟수가 다르면 응답 시간으로 가입 여부를 가를 수 있다. 재설정 요청은
     * 재사용 금지 검사 앞의 토큰 사전확인과 서비스의 토큰 검증, 두 곳에서 비교한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=reset_and_validate__unknown
     *
     * @effects unknown_and_known_account_cost_the_same_hash_comparisons
     */
    public function test_reset_and_validate_compare_hashes_the_same_number_of_times(): void
    {
        User::factory()->create(['email' => 'known@example.com']);
        User::factory()->create(['email' => 'no-token@example.com']);
        PasswordResetToken::create([
            'email' => 'known@example.com',
            'token' => Hash::make('the-real-token'),
            'created_at' => now(),
        ]);

        // 실제 해시 관리자를 감싸 비교 호출만 센다(나머지 호출은 그대로 넘긴다).
        $counter = new class(app('hash')) implements Hasher
        {
            public int $checks = 0;

            /**
             * 감쌀 실제 해시 관리자를 받습니다.
             *
             * @param  Hasher  $real  실제 해시 관리자
             */
            public function __construct(private readonly Hasher $real) {}

            /**
             * 해시 정보를 그대로 넘깁니다.
             *
             * @param  string  $hashedValue  해시
             * @return array 해시 정보
             */
            public function info($hashedValue)
            {
                return $this->real->info($hashedValue);
            }

            /**
             * 해시 생성을 그대로 넘깁니다.
             *
             * @param  string  $value  원문
             * @param  array  $options  옵션
             * @return string 해시
             */
            public function make($value, array $options = [])
            {
                return $this->real->make($value, $options);
            }

            /**
             * 해시 비교 호출을 세고 그대로 넘깁니다.
             *
             * @param  string  $value  원문
             * @param  string  $hashedValue  해시
             * @param  array  $options  옵션
             * @return bool 일치 여부
             */
            public function check($value, $hashedValue, array $options = [])
            {
                $this->checks++;

                return $this->real->check($value, $hashedValue, $options);
            }

            /**
             * 재해시 필요 판정을 그대로 넘깁니다.
             *
             * @param  string  $hashedValue  해시
             * @param  array  $options  옵션
             * @return bool 재해시 필요 여부
             */
            public function needsRehash($hashedValue, array $options = [])
            {
                return $this->real->needsRehash($hashedValue, $options);
            }

            /**
             * 계약 밖의 호출(해시 관리자 고유 메서드)을 그대로 넘깁니다.
             *
             * @param  string  $method  메서드 이름
             * @param  array  $args  인자
             * @return mixed 실제 관리자의 반환값
             */
            public function __call(string $method, array $args): mixed
            {
                return $this->real->{$method}(...$args);
            }
        };
        Hash::swap($counter);

        $count = function (string $uri, string $email) use ($counter): int {
            $counter->checks = 0;
            $this->withHeaders(['Accept' => 'application/json'])->postJson($uri, [
                'token' => 'wrong-token',
                'email' => $email,
                'password' => 'New-Password-123!',
                'password_confirmation' => 'New-Password-123!',
            ])->assertStatus(422);

            return $counter->checks;
        };

        foreach (['/api/auth/reset-password', '/api/auth/validate-reset-token'] as $uri) {
            $known = $count($uri, 'known@example.com');

            $this->assertGreaterThan(0, $known, $uri);
            $this->assertSame($known, $count($uri, 'nobody@example.com'), $uri.' 가입되지 않은 주소');
            $this->assertSame($known, $count($uri, 'no-token@example.com'), $uri.' 토큰을 받은 적 없는 가입 주소');
        }
    }

    /**
     * 같은 IP 의 비밀번호 찾기는 분당 설정값(기본 10)까지만 받고 그 다음은 429 다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=forgot_password__rate_limited
     *
     * @effects per_ip_rate_limit_returns_429_with_retry_after
     */
    public function test_forgot_password_is_rate_limited_per_ip(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->forgot(['email' => "nobody{$i}@example.com"])->assertStatus(200);
        }

        $blocked = $this->forgot(['email' => 'nobody11@example.com']);

        $blocked->assertStatus(429);
        $this->assertNotNull($blocked->headers->get('Retry-After'));
        $this->assertFalse($blocked->json('success'));
        $this->assertNotSame('Too Many Attempts.', $blocked->json('message'));
    }

    /**
     * 재설정·토큰 확인은 찾기와 따로 센다 — 찾기 한도가 다 차도 재설정·확인은 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=reset_and_validate__rate_limited
     *
     * @effects verify_counter_is_independent_from_forgot_counter
     */
    public function test_reset_and_validate_share_a_counter_separate_from_forgot(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->forgot(['email' => "nobody{$i}@example.com"]);
        }
        $this->forgot(['email' => 'nobody11@example.com'])->assertStatus(429);

        $headers = ['Accept' => 'application/json'];
        $body = ['token' => 'x', 'email' => 'nobody@example.com', 'password' => 'New-Password-123!', 'password_confirmation' => 'New-Password-123!'];

        for ($i = 1; $i <= 5; $i++) {
            $this->withHeaders($headers)->postJson('/api/auth/validate-reset-token', $body)->assertStatus(422);
            $this->withHeaders($headers)->postJson('/api/auth/reset-password', $body)->assertStatus(422);
        }

        // 확인 5회 + 재설정 5회 = 10회 — 같은 카운터라 11번째는 어느 쪽이든 429 다.
        $this->withHeaders($headers)->postJson('/api/auth/validate-reset-token', $body)->assertStatus(429);
        $this->withHeaders($headers)->postJson('/api/auth/reset-password', $body)->assertStatus(429);
    }

    /**
     * 한도는 보안 설정값을 따른다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=forgot_password__rate_limited
     *
     * @effects rate_limit_follows_security_setting
     */
    public function test_rate_limit_follows_the_security_setting(): void
    {
        config(['g7_settings.core.security.password_reset_rate_limit_per_minute' => 3]);

        for ($i = 1; $i <= 3; $i++) {
            $this->forgot(['email' => "nobody{$i}@example.com"])->assertStatus(200);
        }

        $this->forgot(['email' => 'nobody4@example.com'])->assertStatus(429);
    }

    /**
     * 회원가입도 같은 IP 에서 분당 설정값(기본 10)까지만 받는다. 중복 이메일 안내는 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=register__rate_limited
     *
     * @effects per_ip_rate_limit_returns_429_with_retry_after
     */
    public function test_register_is_rate_limited_per_ip_and_keeps_duplicate_email_message(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $headers = ['Accept' => 'application/json'];

        $first = $this->withHeaders($headers)->postJson('/api/auth/register', ['email' => 'taken@example.com']);
        $first->assertStatus(422)->assertJsonValidationErrors(['email']);

        for ($i = 2; $i <= 10; $i++) {
            $this->withHeaders($headers)->postJson('/api/auth/register', ['email' => 'taken@example.com'])->assertStatus(422);
        }

        $this->withHeaders($headers)->postJson('/api/auth/register', ['email' => 'taken@example.com'])->assertStatus(429);
    }

    /**
     * 메일·활동 기록을 일으키는 훅은 응답 뒤에 발화하고, 그때도 요청 정보(IP)를 읽을 수 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=forgot_password__known
     *
     * @effects notification_hook_fires_after_response_with_request_context
     */
    public function test_reset_request_hook_is_deferred_until_after_the_response(): void
    {
        $fired = new \ArrayObject;
        HookManager::addAction(self::HOOK, function (User $user) use ($fired) {
            $fired->append(request()->ip());
        });
        User::factory()->create(['email' => 'known@example.com']);

        // 서비스 호출이 끝난 시점에는 아직 발화하지 않았다.
        app(AuthService::class)->forgotPassword('known@example.com');
        $this->assertCount(0, $fired);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'known@example.com']);

        // 응답 뒤 실행분을 돌리면 발화한다.
        app(DeferredCallbackCollection::class)->invoke();
        $this->assertCount(1, $fired);

        // HTTP 요청에서는 응답 뒤 실행분이 요청 종료 때 돌고, 요청 IP 를 읽는다.
        $this->travel(61)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/auth/forgot-password', ['email' => 'known@example.com'])
            ->assertStatus(200);

        $this->assertCount(2, $fired);
        $this->assertSame('203.0.113.9', $fired[1]);
    }

    /**
     * 응답은 본문 길이를 알리고 프록시 버퍼링을 끈다 — 가입·미가입 모두 같은 머리글이다.
     *
     * 요청을 일찍 끝낼 수 없는 실행 환경에서는 응답 뒤 작업(메일·알림)이 끝날 때까지 웹서버가 응답의
     * 끝을 알리지 않는다. 길이를 알려 두면 받는 쪽은 본문을 다 받은 순간 끝난다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=forgot_password__known
     *
     * @effects response_end_does_not_wait_for_deferred_work
     */
    public function test_response_declares_its_length_so_it_ends_before_deferred_work(): void
    {
        User::factory()->create(['email' => 'length@example.com']);

        foreach (['length@example.com', 'nobody-length@example.com'] as $email) {
            $response = $this->forgot(['email' => $email]);

            $response->assertStatus(200);
            $this->assertSame((string) strlen((string) $response->getContent()), $response->headers->get('Content-Length'));
            $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
        }
    }
}
