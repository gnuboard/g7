<?php

namespace Tests\Unit\ActivityLog;

use App\ActivityLog\ActivityLogChannel;
use App\ActivityLog\ActivityLogHandler;
use App\ActivityLog\ActivityLogProcessor;
use Monolog\Logger;
use Tests\PureTestCase;

/**
 * ActivityLogChannel 테스트
 *
 * Monolog 커스텀 채널 팩토리가 올바른 Logger 인스턴스를 생성하고
 * ActivityLogProcessor와 ActivityLogHandler가 등록되는지 검증합니다.
 */
class ActivityLogChannelTest extends PureTestCase
{
    private ActivityLogChannel $channel;

    /**
     * 검사 대상 ActivityLogChannel 팩토리 인스턴스를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = new ActivityLogChannel;
    }

    /**
     * __invoke()가 Monolog Logger 인스턴스를 만들어 돌려준다.
     *
     * @scenario case=logger_creation
     *
     * @effects channel_factory_returns_monolog_logger
     *
     * @return void 반환값 없음
     */
    public function test_invoke_returns_monolog_logger(): void
    {
        $logger = ($this->channel)([]);

        $this->assertInstanceOf(Logger::class, $logger);
    }

    /**
     * 만들어진 Logger 의 채널명이 'activity' 로 고정된다.
     *
     * @scenario case=logger_creation
     *
     * @effects logger_channel_named_activity
     *
     * @return void 반환값 없음
     */
    public function test_logger_has_correct_channel_name(): void
    {
        $logger = ($this->channel)([]);

        $this->assertEquals('activity', $logger->getName());
    }

    /**
     * 만들어진 Logger 의 핸들러 목록에 ActivityLogHandler 가 들어 있다.
     *
     * @scenario case=pipeline_registration
     *
     * @effects activity_log_handler_registered
     *
     * @return void 반환값 없음
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
     * 만들어진 Logger 의 프로세서 목록에 ActivityLogProcessor 가 들어 있다.
     *
     * @scenario case=pipeline_registration
     *
     * @effects activity_log_processor_registered
     *
     * @return void 반환값 없음
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
     * 팩토리를 두 번 호출하면 서로 다른 Logger 인스턴스를 돌려준다(공유 상태 없음).
     *
     * @scenario case=logger_creation
     *
     * @effects new_logger_instance_per_call
     *
     * @return void 반환값 없음
     */
    public function test_creates_new_logger_instance_each_call(): void
    {
        $logger1 = ($this->channel)([]);
        $logger2 = ($this->channel)([]);

        $this->assertNotSame($logger1, $logger2);
    }
}
