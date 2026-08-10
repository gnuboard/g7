<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Unit\Services;

use Plugins\Sirsoft\VerificationKginicis\Services\InicisCallbackOutcome;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * InicisCallbackOutcome DTO 단위 테스트.
 *
 * Value Object factory + bridge query 변환만 검증한다. 지금은 DB/컨테이너를 쓰지 않지만,
 * 확장 테스트는 PluginTestCase 를 상속한다 — 나중에 컨테이너/설정을 건드리는 단언이 추가될 때
 * 조용히 다른 환경에서 도는 테스트가 되는 것을 막는다.
 */
class InicisCallbackOutcomeTest extends PluginTestCase
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
