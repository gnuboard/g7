<?php

namespace Tests\Unit\ActivityLog\Drivers;

use App\ActivityLog\Drivers\NullActivityLogDriver;
use App\Enums\ActivityLogType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NullActivityLogDriver 테스트
 *
 * Null 드라이버가 아무 동작도 하지 않는지 검증합니다.
 */
class NullActivityLogDriverTest extends TestCase
{
    use RefreshDatabase;

    private NullActivityLogDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new NullActivityLogDriver;
    }

    /**
     * 드라이버 이름이 'null'인지 확인
     */
    public function test_driver_name_is_null(): void
    {
        $this->assertEquals('null', $this->driver->getName());
    }

    /**
     * log()가 null을 반환하는지 확인
     */
    public function test_log_returns_null(): void
    {
        $result = $this->driver->log(
            ActivityLogType::Admin,
            'test.action',
            'Test description'
        );

        $this->assertNull($result);
    }

    /**
     * log()가 데이터베이스에 아무것도 저장하지 않는지 확인
     */
    public function test_log_does_not_save_to_database(): void
    {
        $user = User::factory()->create();

        $this->driver->log(
            ActivityLogType::Admin,
            'test.action',
            'Test description',
            $user,
            ['key' => 'value'],
            $user->id,
            '127.0.0.1',
            'Test Agent'
        );

        $this->assertDatabaseCount('activity_logs', 0);
    }
}
