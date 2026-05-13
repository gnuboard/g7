<?php

namespace Tests\Unit\Seo;

use App\Extension\HookManager;
use App\Seo\BotDetector;
use App\Seo\BotDetectorCustomProvider;
use Illuminate\Http\Request;
use Tests\TestCase;

class BotDetectorTest extends TestCase
{
    private BotDetector $detector;

    /**
     * 테스트 초기화 - jaybizzle 라이브러리 활성화 + G7 보강 패턴 검증을 위한 기본 상태.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new BotDetector;

        config()->set('g7_settings.core.seo.bot_detection_enabled', true);
        config()->set('g7_settings.core.seo.bot_detection_library_enabled', true);
        config()->set('g7_settings.core.seo.bot_user_agents', []);
    }

    /**
     * 테스트 종료 시 동적 등록한 훅 리스너를 정리합니다.
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter('core.seo.resolve_is_bot');
        parent::tearDown();
    }

    /**
     * 주어진 User-Agent / 쿼리로 Request 를 생성합니다.
     *
     * @param  string  $userAgent  User-Agent 문자열
     * @param  array<string, mixed>  $query  쿼리 파라미터
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

    // ─── 체인 1~3 단계 ────────────────────────────────────────────────────

    public function test_bot_detection_disabled_returns_false_for_all(): void
    {
        config()->set('g7_settings.core.seo.bot_detection_enabled', false);

        $this->assertFalse($this->detector->isBot(
            $this->createRequest('Mozilla/5.0 (compatible; Googlebot/2.1)')
        ));
        $this->assertFalse($this->detector->isBot(
            $this->createRequest('Chrome/120.0', ['_escaped_fragment_' => ''])
        ));
    }

    public function test_escaped_fragment_query_returns_true(): void
    {
        $request = $this->createRequest('Mozilla/5.0 Chrome/120.0', ['_escaped_fragment_' => '']);

        $this->assertTrue($this->detector->isBot($request));
    }

    public function test_empty_user_agent_returns_false(): void
    {
        $this->assertFalse($this->detector->isBot($this->createRequest('')));
    }

    // ─── 체인 4단계: 훅 슬롯 ──────────────────────────────────────────────

    public function test_hook_returning_true_short_circuits_to_bot(): void
    {
        HookManager::addFilter('core.seo.resolve_is_bot', fn ($prev, $ctx) => true);

        // 일반 Chrome 인데도 훅이 true 면 봇으로 결정.
        $this->assertTrue($this->detector->isBot(
            $this->createRequest('Mozilla/5.0 (Windows NT 10.0) Chrome/120.0')
        ));
    }

    public function test_hook_returning_false_short_circuits_to_not_bot(): void
    {
        HookManager::addFilter('core.seo.resolve_is_bot', fn ($prev, $ctx) => false);

        // jaybizzle 가 알아서 잡을 GPTBot 도 훅이 false 면 봇 아님.
        $this->assertFalse($this->detector->isBot(
            $this->createRequest('Mozilla/5.0 (compatible; GPTBot/1.2)')
        ));
    }

    public function test_hook_returning_null_falls_through_to_library(): void
    {
        HookManager::addFilter('core.seo.resolve_is_bot', fn ($prev, $ctx) => null);

        $this->assertTrue($this->detector->isBot(
            $this->createRequest('Mozilla/5.0 (compatible; GPTBot/1.2)')
        ));
    }

    // ─── 체인 5단계: jaybizzle 라이브러리 경로 ───────────────────────────

    /**
     * jaybizzle 1.3.9 가 자체 픽스처로 잡는 대표 봇들 — 라이브러리 통합이
     * 실제로 동작하는지 한 케이스만 회귀 보장.
     */
    public function test_library_detects_gptbot(): void
    {
        $this->assertTrue($this->detector->isBot(
            $this->createRequest('Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)')
        ));
    }

    public function test_library_detects_facebookexternalhit(): void
    {
        $this->assertTrue($this->detector->isBot(
            $this->createRequest('facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)')
        ));
    }

