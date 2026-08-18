<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;
use Plugins\Sirsoft\MessageBizppurio\Services\BizppurioKakaoApiClient;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * BizppurioKakaoApiClient — kapi 단일 도메인, bizId+apiKey body 인증 검증.
 */
class BizppurioKakaoApiClientTest extends PluginTestCase
{
    private const IDENTIFIER = 'sirsoft-message_bizppurio';

    /**
     * @param  array<string, string>  $settings
     */
    private function makeSettings(array $settings): PluginSettingsService
    {
        $mock = Mockery::mock(PluginSettingsService::class);
        $mock->shouldReceive('get')->with(self::IDENTIFIER)->andReturn($settings);

        return $mock;
    }

    private function client(): BizppurioKakaoApiClient
    {
        return new BizppurioKakaoApiClient(
            $this->makeSettings(['bizppurio_id' => 'biz01', 'api_key' => 'key01']),
        );
    }

    public function test_발신프로필_조회는_bizid_apikey를_body에_싣는다(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response(['code' => '200', 'data' => ['success' => []]], 200),
        ]);

        $result = $this->client()->getSenderProfiles();

        $this->assertTrue($this->client()->isSuccess($result));
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'kapi.ppurio.com/v3/kakao/profile/use')
                && $request['bizId'] === 'biz01'
                && $request['apiKey'] === 'key01';
        });
    }

    public function test_템플릿_목록은_senderkey를_포함한다(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response(['code' => '200', 'data' => []], 200),
        ]);

        $this->client()->getTemplateList('SENDER_KEY', ['count' => 20]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v3/kakao/template/list')
                && $request['senderKey'] === 'SENDER_KEY'
                && $request['count'] === 20
                && $request['bizId'] === 'biz01';
        });
    }

    public function test_템플릿_상세_조회(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response(['code' => '200', 'data' => ['templateCode' => 'TW_1']], 200),
        ]);

        $result = $this->client()->getTemplateDetail('SK', 'TW_1');

        $this->assertSame('TW_1', $result['data']['templateCode']);
    }

    public function test_임의_엔드포인트_request_위임(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response(['code' => '200'], 200),
        ]);

        $this->client()->request('/v3/kakao/template/add', ['templateName' => 'T']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/kakao/template/add')
            && $request['templateName'] === 'T');
    }

    public function test_자격증명_미설정시_예외(): void
    {
        $client = new BizppurioKakaoApiClient(
            $this->makeSettings(['bizppurio_id' => 'biz01', 'api_key' => '']),
        );

        $this->expectException(BizppurioApiException::class);
        $client->getSenderProfiles();
    }

    public function test_http_실패시_예외(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response([], 500)]);

        $this->expectException(BizppurioApiException::class);
        $this->client()->getSenderProfiles();
    }

    public function test_http_실패시_카카오_message와_code를_예외에_싣는다(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response(
                ['code' => '403', 'message' => '접근할 수 없는 IP 입니다. (114.207.113.206)'],
                403,
            ),
        ]);

        try {
            $this->client()->getSenderProfiles();
            $this->fail('BizppurioApiException 이 발생해야 한다.');
        } catch (BizppurioApiException $e) {
            $this->assertSame('접근할 수 없는 IP 입니다. (114.207.113.206)', $e->getMessage());
            $this->assertSame('403', $e->getResultCode());
            $this->assertSame(403, $e->getHttpStatus());
        }
    }

    public function test_http_실패_body에_message가_없으면_일반문구로_폴백한다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response([], 500)]);

        try {
            $this->client()->getSenderProfiles();
            $this->fail('BizppurioApiException 이 발생해야 한다.');
        } catch (BizppurioApiException $e) {
            $this->assertSame(
                __('sirsoft-message_bizppurio::messages.error.kakao_request_failed'),
                $e->getMessage(),
            );
            $this->assertNull($e->getResultCode());
            $this->assertSame(500, $e->getHttpStatus());
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
