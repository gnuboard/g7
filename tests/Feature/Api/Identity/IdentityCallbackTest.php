<?php

namespace Tests\Feature\Api\Identity;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * POST /api/identity/callback/{providerId} 외부 redirect 콜백 수신 테스트.
 *
 * 외부 IDV provider (PortOne/KCP/토스인증/Stripe Identity 등) 가 사용자 브라우저를 우리 서버로
 * 다시 보내는 콜백 진입점. provider 식별자 일치 검증, return URL 안전성 검증, verify 위임 동작 확인.
 *
 * @since engine-v1.46.0
 */
class IdentityCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        // PermissionMiddleware 가 IDV 라우트에 권한 가드 적용 — guest 역할 권한 sync 필수
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * 알려진 코드로 해시 주입한 challenge 를 만들어 반환합니다 (verify 가능 상태).
     */
    private function createReadyChallenge(string $code = '123456'): string
    {
        /** @var IdentityVerificationLogRepositoryInterface $repo */
        $repo = $this->app->make(IdentityVerificationLogRepositoryInterface::class);

        $request = $this->postJson('/api/identity/challenges', [
            'purpose' => 'sensitive_action',
            'target' => ['email' => 'user@example.com'],
        ]);
        $id = $request->json('data.id');

        $repo->updateById($id, [
            'metadata' => ['code_hash' => Hash::make($code)],
            'status' => IdentityVerificationStatus::Sent->value,
        ]);

        return $id;
    }

    public function test_callback_returns_404_for_unknown_challenge(): void
    {
        $response = $this->postJson('/api/identity/callback/g7:core.mail', [
            'challenge_id' => '00000000-0000-0000-0000-000000000000',
            'code' => '123456',
        ]);

        // failure → 422 (NOT_FOUND failure_code)
        $response->assertStatus(422)
            ->assertJsonPath('errors.failure_code', 'NOT_FOUND');
    }

    public function test_callback_rejects_wrong_provider(): void
    {
        $id = $this->createReadyChallenge();

        $response = $this->postJson('/api/identity/callback/external.unknown', [
            'challenge_id' => $id,
            'code' => '123456',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.failure_code', 'WRONG_PROVIDER');
    }

    public function test_callback_with_correct_code_returns_token_json(): void
    {
        $id = $this->createReadyChallenge();

        $response = $this->postJson('/api/identity/callback/g7:core.mail', [
            'challenge_id' => $id,
            'code' => '123456',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['challenge_id', 'provider_id', 'verified_at', 'verification_token'],
            ])
            ->assertJsonPath('success', true);

        $log = IdentityVerificationLog::find($id);
        $this->assertSame(IdentityVerificationStatus::Verified, $log->status);
    }

    public function test_callback_with_safe_return_url_redirects_with_token_query(): void
    {
        $id = $this->createReadyChallenge();

        // 상대 경로 — 항상 안전 (same-origin)
        $response = $this->post(
            '/api/identity/callback/g7:core.mail?return=/auth/register',
            [
                'challenge_id' => $id,
                'code' => '123456',
            ],
        );

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/auth/register', $location);
        $this->assertStringContainsString('verification_token=', $location);
        $this->assertStringContainsString('challenge_id='.$id, $location);
    }

    public function test_callback_blocks_protocol_relative_redirect_open_redirect_attack(): void
    {
        $id = $this->createReadyChallenge();

        // 프로토콜 상대 URL (`//evil.example.com`) 은 open redirect 시도 — JSON 응답으로 폴백되어야 함
        $response = $this->post(
            '/api/identity/callback/g7:core.mail?return=//evil.example.com/phish',
            [
                'challenge_id' => $id,
                'code' => '123456',
            ],
        );

        // redirect 가 아닌 JSON 응답
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['verification_token']]);
    }

    public function test_callback_failure_with_safe_return_url_redirects_with_error(): void
    {
        $id = $this->createReadyChallenge();

        $response = $this->post(
            '/api/identity/callback/g7:core.mail?return=/auth/register',
            [
                'challenge_id' => $id,
                'code' => 'wrong-code',
            ],
        );

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/auth/register', $location);
        $this->assertStringContainsString('identity_error=', $location);
    }

    public function test_callback_validates_required_challenge_id(): void
    {
        $response = $this->postJson('/api/identity/callback/g7:core.mail', [
            'code' => '123456',
        ]);

        $response->assertStatus(422);
    }

    public function test_callback_accepts_challenge_id_from_query_string(): void
    {
        $id = $this->createReadyChallenge();

        // OAuth 스타일 — query 에 challenge_id 동봉
        $response = $this->postJson(
            "/api/identity/callback/g7:core.mail?challenge_id={$id}",
            ['code' => '123456'],
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }
}
