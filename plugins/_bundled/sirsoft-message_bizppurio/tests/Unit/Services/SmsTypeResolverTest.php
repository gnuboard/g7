<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Services;

use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchChannel;
use Plugins\Sirsoft\MessageBizppurio\Services\SmsTypeResolver;
use Tests\PureTestCase;

/**
 * SmsTypeResolver — EUC-KR byte 판별(SMS≤90/LMS≤2000) 검증.
 */
class SmsTypeResolverTest extends PureTestCase
{
    private SmsTypeResolver $resolver;

    /**
     * 각 테스트가 쓸 새 SmsTypeResolver 인스턴스를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SmsTypeResolver;
    }

    /**
     * 45바이트 영문 본문은 SMS 한도 안이라 SMS 채널로 판정된다.
     *
     * @scenario case=sms_boundary
     *
     * @effects short_body_resolves_to_sms
     *
     * @return void 반환값 없음
     */
    public function test_영문_짧은_본문은_sms(): void
    {
        // 영문 45바이트 → SMS
        $this->assertSame(DispatchChannel::Sms, $this->resolver->resolve(str_repeat('a', 45)));
    }

    /**
     * 한글을 글자당 2바이트로 세어 45자(90바이트)는 SMS, 46자(92바이트)는 LMS 로 판정한다.
     *
     * @scenario case=euckr_byte_count
     *
     * @effects korean_counted_as_two_bytes, short_body_resolves_to_sms, over_90_bytes_resolves_to_lms
     *
     * @return void 반환값 없음
     */
    public function test_한글은_euckr_2바이트로_계산된다(): void
    {
        // 한글 45자 = 90byte → 경계 안(SMS)
        $this->assertSame(90, $this->resolver->byteLength(str_repeat('가', 45)));
        $this->assertSame(DispatchChannel::Sms, $this->resolver->resolve(str_repeat('가', 45)));

        // 한글 46자 = 92byte → SMS 경계 초과 → LMS
        $this->assertSame(92, $this->resolver->byteLength(str_repeat('가', 46)));
        $this->assertSame(DispatchChannel::Lms, $this->resolver->resolve(str_repeat('가', 46)));
    }

    /**
     * 영문 정확히 90바이트는 SMS 에 머물고 91바이트부터 LMS 로 넘어간다.
     *
     * @scenario case=sms_boundary
     *
     * @effects short_body_resolves_to_sms, over_90_bytes_resolves_to_lms
     *
     * @return void 반환값 없음
     */
    public function test_영문_90바이트_경계와_초과(): void
    {
        // 정확히 90byte → SMS
        $this->assertSame(DispatchChannel::Sms, $this->resolver->resolve(str_repeat('a', 90)));
        // 91byte → LMS
        $this->assertSame(DispatchChannel::Lms, $this->resolver->resolve(str_repeat('a', 91)));
    }

    /**
     * EUC-KR 로 표현할 수 없는 이모지는 제거된 뒤 바이트를 세므로 길이에 포함되지 않고, 이모지뿐인 본문은 SMS 가 된다.
     *
     * @scenario case=euckr_byte_count
     *
     * @effects emoji_excluded_from_byte_count
     *
     * @return void 반환값 없음
     */
    public function test_이모지는_제거되어_byte에_포함되지_않는다(): void
    {
        // 영문 10 + 이모지 → 이모지 제거 후 10byte 만 계산(EUC-KR 미표현 제거)
        $this->assertSame(10, $this->resolver->byteLength('aaaaaaaaaa😀'));
        // 이모지만 있는 경우 0byte → SMS
        $this->assertSame(0, $this->resolver->byteLength('😀😀'));
        $this->assertSame(DispatchChannel::Sms, $this->resolver->resolve('😀'));
    }

    /**
     * 2000바이트까지는 LMS 한도 안으로, 2001바이트부터는 한도 초과로 판정한다.
     *
     * @scenario case=lms_limit
     *
     * @effects over_2000_bytes_exceeds_lms_limit
     *
     * @return void 반환값 없음
     */
    public function test_lms_한도_초과_판정(): void
    {
        // 영문 2000byte → 한도 이내(false)
        $this->assertFalse($this->resolver->exceedsLmsLimit(str_repeat('a', 2000)));
        // 2001byte → 초과(true)
        $this->assertTrue($this->resolver->exceedsLmsLimit(str_repeat('a', 2001)));
    }
}
