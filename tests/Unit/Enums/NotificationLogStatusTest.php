<?php

namespace Tests\Unit\Enums;

use App\Enums\NotificationLogStatus;
use Tests\PureTestCase;

/**
 * NotificationLogStatus Enum 테스트
 *
 * 메일 발송 상태 Enum의 값과 동작을 검증합니다.
 */
class NotificationLogStatusTest extends PureTestCase
{
    // ========================================================================
    // 케이스 값 테스트
    // ========================================================================

    /**
     * Sent 케이스의 저장 값이 'sent' 이다.
     *
     * @scenario case=case_values
     *
     * @effects case_values_match_storage_strings
     *
     * @return void 반환값 없음
     */
    public function test_sent_case_has_correct_value(): void
    {
        $this->assertSame('sent', NotificationLogStatus::Sent->value);
    }

    /**
     * Failed 케이스의 저장 값이 'failed' 이다.
     *
     * @scenario case=case_values
     *
     * @effects case_values_match_storage_strings
     *
     * @return void 반환값 없음
     */
    public function test_failed_case_has_correct_value(): void
    {
        $this->assertSame('failed', NotificationLogStatus::Failed->value);
    }

    /**
     * Skipped 케이스의 저장 값이 'skipped' 이다.
     *
     * @scenario case=case_values
     *
     * @effects case_values_match_storage_strings
     *
     * @return void 반환값 없음
     */
    public function test_skipped_case_has_correct_value(): void
    {
        $this->assertSame('skipped', NotificationLogStatus::Skipped->value);
    }

    /**
     * cases() 가 Sent·Failed·Skipped 세 상태를 모두 돌려준다.
     *
     * @scenario case=case_values
     *
     * @effects all_statuses_enumerated
     *
     * @return void 반환값 없음
     */
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

    /**
     * from() 이 유효한 문자열마다 대응하는 Enum 케이스를 돌려준다.
     *
     * @scenario case=valid_conversion
     *
     * @effects valid_string_converted_to_enum
     *
     * @return void 반환값 없음
     */
    public function test_from_returns_correct_enum_for_valid_value(): void
    {
        $this->assertSame(NotificationLogStatus::Sent, NotificationLogStatus::from('sent'));
        $this->assertSame(NotificationLogStatus::Failed, NotificationLogStatus::from('failed'));
        $this->assertSame(NotificationLogStatus::Skipped, NotificationLogStatus::from('skipped'));
    }

    /**
     * from() 에 알 수 없는 값을 넘기면 ValueError 를 던진다.
     *
     * @scenario case=invalid_conversion
     *
     * @effects invalid_value_throws_on_from
     *
     * @return void 반환값 없음
     */
    public function test_from_throws_exception_for_invalid_value(): void
    {
        $this->expectException(\ValueError::class);

        NotificationLogStatus::from('invalid');
    }

    /**
     * tryFrom() 에 알 수 없는 값을 넘기면 예외 없이 null 을 돌려준다.
     *
     * @scenario case=invalid_conversion
     *
     * @effects invalid_value_yields_null_on_try_from
     *
     * @return void 반환값 없음
     */
    public function test_try_from_returns_null_for_invalid_value(): void
    {
        $this->assertNull(NotificationLogStatus::tryFrom('invalid'));
    }

    /**
     * tryFrom() 이 유효한 문자열에 대해 대응 Enum 케이스를 돌려준다.
     *
     * @scenario case=valid_conversion
     *
     * @effects valid_string_converted_to_enum
     *
     * @return void 반환값 없음
     */
    public function test_try_from_returns_enum_for_valid_value(): void
    {
        $this->assertSame(NotificationLogStatus::Sent, NotificationLogStatus::tryFrom('sent'));
    }
}
