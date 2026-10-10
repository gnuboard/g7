<?php

namespace Tests\Unit\Notifications;

use App\Models\NotificationDefinition;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Notifications\GuestNotifiable;
use App\Services\NotificationChannelService;
use App\Services\NotificationTemplateService;
use Tests\Helpers\MocksChannelReadiness;
use Tests\TestCase;

/**
 * 비회원(게스트) 채널 게이트 테스트
 *
 * GenericNotification::via() 가 게스트 수신자에 대해 채널 allow_guest 정책을 적용하는지,
 * NotificationChannelService::isChannelGuestAllowed() 가 config/훅 기반으로 동작하는지 검증.
 */
class GenericNotificationGuestGateTest extends TestCase
{
    use MocksChannelReadiness;

    private NotificationDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();

        // 게스트 게이트가 관심사다 — readiness 는 고정한다. 이 테스트는 mail 과 database 를
        // 함께 태우므로 mail 만 여는 enableMailChannelReadiness() 로는 부족하다
        // (기본 테스트 설정은 from_address 가 플레이스홀더라 mail readiness 가 false 다).
        $this->mockAllChannelsReady();

        $templateService = app(NotificationTemplateService::class);
        foreach (['mail', 'database'] as $ch) {
            $templateService->invalidateCache('guest_gate_check', $ch);
        }

        $this->definition = NotificationDefinition::updateOrCreate(
            ['type' => 'guest_gate_check'],
            [
                'hook_prefix' => 'core.test',
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'name' => ['ko' => '테스트', 'en' => 'Test'],
                'variables' => [],
                'channels' => ['mail', 'database'],
                'hooks' => [],
                'is_active' => true,
                'is_default' => false,
            ]
        );

        foreach (['mail', 'database'] as $ch) {
            NotificationTemplate::updateOrCreate(
                ['definition_id' => $this->definition->id, 'channel' => $ch],
                [
                    'subject' => ['ko' => '제목', 'en' => 'Subject'],
                    'body' => ['ko' => '본문', 'en' => 'Body'],
                    'is_active' => true,
                    'is_default' => false,
                ]
            );
        }
    }

    protected function tearDown(): void
    {
        // RefreshDatabase 미사용 — 남긴 행이 커밋된 채 다음 테스트에 보인다. 게이트가 채널을 막을 때
        // skip 로그가 쌓이므로 notification_logs 도 함께 지운다(절대 건수를 세는 테스트 오염 방지).
        NotificationLog::where('notification_type', 'guest_gate_check')->delete();
        NotificationTemplate::where('definition_id', $this->definition->id)->delete();
        $this->definition->delete();
        parent::tearDown();
    }

    private function notification(string $channel): GenericNotification
    {
        return new GenericNotification(
            type: 'guest_gate_check',
            hookPrefix: 'core.test',
            data: [],
            extensionType: 'core',
            extensionIdentifier: 'core',
            channel: $channel,
        );
    }

    // ── isChannelGuestAllowed (config 기반) ──

    public function test_channel_guest_allowed_reads_config(): void
    {
        $service = app(NotificationChannelService::class);

        $this->assertTrue($service->isChannelGuestAllowed('mail'));
        $this->assertFalse($service->isChannelGuestAllowed('database'));
        // 미선언 채널은 기본 차단(false)
        $this->assertFalse($service->isChannelGuestAllowed('nonexistent_channel'));
    }

    // ── via() 게스트 게이트 ──

    public function test_guest_passes_mail_channel(): void
    {
        $guest = new GuestNotifiable('guest@example.com', '홍길동', 'ko');

        $this->assertSame(['mail'], $this->notification('mail')->via($guest));
    }

    public function test_guest_blocked_on_database_channel(): void
    {
        $guest = new GuestNotifiable('guest@example.com', '홍길동', 'ko');

        $this->assertSame([], $this->notification('database')->via($guest));
    }

    public function test_member_is_unaffected_by_guest_gate(): void
    {
        $member = User::factory()->make();

        // 회원은 게스트 게이트 무영향 — database 채널도 정상 통과(템플릿 존재 + readiness)
        $this->assertSame(['database'], $this->notification('database')->via($member));
    }
}
