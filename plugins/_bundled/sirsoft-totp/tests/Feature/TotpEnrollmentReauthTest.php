<?php

namespace Plugins\Sirsoft\Totp\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\Totp\Tests\PluginTestCase;

/**
 * 인증 앱 등록 시작이 현재 비밀번호를 다시 확인하는지 검증한다 (KVE-2026-3194).
 *
 * 수정 전: 로그인된 탭만 있으면 비밀번호를 몰라도 인증 앱을 새로 등록하고 백업 코드를 받을 수 있었다.
 */
class TotpEnrollmentReauthTest extends PluginTestCase
{
    private const PASSWORD = 'Passw0rd!totp';

    private User $user;

    /**
     * 비밀번호를 아는 회원으로 로그인하고 시도 기준을 3회로 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'g7_settings.core.security.max_login_attempts' => 3,
            'g7_settings.core.security.login_lockout_time' => 5,
        ]);

        $this->user = User::factory()->create(['email' => 'totp-reauth@test.com', 'password' => Hash::make(self::PASSWORD)]);
        RateLimiter::clear('password-reconfirm:'.$this->user->id);
        Sanctum::actingAs($this->user);
    }

    /**
     * 등록 시작을 요청합니다.
     *
     * @param  array<string, mixed>  $body  요청 본문
     * @return TestResponse 응답
     */
    private function start(array $body): TestResponse
    {
        return $this->postJson('/api/plugins/sirsoft-totp/enrollment', $body);
    }

    /**
     * 현재 비밀번호 없이는 등록을 시작할 수 없다 (수정 전: 200 + 시크릿).
     *
     * @scenario case=enrollment_requires_password
     *
     * @effects enrollment_requires_password
     *
     * @return void 반환값 없음
     */
    public function test_start_without_password_is_rejected(): void
    {
        $response = $this->start([]);

        $response->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->assertNull($response->json('data.secret'));
    }

    /**
     * 틀린 비밀번호는 422, 맞는 비밀번호는 시크릿을 받는다.
     *
     * @scenario case=enrollment_requires_password
     *
     * @effects enrollment_requires_password
     *
     * @return void 반환값 없음
     */
    public function test_wrong_password_rejected_and_right_password_starts(): void
    {
        $wrong = $this->start(['current_password' => 'not-the-password']);
        $wrong->assertStatus(422);
        $this->assertSame(__('sirsoft-totp::messages.errors.password_incorrect'), $wrong->json('message'));
        $this->assertNull($wrong->json('data.secret'));

        $right = $this->start(['current_password' => self::PASSWORD]);
        $right->assertOk();
        $this->assertNotEmpty($right->json('data.secret'));
        $this->assertNotEmpty($right->json('data.otpauth_uri'));
    }

    /**
     * 비밀번호를 기준 횟수만큼 틀리면 다음 시도는 429 이고, 화면이 그동안 시작 버튼을 잠글 수 있게 남은 대기 초를
     * 본문(`errors.retry_after`)에도 `Retry-After` 헤더와 같은 값으로 싣는다.
     *
     * @scenario case=enrollment_password_throttled
     *
     * @effects enrollment_password_throttled, throttled_response_carries_retry_after
     *
     * @return void 반환값 없음
     */
    public function test_wrong_passwords_are_throttled_per_member(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->start(['current_password' => 'wrong-'.$i])->assertStatus(422);
        }

        $response = $this->start(['current_password' => self::PASSWORD])->assertStatus(429);
        $retryAfter = (int) $response->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retryAfter);
        $response->assertJsonPath('errors.retry_after', $retryAfter);
    }

    /**
     * 마이페이지 비밀번호 확인과 같은 제한을 공유한다 — 한쪽에서 틀린 횟수가 다른 쪽에도 쌓인다.
     *
     * @scenario case=enrollment_password_throttled
     *
     * @effects enrollment_password_throttled
     *
     * @return void 반환값 없음
     */
    public function test_throttle_is_shared_with_profile_password_check(): void
    {
        $token = $this->user->createToken('t')->plainTextToken;
        $this->withToken($token)->postJson('/api/me/verify-password', ['password' => 'wrong-a'])->assertStatus(401);
        $this->withToken($token)->postJson('/api/me/verify-password', ['password' => 'wrong-b'])->assertStatus(401);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->user);

        $this->start(['current_password' => 'wrong-c'])->assertStatus(422);
        $this->start(['current_password' => self::PASSWORD])->assertStatus(429);
    }
}
