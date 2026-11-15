<?php

namespace Tests\Feature\Auth;

use App\Extension\HookManager;
use App\Models\User;
use App\Services\UnknownEmailLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 가입되지 않은 주소의 로그인 실패도 가입 계정과 같은 방식으로 세어 같은 423 으로 답한다.
 *
 * 가입 계정만 N 회 실패 뒤 잠금 응답(423)을 받으면, 아무 주소에 틀린 비밀번호를 N+1 번 넣어 보는 것으로
 * 가입 여부를 알 수 있다. 가입되지 않은 주소도 같은 횟수 뒤에 같은 상태·같은 본문 모양으로 답한다.
 * 가입 계정의 잠금 동작은 바뀌지 않는다.
 *
 * @scenario-manifest tests/scenarios/auth-account-enumeration.yaml
 */
class UnknownEmailLockParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 보안 설정을 고정하고 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setSecuritySettings([
            'login_attempt_enabled' => true,
            'max_login_attempts' => 3,
            'login_lockout_time' => 5,
            'auth_token_lifetime' => 30,
        ]);

        Cache::flush();
    }

    /**
     * 시험이 건 필터를 걷어냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter('core.auth.max_login_attempts');
        HookManager::clearFilter('core.auth.lockout_minutes');

        parent::tearDown();
    }

    /**
     * 보안 환경설정 값을 덮어씁니다.
     *
     * @param  array<string, mixed>  $values  덮어쓸 security 설정
     * @return void 반환값 없음
     */
    private function setSecuritySettings(array $values): void
    {
        $existing = (array) config('g7_settings.core.security', []);
        config(['g7_settings.core.security' => array_merge($existing, $values)]);
    }

    /**
     * 틀린 비밀번호로 로그인합니다.
     *
     * @param  string  $email  이메일
     * @return TestResponse 응답
     */
    private function failLogin(string $email): TestResponse
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong-password']);
    }

    /**
     * 응답 본문의 키 구조(값은 무시)를 반환합니다.
     *
     * @param  array<string, mixed>  $body  응답 본문
     * @return array<int, string> 점 표기 키 목록 (정렬됨)
     */
    private function keyShape(array $body): array
    {
        $keys = array_keys(Arr::dot($body));
        sort($keys);

        return $keys;
    }

    /**
     * 가입되지 않은 주소도 임계 횟수 뒤에는 가입 계정과 같은 423·같은 본문 모양·같은 안내를 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=login__unknown
     *
     * @effects unknown_email_locks_like_a_registered_account
     */
    public function test_unknown_email_gets_the_same_423_after_the_same_number_of_failures(): void
    {
        User::factory()->create([
            'email' => 'known@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->failLogin('known@example.com')->assertStatus(401);
            $this->failLogin('nobody@example.com')->assertStatus(401);
        }

        $known = $this->failLogin('known@example.com');
        $unknown = $this->failLogin('NOBODY@example.com');

        $known->assertStatus(423);
        $unknown->assertStatus(423);
        $this->assertSame($this->keyShape($known->json()), $this->keyShape($unknown->json()));
        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame($known->json('errors.permanent'), $unknown->json('errors.permanent'));
        $this->assertNotNull($unknown->json('errors.locked_until'));
        $this->assertSame($known->headers->get('Retry-After'), $unknown->headers->get('Retry-After'));
    }

    /**
     * 잠금 시간이 지나면 가입되지 않은 주소도 다시 401 로 돌아간다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=login__unknown
     *
     * @effects unknown_email_lock_expires_with_the_lockout_time
     */
    public function test_unknown_email_lock_expires_after_the_lockout_time(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->failLogin('nobody@example.com');
        }
        $this->failLogin('nobody@example.com')->assertStatus(423);

        $this->travel(6)->minutes();

        $this->failLogin('nobody@example.com')->assertStatus(401);
    }

    /**
     * 그 주소로 가입이 끝나면 흉내 카운터를 지운다 — 가입 직후 로그인이 막히지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=login__unknown_then_registered
     *
     * @effects unknown_email_counter_cleared_on_register
     */
    public function test_counter_is_cleared_when_the_address_registers(): void
    {
        $service = app(UnknownEmailLockService::class);

        for ($i = 1; $i <= 3; $i++) {
            $this->failLogin('soon@example.com');
        }
        $this->assertNotNull($service->lockState('soon@example.com'));

        $user = User::factory()->create(['email' => 'soon@example.com', 'password' => Hash::make('correct-password'), 'status' => 'active']);
        HookManager::doAction('core.auth.after_register', $user, []);

        $this->assertNull($service->lockState('soon@example.com'));
        $this->assertSame(0, $service->failureCount('soon@example.com'));
    }

    /**
     * 로그인 시도 제한이 꺼져 있으면 흉내 카운터도 쓰지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=login__unknown_limit_disabled
     *
     * @effects unknown_email_counter_off_when_login_limit_disabled
     */
    public function test_no_counter_when_login_attempt_limit_is_disabled(): void
    {
        $this->setSecuritySettings(['login_attempt_enabled' => false]);

        for ($i = 1; $i <= 6; $i++) {
            $this->failLogin('nobody@example.com')->assertStatus(401);
        }

        $this->assertSame(0, app(UnknownEmailLockService::class)->failureCount('nobody@example.com'));
    }

    /**
     * 잠금 시간이 무기한(0)이어도 흉내 카운터는 최대 24시간만 남고, 응답은 가입 계정의 무기한 잠금과 같다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=login__unknown_permanent_setting
     *
     * @effects unknown_email_lock_capped_at_24_hours
     */
    public function test_permanent_setting_is_mimicked_but_capped_at_24_hours(): void
    {
        $this->setSecuritySettings(['login_lockout_time' => 0]);
        User::factory()->create(['email' => 'known@example.com', 'password' => Hash::make('correct-password'), 'status' => 'active']);

        for ($i = 1; $i <= 3; $i++) {
            $this->failLogin('known@example.com');
            $this->failLogin('nobody@example.com');
        }

        $known = $this->failLogin('known@example.com');
        $unknown = $this->failLogin('nobody@example.com');

        $unknown->assertStatus(423);
        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertTrue($unknown->json('errors.permanent'));
        $this->assertNull($unknown->json('errors.locked_until'));
        $this->assertNull($unknown->headers->get('Retry-After'));

        $this->travel(23)->hours();
        $this->failLogin('nobody@example.com')->assertStatus(423);

        $this->travel(2)->hours();
        $this->failLogin('nobody@example.com')->assertStatus(401);
    }

    /**
     * 잠금 기준을 바꾸는 확장 필터는 가입되지 않은 주소 경로에서 부르지 않는다 — 보안 설정값만 쓴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=login__unknown
     *
     * @effects unknown_email_path_ignores_extension_filters
     */
    public function test_extension_filters_are_not_called_for_unknown_email(): void
    {
        $called = 0;
        HookManager::addFilter('core.auth.max_login_attempts', function ($value) use (&$called) {
            $called++;

            return 100;
        });
        HookManager::addFilter('core.auth.lockout_minutes', function ($value) use (&$called) {
            $called++;

            return 100;
        });

        for ($i = 1; $i <= 3; $i++) {
            $this->failLogin('nobody@example.com')->assertStatus(401);
        }

        $this->failLogin('nobody@example.com')->assertStatus(423);
        $this->assertSame(0, $called);
    }

    /**
     * 가입 계정의 잠금 동작은 그대로다 — 가입되지 않은 다른 주소의 실패가 가입 계정을 잠그지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=login__known
     *
     * @effects registered_account_lock_behaviour_unchanged
     */
    public function test_registered_account_is_unaffected_by_unknown_failures(): void
    {
        User::factory()->create(['email' => 'known@example.com', 'password' => Hash::make('correct-password'), 'status' => 'active']);

        for ($i = 1; $i <= 4; $i++) {
            $this->failLogin('nobody@example.com');
        }

        $this->postJson('/api/auth/login', ['email' => 'known@example.com', 'password' => 'correct-password'])
            ->assertStatus(200);
    }
}
