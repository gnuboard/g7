<?php

namespace Tests\Unit\Analytics;

use App\Analytics\UserAgentClassifier;
use App\Enums\Analytics\DeviceType;
use Tests\TestCase;

/**
 * User-Agent 분류 — 브라우저·운영체제·기기.
 *
 * 판정 순서가 계약이다. 파생 브라우저의 UA 에는 원조 이름이 함께 들어 있어(Edge 안의
 * "Chrome", 안드로이드 안의 "Linux"), 순서를 잘못 두면 전 방문이 엉뚱한 브라우저로
 * 집계된다 — 숫자는 그럴듯해 보이므로 화면만 봐서는 드러나지 않는다.
 */
class UserAgentClassifierTest extends TestCase
{
    /**
     * 데스크톱 브라우저를 가른다.
     *
     * @scenario browser=chrome|edge|firefox|safari|whale|opera, device=desktop
     *
     * @effects browser_and_os_are_classified_from_user_agent
     *
     * @return void 없음
     */
    public function test_classifies_desktop_browsers(): void
    {
        $cases = [
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0.0.0 Safari/537.36', 'Chrome', 'Windows'],
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0.0.0 Safari/537.36 Edg/128.0.0.0', 'Edge', 'Windows'],
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0', 'Firefox', 'Windows'],
            ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15', 'Safari', 'macOS'],
            ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/128.0.0.0 Whale/3.0 Safari/537.36', 'Whale', 'Windows'],
            ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/128.0.0.0 Safari/537.36 OPR/114.0.0.0', 'Opera', 'Windows'],
        ];

        foreach ($cases as [$ua, $browser, $os]) {
            $result = UserAgentClassifier::classify($ua);
            $this->assertSame($browser, $result['browser_name'], $ua);
            $this->assertSame($os, $result['os_name'], $ua);
            $this->assertSame(DeviceType::Desktop, $result['device_type'], $ua);
        }
    }

    /**
     * 파생 브라우저가 원조 이름에 가려지지 않는다.
     *
     * Edge·Whale·Opera 는 전부 UA 에 "Chrome" 을 담는다. 이 단언이 없으면 판정 순서가
     * 뒤집혀도 테스트가 통과한다.
     *
     * @scenario browser=chromium_derivatives
     *
     * @effects derivative_browsers_are_not_misread_as_their_base
     *
     * @return void 없음
     */
    public function test_derivative_browsers_are_not_read_as_chrome(): void
    {
        $derivatives = [
            'Edg/128.0.0.0' => 'Edge',
            'Whale/3.0' => 'Whale',
            'OPR/114.0.0.0' => 'Opera',
            'SamsungBrowser/25.0' => 'Samsung Internet',
        ];

        foreach ($derivatives as $token => $expected) {
            $ua = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/128.0.0.0 '.$token.' Safari/537.36';
            $this->assertSame($expected, UserAgentClassifier::classify($ua)['browser_name'], $token);
        }
    }

    /**
     * 모바일·태블릿을 가른다.
     *
     * 안드로이드는 "Mobile" 표기가 없으면 태블릿이다 — 그 규칙이 빠지면 태블릿이
     * 전부 휴대전화로 집계된다.
     *
     * @scenario device=mobile|tablet, os=ios|ipados|android
     *
     * @effects device_type_distinguishes_mobile_from_tablet
     *
     * @return void 없음
     */
    public function test_classifies_mobile_and_tablet(): void
    {
        $cases = [
            ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1', 'iOS', DeviceType::Mobile],
            ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1', 'iPadOS', DeviceType::Tablet],
            ['Mozilla/5.0 (Linux; Android 14; SM-S918N) AppleWebKit/537.36 Chrome/128.0.0.0 Mobile Safari/537.36', 'Android', DeviceType::Mobile],
            ['Mozilla/5.0 (Linux; Android 13; SM-X900) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36', 'Android', DeviceType::Tablet],
        ];

        foreach ($cases as [$ua, $os, $device]) {
            $result = UserAgentClassifier::classify($ua);
            $this->assertSame($os, $result['os_name'], $ua);
            $this->assertSame($device, $result['device_type'], $ua);
        }
    }

    /**
     * 클라이언트 힌트가 User-Agent 보다 우선한다.
     *
     * @scenario hints=present, ua=conflicting
     *
     * @effects client_hints_take_precedence_over_user_agent
     *
     * @return void 없음
     */
    public function test_client_hints_take_precedence(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/128.0.0.0 Safari/537.36';
        $hints = [
            'brands' => [
                ['brand' => 'Not;A=Brand', 'version' => '99'],
                ['brand' => 'Microsoft Edge', 'version' => '128'],
            ],
            'platform' => 'macOS',
            'mobile' => false,
        ];

        $result = UserAgentClassifier::classify($ua, $hints);

        $this->assertSame('Edge', $result['browser_name']);
        $this->assertSame('128', $result['browser_version']);
        $this->assertSame('macOS', $result['os_name']);
    }

