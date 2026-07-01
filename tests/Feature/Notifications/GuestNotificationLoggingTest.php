<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationLog;
use App\Notifications\GenericNotification;
use App\Notifications\GuestNotifiable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 비회원(게스트) 발송 로깅 end-to-end 테스트
 *
 * 비회원에게 실제로 발송이 일어날 때(mail driver=log) 알림 발송 시스템(notification_logs)에
 * 정상 기록되는지, 그리고 게스트의 합성 키(guest:...)가 정수 FK 컬럼에 들어가지 않고
 * recipient_user_id=null + recipient_identifier=email 로 분리 기록되는지 검증한다.
 */
class GuestNotificationLoggingTest extends TestCase
{
    use RefreshDatabase;

    private function sendGuestMail(string $email, ?string $name = '비회원'): void
    {
        // mail 채널 직접 지정 GenericNotification — definition/template 없이도
        // 발송 파이프라인(NotificationDispatcher::buildContext → after_channel_send 훅)을 탄다.
        $guest = new GuestNotifiable($email, $name, 'ko');
        $guest->notify(new GenericNotification(
            type: 'welcome',
            hookPrefix: 'core.auth',
            data: ['name' => $name],
            extensionType: 'core',
            extensionIdentifier: 'core',
            channel: 'mail',
        ));
    }

    public function test_guest_mail_send_is_logged_with_null_user_id_and_email_identifier(): void
    {
        $this->sendGuestMail('guest@example.com', '비회원주문자');

        $log = NotificationLog::where('channel', 'mail')
            ->where('recipient_identifier', 'guest@example.com')
            ->first();

        $this->assertNotNull($log, '비회원 mail 발송이 notification_logs 에 기록되어야 한다.');
        // 게스트는 users FK 가 없으므로 recipient_user_id 는 null 이어야 한다 (합성 키 문자열 미삽입).
        $this->assertNull($log->recipient_user_id);
        $this->assertSame('비회원주문자', $log->recipient_name);
    }

    public function test_guest_database_channel_is_not_logged_as_sent(): void
    {
        // database 채널은 allow_guest=false 게이트로 via() 가 빈 배열을 반환 → 발송 안 됨.
        $guest = new GuestNotifiable('guest-db@example.com', '비회원', 'ko');
        $guest->notify(new GenericNotification(
            type: 'welcome',
            hookPrefix: 'core.auth',
            data: ['name' => '비회원'],
            extensionType: 'core',
            extensionIdentifier: 'core',
            channel: 'database',
        ));

        // database 채널로 발송 성공 기록이 없어야 한다 (게이트 차단).
        $sent = NotificationLog::where('channel', 'database')
            ->where('recipient_identifier', 'guest-db@example.com')
            ->where('status', '!=', 'skipped')
            ->count();

        $this->assertSame(0, $sent, '비회원 database 발송은 게이트로 차단되어 발송 기록이 없어야 한다.');

        // 사이트내(database) 알림 morph 테이블에도 게스트 행이 생기지 않아야 한다.
        $this->assertDatabaseCount('notifications', 0);
    }
}
