<?php

namespace Tests\Feature\Api\Public;

use App\Http\Requests\Public\Csp\CspReportRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * CSP 위반 보고 수신 `POST /api/csp-report` (#158)
 *
 * 응답은 늘 204(16KB 초과만 413) — 보고에 4xx 를 돌려주면 방문자 콘솔에 오류가 쌓인다. 두 본문 형식
 * (report-uri `application/csp-report`, Reporting API `application/reports+json`)을 받고, 기록에는
 * 쿼리스트링을 남기지 않는다(토큰·검색어 같은 개인 값). 호출 제한은 분당 60.
 */
class CspReportControllerTest extends TestCase
{
    /**
     * 로그 채널을 가로챌 준비와 호출 제한 초기화.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('csp_report');
    }

    /**
     * 본문을 그대로 보냅니다.
     *
     * @param  string  $body  요청 본문
     * @param  string  $contentType  요청 본문 형식 헤더 값
     * @return TestResponse 응답
     */
    private function report(string $body, string $contentType = 'application/csp-report'): TestResponse
    {
        return $this->call('POST', '/api/csp-report', [], [], [], [
            'CONTENT_TYPE' => $contentType,
            'HTTP_ACCEPT' => '*/*',
            'CONTENT_LENGTH' => (string) strlen($body),
        ], $body);
    }

    /**
     * 로그 채널 csp 의 info 호출을 기록하는 가짜를 심습니다.
     *
     * @return \ArrayObject<int, array<string, mixed>> 기록된 컨텍스트
     */
    private function captureLog(): \ArrayObject
    {
        $captured = new \ArrayObject;
        $channel = \Mockery::mock();
        $channel->shouldReceive('info')->andReturnUsing(function (string $message, array $context) use ($captured) {
            $captured->append($context);
        });
        Log::shouldReceive('channel')->with('csp')->andReturn($channel);

        return $captured;
    }

    /**
     * report-uri 형식 — 204 와 기록, 주소의 쿼리스트링 제거.
     *
     * @scenario case=format_report_uri
     *
     * @effects csp_report_logged_without_query
     *
     * @return void 반환값 없음
     */
    public function test_report_uri_형식은_쿼리_없이_기록된다(): void
    {
        $captured = $this->captureLog();

        $this->report(json_encode(['csp-report' => [
            'document-uri' => 'https://site.test/board/free?token=secret#x',
            'blocked-uri' => 'inline',
            'effective-directive' => 'script-src-elem',
            'disposition' => 'report',
            'line-number' => 12,
            'source-file' => 'https://site.test/admin/login?redirect=/x',
            'script-sample' => str_repeat('a', 200),
        ]]))->assertNoContent();

        $this->assertCount(1, $captured);
        $row = $captured[0];
        $this->assertSame('https://site.test/board/free', $row['document_uri']);
        $this->assertSame('inline', $row['blocked_uri']);
        $this->assertSame('script-src-elem', $row['effective_directive']);
        $this->assertSame('https://site.test/admin/login', $row['source_file']);
        $this->assertSame(12, $row['line_number']);
        $this->assertSame(80, mb_strlen($row['sample']));
        $this->assertArrayNotHasKey('ip', $row);
    }

    /**
     * Reporting API 형식(배열)도 받는다.
     *
     * @scenario case=format_reporting_api
     *
     * @effects csp_report_logged_without_query
     *
     * @return void 반환값 없음
     */
    public function test_reporting_api_형식도_받는다(): void
    {
        $captured = $this->captureLog();

        $this->report(json_encode([
            ['type' => 'csp-violation', 'body' => ['documentURL' => 'https://site.test/?q=1', 'blockedURL' => 'eval', 'effectiveDirective' => 'script-src', 'disposition' => 'enforce']],
            ['type' => 'deprecation', 'body' => ['id' => 'x']],
        ]), 'application/reports+json')->assertNoContent();

        $this->assertCount(1, $captured);
        $this->assertSame('https://site.test/', $captured[0]['document_uri']);
        $this->assertSame('enforce', $captured[0]['disposition']);
    }

    /**
     * 잘못된 본문은 204 로 끊고 기록하지 않는다.
     *
     * @scenario case=format_malformed
     *
     * @effects csp_report_malformed_returns_204_without_log
     *
     * @return void 반환값 없음
     */
    public function test_잘못된_본문은_204_로_끊고_기록하지_않는다(): void
    {
        $captured = $this->captureLog();

        $this->report('not json')->assertNoContent();
        $this->report('')->assertNoContent();
        $this->report(json_encode(['foo' => 'bar']))->assertNoContent();

        $this->assertCount(0, $captured);
    }

    /**
     * 16KB 를 넘는 본문은 413.
     *
     * @scenario case=format_oversized
     *
     * @effects csp_report_oversized_returns_413
     *
     * @return void 반환값 없음
     */
    public function test_16kb_초과는_413(): void
    {
        $this->captureLog();

        $body = json_encode(['csp-report' => ['document-uri' => str_repeat('a', CspReportRequest::MAX_BODY_BYTES)]]);

        $this->report($body)->assertStatus(413);
    }

    /**
     * 분당 60회를 넘으면 429.
     *
     * @scenario case=format_report_uri
     *
     * @effects csp_report_throttled
     *
     * @return void 반환값 없음
     */
    public function test_61번째_요청은_429(): void
    {
        $this->captureLog();
        $body = json_encode(['csp-report' => ['document-uri' => 'https://site.test/']]);

        for ($i = 1; $i <= 60; $i++) {
            $this->report($body)->assertNoContent();
        }

        $this->report($body)->assertStatus(429);
    }
}