    /**
     * 위장용 힌트 항목만 있으면 User-Agent 판정으로 물러선다.
     *
     * @scenario hints=placeholder_only
     *
     * @effects client_hints_take_precedence_over_user_agent
     *
     * @return void 없음
     */
    public function test_falls_back_when_hints_have_no_known_brand(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/128.0.0.0 Safari/537.36';
        $hints = ['brands' => [['brand' => 'Not;A=Brand', 'version' => '99']]];

        $this->assertSame('Chrome', UserAgentClassifier::classify($ua, $hints)['browser_name']);
    }

    /**
     * 인앱 브라우저를 가른다.
     *
     * @scenario browser=kakaotalk|naver_app
     *
     * @effects browser_and_os_are_classified_from_user_agent
     *
     * @return void 없음
     */
    public function test_classifies_in_app_browsers(): void
    {
        $kakao = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/128.0.0.0 Mobile Safari/537.36 KAKAOTALK 10.0.0';
        $naver = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/128.0.0.0 Mobile Safari/537.36 NAVER(inapp; search; 1234)';

        $this->assertSame('KakaoTalk', UserAgentClassifier::classify($kakao)['browser_name']);
        $this->assertSame('Naver App', UserAgentClassifier::classify($naver)['browser_name']);
    }

    /**
     * 빈 User-Agent 는 Other 로 떨어진다.
     *
     * @scenario ua=empty
     *
     * @effects unknown_environment_falls_back_to_other
     *
     * @return void 없음
     */
    public function test_empty_user_agent_falls_back_to_other(): void
    {
        $result = UserAgentClassifier::classify('');

        $this->assertSame('Other', $result['browser_name']);
        $this->assertSame('Other', $result['os_name']);
        $this->assertSame(DeviceType::Other, $result['device_type']);
        $this->assertNull($result['browser_version']);
    }

    /**
     * 운영체제 버전을 뽑는다.
     *
     * @scenario os_version=windows|macos|android|ios
     *
     * @effects browser_and_os_are_classified_from_user_agent
     *
     * @return void 없음
     */
    public function test_extracts_os_version(): void
    {
        $this->assertSame('10.0', UserAgentClassifier::classify('Mozilla/5.0 (Windows NT 10.0)')['os_version']);
        $this->assertSame('10.15.7', UserAgentClassifier::classify('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)')['os_version']);
        $this->assertSame('14', UserAgentClassifier::classify('Mozilla/5.0 (Linux; Android 14)')['os_version']);
    }

