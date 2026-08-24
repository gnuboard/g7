<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;
use Plugins\Sirsoft\MessageBizppurio\Services\BizppurioApiClient;
use Plugins\Sirsoft\MessageBizppurio\Services\BizppurioTokenService;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * BizppurioApiClient — /v3/message 발송·결과코드·토큰 재발급 재시도 검증.
 */
class BizppurioApiClientTest extends PluginTestCase
{
    private const IDENTIFIER = 'sirsoft-message_bizppurio';

    private array $payload = [
        'account' => 'acct',
        'type' => 'sms',
        'from' => '07012345678',
        'to' => '01011112222',
        'refkey' => 'ref1',
        'content' => ['sms' => ['message' => 'hi']],
    ];

    private function makeSettings(bool $isTestMode = true): PluginSettingsService
    {
        $mock = Mockery::mock(PluginSettingsService::class);
        $mock->shouldReceive('get')
            ->with(self::IDENTIFIER, 'is_test_mode', true)
            ->andReturn($isTestMode);

        return $mock;
    }

    /**
     * getToken/refreshToken 을 순차 반환하는 TokenService mock.
     *
     * @param  string  $token  getToken 반환 토큰
     * @param  string|null  $refreshed  refreshToken 반환 토큰(null 이면 미기대)
     */
    private function makeToken(string $token, ?string $refreshed = null): BizppurioTokenService
    {
        $mock = Mockery::mock(BizppurioTokenService::class);
        $mock->shouldReceive('getToken')->andReturn($token);

        if ($refreshed !== null) {
            $mock->shouldReceive('refreshToken')->once()->andReturn($refreshed);
        }

        return $mock;
    }

    public function test_발송_성공(): void
    {
        Http::fake([
            '*/v3/message' => Http::response([
                'code' => 1000, 'description' => 'Success', 'refkey' => 'ref1', 'messagekey' => 'mk1',
            ], 200),
        ]);

        $client = new BizppurioApiClient($this->makeToken('TOKEN'), $this->makeSettings());
        $result = $client->sendMessage($this->payload);

        $this->assertTrue($client->isSuccess($result));
        $this->assertSame('mk1', $result['messagekey']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer TOKEN'));
    }

    public function test_토큰무효_3002는_재발급_후_1회_재시도(): void
    {
        Http::fake([
            '*/v3/message' => Http::sequence()
                ->push(['code' => 3002, 'description' => 'Invalid token'], 200)
                ->push(['code' => 1000, 'description' => 'Success', 'messagekey' => 'mk2'], 200),
        ]);

        $client = new BizppurioApiClient(
            $this->makeToken('OLD', 'NEW'),
            $this->makeSettings(),
        );
        $result = $client->sendMessage($this->payload);

        $this->assertTrue($client->isSuccess($result));
        Http::assertSentCount(2);
    }

    public function test_운영_모드는_live_도메인_호출(): void
    {
        Http::fake([
            'api.bizppurio.com/*' => Http::response(['code' => 1000], 200),
        ]);

        $client = new BizppurioApiClient($this->makeToken('T'), $this->makeSettings(false));
        $client->sendMessage($this->payload);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.bizppurio.com'));
    }

    public function test_http_실패시_예외(): void
    {
        Http::fake(['*/v3/message' => Http::response([], 500)]);

        $client = new BizppurioApiClient($this->makeToken('T'), $this->makeSettings());

        $this->expectException(BizppurioApiException::class);
        $client->sendMessage($this->payload);
    }

    /**
     * HTTP 실패 응답에 code·description 이 있으면 예외에 사유·결과코드로 담긴다.
     *
     * 회귀 배경: 실패 시 "메시지 발송 요청에 실패했습니다"만 뜨고 비즈뿌리오가 준 실제 사유·코드를
     * 버려, 발송 이력·로그로 원인을 알 수 없던 문제. 실패 body 를 추출해 예외에 실어 이력에 남긴다.
     */
    public function test_http_실패시_응답_body_의_사유와_코드가_예외에_담긴다(): void
    {
        Http::fake([
            '*/v3/message' => Http::response(
                ['code' => 7103, 'description' => '발신 프로필 키가 유효하지 않음'],
                400,
            ),
        ]);

        $client = new BizppurioApiClient($this->makeToken('T'), $this->makeSettings());

        try {
            $client->sendMessage($this->payload);
            $this->fail('예외가 발생해야 한다.');
        } catch (BizppurioApiException $e) {
            $this->assertSame('7103', $e->getResultCode(), '실패 body 의 code 가 예외에 담겨야 한다.');
            $this->assertSame('발신 프로필 키가 유효하지 않음', $e->getMessage(), '실패 body 의 description 이 예외 메시지로 담겨야 한다.');
        }
    }

    /**
     * HTTP 실패 응답에 body 가 없으면 기본 사유로 폴백하고 결과코드는 null.
     */
    public function test_http_실패시_body_없으면_기본_사유_폴백(): void
    {
        Http::fake(['*/v3/message' => Http::response([], 500)]);

        $client = new BizppurioApiClient($this->makeToken('T'), $this->makeSettings());

        try {
            $client->sendMessage($this->payload);
            $this->fail('예외가 발생해야 한다.');
        } catch (BizppurioApiException $e) {
            $this->assertNull($e->getResultCode());
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function test_영구실패_결과코드는_그대로_반환(): void
    {
        Http::fake([
            '*/v3/message' => Http::response(['code' => 3006, 'description' => 'Account error'], 200),
        ]);

        $client = new BizppurioApiClient($this->makeToken('T'), $this->makeSettings());
        $result = $client->sendMessage($this->payload);

        $this->assertFalse($client->isSuccess($result));
        $this->assertSame(3006, $result['code']);
        // 3006 은 재시도 대상 아님 → HTTP 1회
        Http::assertSentCount(1);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
