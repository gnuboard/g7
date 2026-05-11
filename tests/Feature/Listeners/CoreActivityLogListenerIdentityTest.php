<?php

namespace Tests\Feature\Listeners;

use App\Enums\IdentityVerificationStatus;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Models\ActivityLog;
use App\Models\IdentityVerificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CoreActivityLogListener 의 IDV 훅 매핑 동작 테스트.
 *
 * 계획서 PR#2 요구: `core.identity.after_request` / `.after_verify` / `.challenge_expired`
 * 3 훅이 activity_logs 에 정확히 파생되는지 검증.
 */
class CoreActivityLogListenerIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_after_request_hook_creates_activity_log(): void
    {
        $challenge = new VerificationChallenge(
            id: 'test-challenge-1',
            providerId: 'g7:core.mail',
            purpose: 'sensitive_action',
            channel: 'email',
            targetHash: hash('sha256', 'user@example.com'),
            expiresAt: now()->addMinutes(15),
            renderHint: 'text_code',
        );

        HookManager::doAction(
            'core.identity.after_request',
            $challenge,
            'sensitive_action',
            ['email' => 'user@example.com'],
            []
        );

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'identity.request',
        ]);

        $latest = ActivityLog::where('action', 'identity.request')->latest('id')->first();
        $this->assertNotNull($latest);
        $this->assertStringContainsString('g7:core.mail', json_encode($latest->properties));
    }

    public function test_after_verify_success_creates_verify_activity_log(): void
    {
        $result = new VerificationResult(
            success: true,
            challengeId: 'test-challenge-2',
            providerId: 'g7:core.mail',
            verifiedAt: now(),
        );
        $log = IdentityVerificationLog::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'sensitive_action',
            'channel' => 'email',
            'target_hash' => hash('sha256', 'user@example.com'),
            'status' => IdentityVerificationStatus::Verified->value,
            'expires_at' => now()->addMinutes(15),
        ]);

        HookManager::doAction('core.identity.after_verify', $result, $log, []);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'identity.verify',
        ]);
    }

    public function test_after_verify_failure_creates_failed_activity_log(): void
    {
        $result = new VerificationResult(
            success: false,
            challengeId: 'test-challenge-3',
            providerId: 'g7:core.mail',
            verifiedAt: null,
            failureCode: 'invalid_code',
            failureReason: 'Wrong code',
        );
        $log = IdentityVerificationLog::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'sensitive_action',
            'channel' => 'email',
            'target_hash' => hash('sha256', 'user@example.com'),
            'status' => IdentityVerificationStatus::Failed->value,
            'expires_at' => now()->addMinutes(15),
        ]);

        HookManager::doAction('core.identity.after_verify', $result, $log, []);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'identity.verify_failed',
        ]);
    }

    public function test_challenge_expired_hook_creates_expired_activity_log(): void
    {
        $log = IdentityVerificationLog::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'password_reset',
            'channel' => 'email',
            'target_hash' => hash('sha256', 'user@example.com'),
            'status' => IdentityVerificationStatus::Expired->value,
            'expires_at' => now()->subMinutes(1),
        ]);

        HookManager::doAction('core.identity.challenge_expired', $log);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'identity.expired',
        ]);
    }
}
