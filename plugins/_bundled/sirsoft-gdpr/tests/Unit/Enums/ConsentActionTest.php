<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Enums;

use Plugins\Sirsoft\Gdpr\Enums\ConsentAction;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * ConsentAction Enum 단위 테스트 (이슈 #430 거부 행위 정의).
 *
 * granted / revoked / rejected 3분기 판정 로직을 검증한다.
 */
class ConsentActionTest extends PluginTestCase
{
    public function test_cases_are_defined(): void
    {
        $this->assertSame(
            ['granted', 'revoked', 'rejected'],
            ConsentAction::allValues()
        );
    }

    /**
     * @scenario entry=accept_all, subject=member, category=required
     * @effects consent_action_from_decision_granted_revoked_rejected
     */
    public function test_from_decision_returns_granted_when_value_true(): void
    {
        // 동의 시 거부 신호와 무관하게 항상 granted (필수 항목 등)
        $this->assertSame(ConsentAction::Granted, ConsentAction::fromDecision(true, false));
        $this->assertSame(ConsentAction::Granted, ConsentAction::fromDecision(true, true));
    }

    public function test_from_decision_returns_rejected_when_value_false_and_rejection(): void
    {
        $this->assertSame(ConsentAction::Rejected, ConsentAction::fromDecision(false, true));
    }

    public function test_from_decision_returns_revoked_when_value_false_without_rejection(): void
    {
        // 명시적 거부 신호가 없는 미동의는 철회로 취급 (기존 동작 유지)
        $this->assertSame(ConsentAction::Revoked, ConsentAction::fromDecision(false, false));
    }
}
