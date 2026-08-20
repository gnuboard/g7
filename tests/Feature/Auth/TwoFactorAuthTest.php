<?php

namespace Tests\Feature\Auth;

use App\Enums\IdentityVerificationPurpose;
use App\Enums\UserStatus;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\IdentityVerificationService;
use Database\Seeders\IdentityMessageDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 로그인 2단계 인증 테스트
 *
 * `security.two_factor_auth` 는 설정 화면에만 있고 구현이 없어, 켜도 아무 일도 일어나지
 * 않았습니다. 관리자는 2단계 인증이 걸린 줄 알지만 실제로는 비밀번호 하나로 로그인됩니다.
 *
 * 코어 본인인증(IDV) 인프라를 재사용하며, 이 흐름의 불변조건은 하나입니다 —
 * **코드 확인을 마치기 전에는 토큰이 발급되지 않는다.**
 */
class TwoFactorAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // 인증번호 메일을 구성하려면 본인인증 메시지 정의가 있어야 한다. 실제 설치에는 있고
        // RefreshDatabase 직후에는 비어 있으므로, 없는 상태를 그대로 두면 "코드 발송 실패" 가 되어
        // 게이트 동작이 아니라 시드 부재를 측정하게 된다.
        $this->seed(IdentityMessageDefinitionSeeder::class);

        $this->user = User::factory()->create([
            'email' => 'two-factor@test.com',
            'password' => Hash::make('Passw0rd!2fa'),
            'status' => UserStatus::Active->value,
        ]);
    }

    /**
     * 2단계 인증 설정을 켜거나 끕니다.
     *
     * @param  bool  $enabled  활성화 여부
     */
    private function setTwoFactor(bool $enabled): void
    {
        config(['g7_settings.core.security.two_factor_auth' => $enabled]);
    }

    /**
     * 로그인을 시도합니다.
     *
     * @return TestResponse 로그인 응답
     */
    private function login()
    {
        return $this->postJson('/api/auth/login', [
            'email' => 'two-factor@test.com',
            'password' => 'Passw0rd!2fa',
        ]);
    }

    #[Test]
    public function login_issues_a_token_directly_when_two_factor_is_off(): void
    {
        $this->setTwoFactor(false);

        $response = $this->login();

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.token'), '2단계 인증이 꺼져 있으면 종전대로 바로 로그인되어야 합니다.');
    }

    #[Test]
    public function login_withholds_the_token_when_two_factor_is_on(): void
    {
        $this->setTwoFactor(true);

        $response = $this->login();

        $response->assertStatus(200);

        $this->assertTrue(
            (bool) $response->json('data.two_factor_required'),
            '2단계 인증이 켜져 있는데 추가 확인 없이 응답했습니다.'
        );
        $this->assertNull(
            $response->json('data.token'),
            '코드 확인 전에 토큰이 발급되면 2단계 인증이 없는 것과 같습니다.'
        );
        $this->assertNotEmpty($response->json('data.challenge_id'));
    }

    #[Test]
    public function verifying_the_challenge_completes_the_login(): void
    {
        $this->setTwoFactor(true);

        $challengeId = $this->login()->json('data.challenge_id');

        $response = $this->postJson('/api/auth/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => $this->issuedCode($challengeId),
        ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.token'), '코드 확인에 성공했는데 토큰이 발급되지 않았습니다.');
        $this->assertSame('two-factor@test.com', $response->json('data.user.email'));
    }

    #[Test]
    public function a_wrong_code_does_not_issue_a_token(): void
    {
        $this->setTwoFactor(true);

        $challengeId = $this->login()->json('data.challenge_id');

        $response = $this->postJson('/api/auth/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => '000000',
        ]);

        $response->assertStatus(401);
        $this->assertNull($response->json('data.token'));
    }

    #[Test]
    public function a_challenge_issued_for_another_purpose_cannot_be_used_to_log_in(): void
    {
        $this->setTwoFactor(true);

        // 가입·비밀번호 재설정 등 다른 용도로 발급된 challenge 를 들고 와 로그인할 수 있으면,
        // 그 흐름 하나만 통과해도 남의 계정에 들어갈 수 있다.
        $foreign = app(IdentityVerificationService::class)->start(
            IdentityVerificationPurpose::PasswordReset->value,
            $this->user,
            ['origin_type' => 'route', 'origin_identifier' => 'test']
        );

        $response = $this->postJson('/api/auth/login/two-factor', [
            'challenge_id' => $foreign->id,
            'code' => $this->issuedCode($foreign->id),
        ]);

        $response->assertStatus(401);
        $this->assertNull($response->json('data.token'));
    }

    #[Test]
    public function login_fails_clearly_when_the_code_cannot_be_delivered(): void
    {
        $this->setTwoFactor(true);

        // 메일 발송이 실패하면 프로바이더가 challenge 를 failed 로 표시한다. 그 상태를 무시하면
        // 사용자는 "인증번호를 보냈습니다" 안내를 받고 완료할 수 없는 challenge 를 들고 막힌다.
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP unavailable'));
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP unavailable'));

        $response = $this->login();

        $response->assertStatus(401);
        $this->assertNull(
            $response->json('data.token'),
            '발송 실패 시 2단계 인증을 건너뛰고 로그인시키면 보안 통제가 조용히 열립니다.'
        );
    }

    /**
     * challenge 에 알려진 인증 코드를 심고 그 값을 돌려줍니다.
     *
     * 발급된 코드는 해시로만 저장되므로 되읽을 수 없습니다. 기존 IDV 테스트와 같은 방식으로
     * 알려진 값의 해시를 심어 "코드가 맞을 때" 를 재현합니다 — 검증 대상은 코드 생성이 아니라
     * 로그인 게이트(코드 확인 전 토큰 미발급 / 확인 후 발급)입니다.
     *
     * @param  string  $challengeId  challenge UUID
     * @return string 심어 둔 인증 코드
     */
    private function issuedCode(string $challengeId): string
    {
        $code = '135790';

        $log = IdentityVerificationLog::find($challengeId);
        $metadata = $log->metadata ?? [];
        $metadata['code_hash'] = Hash::make($code);

        $log->metadata = $metadata;
        $log->save();

        return $code;
    }
}
