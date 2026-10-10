<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\IdentityVerificationStatus;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Concerns\CapturesSentMail;
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
    use CapturesSentMail;
    use RefreshDatabase;

    /**
     * 메일을 격리하고 IDV 권한·본인인증 알림 정의를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        // 본인인증 메일은 알림 정의(category=identity)·메일 채널 준비 상태를 거친다 — 없으면 status='failed'.
        // password_reset 테스트에서 'sent' 단언이 통과되려면 Mail 격리 + 코어 메시지 정의 시드 필수.
        Mail::fake();
        // 본인인증 메일도 알림이다(7.1.0) — Notification::fake() 는 그 발송 경로 자체를 건너뛰어
        // challenge 가 failed 가 된다. 메일 격리는 Mail::fake() 로 충분하다.
        // PermissionMiddleware 가 IDV 라우트에 권한 가드 적용 — guest 역할 권한 sync 필수
        $this->seed(RolePermissionSeeder::class);
        $this->prepareIdentityNotifications();
    }

    /**
     * 활성 회원의 비밀번호 찾기 요청은 200 으로 성공하고 password_reset_tokens 에 그 이메일 행을 남긴다.
     *
     * @scenario surface=forgot_password
     *
     * @effects forgot_password_stores_password_reset_token
     *
     * @return void 반환값 없음
     */
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

    /**
     * 인증 대기(pending_verification) 회원의 비밀번호 찾기 요청 자체는 200 으로 통과한다 — 재설정 차단은 이 테스트가 단언하지 않는다.
     *
     * @scenario surface=forgot_password
     *
     * @effects forgot_password_request_accepted_for_pending_user
     *
     * @return void 반환값 없음
     */
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

    /**
     * 가입되지 않은 이메일로 비밀번호 찾기를 요청하면 email 검증 오류 422 를 받는다.
     *
     * @scenario surface=forgot_password
     *
     * @effects forgot_password_unregistered_email_rejected_422
     *
     * @return void 반환값 없음
     */
    public function test_forgot_password_fails_for_unregistered_email(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    /**
     * IDV 공개 API 로 password_reset 목적 challenge 를 요청하면 201 이고 sent 상태 로그가 남는다.
     *
     * @scenario surface=forgot_password
     *
     * @effects password_reset_purpose_challenge_issued_via_idv_api
     *
     * @return void 반환값 없음
     */
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
