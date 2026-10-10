<?php

namespace Tests\Feature\Analytics;

use App\Analytics\ReferrerClassifier;
use App\Analytics\TrackingRequestFactory;
use App\Analytics\UrlNormalizer;
use App\Http\Middleware\Analytics\TrackBotHit;
use App\Models\Analytics\AnalyticsBotHit;
use App\Services\Analytics\ConversionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 방문 통계가 요청 Host 를 저장하지 않고, 주소에 실린 토큰·이메일을 남기지 않는지 검증합니다.
 *
 * 저장되는 페이지 주소는 요청 Host 가 아니라 사이트 주소 + 요청 경로로 만든다. 주소 쿼리의 비밀번호
 * 재설정 토큰·이메일·본인인증 토큰은 통계를 볼 수 있는 운영자에게 노출되므로 정규화 단계에서 뺀다.
 */
class AnalyticsHostTrustTest extends TestCase
{
    use RefreshDatabase;

    /** 테스트 사이트 주소 */
    private const SITE = 'https://shop.example.test';

    /** 검색엔진 UA */
    private const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    /**
     * 사이트 주소를 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => self::SITE, 'g7_settings.core.general.site_url' => self::SITE]);
    }

    /**
     * 신뢰 프록시 상태를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    /**
     * 위조 Host 변종.
     *
     * @return array<string, array{0: string, 1: array<string, string>}> 요청 루트와 추가 서버 변수
     */
    public static function forgedHosts(): array
    {
        return [
            'canonical' => ['https://shop.example.test', []],
            'external' => ['https://attacker.example', []],
            'subdomain' => ['https://attacker.shop.example.test', []],
            'port' => ['https://shop.example.test:6666', []],
            'forwarded' => ['http://10.0.0.5', ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_HOST' => 'attacker.example', 'HTTP_X_FORWARDED_PROTO' => 'https']],
        ];
    }

    /**
     * 위조 요청을 만듭니다.
     *
     * @param  string  $root  요청 루트
     * @param  string  $path  경로
     * @param  array<string, string>  $server  추가 서버 변수
     * @return Request 위조 요청
     */
    private function forged(string $root, string $path, array $server): Request
    {
        if (isset($server['HTTP_X_FORWARDED_HOST'])) {
            Request::setTrustedProxies(['10.0.0.5'], Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO);
        }

        return Request::create($root.$path, 'GET', [], [], [], $server + ['REMOTE_ADDR' => '203.0.113.50']);
    }

    /**
     * 서버 폴백 수집의 방문 주소는 사이트 주소 + 요청 경로다.
     *
     * @scenario host_header=forged, sink=analytics
     *
     * @effects analytics_page_url_uses_site_origin
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_server_fallback_url_ignores_forged_host(string $root, array $server): void
    {
        $tracking = TrackingRequestFactory::fromHttp($this->forged($root, '/shop/products?x=1', $server), [], 'server');

        $this->assertSame(self::SITE.'/shop/products?x=1', $tracking->url);
    }

    /**
     * 봇 요청 기록의 주소도 사이트 주소 + 요청 경로다.
     *
     * @scenario host_header=forged, sink=analytics
     *
     * @effects analytics_page_url_uses_site_origin
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_bot_hit_url_ignores_forged_host(string $root, array $server): void
    {
        config(['g7_settings.core.seo.bot_detection_enabled' => true]);

        app(TrackBotHit::class)->terminate(
            $this->forged($root, '/board/free', $server + ['HTTP_USER_AGENT' => self::GOOGLEBOT]),
            new Response('<html></html>', 200, ['Content-Type' => 'text/html']),
        );

        $this->assertSame([self::SITE.'/board/free'], AnalyticsBotHit::query()->pluck('url')->all());
    }

    /**
     * 서버 측 전환의 화면 주소 — 사이트 주소의 Referer 만 믿고, 요청 Host 와 같은 Referer 는 믿지 않는다.
     *
     * @scenario host_header=forged, sink=analytics
     *
     * @effects analytics_referer_self_check_uses_site_host
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_conversion_page_url_ignores_forged_host(string $root, array $server): void
    {
        $method = new \ReflectionMethod(ConversionRecorder::class, 'pageUrl');
        $method->setAccessible(true);
        $recorder = app(ConversionRecorder::class);

        $request = $this->forged($root, '/api/auth/register', $server + ['HTTP_REFERER' => $root.'/landing']);
        $expected = $root === self::SITE ? self::SITE.'/landing' : self::SITE.'/api/auth/register';
        $this->assertSame($expected, $method->invoke($recorder, $request));

        $request = $this->forged($root, '/api/auth/register', $server + ['HTTP_REFERER' => self::SITE.'/signup']);
        $this->assertSame(self::SITE.'/signup', $method->invoke($recorder, $request));
    }

    /**
     * 재설정·본인인증 토큰과 이메일은 페이지 이름과 유입 주소에 남지 않는다.
     *
     * @scenario host_header=canonical, sink=analytics
     *
     * @effects analytics_strips_credential_query
     *
     * @return void 반환값 없음
     */
    public function test_credential_query_is_stripped(): void
    {
        $name = UrlNormalizer::normalize(
            self::SITE.'/reset-password?token=PROBETOKEN&email=probe%40example.test&verification_token=VT&challenge_id=CH&signature=SG&expires=1&pv=1'
        )['name'];

        $this->assertSame('shop.example.test/reset-password?pv=1', $name);

        $classified = ReferrerClassifier::classify(
            'https://other.example/identity/verify?challenge=c&token=PROBETOKEN&verification_token=VT&page=2',
        );

        $this->assertSame('https://other.example/identity/verify?challenge=c&page=2', $classified['url']);
    }
}
