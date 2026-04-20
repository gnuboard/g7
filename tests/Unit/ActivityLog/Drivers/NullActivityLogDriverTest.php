<?php

namespace Tests\Unit\ActivityLog\Drivers;

use App\Enums\ActivityLogType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Null 활동 로그 동작 테스트 (config('activity_log.enabled') = false 기반)
 *
 * 구버전 NullActivityLogDriver 클래스는 폐기되었고,
 * 현재는 config('activity_log.enabled')가 false이면 ActivityLogHandler::write()가
 * DB 저장을 건너뛰는 방식으로 비활성화 동작이 제공됩니다.
 * 본 테스트는 비활성화 시 어떤 로그도 DB에 저장되지 않는지 검증합니다.
 */
class NullActivityLogDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['activity_log.enabled' => false]);
    }

    /**
     * 비활성화 시 로그가 데이터베이스에 저장되지 않는지 확인
     */
    public function test_disabled_log_does_not_save_to_database(): void
    {
        Log::channel('activity')->info('test.action', [
            'log_type' => ActivityLogType::Admin,
            'description_key' => 'test.description',
        ]);

        $this->assertDatabaseCount('activity_logs', 0);
    }

    /**
     * 비활성화 시 모든 필드가 전달되어도 아무것도 저장되지 않는지 확인
     */
    public function test_disabled_log_ignores_all_fields(): void
    {
        $user = User::factory()->create();

        Log::channel('activity')->info('test.action', [
            'log_type' => ActivityLogType::Admin,
            'description_key' => 'test.description',
            'loggable' => $user,
            'properties' => ['key' => 'value'],
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test Agent',
        ]);

        $this->assertDatabaseCount('activity_logs', 0);
    }

    /**
     * 비활성화 → 활성화 토글이 정상 동작하는지 확인
     */
    public function test_enabling_after_disabled_resumes_logging(): void
    {
        Log::channel('activity')->info('disabled.action', [
            'log_type' => ActivityLogType::Admin,
        ]);
        $this->assertDatabaseCount('activity_logs', 0);

        config(['activity_log.enabled' => true]);

        Log::channel('activity')->info('enabled.action', [
            'log_type' => ActivityLogType::Admin,
        ]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'enabled.action']);
    }
}
