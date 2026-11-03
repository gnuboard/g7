<?php

namespace Tests\Unit\Extension;

use App\Enums\DeactivationReason;
use Tests\PureTestCase;

/**
 * DeactivationReason Enum 단위 테스트.
 *
 * 사용자 수동 / 시스템 자동 비활성화 사유의 Enum 값 도메인과 헬퍼 메서드
 * (label, isSystemTriggered, values, isValid) 동작을 검증합니다.
 */
class DeactivationReasonTest extends PureTestCase
{
    /**
     * Enum 값이 DB 문자열 도메인(manual, incompatible_core)과 일치한다.
     *
     * @scenario case=value_domain
     *
     * @effects enum_values_match_db_domain
     *
     * @return void 반환값 없음
     */
    public function test_enum_values_match_db_string_domain(): void
    {
        $this->assertSame('manual', DeactivationReason::Manual->value);
        $this->assertSame('incompatible_core', DeactivationReason::IncompatibleCore->value);
    }

    /**
     * label() 이 각 사유의 한국어 폴백 문구를 돌려준다.
     *
     * @scenario case=label
     *
     * @effects korean_label_fallback_returned
     *
     * @return void 반환값 없음
     */
    public function test_label_returns_korean_fallback(): void
    {
        $this->assertSame('사용자 수동 비활성화', DeactivationReason::Manual->label());
        $this->assertSame('코어 버전 호환성', DeactivationReason::IncompatibleCore->label());
    }

    /**
     * isSystemTriggered() 가 수동 비활성화는 false, 코어 비호환 자동 비활성화는 true 로 구분한다.
     *
     * @scenario case=system_triggered
     *
     * @effects system_triggered_reason_distinguished
     *
     * @return void 반환값 없음
     */
    public function test_is_system_triggered_distinguishes_manual_vs_incompatible(): void
    {
        $this->assertFalse(DeactivationReason::Manual->isSystemTriggered());
        $this->assertTrue(DeactivationReason::IncompatibleCore->isSystemTriggered());
    }

    /**
     * values() 가 모든 사유 문자열을 빠짐없이 담은 배열을 돌려준다.
     *
     * @scenario case=value_helpers
     *
     * @effects values_list_complete
     *
     * @return void 반환값 없음
     */
    public function test_values_returns_complete_string_array(): void
    {
        $values = DeactivationReason::values();
        $this->assertCount(2, $values);
        $this->assertContains('manual', $values);
        $this->assertContains('incompatible_core', $values);
    }

    /**
     * isValid() 가 알려진 사유는 받아들이고 미지의 값·빈 문자열은 거부한다.
     *
     * @scenario case=value_helpers
     *
     * @effects unknown_reason_rejected
     *
     * @return void 반환값 없음
     */
    public function test_is_valid_accepts_known_and_rejects_unknown(): void
    {
        $this->assertTrue(DeactivationReason::isValid('manual'));
        $this->assertTrue(DeactivationReason::isValid('incompatible_core'));
        $this->assertFalse(DeactivationReason::isValid('unknown'));
        $this->assertFalse(DeactivationReason::isValid(''));
    }
}
