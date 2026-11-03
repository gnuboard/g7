<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Helpers;

use Modules\Sirsoft\Ecommerce\Helpers\DeviceDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PureTestCase;

class DeviceDetectorTest extends PureTestCase
{
    /**
     * iPhone·iPad·Android UA 는 mobile 로, 데스크톱 브라우저·빈 UA 는 pc 로 기기 종류를 판정한다.
     *
     * @scenario device=non_ios
     *
     * @effects server_ua_classifies_mobile_or_pc
     *
     * @param  string  $userAgent  판정할 User-Agent 문자열
     * @param  string  $expected  기대 기기 종류(mobile·pc)
     * @return void 반환값 없음
     */
    #[DataProvider('deviceTypeProvider')]
    public function test_detect_from_user_agent(string $userAgent, string $expected): void
    {
        $this->assertSame($expected, DeviceDetector::detectFromUserAgent($userAgent));
    }

    /**
     * 모바일·데스크톱·빈 User-Agent 와 기대 기기 종류 사례를 제공한다.
     *
     * @return array<string, array{0: string, 1: string}> 사례 이름별 [User-Agent, 기대 기기 종류]
     */
    public static function deviceTypeProvider(): array
    {
        return [
            'iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', 'mobile'],
            'iPad' => ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)', 'mobile'],
            'Android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8)', 'mobile'],
            'desktop Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120', 'pc'],
            'macOS Safari' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/17', 'pc'],
            'empty' => ['', 'pc'],
        ];
    }

    /**
     * iPhone·iPad·iPod UA 만 iOS 로 판정하고, Android·Windows·빈 UA 와 데스크톱 UA 를 보내는 iPadOS 는 iOS 가 아니라고 판정한다.
     *
     * @scenario mark_form=badge, requires_ios=true, device=ios
     *
     * @effects server_ua_sets_is_ios, applepay_shown_on_ios
     *
     * @param  string  $userAgent  판정할 User-Agent 문자열
     * @param  bool  $expected  iOS 여부 기대값
     * @return void 반환값 없음
     */
    #[DataProvider('iosProvider')]
    public function test_is_ios_from_user_agent(string $userAgent, bool $expected): void
    {
        $this->assertSame($expected, DeviceDetector::isIosFromUserAgent($userAgent));
    }

    /**
     * iOS·비-iOS·iPadOS 데스크톱 UA·빈 User-Agent 와 iOS 판정 기대값 사례를 제공한다.
     *
     * @return array<string, array{0: string, 1: bool}> 사례 이름별 [User-Agent, iOS 여부]
     */
    public static function iosProvider(): array
    {
        return [
            'iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', true],
            'iPad' => ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)', true],
            'iPod' => ['Mozilla/5.0 (iPod touch; CPU iPhone OS 15_0 like Mac OS X)', true],
            // Android 모바일은 iOS 가 아님(애플페이 미노출).
            'Android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8)', false],
            // 데스크탑 UA 를 보내는 iPadOS 는 서버 UA 판정으로 놓친다(클라 보정 대상).
            'macOS desktop UA' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/17', false],
            'Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120', false],
            'empty' => ['', false],
        ];
    }
}
