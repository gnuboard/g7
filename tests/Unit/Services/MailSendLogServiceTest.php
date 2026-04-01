<?php

namespace Tests\Unit\Services;

use App\Enums\ExtensionOwnerType;
use App\Enums\MailSendStatus;
use App\Models\MailSendLog;
use App\Services\MailSendLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MailSendLogService 테스트
 *
 * 메일 발송 이력 서비스의 로깅, 조회, 통계 메서드를 검증합니다.
 */
class MailSendLogServiceTest extends TestCase
{
    use RefreshDatabase;

    private MailSendLogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MailSendLogService::class);
    }

    // ========================================================================
    // logSent
    // ========================================================================

    /**
     * 발송 성공 이력을 기록합니다
     */
    public function test_log_sent_creates_sent_record(): void
    {
        $log = $this->service->logSent(
            recipientEmail: 'user@example.com',
            recipientName: 'John Doe',
            subject: 'Welcome',
            templateType: 'welcome',
            extensionType: ExtensionOwnerType::Core,
            extensionIdentifier: 'core',
            source: 'notification',
        );

        $this->assertInstanceOf(MailSendLog::class, $log);
        $this->assertTrue($log->exists);
        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'user@example.com',
            'recipient_name' => 'John Doe',
            'subject' => 'Welcome',
            'template_type' => 'welcome',
            'extension_type' => ExtensionOwnerType::Core->value,
            'extension_identifier' => 'core',
            'source' => 'notification',
            'status' => MailSendStatus::Sent->value,
        ]);
    }

    /**
     * logSent는 기본값으로 extensionType=Core, extensionIdentifier='core', source=null을 사용
     */
    public function test_log_sent_uses_default_values(): void
    {
        $log = $this->service->logSent(
            recipientEmail: 'test@example.com',
        );

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'test@example.com',
            'recipient_name' => null,
            'subject' => null,
            'template_type' => null,
            'extension_type' => ExtensionOwnerType::Core->value,
            'extension_identifier' => 'core',
            'source' => null,
            'status' => MailSendStatus::Sent->value,
        ]);
    }

    /**
     * logSent는 sent_at을 자동 설정
     */
    public function test_log_sent_sets_sent_at(): void
    {
        $log = $this->service->logSent(recipientEmail: 'test@example.com');

        $this->assertNotNull($log->sent_at);
    }

    /**
     * logSent는 body를 기록합니다
     */
    public function test_log_sent_records_body(): void
    {
        $body = '<p>Welcome to G7!</p>';

        $log = $this->service->logSent(
            recipientEmail: 'user@example.com',
            subject: 'Welcome',
            body: $body,
        );

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'user@example.com',
            'body' => $body,
        ]);
    }

    /**
     * logSent는 body가 null일 수 있음
     */
    public function test_log_sent_allows_null_body(): void
    {
        $log = $this->service->logSent(
            recipientEmail: 'test@example.com',
        );

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'test@example.com',
            'body' => null,
        ]);
    }

    /**
     * logSent는 발송자 정보를 기록합니다
     */
    public function test_log_sent_records_sender(): void
    {
        $log = $this->service->logSent(
            recipientEmail: 'user@example.com',
            subject: 'Welcome',
            senderEmail: 'admin@example.com',
            senderName: 'G7 Admin',
        );

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'user@example.com',
            'sender_email' => 'admin@example.com',
            'sender_name' => 'G7 Admin',
        ]);
    }

    /**
     * logSent는 발송자 정보가 null일 수 있음
     */
    public function test_log_sent_allows_null_sender(): void
    {
        $log = $this->service->logSent(
            recipientEmail: 'test@example.com',
        );

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'test@example.com',
            'sender_email' => null,
            'sender_name' => null,
        ]);
    }

    // ========================================================================
    // logFailed
    // ========================================================================

    /**
     * 발송 실패 이력을 기록합니다
     */
    public function test_log_failed_creates_failed_record(): void
    {
        $log = $this->service->logFailed(
            recipientEmail: 'user@example.com',
            subject: 'Order Confirmation',
            templateType: 'order_confirmed',
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-ecommerce',
            source: 'notification',
            errorMessage: 'Connection timeout',
        );

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'user@example.com',
            'subject' => 'Order Confirmation',
            'template_type' => 'order_confirmed',
            'extension_type' => ExtensionOwnerType::Module->value,
            'extension_identifier' => 'sirsoft-ecommerce',
            'source' => 'notification',
            'status' => MailSendStatus::Failed->value,
            'error_message' => 'Connection timeout',
        ]);
    }

    /**
     * logFailed는 body를 기록합니다
     */
    public function test_log_failed_records_body(): void
    {
        $body = '<p>Order Confirmation</p>';

        $log = $this->service->logFailed(
            recipientEmail: 'user@example.com',
            subject: 'Order',
            errorMessage: 'SMTP error',
            body: $body,
        );

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'user@example.com',
            'body' => $body,
            'status' => MailSendStatus::Failed->value,
        ]);
    }

    /**
     * logFailed는 발송자 정보를 기록합니다
     */
    public function test_log_failed_records_sender(): void
    {
        $log = $this->service->logFailed(
            recipientEmail: 'user@example.com',
            errorMessage: 'SMTP error',
            senderEmail: 'noreply@example.com',
            senderName: 'G7 System',
        );

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'user@example.com',
            'sender_email' => 'noreply@example.com',
            'sender_name' => 'G7 System',
            'status' => MailSendStatus::Failed->value,
        ]);
    }

    /**
     * logFailed는 error_message가 null일 수 있음
     */
    public function test_log_failed_allows_null_error_message(): void
    {
        $log = $this->service->logFailed(
            recipientEmail: 'test@example.com',
        );

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'test@example.com',
            'status' => MailSendStatus::Failed->value,
            'error_message' => null,
        ]);
    }

    // ========================================================================
    // logSkipped
    // ========================================================================

    /**
     * 발송 건너뛰기 이력을 기록합니다
     */
    public function test_log_skipped_creates_skipped_record(): void
    {
        $log = $this->service->logSkipped(
            recipientEmail: 'user@example.com',
            recipientName: 'User',
            templateType: 'welcome',
            extensionType: ExtensionOwnerType::Core,
            extensionIdentifier: 'core',
            source: 'notification',
            errorMessage: '비활성 템플릿',
        );

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'user@example.com',
            'recipient_name' => 'User',
            'subject' => null,
            'template_type' => 'welcome',
            'extension_type' => ExtensionOwnerType::Core->value,
            'extension_identifier' => 'core',
            'source' => 'notification',
            'status' => MailSendStatus::Skipped->value,
            'error_message' => '비활성 템플릿',
        ]);
    }

    /**
     * logSkipped는 발송자 정보를 기록합니다
     */
    public function test_log_skipped_records_sender(): void
    {
        $log = $this->service->logSkipped(
            recipientEmail: 'user@example.com',
            senderEmail: 'admin@example.com',
            senderName: 'G7 Admin',
        );

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'user@example.com',
            'sender_email' => 'admin@example.com',
            'sender_name' => 'G7 Admin',
            'status' => MailSendStatus::Skipped->value,
        ]);
    }

    /**
     * logSkipped는 subject를 항상 null로 설정
     */
    public function test_log_skipped_always_sets_null_subject(): void
    {
        $log = $this->service->logSkipped(
            recipientEmail: 'test@example.com',
        );

        $this->assertNull($log->subject);
    }

    // ========================================================================
    // getLogs
    // ========================================================================

    /**
     * 발송 이력 목록 조회
     */
    public function test_get_logs_returns_paginated_result(): void
    {
        MailSendLog::factory()->count(5)->create();

        $result = $this->service->getLogs();

        $this->assertCount(5, $result->items());
        $this->assertEquals(5, $result->total());
    }

    /**
     * 발송 이력 목록 필터 조회
     */
    public function test_get_logs_applies_filters(): void
    {
        MailSendLog::factory()->forExtension(ExtensionOwnerType::Core, 'core')->count(3)->create();
        MailSendLog::factory()->forExtension(ExtensionOwnerType::Module, 'sirsoft-ecommerce')->count(2)->create();

        $result = $this->service->getLogs(['extension_type' => 'core']);

        $this->assertCount(3, $result->items());
    }

    // ========================================================================
    // getStatistics
    // ========================================================================

    /**
     * 발송 통계 조회
     */
    public function test_get_statistics_returns_correct_data(): void
    {
        MailSendLog::factory()->count(3)->create([
            'status' => MailSendStatus::Sent->value,
            'sent_at' => now(),
        ]);
        MailSendLog::factory()->failed()->count(1)->create([
            'sent_at' => now(),
        ]);

        $stats = $this->service->getStatistics();

        $this->assertEquals(4, $stats['total']);
        $this->assertEquals(3, $stats['sent']);
        $this->assertEquals(1, $stats['failed']);
        $this->assertEquals(4, $stats['today']);
    }
}
