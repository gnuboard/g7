<?php

namespace Tests\Unit\Enums;

use App\Enums\NotificationLogStatus;
use Tests\TestCase;

/**
 * NotificationLogStatus Enum 테스트
 *
 * 메일 발송 상태 Enum의 값과 동작을 검증합니다.
 */
class NotificationLogStatusTest extends TestCase
{
    // ========================================================================
    // 케이스 값 테스트
    // ========================================================================

    public function test_sent_case_has_correct_value(): void
    {
        $this->assertSame('sent', NotificationLogStatus::Sent->value);
    }

    public function test_failed_case_has_correct_value(): void
    {
        $this->assertSame('failed', NotificationLogStatus::Failed->value);
    }

    public function test_skipped_case_has_correct_value(): void
    {
        $this->assertSame('skipped', NotificationLogStatus::Skipped->value);
    }

    public function test_cases_returns_all_statuses(): void
    {
        $cases = NotificationLogStatus::cases();

        $this->assertCount(3, $cases);
        $this->assertContains(NotificationLogStatus::Sent, $cases);
        $this->assertContains(NotificationLogStatus::Failed, $cases);
        $this->assertContains(NotificationLogStatus::Skipped, $cases);
    }

    // ========================================================================
    // from / tryFrom 테스트
    // ========================================================================

    public function test_from_returns_correct_enum_for_valid_value(): void
    {
        $this->assertSame(NotificationLogStatus::Sent, NotificationLogStatus::from('sent'));
        $this->assertSame(NotificationLogStatus::Failed, NotificationLogStatus::from('failed'));
        $this->assertSame(NotificationLogStatus::Skipped, NotificationLogStatus::from('skipped'));
    }

    public function test_from_throws_exception_for_invalid_value(): void
    {
        $this->expectException(\ValueError::class);

        NotificationLogStatus::from('invalid');
    }

    public function test_try_from_returns_null_for_invalid_value(): void
    {
        $this->assertNull(NotificationLogStatus::tryFrom('invalid'));
    }

    public function test_try_from_returns_enum_for_valid_value(): void
    {
        $this->assertSame(NotificationLogStatus::Sent, NotificationLogStatus::tryFrom('sent'));
    }
}
