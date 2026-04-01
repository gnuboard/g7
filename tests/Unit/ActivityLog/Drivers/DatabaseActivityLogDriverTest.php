<?php

namespace Tests\Unit\ActivityLog\Drivers;

use App\ActivityLog\Drivers\DatabaseActivityLogDriver;
use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DatabaseActivityLogDriver 테스트
 *
 * 데이터베이스 드라이버의 로그 저장 기능을 검증합니다.
 */
class DatabaseActivityLogDriverTest extends TestCase
{
    use RefreshDatabase;

    private DatabaseActivityLogDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new DatabaseActivityLogDriver;
    }

    /**
     * 드라이버 이름이 'database'인지 확인
     */
    public function test_driver_name_is_database(): void
    {
        $this->assertEquals('database', $this->driver->getName());
    }

    /**
     * 기본 로그가 데이터베이스에 저장되는지 확인
     */
    public function test_log_saves_to_database(): void
    {
        $log = $this->driver->log(
            ActivityLogType::Admin,
            'test.action',
            'Test description'
        );

        $this->assertInstanceOf(ActivityLog::class, $log);
        $this->assertDatabaseHas('activity_logs', [
            'id' => $log->id,
            'log_type' => ActivityLogType::Admin->value,
            'action' => 'test.action',
            'description' => 'Test description',
        ]);
    }

    /**
     * 모든 필드가 정확히 저장되는지 확인
     */
    public function test_log_saves_all_fields(): void
    {
        $user = User::factory()->create();

        $log = $this->driver->log(
            ActivityLogType::User,
            'user.login',
            'User logged in',
            $user,
            ['browser' => 'Chrome'],
            $user->id,
            '192.168.1.1',
            'Mozilla/5.0'
        );

        $this->assertEquals(ActivityLogType::User, $log->log_type);
        $this->assertEquals('user.login', $log->action);
        $this->assertEquals('User logged in', $log->description);
        $this->assertEquals($user->getMorphClass(), $log->loggable_type);
        $this->assertEquals($user->id, $log->loggable_id);
        $this->assertEquals(['browser' => 'Chrome'], $log->properties);
        $this->assertEquals($user->id, $log->user_id);
        $this->assertEquals('192.168.1.1', $log->ip_address);
        $this->assertEquals('Mozilla/5.0', $log->user_agent);
    }

    /**
     * 긴 User-Agent가 500자로 잘리는지 확인
     */
    public function test_long_user_agent_is_truncated(): void
    {
        $longUserAgent = str_repeat('a', 600);

        $log = $this->driver->log(
            ActivityLogType::Admin,
            'test.action',
            'Test',
            null,
            null,
            null,
            null,
            $longUserAgent
        );

        $this->assertEquals(500, mb_strlen($log->user_agent));
    }

    /**
     * null 값들이 정상적으로 처리되는지 확인
     */
    public function test_nullable_fields_are_handled(): void
    {
        $log = $this->driver->log(
            ActivityLogType::System,
            'system.task',
            'System task completed',
            null,
            null,
            null,
            null,
            null
        );

        $this->assertNull($log->loggable_type);
        $this->assertNull($log->loggable_id);
        $this->assertNull($log->properties);
        $this->assertNull($log->user_id);
        $this->assertNull($log->ip_address);
        $this->assertNull($log->user_agent);
    }
}
