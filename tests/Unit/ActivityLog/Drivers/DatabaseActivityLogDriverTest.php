<?php

namespace Tests\Unit\ActivityLog\Drivers;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Database 활동 로그 동작 테스트 (Monolog 'activity' 채널 기반)
 *
 * 구버전 DatabaseActivityLogDriver 클래스는 폐기되었고,
 * 현재는 Log::channel('activity')->info() → ActivityLogHandler → DB 저장 흐름을 사용합니다.
 * 본 테스트는 그 흐름이 정상적으로 모든 필드를 저장하는지 검증합니다.
 */
class DatabaseActivityLogDriverTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 채널 이름이 'activity'로 설정되어 있는지 확인
     */
    public function test_channel_name_is_activity(): void
    {
        $this->assertEquals('activity', config('activity_log.channel'));
    }

    /**
     * 기본 로그가 데이터베이스에 저장되는지 확인
     */
    public function test_log_saves_to_database(): void
    {
        Log::channel('activity')->info('test.action', [
            'log_type' => ActivityLogType::Admin,
            'description_key' => 'test.description',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'log_type' => ActivityLogType::Admin->value,
            'action' => 'test.action',
            'description_key' => 'test.description',
        ]);
    }

    /**
     * 모든 필드가 정확히 저장되는지 확인
     */
    public function test_log_saves_all_fields(): void
    {
        $user = User::factory()->create();

        Log::channel('activity')->info('user.login', [
            'log_type' => ActivityLogType::User,
            'description_key' => 'user.login.description',
            'loggable' => $user,
            'properties' => ['browser' => 'Chrome'],
            'user_id' => $user->id,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $log = ActivityLog::where('action', 'user.login')->firstOrFail();

        $this->assertEquals(ActivityLogType::User, $log->log_type);
        $this->assertEquals('user.login', $log->action);
        $this->assertEquals('user.login.description', $log->description_key);
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

        Log::channel('activity')->info('test.action', [
            'log_type' => ActivityLogType::Admin,
            'user_agent' => $longUserAgent,
        ]);

        $log = ActivityLog::where('action', 'test.action')->firstOrFail();

        $this->assertEquals(500, mb_strlen($log->user_agent));
    }

    /**
     * 선택적 필드(loggable, properties)가 미지정 시 null로 저장되는지 확인
     *
     * 주의: ip_address/user_agent/user_id는 ActivityLogProcessor가
     * Auth/Request에서 자동 주입하므로, 본 테스트는 그 외 nullable 필드만 검증합니다.
     */
    public function test_nullable_fields_are_handled(): void
    {
        Log::channel('activity')->info('system.task', [
            'log_type' => ActivityLogType::System,
        ]);

        $log = ActivityLog::where('action', 'system.task')->firstOrFail();

        $this->assertNull($log->loggable_type);
        $this->assertNull($log->loggable_id);
        $this->assertNull($log->properties);
        $this->assertNull($log->changes);
        $this->assertNull($log->description_key);
    }
}
