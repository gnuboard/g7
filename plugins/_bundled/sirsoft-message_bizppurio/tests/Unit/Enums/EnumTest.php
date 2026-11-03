<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Enums;

use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchChannel;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchSource;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchStatus;
use Plugins\Sirsoft\MessageBizppurio\Enums\ResultCategory;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 발송 관련 Enum 4종 검증.
 *
 * @scenario field_kind=plain
 */
class EnumTest extends PluginTestCase
{
    /**
     * DispatchChannel 이 sms, lms, alimtalk 세 값을 갖고 문자 채널만 isText 가 참이며 모르는 값은 null 로 해석되는지 확인한다.
     *
     * @effects dispatch_channel_enum_values_and_text_helper
     *
     * @return void 반환값 없음
     */
    public function test_dispatch_channel_값과_helper(): void
    {
        $this->assertEqualsCanonicalizing(['sms', 'lms', 'alimtalk'], DispatchChannel::values());
        $this->assertTrue(DispatchChannel::Sms->isText());
        $this->assertTrue(DispatchChannel::Lms->isText());
        $this->assertFalse(DispatchChannel::Alimtalk->isText());
        $this->assertNull(DispatchChannel::tryFrom('invalid'));
    }

    /**
     * DispatchStatus 네 값 가운데 success 와 failed 만 최종 상태로 판정되는지 확인한다.
     *
     * @effects dispatch_status_enum_final_state_flags
     *
     * @return void 반환값 없음
     */
    public function test_dispatch_status_값과_final_판정(): void
    {
        $this->assertEqualsCanonicalizing(['pending', 'sent', 'success', 'failed'], DispatchStatus::values());
        $this->assertFalse(DispatchStatus::Pending->isFinal());
        $this->assertFalse(DispatchStatus::Sent->isFinal());
        $this->assertTrue(DispatchStatus::Success->isFinal());
        $this->assertTrue(DispatchStatus::Failed->isFinal());
    }

    /**
     * DispatchSource 가 auto, manual, bulk 세 값을 갖고 Auto 의 값이 auto 인지 확인한다.
     *
     * @effects dispatch_source_enum_values
     *
     * @return void 반환값 없음
     */
    public function test_dispatch_source_값(): void
    {
        $this->assertEqualsCanonicalizing(['auto', 'manual', 'bulk'], DispatchSource::values());
        $this->assertSame('auto', DispatchSource::Auto->value);
    }

    /**
     * ResultCategory 네 값에서 Retry 만 재시도 대상이고 PermanentFailure 와 BalanceLow 만 실패로 판정되는지 확인한다.
     *
     * @effects result_category_enum_retry_and_failure_flags
     *
     * @return void 반환값 없음
     */
    public function test_result_category_분류_판정(): void
    {
        $this->assertEqualsCanonicalizing(
            ['success', 'retry', 'permanent_failure', 'balance_low'],
            ResultCategory::values()
        );

        $this->assertTrue(ResultCategory::Retry->isRetryable());
        $this->assertFalse(ResultCategory::Success->isRetryable());

        $this->assertTrue(ResultCategory::PermanentFailure->isFailure());
        $this->assertTrue(ResultCategory::BalanceLow->isFailure());
        $this->assertFalse(ResultCategory::Success->isFailure());
        $this->assertFalse(ResultCategory::Retry->isFailure());
    }

    /**
     * 발송 Enum 네 종의 label() 이 예외 없이 문자열을 돌려주는지(언어 키 해석) 확인한다.
     *
     * @effects dispatch_enum_labels_resolve_to_strings
     *
     * @return void 반환값 없음
     */
    public function test_label은_다국어_문자열을_반환한다(): void
    {
        // label() 이 예외 없이 문자열을 반환하는지 (lang 키 해석 스모크)
        $this->assertIsString(DispatchChannel::Sms->label());
        $this->assertIsString(DispatchStatus::Success->label());
        $this->assertIsString(DispatchSource::Auto->label());
        $this->assertIsString(ResultCategory::BalanceLow->label());
    }
}
