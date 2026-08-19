<?php

namespace Tests\Unit\Services;

use App\Services\DriverConnectionTester;
use Illuminate\Support\Facades\Http;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Tests\TestCase;

/**
 * DriverConnectionTester 테스트 (공개 #99 / 내부 #563, A5·A6)
 *
 * testS3 의 endpoint/path-style 반영·어댑터 선검사, testWebsocket 의
 * client/server 양측 endpoint probe 를 검증합니다.
 *
 * 한계: Http::fake 는 요청 URL 계약까지만 검증한다 (요청 본문 미검증 —
 * feedback_http_fake_does_not_validate_request_body). S3 종단 검증은
 * Chrome MCP MinIO 매트릭스가 담당한다.
 */
class DriverConnectionTesterTest extends TestCase
{
    private DriverConnectionTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tester = new DriverConnectionTester;
    }

    /**
     * 필수 설정 누락 시 s3_missing_config 메시지를 반환하는지 테스트합니다.
     */
    public function test_s3_missing_config_returns_failure(): void
    {
        $result = $this->tester->testS3(['s3_bucket' => 'only-bucket']);

        $this->assertFalse($result['success']);
        $this->assertSame(__('settings.s3_missing_config'), $result['message']);
    }

    /**
     * 커스텀 endpoint 가 실제 아웃바운드 대상에 반영되는지 테스트합니다.
     *
     * 도달 불가한 로컬 endpoint 로 시도 → 연결 실패의 error 문자열에 해당 endpoint
     * 호스트가 나타나야 한다 (AWS 리전 도메인이 아니라). 이것이 endpoint 미반영
     * 결함(#99 ③ — s3_url 만 있고 SDK 요청 대상은 amazonaws.com 고정)의 회귀 가드다.
     *
     * @scenario region_shape=custom_minio,endpoint=path_style,driver_usability=adapter_present,attachment_disk=local_default
     *
     * @effects endpoint_injected
     */
    public function test_s3_custom_endpoint_reaches_outbound_target(): void
    {
        $result = $this->tester->testS3([
            's3_bucket' => 'test-bucket',
            's3_region' => 'us-east-1',
            's3_access_key' => 'test-key',
            's3_secret_key' => 'test-secret',
            's3_endpoint' => 'http://127.0.0.1:9',
            's3_use_path_style' => true,
        ]);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString(
            '127.0.0.1',
            $result['error'],
            '아웃바운드가 커스텀 endpoint 를 향해야 함 — amazonaws.com 고정이면 endpoint 미반영 회귀'
        );
        $this->assertStringNotContainsString('amazonaws.com', $result['error']);
    }

    /**
     * path-style 토글이 아웃바운드 주소 형태를 실제로 바꾸는지 테스트합니다.
     *
     * IP endpoint 는 SDK 가 path-style 을 자동 강제해 off↔on 이 구분되지 않으므로
     * hostname endpoint 로 검증한다: off = virtual-hosted(버킷이 호스트에 접두) /
     * on = path-style(버킷이 경로로 이동). off 상태로도 통과하던 endpoint 단언의
     * 사각을 메우는 회귀 가드다.
     *
     * @scenario region_shape=custom_minio,endpoint=path_style,driver_usability=adapter_present,attachment_disk=local_default
     *
     * @effects endpoint_injected
     */
    public function test_s3_path_style_toggle_changes_outbound_address_shape(): void
    {
        $config = [
            's3_bucket' => 'test-bucket',
            's3_region' => 'us-east-1',
            's3_access_key' => 'test-key',
            's3_secret_key' => 'test-secret',
            's3_endpoint' => 'http://s3-parity.invalid:9',
        ];

        $virtualHosted = $this->tester->testS3($config + ['s3_use_path_style' => false]);
        $pathStyle = $this->tester->testS3($config + ['s3_use_path_style' => true]);

        $this->assertFalse($virtualHosted['success']);
        $this->assertFalse($pathStyle['success']);

        // off: 버킷명이 호스트에 접두된 virtual-hosted 주소를 향해야 한다
        $this->assertStringContainsString(
            'test-bucket.s3-parity.invalid',
            $virtualHosted['error'],
            'path-style off 는 virtual-hosted 주소여야 함'
        );

        // on: 버킷 접두 호스트가 사라져야 한다 — off 와 동일하면 path-style 주입 회귀
        $this->assertStringNotContainsString(
            'test-bucket.s3-parity.invalid',
            $pathStyle['error'],
            'path-style on 인데 버킷 접두 호스트가 남아 있으면 use_path_style_endpoint 미반영 회귀'
        );
        $this->assertStringContainsString('s3-parity.invalid', $pathStyle['error']);
    }

    /**
     * 어댑터 선검사 계약 — 어댑터가 설치된 환경에서는 missing 분기가 발동하지 않고,
     * 코드가 어댑터 존재를 검사하고 있는지(클래스 상수 참조) 검증합니다.
     *
     * 어댑터는 composer 필수 의존성이라 부재 상태를 만들 수 없으므로 구성 검증으로 대체한다
     * (계획서 A5 — "mock 불가 시 구성 검증으로 대체").
     */
    public function test_s3_adapter_precheck_present_in_code(): void
    {
        $this->assertTrue(
            class_exists(AwsS3V3Adapter::class),
            '어댑터는 코어 필수 의존성 (#99 A1)'
        );

        $source = file_get_contents(app_path('Services/DriverConnectionTester.php'));
        $this->assertStringContainsString(
            'AwsS3V3Adapter::class',
            $source,
            'testS3 는 어댑터 존재 선검사를 유지해야 함 — 테스트(SDK 직접)와 실경로(Flysystem)의 분리가 결함을 가리는 구조 방지 (A5)'
        );
        $this->assertStringContainsString('s3_adapter_missing', $source);
    }

    /**
     * testWebsocket 이 client 와 server endpoint 를 모두 probe 하는지 테스트합니다.
     *
     * @scenario region_shape=aws_region,endpoint=absent,driver_usability=adapter_present,attachment_disk=local_default
     *
     * @effects test_covers_server_websocket
     */
    public function test_websocket_probes_both_client_and_server_endpoints(): void
    {
        Http::fake([
            'client.test:8443*' => Http::response('ok', 200),
            'server.test:8080*' => Http::response('ok', 200),
        ]);

        $result = $this->tester->testWebsocket([
            'websocket_host' => 'client.test',
            'websocket_port' => 8443,
            'websocket_scheme' => 'https',
            'websocket_server_host' => 'server.test',
            'websocket_server_port' => 8080,
            'websocket_server_scheme' => 'http',
        ]);

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://client.test'));
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://server.test'));
    }

    /**
     * server endpoint 실패 시 어느 쪽 실패인지 구분된 메시지를 반환하는지 테스트합니다.
     */
    public function test_websocket_server_endpoint_failure_is_distinguished(): void
    {
        Http::fake([
            'client.test:8443*' => Http::response('ok', 200),
            'server.test:8080*' => Http::response('error', 500),
        ]);

        $result = $this->tester->testWebsocket([
            'websocket_host' => 'client.test',
            'websocket_port' => 8443,
            'websocket_scheme' => 'https',
            'websocket_server_host' => 'server.test',
            'websocket_server_port' => 8080,
            'websocket_server_scheme' => 'http',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(
            __('settings.websocket_server_test_failed'),
            $result['message'],
            '백엔드 발송은 server endpoint 를 쓴다 — client 만 검사하면 "테스트 성공 + 실제 발송 실패" (A6)'
        );
    }

    /**
     * server 필드가 비어 있으면 client 값으로 폴백하며 중복 probe 를 생략하는지 테스트합니다.
     */
    public function test_websocket_server_fields_fallback_to_client(): void
    {
        Http::fake([
            'client.test:8443*' => Http::response('ok', 200),
        ]);

        $result = $this->tester->testWebsocket([
            'websocket_host' => 'client.test',
            'websocket_port' => 8443,
            'websocket_scheme' => 'https',
        ]);

        $this->assertTrue($result['success']);
        Http::assertSentCount(1);
    }

    /**
     * client endpoint 실패 시 기존 메시지 계약이 유지되는지 테스트합니다 (하위호환).
     */
    public function test_websocket_client_failure_keeps_existing_contract(): void
    {
        Http::fake([
            'client.test:8443*' => Http::response('error', 500),
        ]);

        $result = $this->tester->testWebsocket([
            'websocket_host' => 'client.test',
            'websocket_port' => 8443,
            'websocket_scheme' => 'https',
            'websocket_server_host' => 'server.test',
            'websocket_server_port' => 8080,
            'websocket_server_scheme' => 'http',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(__('settings.websocket_test_failed'), $result['message']);
    }
}
