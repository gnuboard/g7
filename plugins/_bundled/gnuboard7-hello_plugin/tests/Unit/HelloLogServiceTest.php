<?php

namespace Plugins\Gnuboard7\HelloPlugin\Tests\Unit;

use Plugins\Gnuboard7\HelloPlugin\Services\HelloLogService;
use Plugins\Gnuboard7\HelloPlugin\Tests\PluginTestCase;

/**
 * HelloLogService 단위 테스트
 *
 * 실제 파일시스템에 기록하는 단순 래퍼이므로 실제 파일에 기록되고
 * 이후 정리되는지 관찰 가능한 상태로 검증합니다.
 */
class HelloLogServiceTest extends PluginTestCase
{
    /**
     * 테스트 종료 후 생성된 로그 파일을 정리합니다.
     */
    protected function tearDown(): void
    {
        $logFile = storage_path(HelloLogService::LOG_FILE);
        if (file_exists($logFile)) {
            @unlink($logFile);
        }

        parent::tearDown();
    }

    /**
     * log() 호출 시 hello-plugin.log 파일에 메시지가 기록되어야 합니다.
     */
    public function test_log_writes_message_to_hello_plugin_log_file(): void
    {
        /** @var HelloLogService $service */
        $service = app(HelloLogService::class);

        $service->log('[Unit] sample message', ['foo' => 'bar']);

        $logFile = storage_path(HelloLogService::LOG_FILE);
        $this->assertFileExists($logFile, 'hello-plugin.log 파일이 생성되어야 합니다.');

        $contents = file_get_contents($logFile);
        $this->assertStringContainsString('[Unit] sample message', $contents);
        $this->assertStringContainsString('foo', $contents);
    }

    /**
     * 여러 번 호출하면 모든 메시지가 추가 기록되어야 합니다.
     */
    public function test_log_appends_multiple_messages(): void
    {
        /** @var HelloLogService $service */
        $service = app(HelloLogService::class);

        $service->log('first message');
        $service->log('second message');

        $contents = file_get_contents(storage_path(HelloLogService::LOG_FILE));
        $this->assertStringContainsString('first message', $contents);
        $this->assertStringContainsString('second message', $contents);
    }
}
