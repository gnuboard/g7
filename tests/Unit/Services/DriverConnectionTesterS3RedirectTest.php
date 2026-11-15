<?php

namespace Tests\Unit\Services;

use App\Services\DriverConnectionTester;
use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use ReflectionMethod;
use Tests\TestCase;

/**
 * S3 연결 테스트는 응답의 리다이렉트를 따라가지 않는다.
 *
 * 관리자가 넣은 S3 주소가 다른 주소(내부망·메타데이터)로 넘기는 응답을 돌려주면, 따라가는 순간 서버가 그 주소에
 * 요청을 보낸다. 연결 테스트가 만드는 클라이언트 옵션을 실제 SDK 에 넣어 두 번째 요청이 나가지 않는지 잰다.
 *
 * @scenario-manifest tests/scenarios/outbound-connection-target.yaml
 */
class DriverConnectionTesterS3RedirectTest extends TestCase
{
    /**
     * 연결 테스트가 쓰는 S3 클라이언트 옵션을 가져옵니다.
     *
     * @return array<string, mixed> S3Client 생성 옵션
     */
    private function clientOptions(): array
    {
        $method = new ReflectionMethod(DriverConnectionTester::class, 's3ClientOptions');

        return $method->invoke(app(DriverConnectionTester::class), [
            's3_region' => 'us-east-1',
            's3_access_key' => 'test-key',
            's3_secret_key' => 'test-secret',
            's3_endpoint' => 'http://storage.example.test',
            's3_use_path_style' => true,
        ]);
    }

    /**
     * 301 응답을 받아도 Location 이 가리키는 주소로 두 번째 요청을 보내지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=redirecting_endpoint__storage_test
     *
     * @effects connection_test_does_not_follow_redirects
     */
    public function test_s3_client_does_not_follow_a_redirect_response(): void
    {
        $queue = new MockHandler([
            new Response(301, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            new Response(200),
        ]);
        $requests = [];
        $stack = HandlerStack::create($queue);
        $stack->push(function (callable $handler) use (&$requests) {
            return function ($request, array $options) use ($handler, &$requests) {
                $requests[] = (string) $request->getUri();

                return $handler($request, $options);
            };
        });

        $options = $this->clientOptions();
        $options['http_handler'] = new GuzzleHandler(new Client(['handler' => $stack]));
        $options['retries'] = 0;

        try {
            (new S3Client($options))->headBucket(['Bucket' => 'sample-bucket']);
        } catch (\Throwable) {
            // 301 은 SDK 가 오류로 돌려준다 — 여기서는 요청이 몇 번 나갔는지만 본다.
        }

        $this->assertCount(1, $requests, '리다이렉트를 따라가 두 번째 요청이 나갔습니다: '.implode(', ', $requests));
        $this->assertStringNotContainsString('169.254.169.254', $requests[0]);
        $this->assertSame(1, $queue->count(), '두 번째 응답이 소비됐습니다(리다이렉트 추종).');
    }

    /**
     * 옵션은 리다이렉트 금지와 짧은 제한 시간을 선언하고, 설정의 엔드포인트·경로 방식을 반영한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=redirecting_endpoint__storage_test
     *
     * @effects connection_test_does_not_follow_redirects
     */
    public function test_client_options_declare_no_redirects_and_reflect_the_endpoint(): void
    {
        $options = $this->clientOptions();

        $this->assertFalse($options['http']['allow_redirects']);
        $this->assertSame(5, $options['http']['timeout']);
        $this->assertSame('http://storage.example.test', $options['endpoint']);
        $this->assertTrue($options['use_path_style_endpoint']);
        $this->assertSame('test-key', $options['credentials']['key']);
    }
}
