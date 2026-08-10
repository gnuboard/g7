<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Services;

use App\Contracts\Extension\CacheInterface;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkTemplateService;
use Plugins\Sirsoft\MessageBizppurio\Services\BizppurioKakaoApiClient;
use Plugins\Sirsoft\MessageBizppurio\Services\KakaoTemplateContentResolver;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * KakaoTemplateContentResolver — 카카오 템플릿 내용 조회 + 캐시 검증 (B안 5-1).
 *
 * 발송 시 카카오 상세조회로 본문·버튼·요소 원천을 가져오되, template_code 단위로
 * 캐시해 rate limit 을 억제한다. TTL=0 이면 캐시를 우회(매번 조회)하고, 조회 실패는
 * null 을 반환한다(호출측이 발송 skip).
 */
class KakaoTemplateContentResolverTest extends PluginTestCase
{
    private const IDENTIFIER = 'sirsoft-message_bizppurio';

    /**
     * 테스트 간 캐시 오염 방지 — 실제 확장 캐시 드라이버를 공유하므로 각 테스트 전 비운다.
     * (같은 template_code 키를 여러 테스트가 재사용해 캐시 히트가 교차 오염되는 것을 막는다.)
     */
    protected function setUp(): void
    {
        parent::setUp();
        app(CacheInterface::class)->flush();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function resolver(array $settings = ['bizppurio_id' => 'biz01', 'api_key' => 'key01', 'sender_key' => 'SK_40']): KakaoTemplateContentResolver
    {
        $pluginSettings = Mockery::mock(PluginSettingsService::class);
        $pluginSettings->shouldReceive('get')->with(self::IDENTIFIER)->andReturn($settings);
        // template_cache_minutes 조회 (기본 60분 → 내부에서 ×60 초)
        $pluginSettings->shouldReceive('get')
            ->with(self::IDENTIFIER, 'template_cache_minutes', Mockery::any())
            ->andReturnUsing(fn ($id, $key, $default) => $settings['template_cache_minutes'] ?? $default);

        $kakao = new BizppurioKakaoApiClient($pluginSettings);
        $templates = new AlimtalkTemplateService($kakao, $pluginSettings);

        // 실제 확장 캐시 드라이버 주입 (contextual binding 과 동일 계약)
        $cache = app(CacheInterface::class);

        return new KakaoTemplateContentResolver($templates, $cache, $pluginSettings);
    }

    /**
     * 상세조회 응답의 원본 카카오 필드(templateContent/buttons)를 그대로 반환한다.
     */
    public function test_템플릿_내용을_조회해_반환한다(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response([
                'code' => '200',
                'data' => [
                    'templateCode' => 'TW_1',
                    'templateContent' => '#{name}님 주문 완료',
                    'buttons' => [
                        ['name' => '주문조회', 'linkType' => 'WL', 'linkMo' => 'https://m.shop/#{order}'],
                    ],
                    'inspectionStatus' => 'APR',
                    'status' => 'A',
                ],
            ], 200),
        ]);

        $content = $this->resolver()->resolve('TW_1');

        $this->assertNotNull($content);
        $this->assertSame('#{name}님 주문 완료', $content['templateContent']);
        $this->assertSame('WL', $content['buttons'][0]['linkType']);
    }

    /**
     * 같은 template_code 를 두 번 조회하면 두 번째는 캐시 히트 — kapi 호출은 1회뿐.
     */
    public function test_두번째_조회는_캐시_히트로_kapi를_다시_부르지_않는다(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response([
                'code' => '200',
                'data' => ['templateCode' => 'TW_1', 'templateContent' => '본문', 'inspectionStatus' => 'APR', 'status' => 'A'],
            ], 200),
        ]);

        $resolver = $this->resolver();
        $resolver->resolve('TW_1');
        $resolver->resolve('TW_1');

        Http::assertSentCount(1);
    }

    /**
     * TTL=0 이면 캐시를 우회 — 매 조회마다 kapi 를 부른다(항상 최신).
     */
    public function test_ttl이_0이면_캐시를_우회해_매번_조회한다(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response([
                'code' => '200',
                'data' => ['templateCode' => 'TW_1', 'templateContent' => '본문', 'inspectionStatus' => 'APR', 'status' => 'A'],
            ], 200),
        ]);

        $resolver = $this->resolver(['bizppurio_id' => 'biz01', 'api_key' => 'key01', 'sender_key' => 'SK_40', 'template_cache_minutes' => 0]);
        $resolver->resolve('TW_1');
        $resolver->resolve('TW_1');

        Http::assertSentCount(2);
    }

    /**
     * 조회 실패(고아 템플릿·장애)는 null 을 반환한다(호출측이 발송 skip).
     */
    public function test_조회_실패시_null을_반환한다(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response(['code' => '7315', 'message' => '템플릿 없음'], 200),
        ]);

        $this->assertNull($this->resolver()->resolve('GONE'));
    }

    /**
     * rate limit(HTTP 429)이면 짧게 재시도한 뒤 성공하면 내용을 반환한다(조회 폭주 완화).
     */
    public function test_rate_limit_429는_재시도해_성공하면_반환한다(): void
    {
        // 첫 응답 429 → 재시도 → 두 번째 성공.
        Http::fakeSequence('kapi.ppurio.com/*')
            ->push(['code' => '5002', 'description' => 'too many requests'], 429)
            ->push([
                'code' => '200',
                'data' => ['templateCode' => 'TW_1', 'templateContent' => '본문', 'inspectionStatus' => 'APR', 'status' => 'A'],
            ], 200);

        $content = $this->resolver(['bizppurio_id' => 'biz01', 'api_key' => 'key01', 'sender_key' => 'SK_40', 'template_cache_minutes' => 0])
            ->resolve('TW_1');

        $this->assertNotNull($content);
        $this->assertSame('본문', $content['templateContent']);
        Http::assertSentCount(2);
    }

    /**
     * 재시도해도 계속 429면 null 을 반환한다(무한 재시도 방지).
     */
    public function test_rate_limit이_지속되면_null을_반환한다(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response(['code' => '5002', 'description' => 'too many requests'], 429),
        ]);

        $this->assertNull(
            $this->resolver(['bizppurio_id' => 'biz01', 'api_key' => 'key01', 'sender_key' => 'SK_40', 'template_cache_minutes' => 0])
                ->resolve('TW_1'),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
