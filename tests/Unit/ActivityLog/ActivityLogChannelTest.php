<?php

namespace Tests\Unit\ActivityLog;

use App\ActivityLog\ActivityLogChannel;
use App\ActivityLog\ActivityLogHandler;
use App\ActivityLog\ActivityLogProcessor;
use Monolog\Logger;
use Tests\TestCase;

/**
 * ActivityLogChannel 테스트
 *
 * Monolog 커스텀 채널 팩토리가 올바른 Logger 인스턴스를 생성하고
 * ActivityLogProcessor와 ActivityLogHandler가 등록되는지 검증합니다.
 */
class ActivityLogChannelTest extends TestCase
{
    private ActivityLogChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = new ActivityLogChannel;
    }

    /**
     * __invoke()가 Monolog Logger 인스턴스를 반환하는지 확인
     */
    public function test_invoke_returns_monolog_logger(): void
    {
        $logger = ($this->channel)([]);

        $this->assertInstanceOf(Logger::class, $logger);
    }

    /**
     * Logger의 채널명이 'activity'인지 확인
     */
    public function test_logger_has_correct_channel_name(): void
    {
        $logger = ($this->channel)([]);

        $this->assertEquals('activity', $logger->getName());
    }

    /**
     * Logger에 ActivityLogHandler가 등록되어 있는지 확인
     */
    public function test_logger_has_activity_log_handler(): void
    {
        $logger = ($this->channel)([]);

        $handlers = $logger->getHandlers();
        $hasHandler = false;

        foreach ($handlers as $handler) {
            if ($handler instanceof ActivityLogHandler) {
                $hasHandler = true;
                break;
            }
        }

        $this->assertTrue($hasHandler, 'Logger에 ActivityLogHandler가 등록되어 있어야 합니다.');
    }

    /**
     * Logger에 ActivityLogProcessor가 등록되어 있는지 확인
     */
    public function test_logger_has_activity_log_processor(): void
    {
        $logger = ($this->channel)([]);

        $processors = $logger->getProcessors();
        $hasProcessor = false;

        foreach ($processors as $processor) {
            if ($processor instanceof ActivityLogProcessor) {
                $hasProcessor = true;
                break;
            }
        }

        $this->assertTrue($hasProcessor, 'Logger에 ActivityLogProcessor가 등록되어 있어야 합니다.');
    }

    /**
     * 매 호출마다 새 Logger 인스턴스를 반환하는지 확인
     */
    public function test_creates_new_logger_instance_each_call(): void
    {
        $logger1 = ($this->channel)([]);
        $logger2 = ($this->channel)([]);

        $this->assertNotSame($logger1, $logger2);
    }
}
