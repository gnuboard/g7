<?php

namespace Tests\Unit\Extension;

use App\Enums\DeactivationReason;
use Tests\TestCase;

/**
 * DeactivationReason Enum 단위 테스트.
 *
 * 사용자 수동 / 시스템 자동 비활성화 사유의 Enum 값 도메인과 헬퍼 메서드
 * (label, isSystemTriggered, values, isValid) 동작을 검증합니다.
 */
class DeactivationReasonTest extends TestCase
{
    public function test_enum_values_match_db_string_domain(): void
    {
        $this->assertSame('manual', DeactivationReason::Manual->value);
        $this->assertSame('incompatible_core', DeactivationReason::IncompatibleCore->value);
    }

    public function test_label_returns_korean_fallback(): void
    {
        $this->assertSame('사용자 수동 비활성화', DeactivationReason::Manual->label());
        $this->assertSame('코어 버전 호환성', DeactivationReason::IncompatibleCore->label());
    }

    public function test_is_system_triggered_distinguishes_manual_vs_incompatible(): void
    {
        $this->assertFalse(DeactivationReason::Manual->isSystemTriggered());
        $this->assertTrue(DeactivationReason::IncompatibleCore->isSystemTriggered());
    }

    public function test_values_returns_complete_string_array(): void
    {
        $values = DeactivationReason::values();
        $this->assertCount(2, $values);
        $this->assertContains('manual', $values);
        $this->assertContains('incompatible_core', $values);
    }

    public function test_is_valid_accepts_known_and_rejects_unknown(): void
    {
        $this->assertTrue(DeactivationReason::isValid('manual'));
        $this->assertTrue(DeactivationReason::isValid('incompatible_core'));
        $this->assertFalse(DeactivationReason::isValid('unknown'));
        $this->assertFalse(DeactivationReason::isValid(''));
    }
}
