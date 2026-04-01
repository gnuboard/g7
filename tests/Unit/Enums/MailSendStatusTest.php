<?php

namespace Tests\Unit\Enums;

use App\Enums\MailSendStatus;
use Tests\TestCase;

/**
 * MailSendStatus Enum 테스트
 *
 * 메일 발송 상태 Enum의 값과 동작을 검증합니다.
 */
class MailSendStatusTest extends TestCase
{
    // ========================================================================
    // 케이스 값 테스트
    // ========================================================================

    public function test_sent_case_has_correct_value(): void
    {
        $this->assertSame('sent', MailSendStatus::Sent->value);
    }

    public function test_failed_case_has_correct_value(): void
    {
        $this->assertSame('failed', MailSendStatus::Failed->value);
    }

    public function test_skipped_case_has_correct_value(): void
    {
        $this->assertSame('skipped', MailSendStatus::Skipped->value);
    }

    public function test_cases_returns_all_statuses(): void
    {
        $cases = MailSendStatus::cases();

        $this->assertCount(3, $cases);
        $this->assertContains(MailSendStatus::Sent, $cases);
        $this->assertContains(MailSendStatus::Failed, $cases);
        $this->assertContains(MailSendStatus::Skipped, $cases);
    }

    // ========================================================================
    // from / tryFrom 테스트
    // ========================================================================

    public function test_from_returns_correct_enum_for_valid_value(): void
    {
        $this->assertSame(MailSendStatus::Sent, MailSendStatus::from('sent'));
        $this->assertSame(MailSendStatus::Failed, MailSendStatus::from('failed'));
        $this->assertSame(MailSendStatus::Skipped, MailSendStatus::from('skipped'));
    }

    public function test_from_throws_exception_for_invalid_value(): void
    {
        $this->expectException(\ValueError::class);

        MailSendStatus::from('invalid');
    }

    public function test_try_from_returns_null_for_invalid_value(): void
    {
        $this->assertNull(MailSendStatus::tryFrom('invalid'));
    }

    public function test_try_from_returns_enum_for_valid_value(): void
    {
        $this->assertSame(MailSendStatus::Sent, MailSendStatus::tryFrom('sent'));
    }
}
