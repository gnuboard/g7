<?php

namespace Tests\Unit\Extension\IdentityVerification\Providers;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Extension\IdentityVerification\Providers\MailIdentityProvider;
use App\Models\IdentityVerificationLog;
use Database\Seeders\IdentityMessageDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * MailIdentityProvider 테스트.
 *
 * Challenge 발행 / 검증 성공 / 잘못된 코드 / 만료 / 재시도 초과 / 취소 경로를 검증합니다.
 */
class MailIdentityProviderTest extends TestCase
{
    use RefreshDatabase;

    private MailIdentityProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        // dispatchMessage 가 IdentityMessageDefinition/Template 을 조회하므로 시더 필수.
        $this->seed(IdentityMessageDefinitionSeeder::class);
        $this->provider = $this->app->make(MailIdentityProvider::class);
    }

    public function test_provider_metadata(): void
    {
        $this->assertSame('g7:core.mail', $this->provider->getId());
        $this->assertContains('email', $this->provider->getChannels());
        $this->assertTrue($this->provider->supportsPurpose('signup'));
        $this->assertTrue($this->provider->supportsPurpose('sensitive_action'));
    }

    public function test_request_challenge_creates_log_and_sends_notification(): void
    {
        $challenge = $this->provider->requestChallenge(
            ['email' => 'user@example.com'],
            ['purpose' => 'signup', 'ip_address' => '127.0.0.1'],
        );

        $this->assertSame('g7:core.mail', $challenge->providerId);
        $this->assertSame('email', $challenge->channel);
        $this->assertSame('text_code', $challenge->renderHint);
        $this->assertSame(hash('sha256', 'user@example.com'), $challenge->targetHash);

        $log = IdentityVerificationLog::find($challenge->id);
        $this->assertNotNull($log);
        $this->assertSame(IdentityVerificationStatus::Sent, $log->status);
        $this->assertArrayHasKey('code_hash', $log->metadata ?? []);
    }

    public function test_request_challenge_password_reset_uses_link_render_hint(): void
    {
        $challenge = $this->provider->requestChallenge(
            ['email' => 'user@example.com'],
            ['purpose' => 'password_reset'],
        );

        $this->assertSame('link', $challenge->renderHint);

        $log = IdentityVerificationLog::find($challenge->id);
        $this->assertArrayHasKey('link_token_hash', $log->metadata ?? []);
    }

    public function test_verify_with_correct_code_succeeds(): void
    {
        /** @var IdentityVerificationLogRepositoryInterface $repo */
        $repo = $this->app->make(IdentityVerificationLogRepositoryInterface::class);

        $challenge = $this->provider->requestChallenge(
            ['email' => 'user@example.com'],
            ['purpose' => 'sensitive_action'],
        );

        // 테스트 목적으로 메타데이터에서 알려진 코드의 해시를 직접 주입
        $knownCode = '123456';
        $repo->updateById($challenge->id, [
            'metadata' => ['code_hash' => password_hash($knownCode, PASSWORD_BCRYPT)],
            'status' => IdentityVerificationStatus::Sent->value,
        ]);

        $result = $this->provider->verify($challenge->id, ['code' => $knownCode]);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->verifiedAt);

        $log = IdentityVerificationLog::find($challenge->id);
        $this->assertSame(IdentityVerificationStatus::Verified, $log->status);
        $this->assertNotNull($log->verification_token);
    }

    public function test_verify_with_wrong_code_increments_attempts(): void
    {
        /** @var IdentityVerificationLogRepositoryInterface $repo */
        $repo = $this->app->make(IdentityVerificationLogRepositoryInterface::class);

        $challenge = $this->provider->requestChallenge(
            ['email' => 'user@example.com'],
            ['purpose' => 'sensitive_action'],
        );

        $repo->updateById($challenge->id, [
            'metadata' => ['code_hash' => password_hash('123456', PASSWORD_BCRYPT)],
            'status' => IdentityVerificationStatus::Sent->value,
        ]);

        $result = $this->provider->verify($challenge->id, ['code' => '999999']);

        $this->assertFalse($result->success);
        $this->assertSame('INVALID_CODE', $result->failureCode);

        $log = IdentityVerificationLog::find($challenge->id);
        $this->assertSame(1, $log->attempts);
    }

    public function test_verify_returns_max_attempts_on_final_wrong_attempt(): void
    {
        /** @var IdentityVerificationLogRepositoryInterface $repo */
        $repo = $this->app->make(IdentityVerificationLogRepositoryInterface::class);

        $challenge = $this->provider->requestChallenge(
            ['email' => 'user@example.com'],
            ['purpose' => 'sensitive_action'],
        );

        // max_attempts 를 2 로 낮춰 빠르게 소진. 1회차는 INVALID_CODE, 2회차(소진)는 MAX_ATTEMPTS 안내.
        $repo->updateById($challenge->id, [
            'metadata' => ['code_hash' => password_hash('123456', PASSWORD_BCRYPT)],
            'status' => IdentityVerificationStatus::Sent->value,
            'max_attempts' => 2,
        ]);

        // 1회차 오답 — 아직 소진 전이므로 일반 오답 안내.
        $first = $this->provider->verify($challenge->id, ['code' => '999999']);
        $this->assertFalse($first->success);
        $this->assertSame('INVALID_CODE', $first->failureCode);
        $this->assertSame('identity.errors.invalid_code', $first->failureReason);

        // 2회차 오답 — 이번 시도로 max_attempts 도달(소진). 사용자에게 "최대 시도 초과 + 재요청" 안내가 도달해야 함.
        $second = $this->provider->verify($challenge->id, ['code' => '999999']);
        $this->assertFalse($second->success);
        $this->assertSame('MAX_ATTEMPTS', $second->failureCode);
        $this->assertSame('identity.errors.max_attempts', $second->failureReason);

        $log = IdentityVerificationLog::find($challenge->id);
        $this->assertSame(2, $log->attempts);
        $this->assertSame(IdentityVerificationStatus::Failed, $log->status);
    }

    public function test_verify_returns_max_attempts_when_already_exhausted(): void
    {
        /** @var IdentityVerificationLogRepositoryInterface $repo */
        $repo = $this->app->make(IdentityVerificationLogRepositoryInterface::class);

        $challenge = $this->provider->requestChallenge(
            ['email' => 'user@example.com'],
            ['purpose' => 'sensitive_action'],
        );

        // 이미 소진된 상태(attempts == max_attempts)에서 또 시도 — 코드 대조 전에 차단.
        $repo->updateById($challenge->id, [
            'metadata' => ['code_hash' => password_hash('123456', PASSWORD_BCRYPT)],
            'status' => IdentityVerificationStatus::Sent->value,
            'attempts' => 5,
            'max_attempts' => 5,
        ]);

        $result = $this->provider->verify($challenge->id, ['code' => '123456']);

        $this->assertFalse($result->success);
        $this->assertSame('MAX_ATTEMPTS', $result->failureCode);
        $this->assertSame('identity.errors.max_attempts', $result->failureReason);
    }

    public function test_cancel_marks_status_as_cancelled(): void
    {
        $challenge = $this->provider->requestChallenge(
            ['email' => 'user@example.com'],
            ['purpose' => 'sensitive_action'],
        );

        $this->assertTrue($this->provider->cancel($challenge->id));

        $log = IdentityVerificationLog::find($challenge->id);
        $this->assertSame(IdentityVerificationStatus::Cancelled, $log->status);
    }

    public function test_request_challenge_fails_without_email(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->provider->requestChallenge([], ['purpose' => 'signup']);
    }
}
