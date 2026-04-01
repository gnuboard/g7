<?php

namespace Tests\Unit\Seo;

use App\Seo\BotDetector;
use Illuminate\Http\Request;
use Tests\TestCase;

class BotDetectorTest extends TestCase
{
    private BotDetector $detector;

    /**
     * 테스트 초기화 - BotDetector 인스턴스 및 기본 설정을 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new BotDetector;

        // 기본 설정: 봇 감지 활성화 + 주요 봇 UA 패턴
        config()->set('g7_settings.core.seo.bot_detection_enabled', true);
        config()->set('g7_settings.core.seo.bot_user_agents', [
            'Googlebot',
            'Bingbot',
            'Yeti',
            'Naverbot',
            'Slurp',
            'DuckDuckBot',
            'Baiduspider',
            'facebookexternalhit',
            'Twitterbot',
            'LinkedInBot',
        ]);
    }

    /**
     * 주어진 User-Agent 문자열로 Request를 생성합니다.
     *
     * @param  string  $userAgent  User-Agent 문자열
     * @param  array  $query  쿼리 파라미터
     * @return Request 생성된 요청 객체
     */
    private function createRequest(string $userAgent = '', array $query = []): Request
    {
        $server = [];
        if ($userAgent !== '') {
            $server['HTTP_USER_AGENT'] = $userAgent;
        }

        return Request::create('/', 'GET', $query, [], [], $server);
    }

    /**
     * Googlebot User-Agent를 봇으로 감지합니다.
     */
    public function test_detects_googlebot(): void
    {
        $request = $this->createRequest('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');

        $this->assertTrue($this->detector->isBot($request));
    }

    /**
     * Bingbot User-Agent를 봇으로 감지합니다.
     */
    public function test_detects_bingbot(): void
    {
        $request = $this->createRequest('Mozilla/5.0 (compatible; Bingbot/2.0; +http://www.bing.com/bingbot.htm)');

        $this->assertTrue($this->detector->isBot($request));
    }

    /**
     * Naverbot User-Agent를 봇으로 감지합니다.
     */
    public function test_detects_naverbot(): void
    {
        $request = $this->createRequest('Mozilla/5.0 (compatible; Naverbot/1.0; +http://naver.me/bot)');

        $this->assertTrue($this->detector->isBot($request));
    }

    /**
     * Yeti User-Agent를 봇으로 감지합니다.
     */
    public function test_detects_yeti(): void
    {
        $request = $this->createRequest('Yeti/1.1 (Naver Corp.; http://help.naver.com/robots/)');

        $this->assertTrue($this->detector->isBot($request));
    }

    /**
     * 일반 Chrome User-Agent는 봇이 아닙니다.
     */
    public function test_normal_chrome_ua_is_not_bot(): void
    {
        $request = $this->createRequest('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

        $this->assertFalse($this->detector->isBot($request));
    }

    /**
     * 일반 Safari User-Agent는 봇이 아닙니다.
     */
    public function test_normal_safari_ua_is_not_bot(): void
    {
        $request = $this->createRequest('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15');

        $this->assertFalse($this->detector->isBot($request));
    }

    /**
     * 빈 User-Agent는 봇이 아닙니다.
     */
    public function test_empty_ua_is_not_bot(): void
    {
        $request = $this->createRequest('');

        $this->assertFalse($this->detector->isBot($request));
    }

    /**
     * _escaped_fragment_ 쿼리 파라미터가 있으면 일반 UA여도 봇으로 감지합니다.
     */
    public function test_escaped_fragment_param_detected_as_bot(): void
    {
        $request = $this->createRequest(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
            ['_escaped_fragment_' => '']
        );

        $this->assertTrue($this->detector->isBot($request));
    }

    /**
     * 대소문자를 구분하지 않고 봇 패턴을 매칭합니다.
     */
    public function test_case_insensitive_bot_detection(): void
    {
        $request = $this->createRequest('Mozilla/5.0 (compatible; googleBot/2.1)');

        $this->assertTrue($this->detector->isBot($request));
    }

    /**
     * bot_detection_enabled=false일 때 항상 false를 반환합니다.
     */
    public function test_returns_false_when_detection_disabled(): void
    {
        config()->set('g7_settings.core.seo.bot_detection_enabled', false);

        $googlebot = $this->createRequest('Googlebot/2.1');
        $this->assertFalse($this->detector->isBot($googlebot));

        $fragment = $this->createRequest('Chrome', ['_escaped_fragment_' => '']);
        $this->assertFalse($this->detector->isBot($fragment));
    }

    /**
     * 커스텀 봇 패턴이 추가되면 해당 패턴도 매칭합니다.
     */
    public function test_custom_bot_pattern_matches(): void
    {
        config()->set('g7_settings.core.seo.bot_user_agents', [
            'Googlebot',
            'MyCustomCrawler',
        ]);

        $request = $this->createRequest('MyCustomCrawler/1.0');

        $this->assertTrue($this->detector->isBot($request));
    }

    /**
     * bot_user_agents가 빈 배열이면 모든 요청이 봇이 아닙니다.
     */
    public function test_empty_bot_patterns_returns_false_for_all(): void
    {
        config()->set('g7_settings.core.seo.bot_user_agents', []);

        $googlebot = $this->createRequest('Googlebot/2.1');
        $this->assertFalse($this->detector->isBot($googlebot));

        $chrome = $this->createRequest('Mozilla/5.0 Chrome/120.0.0.0');
        $this->assertFalse($this->detector->isBot($chrome));
    }
}
