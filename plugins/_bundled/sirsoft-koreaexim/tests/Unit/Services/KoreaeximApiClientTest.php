<?php

namespace Plugins\Sirsoft\Koreaexim\Tests\Unit\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\Koreaexim\Enums\RunStatus;
use Plugins\Sirsoft\Koreaexim\Services\KoreaeximApiClient;
use Plugins\Sirsoft\Koreaexim\Tests\PluginTestCase;

class KoreaeximApiClientTest extends PluginTestCase
{
    /**
     * 성공 요청의 쿼리와 API 결과 코드별 응답을 검증합니다.
     *
     * @scenario response=codes
     *
     * @effects api_response_classification
     *
     * @return void 반환값 없음
     */
    public function test_success_query_and_response_variants(): void
    {
        $sequence = Http::fakeSequence();
        foreach ([1, 2, 3, 4] as $code) {
            $sequence->push([['result' => $code, 'cur_unit' => 'USD', 'deal_bas_r' => '1380']]);
        }
        foreach ([1 => RunStatus::Success, 2 => RunStatus::BadRequest, 3 => RunStatus::AuthError, 4 => RunStatus::QuotaExceeded] as $code => $expected) {
            $result = app(KoreaeximApiClient::class)->fetch('test-auth', '20260911');
            $this->assertSame($expected, $result['status']);
            Http::assertSent(fn ($request) => $request['authkey'] === 'test-auth' && $request['searchdate'] === '20260911' && $request['data'] === 'AP01');
        }
    }

    /**
     * 빈 응답과 널 및 잘못된 응답 형식을 구분하는지 검증합니다.
     *
     * @scenario response=malformed
     *
     * @effects api_response_classification
     *
     * @return void 반환값 없음
     */
    public function test_empty_null_and_malformed_payloads(): void
    {
        $sequence = Http::fakeSequence();
        foreach (['[]', 'null', '<html>bad</html>', '{}'] as $body) {
            $sequence->push($body);
        }
        foreach ([RunStatus::NoData, RunStatus::NoData, RunStatus::BadResponse, RunStatus::BadResponse] as $expected) {
            $this->assertSame($expected, app(KoreaeximApiClient::class)->fetch('key', '20260911')['status']);
        }
    }

    /**
     * 연결 실패 응답에 비밀값이 없고 재시도 횟수를 집계하는지 검증합니다.
     *
     * @scenario response=connection_error
     *
     * @effects api_response_classification
     *
     * @return void 반환값 없음
     */
    public function test_connection_failure_never_returns_secret_and_counts_retries(): void
    {
        Http::fake(fn () => throw new ConnectionException('GET https://example.invalid/?authkey=fixture-secret'));
        $result = app(KoreaeximApiClient::class)->fetch('fixture-secret', '20260911');
        $this->assertSame(RunStatus::NetworkError, $result['status']);
        $this->assertSame(2, $result['request_count']);
        $this->assertStringNotContainsString('fixture-secret', json_encode($result));
    }

    /**
     * 외부 호출이 계획된 연결·응답 제한 시간으로 나가는지 검증합니다.
     *
     * @scenario response=codes
     *
     * @effects api_response_classification
     *
     * @return void 반환값 없음
     */
    public function test_request_uses_planned_connect_and_response_timeouts(): void
    {
        $options = null;
        Http::fake(function ($request, $sent) use (&$options) {
            $options = $sent;

            return Http::response([['result' => 1, 'cur_unit' => 'USD', 'deal_bas_r' => '1380']]);
        });
        app(KoreaeximApiClient::class)->fetch('test-auth', '20260911');
        $this->assertNotNull($options, '호출이 나가지 않으면 제한 시간을 잴 수 없습니다.');
        $this->assertSame(5, $options['connect_timeout']);
        $this->assertSame(20, $options['timeout']);
    }
}
