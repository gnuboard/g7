<?php

namespace Tests\Unit\Analytics;

use App\Analytics\AnalyticsBotClassifier;
use App\Analytics\AnalyticsConfig;
use App\Seo\BotDetector;
use Illuminate\Http\Request;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * 봇 판별 — SEO 위임·사전 폴백·결과 캐시·이름.
 *
 * SEO 의 봇 감지를 끈 사이트에서 이 폴백이 약하면 크롤러가 사람 방문으로 섞여 인기
 * 페이지가 통째로 뒤집힌다. 그 상태는 숫자가 커질 뿐 오류가 아니라 화면으로는 못 본다.
 */
class AnalyticsBotClassifierTest extends TestCase
{
    /** 사람 브라우저 UA */
    private const HUMAN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

    /** 검색 봇 UA */
    private const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    /**
     * SEO 봇 감지가 켜져 있으면 그 판별기의 판정을 그대로 따른다.
     *
     * @scenario visitor=bot, seo=on
     *
     * @effects bot_requests_never_enter_the_visit_ledger
     *
     * @return void 없음
     */
    public function test_delegates_to_seo_detector_when_enabled(): void
    {
        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->mock(BotDetector::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isBot')->once()->andReturn(true);
        });

        $request = Request::create('/', 'GET', server: ['HTTP_USER_AGENT' => self::HUMAN]);

        // 사람 UA 인데도 SEO 가 봇이라 하면 봇이다 — 두 기능의 판정이 갈리면 안 된다.
        $this->assertTrue(app(AnalyticsBotClassifier::class)->isBot($request, AnalyticsConfig::fromSettings()));
    }

    /**
     * SEO 봇 감지가 꺼져 있으면 같은 크롤러 사전으로 직접 판정하고 제외 UA 패턴을 더한다.
     *
     * @scenario visitor=bot, seo=off
     *
     * @effects bot_requests_never_enter_the_visit_ledger
     *
     * @return void 없음
     */
    public function test_falls_back_to_crawler_dictionary_with_excluded_user_agents(): void
    {
        config(['g7_settings.core.seo.bot_detection_enabled' => false]);
        config(['g7_settings.core.analytics.excluded_user_agents' => ['ZzCustomProbe']]);

        $this->mock(BotDetector::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('isBot');
        });

        $classifier = app(AnalyticsBotClassifier::class);
        $config = AnalyticsConfig::fromSettings();

        $cases = [
            [self::GOOGLEBOT, true, '사전이 아는 봇'],
            ['Mozilla/5.0 (compatible; Bytespider; spider-feedback@bytedance.com)', true, '손으로 적은 목록에 없던 봇도 사전이 잡는다'],
            ['ZzCustomProbe/1.0 (+https://probe.example)', true, '운영자가 통계 설정에 적은 제외 UA'],
            ['Mozilla/5.0 (X11; Linux x86_64) HeadlessChrome/128.0.0.0 Safari/537.36', true, '헤드리스 패턴'],
            [self::HUMAN, false, '사람 브라우저'],
            ['', false, 'UA 없는 요청은 코어 판별기(BotDetector)와 같이 사람으로 둔다 — 계획 §4.4 292'],
        ];

        foreach ($cases as [$ua, $expected, $why]) {
            $request = Request::create('/', 'GET', server: ['HTTP_USER_AGENT' => $ua]);
            $this->assertSame($expected, $classifier->isBot($request, $config), $why);
        }

        // 판정 토글을 끄면 봇도 사람으로 센다 — 운영자의 결정이다.
        config(['g7_settings.core.analytics.bot_detector_enabled' => false]);
        $request = Request::create('/', 'GET', server: ['HTTP_USER_AGENT' => self::GOOGLEBOT]);
        $this->assertFalse($classifier->isBot($request, AnalyticsConfig::fromSettings()));
    }

    /**
     * 판정 결과는 요청 속성에 캐시되어 같은 요청에서 두 번 계산하지 않는다.
     *
     * @scenario visitor=bot, seo=on
     *
     * @effects bot_requests_never_enter_the_visit_ledger
     *
     * @return void 없음
     */
    public function test_result_is_cached_on_request_attributes(): void
    {
        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        $this->mock(BotDetector::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isBot')->once()->andReturn(false);
        });

        $classifier = app(AnalyticsBotClassifier::class);
        $config = AnalyticsConfig::fromSettings();
        $request = Request::create('/', 'GET', server: ['HTTP_USER_AGENT' => self::HUMAN]);

        $this->assertFalse($classifier->isBot($request, $config));
        $this->assertTrue($request->attributes->has('analytics.is_bot'));
        $this->assertFalse($classifier->isBot($request, $config), '두 번째 호출은 캐시를 읽어야 한다(판별기 once)');
    }

    /**
     * 봇 이름은 사전이 맞춘 표기이고, 모르는 봇은 UA 앞 60자다.
     *
     * 같은 봇이 버전마다 다른 이름으로 갈라지면 봇 순위가 의미를 잃는다.
     *
     * @scenario visitor=bot
     *
     * @effects bot_requests_never_enter_the_visit_ledger
     *
     * @return void 없음
     */
    public function test_bot_name_comes_from_dictionary_match(): void
    {
        $this->assertSame('Googlebot', AnalyticsBotClassifier::botName(self::GOOGLEBOT));
        $this->assertSame(
            AnalyticsBotClassifier::botName('Mozilla/5.0 (compatible; Googlebot/2.1)'),
            AnalyticsBotClassifier::botName('Googlebot/3.0 (+http://www.google.com/bot.html)'),
            '버전이 달라도 같은 이름이어야 한다',
        );
        $this->assertSame('ZzCustomProbe', AnalyticsBotClassifier::botName('ZzCustomProbe/1.0 (+https://probe.example)', ['ZzCustomProbe']));

        $unknown = str_repeat('Z', 80);
        $this->assertSame(str_repeat('Z', 60), AnalyticsBotClassifier::botName($unknown));
        $this->assertSame('Unknown', AnalyticsBotClassifier::botName(''));
    }
}
