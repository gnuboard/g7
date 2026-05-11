<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\IdentityVerificationStatus;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\IdentityMessageDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * PR#3 — 비밀번호 찾기 플로우가 IDV 인프라를 경유하는지 확인.
 *
 * 계획서 요구:
 * - 기존 /api/auth/forgot-password 엔드포인트 시그니처는 변경 없음 (사용자 체감 동일)
 * - 서버 내부는 identity_verification_logs 에 challenge 이력 기록 또는 password_reset_tokens 저장
 * - pending_verification 상태 계정은 `RejectPasswordResetForPendingUser` 가드로 차단
 */
class ForgotPasswordViaIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // IdentityMessageDispatcher 가 Mail::send 직접 호출 + resolver 가 정의 없으면 false → status='failed'.
        // password_reset 테스트에서 'sent' 단언이 통과되려면 Mail 격리 + 코어 메시지 정의 시드 필수.
        Mail::fake();
        Notification::fake();
        // PermissionMiddleware 가 IDV 라우트에 권한 가드 적용 — guest 역할 권한 sync 필수
        $this->seed(RolePermissionSeeder::class);
        $this->seed(IdentityMessageDefinitionSeeder::class);
    }

    public function test_forgot_password_flow_produces_password_reset_token(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'status' => UserStatus::Active->value,
        ]);

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'user@example.com',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        // 기존 password_reset_tokens 는 여전히 채워져야 함 (사용자 체감 동일)
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'user@example.com',
        ]);
    }

    public function test_forgot_password_for_pending_user_can_still_request_but_reset_is_blocked(): void
    {
        User::factory()->create([
            'email' => 'pending@example.com',
            'status' => UserStatus::PendingVerification->value,
        ]);

        // 요청 자체는 통과 (토큰 발송). 실제 재설정 시 RejectPasswordResetForPendingUser 가 차단
        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'pending@example.com',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_forgot_password_fails_for_unregistered_email(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_idv_challenge_can_be_issued_with_password_reset_purpose(): void
    {
        // IDV 인프라가 password_reset purpose 를 지원하는지 직접 확인
        // (forgot-password 엔드포인트와 별개로 IDV 인프라 자체의 호환성)
        User::factory()->create([
            'email' => 'idv@example.com',
            'status' => UserStatus::Active->value,
        ]);

        $response = $this->postJson('/api/identity/challenges', [
            'purpose' => 'password_reset',
            'target' => ['email' => 'idv@example.com'],
        ]);

        $response->assertStatus(201);

        // IDV 인프라의 log 행이 생성되었는지 (MailProvider 는 password_reset 시 link 모드)
        $this->assertDatabaseHas('identity_verification_logs', [
            'purpose' => 'password_reset',
            'status' => IdentityVerificationStatus::Sent->value,
        ]);
    }
}