    public function test_library_does_not_match_normal_chrome(): void
    {
        $this->assertFalse($this->detector->isBot(
            $this->createRequest('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
        ));
    }

    // ─── G7 보강 패턴 (jaybizzle 미커버) ─────────────────────────────────

    public function test_custom_provider_detects_kakaotalk_scrap(): void
    {
        $this->assertTrue($this->detector->isBot(
            $this->createRequest('facebookexternalhit/1.1;kakaotalk-scrap/1.0;+https://devtalk.kakao.com/t/scrap/33984')
        ));
    }

    public function test_custom_provider_detects_meta_external_agent(): void
    {
        $this->assertTrue($this->detector->isBot(
            $this->createRequest('meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)')
        ));
    }

    public function test_custom_provider_detects_chatgpt_user(): void
    {
        $this->assertTrue($this->detector->isBot(
            $this->createRequest('Mozilla/5.0 ChatGPT-User/1.0')
        ));
    }

    // ─── 사용자 커스텀 패턴 (라이브러리에 주입) ──────────────────────────

    public function test_user_custom_pattern_matches_via_library(): void
    {
        config()->set('g7_settings.core.seo.bot_user_agents', ['MyOrgCrawler']);

        $this->assertTrue($this->detector->isBot(
            $this->createRequest('MyOrgCrawler/2.0')
        ));
    }

    /**
     * 회귀: 운영자 커스텀 패턴이 jaybizzle Exclusions 와 충돌하지 않아야 함.
     *
     * 라이브러리는 isCrawler() 첫 단계에서 일반 브라우저 식별자(Firefox/X.Y, Chrome/X.Y,
     * Safari/X.Y, Mozilla/X.Y) 를 UA 에서 strip 한다. 운영자가 "Firefox" 패턴을 추가해도
     * strip 후 매칭 시도하면 false 가 되어 라이브러리 + 사용자 패턴이 함께 작동하지 않음.
     *
     * 본 회귀 가드: 운영자가 "Firefox" 등 브라우저 식별자를 봇 패턴으로 추가하면,
     * 라이브러리 활성 상태에서도 raw UA 에 직접 매칭하여 감지되어야 함.
     */
    public function test_user_custom_pattern_matches_browser_identifier_excluded_by_library(): void
    {
        config()->set('g7_settings.core.seo.bot_user_agents', ['Firefox']);

        $this->assertTrue($this->detector->isBot(
            $this->createRequest('Mozilla/5.0 (X11; Linux x86_64; rv:78.0) Gecko/20100101 Firefox/78.0')
        ));
    }

    public function test_user_custom_pattern_treats_regex_meta_as_literal(): void
    {
        config()->set('g7_settings.core.seo.bot_user_agents', ['foo.bar+baz']);

        $this->assertTrue($this->detector->isBot(
            $this->createRequest('foo.bar+baz client')
        ));
        // '.', '+' 가 정규식 메타로 해석되었다면 'fooXbarYbaz' 도 매치되었을 것.
        $this->assertFalse($this->detector->isBot(
            $this->createRequest('fooXbarYbaz client')
        ));
    }

    // ─── 체인 6단계: 라이브러리 비활성 (레거시 stripos 경로) ───────────

    public function test_library_disabled_falls_back_to_custom_only(): void
    {
        config()->set('g7_settings.core.seo.bot_detection_library_enabled', false);
        config()->set('g7_settings.core.seo.bot_user_agents', ['LegacyBot']);

        $this->assertTrue($this->detector->isBot(
            $this->createRequest('LegacyBot/1.0')
        ));
        // 라이브러리 비활성 이므로 GPTBot 도 잡지 못함 (커스텀 목록에 없으므로).
        $this->assertFalse($this->detector->isBot(
            $this->createRequest('Mozilla/5.0 (compatible; GPTBot/1.2)')
        ));
    }

    public function test_library_disabled_with_empty_custom_returns_false(): void
    {
        config()->set('g7_settings.core.seo.bot_detection_library_enabled', false);
        config()->set('g7_settings.core.seo.bot_user_agents', []);

        $this->assertFalse($this->detector->isBot(
            $this->createRequest('Mozilla/5.0 (compatible; Googlebot/2.1)')
        ));
    }

    public function test_legacy_path_is_case_insensitive(): void
    {
        config()->set('g7_settings.core.seo.bot_detection_library_enabled', false);
        config()->set('g7_settings.core.seo.bot_user_agents', ['Googlebot']);

        $this->assertTrue($this->detector->isBot(
            $this->createRequest('mozilla/5.0 (compatible; googleBot/2.1)')
        ));
    }

    // ─── 직접 커스텀 프로바이더 단위 테스트 ──────────────────────────────

    public function test_custom_provider_isolated_unit(): void
    {
        $provider = new BotDetectorCustomProvider(['MyBot']);

        $this->assertTrue($provider->isCrawler('kakaotalk-scrap/1.0'));
        $this->assertTrue($provider->isCrawler('Meta-ExternalAgent/1.1'));
        $this->assertTrue($provider->isCrawler('ChatGPT-User/1.0'));
        $this->assertTrue($provider->isCrawler('MyBot v1'));
        $this->assertTrue($provider->isCrawler('Mozilla/5.0 (compatible; GPTBot/1.2)'));
        $this->assertFalse($provider->isCrawler('Mozilla/5.0 Chrome/120.0'));
    }
}
