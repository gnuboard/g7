<?php

namespace Tests\Unit\ActivityLog;

use App\ActivityLog\ActivityLogProcessor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * ActivityLogProcessor 테스트
 *
 * Monolog Processor가 요청별 공통 데이터(user_id, ip_address, user_agent)를
 * 올바르게 자동 주입하는지 검증합니다.
 */
class ActivityLogProcessorTest extends TestCase
{
    use RefreshDatabase;

    private ActivityLogProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new ActivityLogProcessor;
    }

    /**
     * 인증된 사용자의 user_id가 자동 주입되는지 확인
     */
    public function test_auto_injects_user_id_from_auth(): void
    {
        $user = User::factory()->create();
        Auth::login($user);

        $record = $this->createRecord();
        $result = ($this->processor)($record);

        $this->assertEquals($user->id, $result->context['user_id']);
    }

    /**
     * ip_address가 자동 주입되는지 확인
     */
    public function test_auto_injects_ip_address(): void
    {
        $record = $this->createRecord();
        $result = ($this->processor)($record);

        $this->assertArrayHasKey('ip_address', $result->context);
    }

    /**
     * user_agent가 자동 주입되는지 확인
     */
    public function test_auto_injects_user_agent(): void
    {
        $record = $this->createRecord();
        $result = ($this->processor)($record);

        $this->assertArrayHasKey('user_agent', $result->context);
    }

    /**
     * 명시적으로 전달된 user_id를 덮어쓰지 않는지 확인
     */
    public function test_does_not_overwrite_explicit_user_id(): void
    {
        $user = User::factory()->create();
        Auth::login($user);

        $explicitUserId = 99999;
        $record = $this->createRecord(['user_id' => $explicitUserId]);
        $result = ($this->processor)($record);

        $this->assertEquals($explicitUserId, $result->context['user_id']);
    }

    /**
     * 명시적으로 전달된 ip_address를 덮어쓰지 않는지 확인
     */
    public function test_does_not_overwrite_explicit_ip_address(): void
    {
        $record = $this->createRecord(['ip_address' => '192.168.1.100']);
        $result = ($this->processor)($record);

        $this->assertEquals('192.168.1.100', $result->context['ip_address']);
    }

    /**
     * 명시적으로 전달된 user_agent를 덮어쓰지 않는지 확인
     */
    public function test_does_not_overwrite_explicit_user_agent(): void
    {
        $record = $this->createRecord(['user_agent' => 'CustomBot/1.0']);
        $result = ($this->processor)($record);

        $this->assertEquals('CustomBot/1.0', $result->context['user_agent']);
    }

    /**
     * 인증된 사용자가 없으면 user_id가 null인지 확인
     */
    public function test_user_id_defaults_to_null_when_no_auth(): void
    {
        Auth::logout();

        $record = $this->createRecord();
        $result = ($this->processor)($record);

        $this->assertNull($result->context['user_id']);
    }

    /**
     * Processor가 원본 레코드를 변경하지 않고 새 레코드를 반환하는지 확인
     */
    public function test_returns_new_log_record_instance(): void
    {
        $record = $this->createRecord();
        $result = ($this->processor)($record);

        // LogRecord는 불변 객체 — with()로 새 인스턴스 반환
        $this->assertNotSame($record, $result);
        $this->assertInstanceOf(LogRecord::class, $result);
    }

    /**
     * 기존 context 데이터가 보존되는지 확인
     */
    public function test_preserves_existing_context_data(): void
    {
        $record = $this->createRecord([
            'log_type' => 'admin',
            'description_key' => 'test.key',
        ]);
        $result = ($this->processor)($record);

        $this->assertEquals('admin', $result->context['log_type']);
        $this->assertEquals('test.key', $result->context['description_key']);
    }

    /**
     * 테스트용 LogRecord를 생성합니다.
     *
     * @param array $context 추가 context 데이터
     * @return LogRecord
     */
    private function createRecord(array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'activity',
            level: Level::Info,
            message: 'test.action',
            context: $context,
        );
    }
}
