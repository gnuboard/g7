<?php

namespace Tests\Feature\Identity;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\IdentityPolicy;
use Database\Seeders\IdentityPolicySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\Identity\TestIdentityProvider;
use Tests\TestCase;

/**
 * 인증 시작 요청이 목적·수단을 검증하고 정책 지정 수단으로 발급하는지 검증한다 (KVE-2026-3188, 동형 B1·B9·B10).
 */
class IdentityChallengeRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 권한·정책 시더와 대역 수단을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(IdentityPolicySeeder::class);
        app(IdentityVerificationManager::class)->register(new TestIdentityProvider(app(IdentityVerificationLogRepositoryInterface::class)));
    }

    /**
     * 비회원으로 인증 시작을 요청합니다.
     *
     * @param  array<string, mixed>  $body  요청 본문
     * @return TestResponse 응답
     */
    private function start(array $body): TestResponse
    {
        return $this->postJson('/api/identity/challenges', $body + ['target' => ['email' => 'challenge-'.uniqid().'@example.test']]);
    }

    /**
     * 등록되지 않은 목적과 공개 경로의 로그인 목적은 거부한다 (B1).
     *
     * @effects unknown_purpose_rejected
     *
     * @return void 반환값 없음
     */
    public function test_unknown_and_login_purposes_are_rejected(): void
    {
        $this->start(['purpose' => 'not.a.purpose'])->assertStatus(422)->assertJsonValidationErrors(['purpose']);
        $this->start(['purpose' => 'login'])->assertStatus(422)->assertJsonValidationErrors(['purpose']);
    }

    /**
     * 등록되지 않은 수단을 지정하면 메일로 조용히 바꾸지 않고 거부한다 (B9 — 수정 전 메일 폴백 201).
     *
     * @effects unknown_provider_rejected
     *
     * @return void 반환값 없음
     */
    public function test_unknown_provider_is_rejected(): void
    {
        $this->start(['purpose' => 'sensitive_action', 'provider_id' => 'not.registered'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['provider_id']);
    }

    /**
     * 정책이 수단을 지정하면 요청이 수단을 빼도 그 수단으로 발급하고, 다른 수단을 고르면 거부한다 (3188 발급 단계).
     *
     * @effects challenge_uses_policy_provider, policy_provider_mismatch_rejected
     *
     * @return void 반환값 없음
     */
    public function test_policy_provider_is_forced(): void
    {
        IdentityPolicy::where('key', 'core.auth.password_reset')->update(['enabled' => true, 'provider_id' => TestIdentityProvider::ID]);

        $mismatch = $this->start(['purpose' => 'password_reset', 'provider_id' => 'g7:core.mail', 'policy_key' => 'core.auth.password_reset']);
        $mismatch->assertStatus(422);
        $this->assertSame(__('identity.errors.provider_policy_mismatch'), $mismatch->json('message'));

        $forced = $this->start(['purpose' => 'password_reset', 'policy_key' => 'core.auth.password_reset']);
        $forced->assertStatus(201);
        $this->assertSame(TestIdentityProvider::ID, $forced->json('data.provider_id'));
    }

    /**
     * 상태 조회 응답은 재전송이 정책 수단을 유지하도록 정책 키를 함께 준다 (B10).
     *
     * @effects status_exposes_policy_key
     *
     * @return void 반환값 없음
     */
    public function test_status_response_includes_policy_key(): void
    {
        IdentityPolicy::where('key', 'core.auth.password_reset')->update(['enabled' => true, 'provider_id' => TestIdentityProvider::ID]);
        $id = $this->start(['purpose' => 'password_reset', 'policy_key' => 'core.auth.password_reset'])->json('data.id');

        $status = $this->getJson('/api/identity/challenges/'.$id);

        $status->assertOk();
        $this->assertSame('core.auth.password_reset', $status->json('data.policy_key'));
        $this->assertSame(TestIdentityProvider::ID, $status->json('data.provider_id'));
    }
}
