<?php

namespace Tests\Unit\Listeners;

use App\Enums\ExtensionOwnerType;
use App\Extension\HookManager;
use App\Jobs\DispatchHookListenerJob;
use App\Listeners\NotificationLogListener;
use App\Models\NotificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * NotificationLogListener 테스트
 *
 * 메일 훅 이벤트(배열 데이터) 수신 시 notification_logs에 기록되는지 검증합니다.
 */
class NotificationLogListenerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * handleChannelSent()가 sent 로그를 기록하는지 확인
     *
     * @scenario case=notification_log_sync
     *
     * @effects log_written_in_send_cycle, channel_send_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_channel_sent_creates_log(): void
    {
        $listener = app(NotificationLogListener::class);
        $listener->handleChannelSent('mail', [
            'recipient_identifier' => 'test@example.com',
            'recipient_name' => 'Test User',
            'notification_type' => 'welcome',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
        ]);

        $this->assertDatabaseHas('notification_logs', [
            'channel' => 'mail',
            'notification_type' => 'welcome',
            'recipient_identifier' => 'test@example.com',
            'status' => 'sent',
        ]);
    }

    /**
     * handleChannelSent()가 database 채널도 기록하는지 확인
     *
     * @scenario case=notification_log_sync
     *
     * @effects log_written_in_send_cycle, channel_send_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_channel_sent_logs_database_channel(): void
    {
        $listener = app(NotificationLogListener::class);
        $listener->handleChannelSent('database', [
            'recipient_identifier' => '1',
            'recipient_name' => 'Test User',
            'notification_type' => 'password_changed',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
        ]);

        $this->assertDatabaseHas('notification_logs', [
            'channel' => 'database',
            'notification_type' => 'password_changed',
            'status' => 'sent',
        ]);
    }

    /**
     * handleChannelFailed()가 failed 로그를 기록하는지 확인
     *
     * @scenario case=notification_log_sync
     *
     * @effects log_written_in_send_cycle, channel_failure_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_channel_failed_creates_log(): void
    {
        $listener = app(NotificationLogListener::class);
        $listener->handleChannelFailed('mail', [
            'recipient_identifier' => 'fail@example.com',
            'notification_type' => 'reset_password',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'error' => 'SMTP connection refused',
        ]);

        $this->assertDatabaseHas('notification_logs', [
            'channel' => 'mail',
            'notification_type' => 'reset_password',
            'recipient_identifier' => 'fail@example.com',
            'status' => 'failed',
        ]);

        $log = NotificationLog::where('recipient_identifier', 'fail@example.com')->first();
        $this->assertStringContainsString('SMTP connection refused', $log->error_message);
    }

    /**
     * handleMailSkipped()가 skipped 로그를 기록하는지 확인
     *
     * @scenario case=notification_log_sync
     *
     * @effects log_written_in_send_cycle, mail_skip_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_mail_skipped_creates_log(): void
    {
        $listener = app(NotificationLogListener::class);
        $listener->handleMailSkipped([
            'recipientEmail' => 'skip@example.com',
            'templateType' => 'welcome',
            'extensionType' => ExtensionOwnerType::Core,
            'extensionIdentifier' => 'core',
        ]);

        $this->assertDatabaseHas('notification_logs', [
            'channel' => 'mail',
            'notification_type' => 'welcome',
            'recipient_identifier' => 'skip@example.com',
            'status' => 'skipped',
        ]);
    }

    /**
     * handleChannelSent()가 context에 subject/body가 있으면 로그에 저장하는지 확인
     *
     * @scenario case=notification_log_sync
     *
     * @effects log_written_in_send_cycle, channel_send_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_channel_sent_saves_subject_and_body(): void
    {
        $listener = app(NotificationLogListener::class);
        $listener->handleChannelSent('mail', [
            'recipient_identifier' => 'test@example.com',
            'recipient_name' => 'Test User',
            'notification_type' => 'password_changed',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'subject' => '비밀번호가 변경되었습니다',
            'body' => '<p>비밀번호가 성공적으로 변경되었습니다.</p>',
        ]);

        $log = NotificationLog::where('recipient_identifier', 'test@example.com')
            ->where('notification_type', 'password_changed')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('비밀번호가 변경되었습니다', $log->subject);
        $this->assertEquals('<p>비밀번호가 성공적으로 변경되었습니다.</p>', $log->body);
    }

    /**
     * handleChannelSent()가 context에 subject/body가 없으면 null로 저장하는지 확인
     *
     * @scenario case=notification_log_sync
     *
     * @effects log_written_in_send_cycle, channel_send_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_channel_sent_saves_null_when_no_subject_body(): void
    {
        $listener = app(NotificationLogListener::class);
        $listener->handleChannelSent('mail', [
            'recipient_identifier' => 'null@example.com',
            'notification_type' => 'welcome',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
        ]);

        $log = NotificationLog::where('recipient_identifier', 'null@example.com')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->subject);
        $this->assertNull($log->body);
    }

    /**
     * handleChannelFailed()가 context에 subject/body가 있으면 로그에 저장하는지 확인
     *
     * @scenario case=notification_log_sync
     *
     * @effects log_written_in_send_cycle, channel_failure_logged
     *
     * @return void 반환값 없음
     */
    public function test_handle_channel_failed_saves_subject_and_body(): void
    {
        $listener = app(NotificationLogListener::class);
        $listener->handleChannelFailed('mail', [
            'recipient_identifier' => 'fail@example.com',
            'notification_type' => 'order_confirmed',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'subject' => '주문이 확인되었습니다',
            'body' => '<p>주문 내역을 확인하세요.</p>',
            'error' => 'SMTP timeout',
        ]);

        $log = NotificationLog::where('recipient_identifier', 'fail@example.com')
            ->where('notification_type', 'order_confirmed')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('주문이 확인되었습니다', $log->subject);
        $this->assertEquals('<p>주문 내역을 확인하세요.</p>', $log->body);
        $this->assertEquals('failed', $log->status->value ?? $log->status);
    }

    /**
     * 정적 훅 목록이 공통 채널 훅 + mail skipped 훅을 구독하는지 확인
     *
     * @scenario case=notification_log_sync
     *
     * @effects log_written_in_send_cycle, channel_hooks_subscribed
     *
     * @return void 반환값 없음
     */
    public function test_subscribed_hooks_contains_channel_hooks(): void
    {
        $hooks = NotificationLogListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.notification.after_channel_send', $hooks);
        $this->assertArrayHasKey('core.notification.channel_send_failed', $hooks);
        $this->assertArrayHasKey('core.mail.send_skipped', $hooks);
    }

    /**
     * 발송 로그 3훅이 모두 sync 다 — 발송과 같은 자리에서 기록해야 확장이 그 사이클의 문맥을 잇고,
     * 마스킹 전 본문이 큐 인자로 직렬화되지 않는다. (실패-먼저)
     *
     * @scenario case=notification_log_sync
     *
     * @effects log_written_in_send_cycle
     *
     * @return void 반환값 없음
     */
    public function test_every_log_hook_is_synchronous(): void
    {
        foreach (NotificationLogListener::getSubscribedHooks() as $hook => $config) {
            $this->assertTrue(($config['sync'] ?? false) === true, "{$hook} 가 sync 가 아닙니다.");
        }
    }

    /**
     * 채널 발송 두 훅은 발송 프로세스 안에서 동기로 기록된다 — 큐 드라이버가 database 여도 잡을 쌓지 않는다 (#139).
     *
     * 큐로 돌면 발송 이력이 늦게 보이고, 그 로그에 자기 발송건을 잇는 확장이 발송 때 남긴 프로세스 내 표식을
     * 워커에서 찾지 못해 연결이 늘 실패했다. `core.mail.send_skipped` 도 동기다(#724 — 마스킹 전 본문이 큐 인자로 남지 않게).
     *
     * @scenario recipients=within_chunk, rerun=none
     *
     * @effects channel_logs_written_in_sending_process
     *
     * @return void 반환값 없음
     */
    public function test_channel_send_hooks_run_synchronously_in_sending_process(): void
    {
        $hooks = NotificationLogListener::getSubscribedHooks();
        $this->assertTrue($hooks['core.notification.after_channel_send']['sync'] ?? false);
        $this->assertTrue($hooks['core.notification.channel_send_failed']['sync'] ?? false);
        $this->assertTrue($hooks['core.mail.send_skipped']['sync'] ?? false);

        config(['queue.default' => 'database']);
        Queue::fake();
        // 코어 리스너는 부팅 때 실제 등록 경로(HookListenerRegistrar)로 이미 붙어 있다 — 그 경로를 그대로 탄다
        $before = NotificationLog::query()->count();

        HookManager::doAction('core.notification.after_channel_send', 'database', [
            'notification_type' => 'sync_log_probe',
            'recipient_identifier' => 'sync@example.test',
            'recipient_user_id' => null,
        ]);

        $this->assertSame($before + 1, NotificationLog::query()->count(), '발송 로그가 같은 프로세스에서 기록되지 않았다');
        Queue::assertNotPushed(DispatchHookListenerJob::class, fn ($job) => $job->listenerClass === NotificationLogListener::class);
    }
}
