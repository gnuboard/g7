<?php

namespace Tests\Feature\Analytics;

use App\Http\Middleware\Analytics\TrackBotHit;
use App\Models\Analytics\AnalyticsBotHit;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * 봇 요청 기록 미들웨어 — 봇 원장·캐시 상태·SEO 꺼짐 폴백·토글.
 *
 * 봇을 따로 세지 않으면 크롤러가 인기 페이지 1위가 된다. 이 원장은 사람 원장과
 * 분리되어야 하고, SEO 캐시가 듣는지도 함께 남아야 운영자가 크롤러 부하를 본다.
 */
class TrackBotHitMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    /** 검색 봇 UA */
    private const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    /** 사람 브라우저 UA */
    private const HUMAN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

    /**
     * 요청 하나를 만듭니다.
     *
     * @param  string  $userAgent  브라우저 식별 문자열
     * @param  string  $path  요청 경로
     * @return Request 만든 HTTP 요청
     */
    private function request(string $userAgent, string $path = '/board/free'): Request
    {
        return Request::create('https://example.test'.$path, 'GET', server: [
            'HTTP_USER_AGENT' => $userAgent,
            'REMOTE_ADDR' => '203.0.113.50',
        ]);
    }

    /**
     * 응답 하나를 만듭니다.
     *
     * @param  string|null  $cacheHeader  X-SEO-Cache 값
     * @return Response 응답
     */
    private function response(?string $cacheHeader = null): Response
    {
        $headers = ['Content-Type' => 'text/html'];

        if ($cacheHeader !== null) {
            $headers['X-SEO-Cache'] = $cacheHeader;
        }

        return new Response('<html></html>', 200, $headers);
    }

    /**
     * Googlebot 은 봇 원장에 1행 남고 캐시 상태 4값이 그대로 적힌다.
     *
     * @scenario source=server, visitor=bot
     *
     * @effects bot_hits_are_recorded_with_cache_status, bot_requests_never_enter_the_visit_ledger
     *
     * @return void 없음
     */
    public function test_googlebot_records_hit_with_cache_status(): void
    {
        config(['g7_settings.core.seo.bot_detection_enabled' => true]);
        // 기록 주소는 사이트 주소 + 요청 경로다 — 운영자 설정이 부팅 config 로 새어 들지 않게 사이트 주소를 고정한다.
        config(['app.url' => 'https://example.test']);
        $middleware = app(TrackBotHit::class);

        foreach (['HIT', 'MISS', 'BYPASS', null] as $header) {
            $middleware->terminate($this->request(self::GOOGLEBOT), $this->response($header));
        }

        $this->assertSame(4, AnalyticsBotHit::count());
        $this->assertEqualsCanonicalizing(['hit', 'miss', 'bypass', 'none'], AnalyticsBotHit::pluck('cache_status')->all());
        $this->assertSame('Googlebot', AnalyticsBotHit::first()->bot_name);
        $this->assertSame('https://example.test/board/free', AnalyticsBotHit::first()->url);
        $this->assertSame(0, AnalyticsVisit::count(), '봇은 사람 원장에 들어가지 않는다');
    }

    /**
     * SEO 가 이미 봇으로 판정한 요청은 판별기를 거치지 않고 기록된다.
     *
     * @scenario source=server, visitor=bot
     *
     * @effects bot_hits_are_recorded_with_cache_status
     *
     * @return void 없음
     */
    public function test_seo_bot_attribute_is_trusted(): void
    {
        $request = $this->request(self::HUMAN);
        $request->attributes->set('seo.is_bot', true);

        app(TrackBotHit::class)->terminate($request, $this->response('HIT'));

        $this->assertSame(1, AnalyticsBotHit::count());
    }

    /**
     * SEO 봇 감지를 껐어도 통계는 같은 크롤러 사전으로 봇을 잡는다.
     *
     * @scenario source=server, visitor=bot, seo=off
     *
     * @effects bot_hits_are_recorded_with_cache_status
     *
     * @return void 없음
     */
    public function test_seo_detection_off_still_records_via_classifier(): void
    {
        config(['g7_settings.core.seo.bot_detection_enabled' => false]);

        app(TrackBotHit::class)->terminate($this->request(self::GOOGLEBOT), $this->response());

        $this->assertSame(1, AnalyticsBotHit::count());
        $this->assertSame('none', AnalyticsBotHit::first()->cache_status);
    }

    /**
     * 사람 브라우저는 봇 원장에 남지 않는다.
     *
     * @scenario source=server, visitor=guest
     *
     * @effects bot_hits_are_recorded_with_cache_status
     *
     * @return void 없음
     */
    public function test_human_user_agent_records_nothing(): void
    {
        app(TrackBotHit::class)->terminate($this->request(self::HUMAN), $this->response('MISS'));

        $this->assertSame(0, AnalyticsBotHit::count());
    }

    /**
     * 봇 집계를 끄면 봇 UA 도 남지 않는다.
     *
     * @scenario source=server, visitor=bot, exclusion=disabled
     *
     * @effects bot_hits_are_recorded_with_cache_status
     *
     * @return void 없음
     */
    public function test_bot_tracking_toggle_off_records_nothing(): void
    {
        config(['g7_settings.core.analytics.bot_tracking_enabled' => false]);

        app(TrackBotHit::class)->terminate($this->request(self::GOOGLEBOT), $this->response('HIT'));

        $this->assertSame(0, AnalyticsBotHit::count());
    }

    /**
     * 봇 원장의 IP 도 사람 원장과 같은 마스킹 규칙을 따른다.
     *
     * @scenario source=server, visitor=bot, mask_octets=1
     *
     * @effects bot_hit_log_applies_the_same_ip_masking_rule
     *
     * @return void 없음
     */
    public function test_bot_hit_ip_is_masked_like_visits(): void
    {
        config(['g7_settings.core.analytics.ip_mask_octets' => 1]);

        app(TrackBotHit::class)->terminate($this->request(self::GOOGLEBOT), $this->response());

        $this->assertSame('203.0.113.0', AnalyticsBotHit::first()->ip);
    }
}
