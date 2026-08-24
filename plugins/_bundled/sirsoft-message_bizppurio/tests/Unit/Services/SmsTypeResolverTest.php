<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Services;

use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchChannel;
use Plugins\Sirsoft\MessageBizppurio\Services\SmsTypeResolver;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * SmsTypeResolver — EUC-KR byte 판별(SMS≤90/LMS≤2000) 검증.
 */
class SmsTypeResolverTest extends PluginTestCase
{
    private SmsTypeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SmsTypeResolver;
    }

    public function test_영문_짧은_본문은_sms(): void
    {
        // 영문 45바이트 → SMS
        $this->assertSame(DispatchChannel::Sms, $this->resolver->resolve(str_repeat('a', 45)));
    }

    public function test_한글은_euckr_2바이트로_계산된다(): void
    {
        // 한글 45자 = 90byte → 경계 안(SMS)
        $this->assertSame(90, $this->resolver->byteLength(str_repeat('가', 45)));
        $this->assertSame(DispatchChannel::Sms, $this->resolver->resolve(str_repeat('가', 45)));

        // 한글 46자 = 92byte → SMS 경계 초과 → LMS
        $this->assertSame(92, $this->resolver->byteLength(str_repeat('가', 46)));
        $this->assertSame(DispatchChannel::Lms, $this->resolver->resolve(str_repeat('가', 46)));
    }

    public function test_영문_90바이트_경계와_초과(): void
    {
        // 정확히 90byte → SMS
        $this->assertSame(DispatchChannel::Sms, $this->resolver->resolve(str_repeat('a', 90)));
        // 91byte → LMS
        $this->assertSame(DispatchChannel::Lms, $this->resolver->resolve(str_repeat('a', 91)));
    }

    public function test_이모지는_제거되어_byte에_포함되지_않는다(): void
    {
        // 영문 10 + 이모지 → 이모지 제거 후 10byte 만 계산(EUC-KR 미표현 제거)
        $this->assertSame(10, $this->resolver->byteLength('aaaaaaaaaa😀'));
        // 이모지만 있는 경우 0byte → SMS
        $this->assertSame(0, $this->resolver->byteLength('😀😀'));
        $this->assertSame(DispatchChannel::Sms, $this->resolver->resolve('😀'));
    }

    public function test_lms_한도_초과_판정(): void
    {
        // 영문 2000byte → 한도 이내(false)
        $this->assertFalse($this->resolver->exceedsLmsLimit(str_repeat('a', 2000)));
        // 2001byte → 초과(true)
        $this->assertTrue($this->resolver->exceedsLmsLimit(str_repeat('a', 2001)));
    }
}
