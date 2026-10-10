<?php

namespace Tests\Feature\Api\Identity;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * IDV E2E 플로우 테스트.
 *
 * Challenge 요청 → 검증 → 로그 3행 생성 → users.identity_verified_at 갱신 경로.
 */
class IdentityChallengeFlowTest extends TestCase
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
        // 'sent' 단언을 위해 Mail 격리 + 코어 메시지 정의 시드 필수.
        Mail::fake();
        // 본인인증 메일도 알림이다(7.1.0) — Notification::fake() 는 그 발송 경로 자체를 건너뛰어
        // challenge 가 failed 가 된다. 메일 격리는 Mail::fake() 로 충분하다.
        // PermissionMiddleware 가 IDV 라우트에 권한 가드 적용 — guest/user 역할 권한 sync 필수
        $this->seed(RolePermissionSeeder::class);
        $this->prepareIdentityNotifications();
    }

    /**
     * 게스트의 challenge 요청이 201 과 응답 구조를 돌려주고 메일 프로바이더 sent 로그를 남긴다.
     *
     * @scenario actor_role=guest, challenge_owner=none, endpoint=request
     *
     * @effects guest_can_request_challenge
     *
     * @return void 반환값 없음
     */
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

    /**
     * 대상(target) 없이 challenge 를 요청하면 422 로 거절된다.
     *
     * @scenario surface=challenge_api
     *
     * @effects request_without_target_rejected_422
     *
     * @return void 반환값 없음
     */
    public function test_request_challenge_fails_without_target(): void
    {
        $response = $this->postJson('/api/identity/challenges', [
            'purpose' => 'sensitive_action',
        ]);

        $response->assertStatus(422);
    }

    /**
     * 맞는 코드로 검증하면 200 과 verification_token 을 돌려주고 로그가 verified 로 바뀐다.
     *
     * @scenario surface=challenge_api
     *
     * @effects verify_correct_code_marks_verified_and_issues_token
     *
     * @return void 반환값 없음
     */
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

    /**
     * 틀린 코드로 검증하면 422 와 failure_code=INVALID_CODE 를 돌려준다.
     *
     * @scenario surface=challenge_api
     *
     * @effects verify_wrong_code_returns_422_invalid_code
     *
     * @return void 반환값 없음
     */
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

    /**
     * 공개 프로바이더 목록에 코어 메일 프로바이더(g7:core.mail)가 포함된다.
     *
     * @scenario surface=challenge_api
     *
     * @effects providers_endpoint_lists_core_mail_provider
     *
     * @return void 반환값 없음
     */
    public function test_providers_endpoint_returns_mail_provider(): void
    {
        $response = $this->getJson('/api/identity/providers');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        $ids = array_column($data, 'id');
        $this->assertContains('g7:core.mail', $ids);
    }

    /**
     * 공개 목적 목록에 코어 목적 4종(signup·password_reset·self_update·sensitive_action)이 포함된다.
     *
     * @scenario surface=challenge_api
     *
     * @effects purposes_endpoint_lists_core_purposes
     *
     * @return void 반환값 없음
     */
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
     *
     * @scenario surface=challenge_api
     *
     * @effects request_route_throttle_allows_more_than_six_per_minute
     *
     * @return void 반환값 없음
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
