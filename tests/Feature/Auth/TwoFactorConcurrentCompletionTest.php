<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 같은 로그인 2단계 코드로 세션(토큰)이 두 번 열리지 않는지 검증한다 (KVE 동형 O1·O3).
 *
 * 수정 전에는 메일 challenge 의 상태 검사가 늘 거짓이라, 로그인을 마친 challenge·코드를 다시 내면
 * 새 토큰이 또 발급됐다.
 */
class TwoFactorConcurrentCompletionTest extends TestCase
{
    use CapturesSentMail;
    use RefreshDatabase;

    private const CODE = '135790';

    /**
     * 메일·알림을 준비하고 2단계 인증을 켭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->prepareIdentityNotifications();
        config(['g7_settings.core.security.two_factor_auth' => true]);
    }

    /**
     * 로그인 challenge 를 받고 알려진 코드를 심습니다.
     *
     * @param  User  $user  로그인할 회원
     * @return string 발급된 challenge 의 ID
     */
    private function startChallenge(User $user): string
    {
        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Passw0rd!2fa']);
        $response->assertOk();
        $challengeId = (string) $response->json('data.challenge_id');

        $log = IdentityVerificationLog::findOrFail($challengeId);
        $log->metadata = array_merge($log->metadata ?? [], ['code_hash' => Hash::make(self::CODE)]);
        $log->save();

        return $challengeId;
    }

    /**
     * 로그인을 마친 challenge·코드를 다시 내도 두 번째 토큰은 나오지 않는다.
     *
     * @effects login_code_single_session
     *
     * @return void 반환값 없음
     */
    public function test_same_login_code_opens_only_one_session(): void
    {
        $user = User::factory()->create([
            'email' => 'concurrent-2fa@example.test',
            'password' => Hash::make('Passw0rd!2fa'),
            'status' => UserStatus::Active->value,
        ]);
        $challengeId = $this->startChallenge($user);

        $first = $this->postJson('/api/auth/login/two-factor', ['challenge_id' => $challengeId, 'code' => self::CODE]);
        $second = $this->postJson('/api/auth/login/two-factor', ['challenge_id' => $challengeId, 'code' => self::CODE]);

        $first->assertOk();
        $this->assertNotEmpty($first->json('data.token'));
        $this->assertNull($second->json('data.token'), '이미 쓴 로그인 코드로 두 번째 토큰이 발급됐습니다.');
        $this->assertSame(1, $user->tokens()->count());
    }
}
