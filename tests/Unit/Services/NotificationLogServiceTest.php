<?php

namespace Tests\Unit\Services;

use App\Enums\NotificationLogStatus;
use App\Models\NotificationLog;
use App\Services\NotificationLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NotificationLogService 테스트
 *
 * 알림 발송 이력 기록, 조회, 삭제 동작을 검증합니다.
 */
class NotificationLogServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationLogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(NotificationLogService::class);
    }

    /**
     * logSent()가 sent 상태로 로그를 기록하는지 확인
     */
    public function test_log_sent_creates_sent_record(): void
    {
        $log = $this->service->logSent([
            'channel' => 'mail',
            'notification_type' => 'welcome',
            'recipient_identifier' => 'test@example.com',
            'recipient_name' => 'Test User',
            'subject' => 'Welcome',
            'source' => 'notification',
        ]);

        $this->assertInstanceOf(NotificationLog::class, $log);
        $this->assertEquals(NotificationLogStatus::Sent, $log->status);
        $this->assertEquals('mail', $log->channel);
        $this->assertEquals('welcome', $log->notification_type);
    }

    /**
     * logFailed()가 failed 상태로 로그를 기록하는지 확인
     */
    public function test_log_failed_creates_failed_record(): void
    {
        $log = $this->service->logFailed([
            'channel' => 'mail',
            'notification_type' => 'welcome',
            'recipient_identifier' => 'test@example.com',
            'error_message' => 'SMTP connection refused',
            'source' => 'notification',
        ]);

        $this->assertEquals(NotificationLogStatus::Failed, $log->status);
        $this->assertEquals('SMTP connection refused', $log->error_message);
    }

    /**
     * logSkipped()가 skipped 상태로 로그를 기록하는지 확인
     */
    public function test_log_skipped_creates_skipped_record(): void
    {
        $log = $this->service->logSkipped([
            'channel' => 'mail',
            'notification_type' => 'welcome',
            'recipient_identifier' => 'test@example.com',
            'source' => 'notification',
        ]);

        $this->assertEquals(NotificationLogStatus::Skipped, $log->status);
    }

    /**
     * deleteLog()가 로그를 삭제하는지 확인
     */
    public function test_delete_log(): void
    {
        $log = $this->service->logSent([
            'channel' => 'mail',
            'notification_type' => 'test',
            'recipient_identifier' => 'test@example.com',
        ]);

        $result = $this->service->deleteLog($log);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('notification_logs', ['id' => $log->id]);
    }

    /**
     * bulkDelete()가 다건 삭제하는지 확인
     */
    public function test_bulk_delete(): void
    {
        $log1 = $this->service->logSent(['channel' => 'mail', 'notification_type' => 'test', 'recipient_identifier' => 'a@test.com']);
        $log2 = $this->service->logSent(['channel' => 'mail', 'notification_type' => 'test', 'recipient_identifier' => 'b@test.com']);
        $log3 = $this->service->logSent(['channel' => 'mail', 'notification_type' => 'test', 'recipient_identifier' => 'c@test.com']);

        $count = $this->service->bulkDelete([$log1->id, $log2->id]);

        $this->assertEquals(2, $count);
        $this->assertDatabaseHas('notification_logs', ['id' => $log3->id]);
    }

    /**
     * getLogs()가 채널 필터를 적용하는지 확인
     */
    public function test_get_logs_filters_by_channel(): void
    {
        $this->service->logSent(['channel' => 'mail', 'notification_type' => 'test', 'recipient_identifier' => 'a@test.com']);
        $this->service->logSent(['channel' => 'database', 'notification_type' => 'test', 'recipient_identifier' => '42']);
        $this->service->logSent(['channel' => 'mail', 'notification_type' => 'test', 'recipient_identifier' => 'b@test.com']);

        $result = $this->service->getLogs(['channel' => 'mail']);

        $this->assertEquals(2, $result->total());
    }
}
