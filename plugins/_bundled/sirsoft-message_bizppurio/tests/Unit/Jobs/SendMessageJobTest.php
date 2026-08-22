<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Jobs;

use Illuminate\Http\Client\ConnectionException;
use Mockery;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchStatus;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;
use Plugins\Sirsoft\MessageBizppurio\Jobs\SendMessageJob;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioDispatchRepositoryInterface;
use Plugins\Sirsoft\MessageBizppurio\Services\BizppurioApiClient;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * SendMessageJob — 성공/일시오류 재시도/영구실패 판정 + 이력 갱신 검증(Phase 4).
 */
class SendMessageJobTest extends PluginTestCase
{
    private array $payload = [
        'account' => 'acct', 'type' => 'sms', 'from' => '070', 'to' => '010',
        'refkey' => 'ref1', 'content' => ['sms' => ['message' => 'hi']],
    ];

    /**
     * sendMessage 응답을 반환하는 ApiClient mock 을 만듭니다.
     *
     * @param  array<string, mixed>  $result  sendMessage 반환값
     */
    private function makeClient(array $result): BizppurioApiClient
    {
        $mock = Mockery::mock(BizppurioApiClient::class);
        $mock->shouldReceive('sendMessage')->with($this->payload)->andReturn($result);
        $mock->shouldReceive('isSuccess')
            ->andReturnUsing(fn ($r) => (string) ($r['code'] ?? '') === '1000');

        return $mock;
    }

    /**
     * 컨테이너에서 실제 리포지토리(Provider 바인딩)를 해석해 반환.
     */
    private function dispatches(): BizppurioDispatchRepositoryInterface
    {
        return app(BizppurioDispatchRepositoryInterface::class);
    }

    /**
     * refkey 로 pending 이력을 1건 seed.
     */
    private function seedPending(string $refkey): BizppurioDispatch
    {
        return BizppurioDispatch::create([
            'refkey' => $refkey,
            'channel' => 'sms',
            'to_number' => '01011112222',
            'content' => 'hi',
            'status' => DispatchStatus::Pending->value,
            'source' => 'auto',
        ]);
    }

    public function test_성공시_예외없이_종료하고_이력을_sent로_갱신한다(): void
    {
        $dispatch = $this->seedPending('ref1');

        $job = new SendMessageJob($this->payload, 'ref1');
        $job->handle($this->makeClient(['code' => 1000, 'messagekey' => 'mk']), $this->dispatches());

        $dispatch->refresh();
        $this->assertSame(DispatchStatus::Sent, $dispatch->status);
        $this->assertSame('mk', $dispatch->messagekey);
    }

    public function test_일시오류_결과코드는_예외를_던져_재시도한다(): void
    {
        $job = new SendMessageJob($this->payload, 'ref1');

        $this->expectException(BizppurioApiException::class);

        try {
            $job->handle($this->makeClient(['code' => 5003, 'description' => 'temp']), $this->dispatches());
        } catch (BizppurioApiException $e) {
            $this->assertSame('5003', $e->getResultCode());
            throw $e;
        }
    }

    public function test_알림톡_일시오류_결과코드는_예외를_던져_재시도한다(): void
    {
        // 알림톡 일시오류(7306 카카오 시스템오류·7307 처리지연·7421 타임아웃·7437 요청실패)는
        // 다시 보내면 성공할 수 있으므로 예외를 던져 큐가 재시도해야 한다.
        foreach (['7306', '7307', '7421', '7437'] as $code) {
            $job = new SendMessageJob($this->payload, 'ref1');

            try {
                $job->handle($this->makeClient(['code' => (int) $code, 'description' => 'temp']), $this->dispatches());
                $this->fail("코드 {$code} 는 재시도(예외)여야 한다");
            } catch (BizppurioApiException $e) {
                $this->assertSame($code, $e->getResultCode(), "코드 {$code}");
            }
        }
    }

    public function test_영구실패_결과코드는_예외없이_종료하고_이력을_failed로_갱신한다(): void
    {
        $dispatch = $this->seedPending('ref1');

        // 3006(계정오류) = 영구실패 → 재시도 안 함(예외 없음)
        $job = new SendMessageJob($this->payload, 'ref1');
        $job->handle($this->makeClient(['code' => 3006, 'description' => 'account error']), $this->dispatches());

        $dispatch->refresh();
        $this->assertSame(DispatchStatus::Failed, $dispatch->status);
        $this->assertSame('3006', $dispatch->result_code);
    }

    public function test_tries와_backoff_기본값(): void
    {
        $job = new SendMessageJob($this->payload, 'ref1');

        $this->assertSame(2, $job->tries);
        $this->assertSame(2, $job->backoff());
    }

    public function test_after_commit_활성화(): void
    {
        $job = new SendMessageJob($this->payload, 'ref1');

        $this->assertTrue($job->afterCommit);
    }

    public function test_최종실패_콜백은_pending이력을_failed로_마감하고_사유를_기록한다(): void
    {
        // 타임아웃·연결실패는 결과코드 없는 ConnectionException 으로 재시도 소진 후 failed() 로 온다.
        $dispatch = $this->seedPending('ref1');

        $job = new SendMessageJob($this->payload, 'ref1');
        $job->failed(new ConnectionException('cURL error 28: Connection timed out'));

        $dispatch->refresh();
        $this->assertSame(DispatchStatus::Failed, $dispatch->status);
        $this->assertNull($dispatch->result_code); // 전송 실패라 결과코드 없음
        $this->assertStringContainsString('timed out', (string) $dispatch->result_message);
    }

    public function test_최종실패_콜백은_bizppurio_api_exception의_결과코드를_보존한다(): void
    {
        $dispatch = $this->seedPending('ref1');

        $job = new SendMessageJob($this->payload, 'ref1');
        $job->failed(new BizppurioApiException('일시 오류', resultCode: '5003'));

        $dispatch->refresh();
        $this->assertSame(DispatchStatus::Failed, $dispatch->status);
        $this->assertSame('5003', $dispatch->result_code);
    }

    public function test_이미_확정된_이력은_최종실패_콜백이_덮어쓰지_않는다(): void
    {
        // webhook 이 먼저 success 로 확정한 뒤 failed() 가 늦게 불려도 멱등해야 한다.
        $dispatch = $this->seedPending('ref1');
        $dispatch->update(['status' => DispatchStatus::Success->value, 'result_code' => '4000']);

        $job = new SendMessageJob($this->payload, 'ref1');
        $job->failed(new ConnectionException('timed out'));

        $dispatch->refresh();
        $this->assertSame(DispatchStatus::Success, $dispatch->status);
        $this->assertSame('4000', $dispatch->result_code);
    }

    public function test_이력이_없으면_최종실패_콜백은_아무것도_하지_않는다(): void
    {
        // 이력 seed 없이 호출 — 예외 없이 조용히 종료해야 한다.
        $job = new SendMessageJob($this->payload, 'missing-ref');
        $job->failed(new ConnectionException('timed out'));

        $this->assertDatabaseMissing('bizppurio_dispatches', ['refkey' => 'missing-ref']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
