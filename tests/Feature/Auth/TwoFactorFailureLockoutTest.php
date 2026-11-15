<?php

namespace Tests\Feature\Auth;

use App\Enums\IdentityVerificationStatus;
use App\Enums\PasswordChangeSource;
use App\Enums\UserStatus;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\PasswordResetService;
use App\Services\UnknownEmailLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 로그인 2단계 오답이 계정 로그인 실패 횟수에 합산되어 같은 잠금을 받는지 검증한다 (KVE-2026-3195).
 *
 * 수정 전: 2단계 오답은 challenge 시도 횟수만 올리고 계정 실패 횟수에는 쌓이지 않아, 재발송으로 시도 상한을
 * 되돌리며 무한히 맞혀 볼 수 있었다.
 */
class TwoFactorFailureLockoutTest extends TestCase
{
    use CapturesSentMail;
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd!2fa';

    private const CODE = '135790';

    private User $user;

    /**
     * 2단계 인증·잠금 기준을 켜고 회원을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->prepareIdentityNotifications();
        config([
            'g7_settings.core.security.two_factor_auth' => true,
            'g7_settings.core.security.login_attempt_enabled' => true,
            'g7_settings.core.security.max_login_attempts' => 5,
            'g7_settings.core.security.login_lockout_time' => 5,
        ]);

        $this->user = User::factory()->create([
            'email' => 'lockout-2fa@example.test',
            'password' => Hash::make(self::PASSWORD),
            'status' => UserStatus::Active->value,
        ]);
    }

    /**
     * 비밀번호를 통과해 2단계 challenge 를 받고 알려진 코드를 심습니다.
     *
     * @return string 발급된 challenge 의 ID
     */
    private function challenge(): string
    {
        $id = (string) $this->postJson('/api/auth/login', ['email' => $this->user->email, 'password' => self::PASSWORD])->json('data.challenge_id');
        $log = IdentityVerificationLog::findOrFail($id);
        $log->metadata = array_merge($log->metadata ?? [], ['code_hash' => Hash::make(self::CODE)]);
        $log->save();

        return $id;
    }

    /**
     * 2단계 코드를 제출합니다.
     *
     * @param  string  $challengeId  제출할 challenge 의 ID
     * @param  string  $code  제출 코드
     * @return TestResponse 응답
     */
    private function submit(string $challengeId, string $code): TestResponse
    {
        return $this->postJson('/api/auth/login/two-factor', ['challenge_id' => $challengeId, 'code' => $code]);
    }

    /**
     * 2단계 오답이 기준 횟수에 이르면 계정이 잠기고 잠김 응답(423)을 받는다 (수정 전: 잠기지 않음).
     *
     * @effects two_factor_failures_counted, two_factor_lockout_423
     *
     * @return void 반환값 없음
     */
    public function test_wrong_two_factor_codes_lock_account(): void
    {
        $challengeId = $this->challenge();

        for ($i = 1; $i <= 4; $i++) {
            $this->assertNotSame(423, $this->submit($challengeId, sprintf('%06d', $i))->status());
        }
        $this->assertSame(4, (int) $this->user->fresh()->failed_login_attempts);

        $this->submit($challengeId, '000005')->assertStatus(423);
        $this->assertNotNull($this->user->fresh()->locked_until);
    }

    /**
     * 2단계 오답으로 잠긴 계정은 비밀번호를 다시 맞혀도 잠김 응답을 받는다.
     *
     * @effects two_factor_lockout_423
     *
     * @return void 반환값 없음
     */
    public function test_relogin_after_two_factor_lockout_is_blocked(): void
    {
        $challengeId = $this->challenge();
        for ($i = 1; $i <= 5; $i++) {
            $this->submit($challengeId, sprintf('%06d', $i));
        }

        $this->postJson('/api/auth/login', ['email' => $this->user->email, 'password' => self::PASSWORD])->assertStatus(423);
    }

    /**
     * 잠긴 계정의 2단계 코드는 맞는 코드라도 평가하지 않는다 — challenge 가 소모되지 않는다.
     *
     * @effects locked_account_code_not_evaluated
     *
     * @return void 반환값 없음
     */
    public function test_locked_account_code_is_not_evaluated(): void
    {
        $challengeId = $this->challenge();
        $this->user->forceFill(['locked_until' => now()->addMinutes(5)])->save();

        $this->submit($challengeId, self::CODE)->assertStatus(423);

        $log = IdentityVerificationLog::findOrFail($challengeId);
        $this->assertSame(IdentityVerificationStatus::Sent, $log->status);
        $this->assertSame(0, (int) $log->attempts);
    }

    /**
     * 회원 이메일이 바뀌어도 2단계 실패는 그 회원에 쌓이고 미가입 주소 카운터로 새지 않는다.
     *
     * @effects two_factor_failure_targets_member_by_id
     *
     * @return void 반환값 없음
     */
    public function test_failure_is_counted_by_user_id_not_email(): void
    {
        $challengeId = $this->challenge();
        $oldEmail = $this->user->email;
        $this->user->forceFill(['email' => 'renamed-2fa@example.test'])->save();

        $this->submit($challengeId, '000001');

        $this->assertSame(1, (int) $this->user->fresh()->failed_login_attempts);
        $this->assertSame(0, app(UnknownEmailLockService::class)->failureCount($oldEmail));
    }

    /**
     * 2단계 실패는 활동 로그에 「2단계 인증 실패」로 남는다.
     *
     * @effects two_factor_failure_activity_logged
     *
     * @return void 반환값 없음
     */
    public function test_two_factor_failure_is_logged_with_its_own_description(): void
    {
        $challengeId = $this->challenge();

        $this->submit($challengeId, '000001');

        $this->assertDatabaseHas('activity_logs', [
            'description_key' => 'activity_log.description.auth_two_factor_failed',
            'user_id' => $this->user->id,
        ]);
    }

    /**
     * 2단계 오답으로 잠긴 계정도 비밀번호 재설정으로 풀린다 (기존 해제 경로 유지).
     *
     * @effects lockout_released_by_password_reset
     *
     * @return void 반환값 없음
     */
    public function test_password_reset_releases_two_factor_lockout(): void
    {
        $challengeId = $this->challenge();
        for ($i = 1; $i <= 5; $i++) {
            $this->submit($challengeId, sprintf('%06d', $i));
        }
        $this->assertNotNull($this->user->fresh()->locked_until);

        app(PasswordResetService::class)->resetByOperator($this->user->fresh(), 'NewPassw0rd!x', PasswordChangeSource::Reset);

        $fresh = $this->user->fresh();
        $this->assertNull($fresh->locked_until);
        $this->assertSame(0, (int) $fresh->failed_login_attempts);
    }
}
