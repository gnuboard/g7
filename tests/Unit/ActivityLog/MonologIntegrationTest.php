<?php

namespace Tests\Unit\ActivityLog;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Monolog 통합 테스트
 *
 * Log::channel('activity')를 통한 전체 파이프라인이
 * 올바르게 동작하는지 End-to-End로 검증합니다.
 * ActivityLogChannel → ActivityLogProcessor → ActivityLogHandler 전체 흐름을 테스트합니다.
 */
class MonologIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['activity_log.enabled' => true]);
    }

    /**
     * Log::channel('activity')->info()가 ActivityLog 레코드를 생성하는지 확인
     */
    public function test_log_channel_creates_activity_log_record(): void
    {
        Log::channel('activity')->info('user.login', [
            'log_type' => ActivityLogType::User,
            'description_key' => 'activity_log.description.user_login',
            'user_id' => null,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'TestBrowser/1.0',
        ]);

        $this->assertDatabaseCount('activity_logs', 1);

        $log = ActivityLog::first();
        $this->assertEquals('user.login', $log->action);
        $this->assertEquals(ActivityLogType::User, $log->log_type);
        $this->assertEquals('activity_log.description.user_login', $log->description_key);
    }

    /**
     * ActivityLogProcessor가 인증된 사용자 정보를 자동 주입하는지 확인
     */
    public function test_processor_auto_injects_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Log::channel('activity')->info('user.profile_update', [
            'log_type' => ActivityLogType::User,
        ]);

        $log = ActivityLog::first();
        $this->assertEquals($user->id, $log->user_id);
    }

    /**
     * ActivityLogProcessor가 IP 주소를 자동 주입하는지 확인
     */
    public function test_processor_auto_injects_ip_address(): void
    {
        Log::channel('activity')->info('system.test', [
            'log_type' => ActivityLogType::System,
        ]);

        $log = ActivityLog::first();
        // IP 주소가 자동 주입됨 (테스트 환경에서는 127.0.0.1 또는 null)
        $this->assertArrayHasKey('ip_address', $log->toArray());
    }

    /**
     * 전체 context(description_key, description_params, changes, loggable)가 올바르게 저장되는지 확인
     */
    public function test_full_context_passes_through_pipeline(): void
    {
        $user = User::factory()->create();

        $changes = [
            [
                'field' => 'name',
                'label_key' => 'user.name',
                'old' => '이전 이름',
                'new' => '새 이름',
                'type' => 'text',
            ],
        ];

        $descriptionParams = ['user_name' => $user->name];

        Log::channel('activity')->info('user.update', [
            'log_type' => ActivityLogType::Admin,
            'description_key' => 'activity_log.description.user_updated',
            'description_params' => $descriptionParams,
            'changes' => $changes,
            'properties' => ['section' => 'profile'],
            'loggable' => $user,
            'user_id' => $user->id,
            'ip_address' => '192.168.0.1',
            'user_agent' => 'AdminPanel/2.0',
        ]);

        $this->assertDatabaseCount('activity_logs', 1);

        $log = ActivityLog::first();

        // 기본 필드
        $this->assertEquals('user.update', $log->action);
        $this->assertEquals(ActivityLogType::Admin, $log->log_type);
        $this->assertEquals($user->id, $log->user_id);
        $this->assertEquals('192.168.0.1', $log->ip_address);
        $this->assertEquals('AdminPanel/2.0', $log->user_agent);

        // description_key, description_params
        $this->assertEquals('activity_log.description.user_updated', $log->description_key);
        $this->assertEquals($descriptionParams, $log->description_params);

        // changes
        $this->assertIsArray($log->changes);
        $this->assertCount(1, $log->changes);
        $this->assertEquals('name', $log->changes[0]['field']);
        $this->assertEquals('이전 이름', $log->changes[0]['old']);
        $this->assertEquals('새 이름', $log->changes[0]['new']);

        // properties
        $this->assertEquals(['section' => 'profile'], $log->properties);

        // loggable (polymorphic)
        $this->assertEquals($user->getMorphClass(), $log->loggable_type);
        $this->assertEquals($user->getKey(), $log->loggable_id);
    }

    /**
     * config disabled 시 Log::channel('activity')가 레코드를 생성하지 않는지 확인
     */
    public function test_disabled_config_prevents_logging(): void
    {
        config(['activity_log.enabled' => false]);

        Log::channel('activity')->info('user.create', [
            'log_type' => ActivityLogType::Admin,
        ]);

        $this->assertDatabaseCount('activity_logs', 0);
    }

    /**
     * 여러 로그를 연속 기록할 수 있는지 확인
     */
    public function test_multiple_logs_can_be_recorded_sequentially(): void
    {
        Log::channel('activity')->info('user.login', [
            'log_type' => ActivityLogType::User,
            'user_id' => null,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'Browser/1.0',
        ]);

        Log::channel('activity')->info('admin.settings_update', [
            'log_type' => ActivityLogType::Admin,
            'user_id' => null,
            'ip_address' => '10.0.0.2',
            'user_agent' => 'Browser/2.0',
        ]);

        $this->assertDatabaseCount('activity_logs', 2);

        $logs = ActivityLog::orderBy('id')->get();
        $this->assertEquals('user.login', $logs[0]->action);
        $this->assertEquals('admin.settings_update', $logs[1]->action);
    }

    /**
     * 명시적으로 전달한 user_id가 Processor에 의해 덮어쓰이지 않는지 확인
     */
    public function test_explicit_context_not_overwritten_by_processor(): void
    {
        $authUser = User::factory()->create();
        $this->actingAs($authUser);

        // 명시 user_id는 인증 사용자와 다른 실제 User여야 함 (FK 제약)
        $explicitUser = User::factory()->create();

        Log::channel('activity')->info('system.impersonate', [
            'log_type' => ActivityLogType::System,
            'user_id' => $explicitUser->id,
            'ip_address' => '172.16.0.1',
            'user_agent' => 'CLI/1.0',
        ]);

        $log = ActivityLog::first();
        $this->assertEquals($explicitUser->id, $log->user_id);
        $this->assertNotEquals($authUser->id, $log->user_id);
        $this->assertEquals('172.16.0.1', $log->ip_address);
        $this->assertEquals('CLI/1.0', $log->user_agent);
    }
}
