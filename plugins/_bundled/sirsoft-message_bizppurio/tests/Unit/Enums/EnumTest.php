<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Enums;

use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchChannel;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchSource;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchStatus;
use Plugins\Sirsoft\MessageBizppurio\Enums\ResultCategory;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 발송 관련 Enum 4종 검증.
 */
class EnumTest extends PluginTestCase
{
    public function test_dispatch_channel_값과_helper(): void
    {
        $this->assertEqualsCanonicalizing(['sms', 'lms', 'alimtalk'], DispatchChannel::values());
        $this->assertTrue(DispatchChannel::Sms->isText());
        $this->assertTrue(DispatchChannel::Lms->isText());
        $this->assertFalse(DispatchChannel::Alimtalk->isText());
        $this->assertNull(DispatchChannel::tryFrom('invalid'));
    }

    public function test_dispatch_status_값과_final_판정(): void
    {
        $this->assertEqualsCanonicalizing(['pending', 'sent', 'success', 'failed'], DispatchStatus::values());
        $this->assertFalse(DispatchStatus::Pending->isFinal());
        $this->assertFalse(DispatchStatus::Sent->isFinal());
        $this->assertTrue(DispatchStatus::Success->isFinal());
        $this->assertTrue(DispatchStatus::Failed->isFinal());
    }

    public function test_dispatch_source_값(): void
    {
        $this->assertEqualsCanonicalizing(['auto', 'manual', 'bulk'], DispatchSource::values());
        $this->assertSame('auto', DispatchSource::Auto->value);
    }

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

    public function test_label은_다국어_문자열을_반환한다(): void
    {
        // label() 이 예외 없이 문자열을 반환하는지 (lang 키 해석 스모크)
        $this->assertIsString(DispatchChannel::Sms->label());
        $this->assertIsString(DispatchStatus::Success->label());
        $this->assertIsString(DispatchSource::Auto->label());
        $this->assertIsString(ResultCategory::BalanceLow->label());
    }
}
