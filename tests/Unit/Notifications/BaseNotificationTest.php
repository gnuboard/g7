<?php

namespace Tests\Unit\Notifications;

use App\Extension\HookManager;
use App\Models\User;
use App\Notifications\BaseNotification;
use Illuminate\Notifications\Notification;
use Mockery;
use Tests\TestCase;

/**
 * BaseNotification 테스트
 *
 * 알림 기본 추상 클래스의 via() 채널 결정 및 훅 연동을 검증합니다.
 */
class BaseNotificationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * BaseNotification이 Notification을 상속하는지 확인
     */
    public function test_extends_laravel_notification(): void
    {
        $notification = $this->createConcreteNotification();

        $this->assertInstanceOf(Notification::class, $notification);
    }

    /**
     * via()가 기본적으로 ['mail'] 채널을 반환하는지 확인
     */
    public function test_via_returns_mail_channel_by_default(): void
    {
        $notification = $this->createConcreteNotification();
        $user = new User(['email' => 'test@example.com', 'name' => 'Test User']);

        $channels = $notification->via($user);

        $this->assertEquals(['mail'], $channels);
    }

    /**
     * Filter 훅으로 채널을 추가할 수 있는지 확인
     */
    public function test_hook_can_add_channels(): void
    {
        // 훅 리스너 등록: database 채널 추가
        HookManager::addFilter(
            'test.prefix.notification.channels',
            function (array $channels) {
                $channels[] = 'database';

                return $channels;
            }
        );

        $notification = $this->createConcreteNotification('test.prefix', 'test_type');
        $user = new User(['email' => 'test@example.com', 'name' => 'Test User']);

        $channels = $notification->via($user);

        $this->assertContains('mail', $channels);
        $this->assertContains('database', $channels);

        // 훅 정리
        HookManager::clearFilter('test.prefix.notification.channels');
    }

    /**
     * 훅에 notification type이 전달되는지 확인
     */
    public function test_hook_receives_notification_type(): void
    {
        $receivedType = null;

        HookManager::addFilter(
            'test.prefix.notification.channels',
            function (array $channels, string $type) use (&$receivedType) {
                $receivedType = $type;

                return $channels;
            }
        );

        $notification = $this->createConcreteNotification('test.prefix', 'welcome');
        $user = new User(['email' => 'test@example.com', 'name' => 'Test User']);

        $notification->via($user);

        $this->assertEquals('welcome', $receivedType);

        // 훅 정리
        HookManager::clearFilter('test.prefix.notification.channels');
    }

    /**
     * 훅에 notifiable 객체가 전달되는지 확인
     */
    public function test_hook_receives_notifiable(): void
    {
        $receivedNotifiable = null;

        HookManager::addFilter(
            'test.prefix.notification.channels',
            function (array $channels, string $type, object $notifiable) use (&$receivedNotifiable) {
                $receivedNotifiable = $notifiable;

                return $channels;
            }
        );

        $notification = $this->createConcreteNotification('test.prefix', 'welcome');
        $user = new User(['email' => 'test@example.com', 'name' => 'Test User']);

        $notification->via($user);

        $this->assertSame($user, $receivedNotifiable);

        // 훅 정리
        HookManager::clearFilter('test.prefix.notification.channels');
    }

    /**
     * 훅 이름이 {hookPrefix}.notification.channels 형식인지 확인
     */
    public function test_hook_name_format(): void
    {
        $hookCalled = false;

        // core.auth 접두사로 등록
        HookManager::addFilter(
            'core.auth.notification.channels',
            function (array $channels) use (&$hookCalled) {
                $hookCalled = true;

                return $channels;
            }
        );

        $notification = $this->createConcreteNotification('core.auth', 'welcome');
        $user = new User(['email' => 'test@example.com', 'name' => 'Test User']);

        $notification->via($user);

        $this->assertTrue($hookCalled, '훅 이름이 core.auth.notification.channels 형식이어야 합니다');

        // 훅 정리
        HookManager::clearFilter('core.auth.notification.channels');
    }

    /**
     * 게시판 모듈 훅 접두사 형식 확인
     */
    public function test_board_module_hook_prefix(): void
    {
        $hookCalled = false;

        HookManager::addFilter(
            'sirsoft-board.notification.channels',
            function (array $channels) use (&$hookCalled) {
                $hookCalled = true;

                return $channels;
            }
        );

        $notification = $this->createConcreteNotification('sirsoft-board', 'new_comment');
        $user = new User(['email' => 'test@example.com', 'name' => 'Test User']);

        $notification->via($user);

        $this->assertTrue($hookCalled, '훅 이름이 sirsoft-board.notification.channels 형식이어야 합니다');

        // 훅 정리
        HookManager::clearFilter('sirsoft-board.notification.channels');
    }

    /**
     * 테스트용 구체 알림 클래스를 생성합니다.
     *
     * @param string $hookPrefix 훅 접두사
     * @param string $notificationType 알림 유형
     * @return BaseNotification
     */
    private function createConcreteNotification(
        string $hookPrefix = 'test.prefix',
        string $notificationType = 'test_type'
    ): BaseNotification {
        return new class($hookPrefix, $notificationType) extends BaseNotification
        {
            public function __construct(
                private string $hookPrefix,
                private string $notificationType
            ) {}

            protected function getHookPrefix(): string
            {
                return $this->hookPrefix;
            }

            protected function getNotificationType(): string
            {
                return $this->notificationType;
            }

            public function toMail(object $notifiable): void
            {
                // 테스트용 빈 구현
            }
        };
    }
}
