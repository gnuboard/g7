<?php

namespace Tests\Feature\Api\Identity;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use Database\Seeders\IdentityMessageDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * IDV E2E 플로우 테스트.
 *
 * Challenge 요청 → 검증 → 로그 3행 생성 → users.identity_verified_at 갱신 경로.
 */
class IdentityChallengeFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // IdentityMessageDispatcher 가 Mail::send 직접 호출 + resolver 가 정의 없으면 false 반환 → status='failed'.
        // 'sent' 단언을 위해 Mail 격리 + 코어 메시지 정의 시드 필수.
        Mail::fake();
        Notification::fake();
        // PermissionMiddleware 가 IDV 라우트에 권한 가드 적용 — guest/user 역할 권한 sync 필수
        $this->seed(RolePermissionSeeder::class);
        $this->seed(IdentityMessageDefinitionSeeder::class);
    }

    public function test_request_challenge_returns_201_and_creates_log(): void
    {
        $response = $this->postJson('/api/identity/challenges', [
            'purpose' => 'sensitive_action',
            'target' => ['email' => 'user@example.com'],
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['success', 'data' => ['id', 'provider_id', 'purpose', 'render_hint', 'expires_at']]);

        $this->assertDatabaseHas('identity_verification_logs', [
            'provider_id' => 'g7:core.mail',
            'purpose' => 'sensitive_action',
            'status' => IdentityVerificationStatus::Sent->value,
        ]);
    }

    public function test_request_challenge_fails_without_target(): void
    {
        $response = $this->postJson('/api/identity/challenges', [
            'purpose' => 'sensitive_action',
        ]);

        $response->assertStatus(422);
    }

    public function test_verify_with_correct_code_returns_token(): void
    {
        /** @var IdentityVerificationLogRepositoryInterface $repo */
        $repo = $this->app->make(IdentityVerificationLogRepositoryInterface::class);

        $requestResponse = $this->postJson('/api/identity/challenges', [
            'purpose' => 'sensitive_action',
            'target' => ['email' => 'user@example.com'],
        ]);

        $id = $requestResponse->json('data.id');

        // 테스트 목적의 알려진 코드로 해시 주입
        $repo->updateById($id, [
            'metadata' => ['code_hash' => Hash::make('123456')],
            'status' => IdentityVerificationStatus::Sent->value,
        ]);

        $verify = $this->postJson("/api/identity/challenges/{$id}/verify", [
            'code' => '123456',
        ]);

        $verify->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['challenge_id', 'verified_at', 'verification_token']]);

        $log = IdentityVerificationLog::find($id);
        $this->assertSame(IdentityVerificationStatus::Verified, $log->status);
        $this->assertNotNull($log->verification_token);
    }

    public function test_verify_with_wrong_code_returns_422(): void
    {
        /** @var IdentityVerificationLogRepositoryInterface $repo */
        $repo = $this->app->make(IdentityVerificationLogRepositoryInterface::class);

        $id = $this->postJson('/api/identity/challenges', [
            'purpose' => 'sensitive_action',
            'target' => ['email' => 'user@example.com'],
        ])->json('data.id');

        $repo->updateById($id, [
            'metadata' => ['code_hash' => Hash::make('123456')],
            'status' => IdentityVerificationStatus::Sent->value,
        ]);

        $response = $this->postJson("/api/identity/challenges/{$id}/verify", [
            'code' => '999999',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.failure_code', 'INVALID_CODE');
    }

    public function test_providers_endpoint_returns_mail_provider(): void
    {
        $response = $this->getJson('/api/identity/providers');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        $ids = array_column($data, 'id');
        $this->assertContains('g7:core.mail', $ids);
    }

    public function test_purposes_endpoint_returns_core_purposes(): void
    {
        $response = $this->getJson('/api/identity/purposes');

        $response->assertStatus(200);
        $data = $response->json('data');
        $ids = array_column($data, 'id');
        foreach (['signup', 'password_reset', 'self_update', 'sensitive_action'] as $expected) {
            $this->assertContains($expected, $ids);
        }
    }

    /**
     * Challenge 발급 라우트의 throttle 한도는 정상 회원가입 흐름(재전송 / 같은 IP 게스트 다회 가입 시도)을
     * 차단하지 않을 만큼 여유 있어야 한다. 이전 한도 throttle:6,1 은 NAT IP 환경 / 모달 재전송 / 만료 후
     * 재시도가 누적될 때 즉시 429 를 유발하여 정상 가입자까지 차단하던 회귀를 막는다.
     */
    public function test_request_challenge_allows_more_than_six_per_minute(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $response = $this->postJson('/api/identity/challenges', [
                'purpose' => 'sensitive_action',
                'target' => ['email' => "user{$i}@example.com"],
            ]);

            $this->assertSame(
                201,
                $response->status(),
                "request #{$i} should not be throttled — challenge request throttle must allow normal signup flow with resends",
            );
        }
    }
}
