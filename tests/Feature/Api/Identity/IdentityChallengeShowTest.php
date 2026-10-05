<?php

namespace Tests\Feature\Api\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Concerns\CapturesSentMail;
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
        // 본인인증 메일도 알림이다(7.1.0) — Notification::fake() 는 그 발송 경로 자체를 건너뛰어
        // challenge 가 failed(502) 가 된다. 메일은 Mail::fake() 로 격리하고 정의·메일 채널을 준비한다.
        Mail::fake();
        // PermissionMiddleware 가 IDV request 라우트에 권한 가드 적용 — guest 역할 권한 sync 필수
        $this->seed(RolePermissionSeeder::class);
        $this->prepareIdentityNotifications();
    }

    /**
     * 존재하지 않는 challenge id 로 상태를 조회하면 404 를 돌려준다.
     *
     * @effects polling_unknown_challenge_returns_404
     *
     * @return void 반환값 없음
     */
    public function test_show_returns_404_when_challenge_not_found(): void
    {
        $response = $this->getJson('/api/identity/challenges/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
    }

    /**
     * 발급한 challenge 의 상태 조회가 200 과 공개 안전 필드(id·status·render_hint·expires_at·public_payload)를 돌려준다.
     *
     * @scenario source=identity-challenge-status-field-exposure, field=id, field=status, field=public_payload
     *
     * @effects public_safe_fields_present_in_response
     *
     * @return void 반환값 없음
     */
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

    /**
     * 상태 조회 응답에 attempts·target_hash·verification_token·metadata 가 없고 max_attempts 는 정수로 노출된다.
     *
     * @scenario source=identity-challenge-status-field-exposure, field=attempts, field=max_attempts, field=target_hash
     *
     * @effects attempts_absent_from_response, max_attempts_absent_from_response, target_hash_absent_from_response, verification_token_absent_from_response, metadata_absent_from_response
     *
     * @return void 반환값 없음
     */
    public function test_show_does_not_expose_sensitive_fields(): void
    {
        $request = $this->postJson('/api/identity/challenges', [
            'purpose' => 'sensitive_action',
            'target' => ['email' => 'user@example.com'],
        ]);
        $id = $request->json('data.id');

        $response = $this->getJson("/api/identity/challenges/{$id}");

        // 누적 시도 횟수(attempts)·코드 본체·target_hash·verification_token 등 노출 금지.
        // 이 엔드포인트는 권한 가드 없는 공개 폴링용이므로, 남의 인증이 몇 번 실패했는지를
        // 추론할 단서를 응답에 담지 않는다.
        $response->assertJsonMissingPath('data.attempts')
            ->assertJsonMissingPath('data.target_hash')
            ->assertJsonMissingPath('data.verification_token')
            ->assertJsonMissingPath('data.metadata');

        // 상한(max_attempts)은 정책 상수라 노출한다 — 화면이 남은 횟수를 표시하려면 필요하고,
        // 상수 자체로는 그 인증 건이 몇 번 실패했는지가 드러나지 않는다.
        // (Service::getStatus 의 판정과 같은 계약. 한쪽만 바뀌면 이 단언이 먼저 깨진다.)
        $this->assertIsInt($response->json('data.max_attempts'));
    }

    /**
     * 로그 상태를 processing 으로 바꾸면 상태 조회 응답의 status 가 그 값을 그대로 반영한다.
     *
     * @effects polling_reflects_current_status
     *
     * @return void 반환값 없음
     */
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
