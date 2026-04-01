<?php

namespace Tests\Unit\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Enums\MailSendStatus;
use App\Listeners\MailSendLogListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MailSendLogListener 테스트
 *
 * 훅 리스너의 메일 발송 이력 기록 동작을 검증합니다.
 */
class MailSendLogListenerTest extends TestCase
{
    use RefreshDatabase;

    private MailSendLogListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = app(MailSendLogListener::class);
    }

    // ========================================================================
    // HookListenerInterface 구현
    // ========================================================================

    /**
     * HookListenerInterface를 구현하는지 확인
     */
    public function test_implements_hook_listener_interface(): void
    {
        $this->assertInstanceOf(HookListenerInterface::class, $this->listener);
    }

    /**
     * getSubscribedHooks()가 올바른 훅 3종을 반환하는지 확인
     */
    public function test_get_subscribed_hooks_returns_correct_hooks(): void
    {
        $hooks = MailSendLogListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.mail.after_send', $hooks);
        $this->assertArrayHasKey('core.mail.send_failed', $hooks);
        $this->assertArrayHasKey('core.mail.send_skipped', $hooks);

        $this->assertEquals('handleAfterSend', $hooks['core.mail.after_send']['method']);
        $this->assertEquals('handleSendFailed', $hooks['core.mail.send_failed']['method']);
        $this->assertEquals('handleSendSkipped', $hooks['core.mail.send_skipped']['method']);
    }

    // ========================================================================
    // handleAfterSend (발송 성공)
    // ========================================================================

    /**
     * 발송 성공 이력을 기록함
     */
    public function test_handle_after_send_creates_sent_record(): void
    {
        $data = $this->createHookData([
            'recipientEmail' => 'user@example.com',
            'recipientName' => 'John Doe',
            'subject' => 'Welcome Email',
            'templateType' => 'welcome',
            'extensionType' => 'core',
            'extensionIdentifier' => 'core',
            'source' => 'notification',
        ]);

        $this->listener->handleAfterSend($data);

        $this->assertDatabaseCount('mail_send_logs', 1);
        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'user@example.com',
            'recipient_name' => 'John Doe',
            'subject' => 'Welcome Email',
            'template_type' => 'welcome',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'source' => 'notification',
            'status' => MailSendStatus::Sent->value,
        ]);
    }

    /**
     * source만 있어도 기록함
     */
    public function test_handle_after_send_with_source_only(): void
    {
        $data = $this->createHookData([
            'recipientEmail' => 'admin@example.com',
            'source' => 'test_mail',
        ]);

        $this->listener->handleAfterSend($data);

        $this->assertDatabaseCount('mail_send_logs', 1);
        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'admin@example.com',
            'source' => 'test_mail',
            'status' => MailSendStatus::Sent->value,
        ]);
    }

    /**
     * extensionType 미제공 시 기본값 'core' 사용
     */
    public function test_handle_after_send_defaults_extension_type_to_core(): void
    {
        $data = [
            'recipientEmail' => 'test@example.com',
            'templateType' => 'password_reset',
        ];

        $this->listener->handleAfterSend($data);

        $this->assertDatabaseHas('mail_send_logs', [
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'template_type' => 'password_reset',
        ]);
    }

    /**
     * extensionType/extensionIdentifier 값을 올바르게 기록함
     */
    public function test_handle_after_send_records_extension(): void
    {
        $data = $this->createHookData([
            'recipientEmail' => 'buyer@example.com',
            'recipientName' => 'Buyer',
            'templateType' => 'order_confirmed',
            'extensionType' => 'module',
            'extensionIdentifier' => 'sirsoft-ecommerce',
        ]);

        $this->listener->handleAfterSend($data);

        $this->assertDatabaseHas('mail_send_logs', [
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
            'template_type' => 'order_confirmed',
        ]);
    }

    /**
     * source 값을 올바르게 기록함
     */
    public function test_handle_after_send_records_source(): void
    {
        $data = $this->createHookData([
            'templateType' => 'welcome',
            'source' => 'notification',
        ]);

        $this->listener->handleAfterSend($data);

        $this->assertDatabaseHas('mail_send_logs', [
            'source' => 'notification',
            'template_type' => 'welcome',
        ]);
    }

    /**
     * source 미제공 시 null로 기록
     */
    public function test_handle_after_send_source_null_when_missing(): void
    {
        $data = [
            'recipientEmail' => 'test@example.com',
            'templateType' => 'welcome',
        ];

        $this->listener->handleAfterSend($data);

        $this->assertDatabaseHas('mail_send_logs', [
            'template_type' => 'welcome',
            'source' => null,
        ]);
    }

    /**
     * recipientName 미제공 시 null로 기록
     */
    public function test_handle_after_send_null_recipient_name(): void
    {
        $data = [
            'recipientEmail' => 'noname@example.com',
            'templateType' => 'welcome',
        ];

        $this->listener->handleAfterSend($data);

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'noname@example.com',
            'recipient_name' => null,
        ]);
    }

    /**
     * body 값을 올바르게 기록함
     */
    public function test_handle_after_send_records_body(): void
    {
        $body = '<p>Welcome to G7!</p>';

        $data = $this->createHookData([
            'recipientEmail' => 'user@example.com',
            'body' => $body,
        ]);

        $this->listener->handleAfterSend($data);

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'user@example.com',
            'body' => $body,
        ]);
    }

    /**
     * 발송자 정보를 올바르게 기록함
     */
    public function test_handle_after_send_records_sender(): void
    {
        $data = $this->createHookData([
            'recipientEmail' => 'user@example.com',
            'senderEmail' => 'admin@example.com',
            'senderName' => 'G7 Admin',
        ]);

        $this->listener->handleAfterSend($data);

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'user@example.com',
            'sender_email' => 'admin@example.com',
            'sender_name' => 'G7 Admin',
        ]);
    }

    /**
     * 발송자 정보 미제공 시 null로 기록
     */
    public function test_handle_after_send_sender_null_when_missing(): void
    {
        $data = [
            'recipientEmail' => 'test@example.com',
        ];

        $this->listener->handleAfterSend($data);

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'test@example.com',
            'sender_email' => null,
            'sender_name' => null,
        ]);
    }

    /**
     * body 미제공 시 null로 기록
     */
    public function test_handle_after_send_body_null_when_missing(): void
    {
        $data = [
            'recipientEmail' => 'test@example.com',
        ];

        $this->listener->handleAfterSend($data);

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'test@example.com',
            'body' => null,
        ]);
    }

    /**
     * 정상 실행에서 예외가 발생하지 않음
     */
    public function test_handle_after_send_does_not_throw(): void
    {
        $data = $this->createHookData();

        $this->listener->handleAfterSend($data);

        $this->assertDatabaseCount('mail_send_logs', 1);
    }

    // ========================================================================
    // handleSendFailed (발송 실패)
    // ========================================================================

    /**
     * 발송 실패 이력을 기록함
     */
    public function test_handle_send_failed_creates_failed_record(): void
    {
        $data = $this->createHookData([
            'recipientEmail' => 'user@example.com',
            'subject' => 'Order Confirmation',
            'templateType' => 'order_confirmed',
            'extensionType' => 'module',
            'extensionIdentifier' => 'sirsoft-ecommerce',
            'source' => 'notification',
            'errorMessage' => 'Connection timeout',
        ]);

        $this->listener->handleSendFailed($data);

        $this->assertDatabaseCount('mail_send_logs', 1);
        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'user@example.com',
            'subject' => 'Order Confirmation',
            'template_type' => 'order_confirmed',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
            'source' => 'notification',
            'status' => MailSendStatus::Failed->value,
            'error_message' => 'Connection timeout',
        ]);
    }

    /**
     * 발송 실패 시 발송자 정보를 올바르게 기록함
     */
    public function test_handle_send_failed_records_sender(): void
    {
        $data = $this->createHookData([
            'recipientEmail' => 'user@example.com',
            'errorMessage' => 'SMTP error',
            'senderEmail' => 'noreply@example.com',
            'senderName' => 'G7',
        ]);

        $this->listener->handleSendFailed($data);

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'user@example.com',
            'sender_email' => 'noreply@example.com',
            'sender_name' => 'G7',
            'status' => MailSendStatus::Failed->value,
        ]);
    }

    /**
     * 발송 실패 시 body를 올바르게 기록함
     */
    public function test_handle_send_failed_records_body(): void
    {
        $body = '<p>Order details</p>';

        $data = $this->createHookData([
            'recipientEmail' => 'user@example.com',
            'errorMessage' => 'SMTP error',
            'body' => $body,
        ]);

        $this->listener->handleSendFailed($data);

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'user@example.com',
            'body' => $body,
            'status' => MailSendStatus::Failed->value,
        ]);
    }

    // ========================================================================
    // handleSendSkipped (발송 건너뜀)
    // ========================================================================

    /**
     * 발송 건너뜀 이력을 기록함
     */
    public function test_handle_send_skipped_creates_skipped_record(): void
    {
        $data = $this->createHookData([
            'recipientEmail' => 'user@example.com',
            'recipientName' => 'User',
            'templateType' => 'welcome',
            'extensionType' => 'core',
            'extensionIdentifier' => 'core',
            'source' => 'notification',
        ]);

        $this->listener->handleSendSkipped($data);

        $this->assertDatabaseCount('mail_send_logs', 1);
        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'user@example.com',
            'recipient_name' => 'User',
            'template_type' => 'welcome',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'source' => 'notification',
            'status' => MailSendStatus::Skipped->value,
        ]);
    }

    // ========================================================================
    // 헬퍼 메서드
    // ========================================================================

    /**
     * 훅 데이터 생성 헬퍼
     *
     * @param array $overrides 오버라이드할 데이터
     * @return array 훅 데이터
     */
    private function createHookData(array $overrides = []): array
    {
        return array_merge([
            'recipientEmail' => 'test@example.com',
            'recipientName' => null,
            'subject' => 'Test',
            'templateType' => null,
            'extensionType' => 'core',
            'extensionIdentifier' => 'core',
            'source' => null,
        ], $overrides);
    }
}
