<?php

namespace Tests\Feature\Identity;

use App\Enums\IdentityVerificationPurpose;
use App\Enums\UserStatus;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\IdentityVerificationService;
use Database\Seeders\IdentityMessageDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 공개 본인인증 엔드포인트의 로그인 목적 게이트 테스트.
 *
 * 로그인 2단계 인증 challenge 는 `auth/login/two-factor` 로만 완료된다. 공개 본인인증
 * 화면(`identity/challenges/{id}/verify` · `/cancel`)이 같은 challenge 를 소비하면,
 * 그 뒤의 로그인 완료가 "이미 처리된 요청" 으로 거절되어 사용자가 자기 challenge 를
 * 스스로 못 쓰게 만든다(자기 DoS). 그 화면은 로그인 흐름을 모르므로 되돌릴 방법도 없다.
 */
class IdentityLoginPurposeGateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // 공개 IDV 라우트는 permission 미들웨어가 가드한다 — guest 역할 권한이 없으면
        // 원하는 게이트에 도달하기 전에 401 로 막혀 검사 자체가 공허해진다.
        $this->seed(RolePermissionSeeder::class);
        $this->seed(IdentityMessageDefinitionSeeder::class);

        $this->user = User::factory()->create([
            'email' => 'purpose-gate@test.com',
            'password' => Hash::make('Passw0rd!2fa'),
            'status' => UserStatus::Active->value,
        ]);

        config(['g7_settings.core.security.two_factor_auth' => true]);
    }

    /**
     * @scenario two_factor=on, controller=user, code=consumed_by_public_verify
     *
     * @effects public_verify_rejects_login_purpose
     */
    #[Test]
    public function the_public_verify_endpoint_refuses_a_login_challenge(): void
    {
        $challengeId = $this->startLoginChallenge();

        $response = $this->postJson("/api/identity/challenges/{$challengeId}/verify", [
            'code' => $this->issuedCode($challengeId),
        ]);

        $response->assertStatus(403);
        $this->assertSame('PURPOSE_NOT_ALLOWED', $response->json('errors.failure_code'));
    }

    /**
     * @scenario two_factor=on, controller=user, code=consumed_by_public_verify
     *
     * @effects public_cancel_rejects_login_purpose
     */
    #[Test]
    public function the_public_cancel_endpoint_refuses_a_login_challenge(): void
    {
        $challengeId = $this->startLoginChallenge();

        $this->postJson("/api/identity/challenges/{$challengeId}/cancel")
            ->assertStatus(403);
    }

    /**
     * @scenario two_factor=on, controller=user, code=valid
     *
     * @effects login_completes_after_public_endpoints_refuse
     */
    #[Test]
    public function a_login_challenge_still_completes_after_the_public_endpoints_refuse_it(): void
    {
        $challengeId = $this->startLoginChallenge();
        $code = $this->issuedCode($challengeId);

        $this->postJson("/api/identity/challenges/{$challengeId}/verify", ['code' => $code]);
        $this->postJson("/api/identity/challenges/{$challengeId}/cancel");

        // 게이트가 상태를 전혀 바꾸지 않아야 로그인이 그대로 완료된다.
        $response = $this->postJson('/api/auth/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.token'));
    }

    /**
     * @scenario two_factor=on, controller=user, code=valid
     *
     * @effects signup_purpose_unaffected_by_login_gate
     */
    #[Test]
    public function a_signup_challenge_is_unaffected_by_the_gate(): void
    {
        // 대조군 — 게이트가 로그인 이외 목적까지 막으면 가입·비밀번호 재설정 화면이 통째로 멈춘다.
        $challenge = app(IdentityVerificationService::class)->start(
            IdentityVerificationPurpose::Signup->value,
            $this->user,
            ['origin_type' => 'route', 'origin_identifier' => 'test']
        );

        $response = $this->postJson("/api/identity/challenges/{$challenge->id}/verify", [
            'code' => $this->issuedCode($challenge->id),
        ]);

        $this->assertNotSame(403, $response->getStatusCode(), '로그인 이외 목적까지 막으면 가입 흐름이 멈춥니다.');
    }

    /**
     * 로그인 2단계 인증 challenge 를 발급하고 그 id 를 돌려줍니다.
     *
     * @return string challenge UUID
     */
    private function startLoginChallenge(): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => 'purpose-gate@test.com',
            'password' => 'Passw0rd!2fa',
        ])->json('data.challenge_id');
    }

    /**
     * challenge 에 알려진 인증 코드를 심고 그 값을 돌려줍니다.
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
