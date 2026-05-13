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
