<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisCallbackOutcome;

/**
 * InicisCallbackOutcome DTO 단위 테스트.
 *
 * Pure unit (DB / Laravel 부트 불필요) — Value Object factory + bridge query 변환만 검증.
 */
class InicisCallbackOutcomeTest extends TestCase
{
    public function test_success_factory_produces_verified_outcome_with_token_and_challenge_id(): void
    {
        $outcome = InicisCallbackOutcome::success(
            challengeId: 'ch-uuid-1',
            verificationToken: 'tok-abc',
        );

        $this->assertTrue($outcome->success);
        $this->assertSame('ch-uuid-1', $outcome->challengeId);
        $this->assertSame('tok-abc', $outcome->verificationToken);
        $this->assertNull($outcome->failureCode);
    }

    public function test_failure_factory_with_challenge_id_preserves_both_fields(): void
    {
        $outcome = InicisCallbackOutcome::failure(
            challengeId: 'ch-uuid-2',
            failureCode: 'ALREADY_CONSUMED',
        );

        $this->assertFalse($outcome->success);
        $this->assertSame('ch-uuid-2', $outcome->challengeId);
        $this->assertSame('ALREADY_CONSUMED', $outcome->failureCode);
        $this->assertNull($outcome->verificationToken);
    }

    public function test_failure_factory_without_challenge_id_defaults_to_null(): void
    {
        $outcome = InicisCallbackOutcome::failure(failureCode: 'PROVIDER_ERROR');

        $this->assertFalse($outcome->success);
        $this->assertNull($outcome->challengeId);
        $this->assertSame('PROVIDER_ERROR', $outcome->failureCode);
    }

    public function test_failure_factory_with_default_failure_code_uses_unknown(): void
    {
        $outcome = InicisCallbackOutcome::failure();

        $this->assertFalse($outcome->success);
        $this->assertSame('UNKNOWN', $outcome->failureCode);
    }

    public function test_to_bridge_query_for_success_returns_token_and_challenge_id(): void
    {
        $outcome = InicisCallbackOutcome::success(
            challengeId: 'ch-uuid-3',
            verificationToken: 'tok-xyz',
        );

        $this->assertSame(
            ['verification_token' => 'tok-xyz', 'challenge_id' => 'ch-uuid-3'],
            $outcome->toBridgeQuery(),
        );
    }

    public function test_to_bridge_query_for_failure_returns_only_identity_error(): void
    {
        $outcome = InicisCallbackOutcome::failure(
            challengeId: 'ch-uuid-4',
            failureCode: 'NOT_FOUND',
        );

        $this->assertSame(['identity_error' => 'NOT_FOUND'], $outcome->toBridgeQuery());
    }

    public function test_to_bridge_query_handles_null_failure_code_as_empty_string(): void
    {
        $outcome = new InicisCallbackOutcome(success: false);

        $this->assertSame(['identity_error' => ''], $outcome->toBridgeQuery());
    }
}