    /**
     * 브라우저 9종 × 운영체제 7종 픽스처 30건이 전부 기대한 대로 갈린다.
     *
     * 판정 순서·패턴 하나가 바뀌면 어느 조합이 무너지는지 이 표가 바로 짚는다.
     * Linux·ChromeOS 처럼 드문 운영체제가 Other 로 새는 것도 여기서 잡는다.
     *
     * @scenario browser=chrome|edge|firefox|safari|samsung|whale|opera|kakaotalk|naver_app, os=windows|macos|ios|ipados|android|linux|chromeos
     *
     * @effects browser_and_os_are_classified_from_user_agent, device_type_distinguishes_mobile_from_tablet
     *
     * @return void 없음
     */
    public function test_thirty_user_agent_fixtures(): void
    {
        $webkit = 'AppleWebKit/537.36 (KHTML, like Gecko)';
        $ios = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko)';
        $ipad = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko)';

        $cases = [
            // Chrome
            ["Mozilla/5.0 (Windows NT 10.0; Win64; x64) $webkit Chrome/128.0.0.0 Safari/537.36", 'Chrome', 'Windows', DeviceType::Desktop],
            ["Mozilla/5.0 (Windows NT 6.1; Win64; x64) $webkit Chrome/109.0.0.0 Safari/537.36", 'Chrome', 'Windows', DeviceType::Desktop],
            ["Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) $webkit Chrome/128.0.0.0 Safari/537.36", 'Chrome', 'macOS', DeviceType::Desktop],
            ["Mozilla/5.0 (X11; Linux x86_64) $webkit Chrome/128.0.0.0 Safari/537.36", 'Chrome', 'Linux', DeviceType::Desktop],
            ["Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) $webkit Chrome/128.0.0.0 Safari/537.36", 'Chrome', 'ChromeOS', DeviceType::Desktop],
            ["Mozilla/5.0 (Linux; Android 14; SM-S918N) $webkit Chrome/128.0.0.0 Mobile Safari/537.36", 'Chrome', 'Android', DeviceType::Mobile],
            // Edge
            ["Mozilla/5.0 (Windows NT 10.0; Win64; x64) $webkit Chrome/128.0.0.0 Safari/537.36 Edg/128.0.0.0", 'Edge', 'Windows', DeviceType::Desktop],
            ["Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) $webkit Chrome/128.0.0.0 Safari/537.36 Edg/128.0.0.0", 'Edge', 'macOS', DeviceType::Desktop],
            ["Mozilla/5.0 (X11; Linux x86_64) $webkit Chrome/128.0.0.0 Safari/537.36 Edg/128.0.0.0", 'Edge', 'Linux', DeviceType::Desktop],
            ["Mozilla/5.0 (Linux; Android 14; SM-S918N) $webkit Chrome/128.0.0.0 Mobile Safari/537.36 EdgA/128.0.0.0", 'Edge', 'Android', DeviceType::Mobile],
            ["$ipad Version/17.0 EdgiOS/128.0.0.0 Mobile/15E148 Safari/604.1", 'Edge', 'iPadOS', DeviceType::Tablet],
            // Firefox
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0', 'Firefox', 'Windows', DeviceType::Desktop],
            ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14.6; rv:130.0) Gecko/20100101 Firefox/130.0', 'Firefox', 'macOS', DeviceType::Desktop],
            ['Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', 'Firefox', 'Linux', DeviceType::Desktop],
            ['Mozilla/5.0 (Android 14; Mobile; rv:130.0) Gecko/130.0 Firefox/130.0', 'Firefox', 'Android', DeviceType::Mobile],
            // Safari
            ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15', 'Safari', 'macOS', DeviceType::Desktop],
            ["$ios Version/17.0 Mobile/15E148 Safari/604.1", 'Safari', 'iOS', DeviceType::Mobile],
            ["$ipad Version/17.0 Mobile/15E148 Safari/604.1", 'Safari', 'iPadOS', DeviceType::Tablet],
            // Samsung Internet
            ["Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918N) $webkit SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36", 'Samsung Internet', 'Android', DeviceType::Mobile],
            ["Mozilla/5.0 (Linux; Android 13; SAMSUNG SM-X900) $webkit SamsungBrowser/25.0 Chrome/121.0.0.0 Safari/537.36", 'Samsung Internet', 'Android', DeviceType::Tablet],
            // Whale
            ["Mozilla/5.0 (Windows NT 10.0; Win64; x64) $webkit Chrome/128.0.0.0 Whale/3.28.266.14 Safari/537.36", 'Whale', 'Windows', DeviceType::Desktop],
            ["Mozilla/5.0 (Linux; Android 14; SM-S918N) $webkit Chrome/128.0.0.0 Whale/3.0 Mobile Safari/537.36", 'Whale', 'Android', DeviceType::Mobile],
            // Opera
            ["Mozilla/5.0 (Windows NT 10.0; Win64; x64) $webkit Chrome/128.0.0.0 Safari/537.36 OPR/114.0.0.0", 'Opera', 'Windows', DeviceType::Desktop],
            ["Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) $webkit Chrome/128.0.0.0 Safari/537.36 OPR/114.0.0.0", 'Opera', 'macOS', DeviceType::Desktop],
            ["Mozilla/5.0 (X11; Linux x86_64) $webkit Chrome/128.0.0.0 Safari/537.36 OPR/114.0.0.0", 'Opera', 'Linux', DeviceType::Desktop],
            ["Mozilla/5.0 (Linux; Android 14; SM-S918N) $webkit Chrome/128.0.0.0 Mobile Safari/537.36 OPR/84.0.0.0", 'Opera', 'Android', DeviceType::Mobile],
            // 인앱 브라우저
            ["Mozilla/5.0 (Linux; Android 14; SM-S918N) $webkit Chrome/128.0.0.0 Mobile Safari/537.36 KAKAOTALK 10.0.0", 'KakaoTalk', 'Android', DeviceType::Mobile],
            ["$ios Mobile/15E148 KAKAOTALK 10.0.0", 'KakaoTalk', 'iOS', DeviceType::Mobile],
            ["Mozilla/5.0 (Linux; Android 14; SM-S918N) $webkit Chrome/128.0.0.0 Mobile Safari/537.36 NAVER(inapp; search; 1000; 12.0.0)", 'Naver App', 'Android', DeviceType::Mobile],
            ["$ios Mobile/15E148 NAVER(inapp; search; 1000; 12.0.0)", 'Naver App', 'iOS', DeviceType::Mobile],
        ];

        $this->assertCount(30, $cases);

        foreach ($cases as [$ua, $browser, $os, $device]) {
            $result = UserAgentClassifier::classify($ua);
            $this->assertSame($browser, $result['browser_name'], $ua);
            $this->assertSame($os, $result['os_name'], $ua);
            $this->assertSame($device, $result['device_type'], $ua);
        }

        $this->assertSame(7, count(array_unique(array_column($cases, 2))), '운영체제 7종을 전부 덮는다');
        $this->assertSame(9, count(array_unique(array_column($cases, 1))), '브라우저 9종을 전부 덮는다');
    }
}
