<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Helpers;

use Modules\Sirsoft\Ecommerce\Helpers\DeviceDetector;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class DeviceDetectorTest extends ModuleTestCase
{
    #[DataProvider('deviceTypeProvider')]
    public function test_detect_from_user_agent(string $userAgent, string $expected): void
    {
        $this->assertSame($expected, DeviceDetector::detectFromUserAgent($userAgent));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
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
     * @scenario mark_form=badge, requires_ios=true, device=ios
     *
     * @effects server_ua_sets_is_ios, applepay_shown_on_ios
     */
    #[DataProvider('iosProvider')]
    public function test_is_ios_from_user_agent(string $userAgent, bool $expected): void
    {
        $this->assertSame($expected, DeviceDetector::isIosFromUserAgent($userAgent));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
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
