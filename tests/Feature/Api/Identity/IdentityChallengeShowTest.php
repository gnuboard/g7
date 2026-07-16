<?php

namespace Tests\Feature\Api\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * GET /api/identity/challenges/{id} 폴링 엔드포인트 테스트.
 *
 * 비동기 검증 흐름(Stripe Identity / 토스인증 push / 외부 redirect 콜백) 에서 클라이언트가
 * verify 즉시 응답을 받지 못할 때 상태를 추적하는 폴링 경로 검증.
 *
 * @since engine-v1.46.0
 */
class IdentityChallengeShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        // PermissionMiddleware 가 IDV request 라우트에 권한 가드 적용 — guest 역할 권한 sync 필수
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_show_returns_404_when_challenge_not_found(): void
    {
        $response = $this->getJson('/api/identity/challenges/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
    }

    public function test_show_returns_public_status_fields(): void
    {
        // start a challenge
        $request = $this->postJson('/api/identity/challenges', [
            'purpose' => 'sensitive_action',
            'target' => ['email' => 'user@example.com'],
        ]);
        $id = $request->json('data.id');

        $response = $this->getJson("/api/identity/challenges/{$id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['id', 'status', 'render_hint', 'expires_at', 'public_payload'],
            ])
            ->assertJsonPath('data.id', $id);
    }

    public function test_show_does_not_expose_sensitive_fields(): void
    {
        $request = $this->postJson('/api/identity/challenges', [
            'purpose' => 'sensitive_action',
            'target' => ['email' => 'user@example.com'],
        ]);
        $id = $request->json('data.id');

        $response = $this->getJson("/api/identity/challenges/{$id}");

        // 시도 횟수·코드 본체·target_hash·verification_token 등 노출 금지
        $response->assertJsonMissingPath('data.attempts')
            ->assertJsonMissingPath('data.target_hash')
            ->assertJsonMissingPath('data.verification_token')
            ->assertJsonMissingPath('data.metadata');
    }

    public function test_show_reflects_status_transitions(): void
    {
        $request = $this->postJson('/api/identity/challenges', [
            'purpose' => 'sensitive_action',
            'target' => ['email' => 'user@example.com'],
        ]);
        $id = $request->json('data.id');

        // processing 상태로 강제 변경 (provider 가 비동기 검증 중인 흐름)
        IdentityVerificationLog::whereKey($id)->update([
            'status' => IdentityVerificationStatus::Processing->value,
        ]);

        $response = $this->getJson("/api/identity/challenges/{$id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', IdentityVerificationStatus::Processing->value);
    }
}
