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

    /**
     * is_test_mode 조회에만 답하는 PluginSettingsService 모의 객체를 만든다.
     *
     * @param  bool  $isTestMode  테스트 모드 여부
     * @return PluginSettingsService 모의 설정 서비스
     */
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
     * @return BizppurioTokenService 모의 토큰 서비스
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

    /**
     * 성공 코드(1000) 응답이면 발송 성공으로 판정하고 메시지 키를 돌려주며, 요청에 Bearer 토큰을 싣는다.
     *
     * @scenario case=message_send_success
     *
     * @effects message_send_success_with_bearer_token
     *
     * @return void 반환값 없음
     */
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

    /**
     * 토큰 무효(3002) 응답이면 토큰을 다시 발급받아 한 번 재시도하고 성공 응답을 돌려준다.
     *
     * @scenario case=message_token_invalid
     *
     * @effects message_send_retries_once_after_token_refresh
     *
     * @return void 반환값 없음
     */
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

    /**
     * 테스트 모드가 꺼져 있으면 운영 도메인으로 호출한다.
     *
     * @scenario case=message_live_mode
     *
     * @effects message_send_uses_live_host_when_test_mode_off
     *
     * @return void 반환값 없음
     */
    public function test_운영_모드는_live_도메인_호출(): void
    {
        Http::fake([
            'api.bizppurio.com/*' => Http::response(['code' => 1000], 200),
        ]);

        $client = new BizppurioApiClient($this->makeToken('T'), $this->makeSettings(false));
        $client->sendMessage($this->payload);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.bizppurio.com'));
    }

    /**
     * HTTP 오류 응답이면 BizppurioApiException 을 던진다.
     *
     * @scenario case=message_http_failure
     *
     * @effects message_http_failure_throws_api_exception
     *
     * @return void 반환값 없음
     */
    public function test_http_실패시_예외(): void
    {
        Http::fake(['*/v3/message' => Http::response([], 500)]);

        $client = new BizppurioApiClient($this->makeToken('T'), $this->makeSettings());

        $this->expectException(BizppurioApiException::class);
        $client->sendMessage($this->payload);
    }

    /**
     * HTTP 실패 응답에 code 와 description 이 있으면 예외에 사유와 결과코드로 담긴다.
     *
     * 회귀 배경: 실패 시 "메시지 발송 요청에 실패했습니다"만 뜨고 비즈뿌리오가 준 실제 사유와 코드를
     * 버려, 발송 이력과 로그로 원인을 알 수 없던 문제. 실패 body 를 추출해 예외에 실어 이력에 남긴다.
     *
     * @scenario case=message_http_failure_with_body
     *
     * @effects message_http_failure_carries_reason_and_result_code
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=message_http_failure_without_body
     *
     * @effects message_http_failure_falls_back_to_default_reason
     *
     * @return void 반환값 없음
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

    /**
     * 재시도 대상이 아닌 실패 코드(3006)는 응답을 그대로 돌려주고 다시 호출하지 않는다.
     *
     * @scenario case=message_permanent_failure_code
     *
     * @effects message_permanent_failure_returned_without_retry
     *
     * @return void 반환값 없음
     */
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

    /**
     * 모의 객체 기대를 검증하고 닫는다. 검증이 실패해도 부모 정리는 반드시 실행한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }
}
