<?php

namespace Tests\Unit\ActivityLog;

use App\ActivityLog\ActivityLogHandler;
use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * ActivityLogHandler 테스트
 *
 * Monolog AbstractProcessingHandler를 확장한 핸들러가
 * LogRecord context에서 구조화 데이터를 추출하여
 * activity_logs 테이블에 올바르게 저장하는지 검증합니다.
 */
class ActivityLogHandlerTest extends TestCase
{
    use RefreshDatabase;

    private ActivityLogHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new ActivityLogHandler;
        config(['activity_log.enabled' => true]);
    }

    /**
     * write()가 context의 구조화 데이터로 ActivityLog 레코드를 생성하는지 확인
     */
    public function test_write_creates_activity_log_record(): void
    {
        $user = User::factory()->create();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'activity',
            level: Level::Info,
            message: 'user.create',
            context: [
                'log_type' => ActivityLogType::Admin,
                'description_key' => 'activity_log.description.user_created',
                'description_params' => ['name' => $user->name],
                'properties' => ['email' => $user->email],
                'changes' => [
                    ['field' => 'name', 'label_key' => 'user.name', 'old' => null, 'new' => $user->name],
                ],
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
            ],
        );

        // write()는 protected이므로 handle()을 통해 호출
        $this->handler->handle($record);

        $this->assertDatabaseCount('activity_logs', 1);

        $log = ActivityLog::first();
        $this->assertEquals(ActivityLogType::Admin, $log->log_type);
        $this->assertEquals('user.create', $log->action);
        $this->assertEquals('activity_log.description.user_created', $log->description_key);
        $this->assertEquals(['name' => $user->name], $log->description_params);
        $this->assertEquals(['email' => $user->email], $log->properties);
        $this->assertIsArray($log->changes);
        $this->assertCount(1, $log->changes);
        $this->assertEquals($user->id, $log->user_id);
        $this->assertEquals('127.0.0.1', $log->ip_address);
        $this->assertEquals('PHPUnit', $log->user_agent);
    }

    /**
     * loggable(Model 인스턴스)에서 morphType과 key를 올바르게 추출하는지 확인
     */
    public function test_loggable_morphing_extracts_type_and_key(): void
    {
        $user = User::factory()->create();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'activity',
            level: Level::Info,
            message: 'user.update',
            context: [
                'log_type' => ActivityLogType::Admin,
                'loggable' => $user,
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
            ],
        );

        $this->handler->handle($record);

        $log = ActivityLog::first();
        $this->assertEquals($user->getMorphClass(), $log->loggable_type);
        $this->assertEquals($user->getKey(), $log->loggable_id);
    }

    /**
     * config('activity_log.enabled')가 false이면 레코드를 생성하지 않는지 확인
     */
    public function test_disabled_config_prevents_record_creation(): void
    {
        config(['activity_log.enabled' => false]);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'activity',
            level: Level::Info,
            message: 'user.create',
            context: [
                'log_type' => ActivityLogType::System,
                'user_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
            ],
        );

        $this->handler->handle($record);

        $this->assertDatabaseCount('activity_logs', 0);
    }

    /**
     * user_agent가 500자를 초과하면 잘리는지 확인
     */
    public function test_truncates_user_agent_exceeding_500_chars(): void
    {
        $longUserAgent = str_repeat('A', 600);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'activity',
            level: Level::Info,
            message: 'user.login',
            context: [
                'log_type' => ActivityLogType::User,
                'user_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => $longUserAgent,
            ],
        );

        $this->handler->handle($record);

        $log = ActivityLog::first();
        $this->assertEquals(500, mb_strlen($log->user_agent));
    }

    /**
     * context에 log_type이 없으면 기본값 System이 사용되는지 확인
     */
    public function test_defaults_log_type_to_system(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'activity',
            level: Level::Info,
            message: 'system.cleanup',
            context: [
                'user_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
            ],
        );

        $this->handler->handle($record);

        $log = ActivityLog::first();
        $this->assertEquals(ActivityLogType::System, $log->log_type);
    }

    /**
     * loggable가 Model 인스턴스가 아니면 loggable_type/loggable_id가 null인지 확인
     */
    public function test_non_model_loggable_is_ignored(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'activity',
            level: Level::Info,
            message: 'system.test',
            context: [
                'log_type' => ActivityLogType::System,
                'loggable' => 'not-a-model',
                'user_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
            ],
        );

        $this->handler->handle($record);

        $log = ActivityLog::first();
        $this->assertNull($log->loggable_type);
        $this->assertNull($log->loggable_id);
    }

    /**
     * null user_agent가 그대로 null로 저장되는지 확인
     */
    public function test_null_user_agent_remains_null(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'activity',
            level: Level::Info,
            message: 'system.test',
            context: [
                'log_type' => ActivityLogType::System,
                'user_id' => null,
                'ip_address' => '127.0.0.1',
            ],
        );

        $this->handler->handle($record);

        $log = ActivityLog::first();
        $this->assertNull($log->user_agent);
    }
}
