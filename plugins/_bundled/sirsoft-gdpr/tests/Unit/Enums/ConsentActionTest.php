<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Enums;

use Plugins\Sirsoft\Gdpr\Enums\ConsentAction;
use Tests\PureTestCase;

/**
 * ConsentAction Enum 단위 테스트 (이슈 #430 거부 행위 정의).
 *
 * granted / revoked / rejected 3분기 판정 로직을 검증한다.
 */
class ConsentActionTest extends PureTestCase
{
    /**
     * 동의 행위 값 목록이 granted·revoked·rejected 세 가지를 이 순서로 정확히 제공하는지 검증한다.
     *
     * @scenario entry=reject, subject=member, category=optional
     *
     * @effects consent_action_from_decision_granted_revoked_rejected
     *
     * @return void 반환값 없음
     */
    public function test_cases_are_defined(): void
    {
        $this->assertSame(
            ['granted', 'revoked', 'rejected'],
            ConsentAction::allValues()
        );
    }

    /**
     * 동의 값이 true 이면 거부 신호 유무와 관계없이 항상 granted 로 판정되는지 검증한다.
     *
     * @scenario entry=accept_all, subject=member, category=required
     *
     * @effects consent_action_from_decision_granted_revoked_rejected
     *
     * @return void 반환값 없음
     */
    public function test_from_decision_returns_granted_when_value_true(): void
    {
        // 동의 시 거부 신호와 무관하게 항상 granted (필수 항목 등)
        $this->assertSame(ConsentAction::Granted, ConsentAction::fromDecision(true, false));
        $this->assertSame(ConsentAction::Granted, ConsentAction::fromDecision(true, true));
    }

    /**
     * 동의 값이 false 이고 명시적 거부 신호가 있으면 rejected 로 판정되는지 검증한다.
     *
     * @scenario entry=reject, subject=member, category=optional
     *
     * @effects consent_action_from_decision_granted_revoked_rejected
     *
     * @return void 반환값 없음
     */
    public function test_from_decision_returns_rejected_when_value_false_and_rejection(): void
    {
        $this->assertSame(ConsentAction::Rejected, ConsentAction::fromDecision(false, true));
    }

    /**
     * 거부 신호 없이 동의 값만 false 이면 기존 동작대로 revoked(철회)로 판정되는지 검증한다.
     *
     * @scenario entry=save_selection, subject=member, category=optional
     *
     * @effects consent_action_from_decision_granted_revoked_rejected, without_rejection_intent_false_is_revoked_not_rejected
     *
     * @return void 반환값 없음
     */
    public function test_from_decision_returns_revoked_when_value_false_without_rejection(): void
    {
        // 명시적 거부 신호가 없는 미동의는 철회로 취급 (기존 동작 유지)
        $this->assertSame(ConsentAction::Revoked, ConsentAction::fromDecision(false, false));
    }
}
