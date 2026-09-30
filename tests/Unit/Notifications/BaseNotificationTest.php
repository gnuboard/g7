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
    /**
     * 설치된 채널 제공 확장이 코어 채널 훅에 답하면 기본 채널 기대가 흔들리므로 비운 채 시작한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        HookManager::clearFilter('core.notification.channels');
    }

    /**
     * Mockery 컨테이너를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * BaseNotification이 Notification을 상속하는지 확인
     *
     * @effects base_notification_defaults_to_mail_channel
     *
     * @return void 반환값 없음
     */
    public function test_extends_laravel_notification(): void
    {
        $notification = $this->createConcreteNotification();

        $this->assertInstanceOf(Notification::class, $notification);
    }

    /**
     * via()가 기본적으로 ['mail'] 채널을 반환하는지 확인
     *
     * @effects base_notification_defaults_to_mail_channel
     *
     * @return void 반환값 없음
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
     *
     * @effects domain_channel_hook_receives_type_and_notifiable
     *
     * @return void 반환값 없음
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
     *
     * @effects domain_channel_hook_receives_type_and_notifiable
     *
     * @return void 반환값 없음
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
     *
     * @effects domain_channel_hook_receives_type_and_notifiable
     *
     * @return void 반환값 없음
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
     *
     * @effects domain_channel_hook_receives_type_and_notifiable
     *
     * @return void 반환값 없음
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
     *
     * @effects domain_channel_hook_receives_type_and_notifiable
     *
     * @return void 반환값 없음
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
     * 채널 제공 확장은 코어 훅 하나에 답해 어느 도메인의 알림에도 후보를 더한다.
     *
     * 문자 같은 채널 플러그인이 도메인 접두사 목록(core.auth·게시판·이커머스 …)을 스스로 적지
     * 않아도, 코어가 모든 접두사의 채널 결정에서 `core.notification.channels` 를 발화하고
     * 접두사를 넷째 인자로 넘긴다.
     *
     * @scenario recipient_type=member, channel=mail
     *
     * @effects core_channel_candidates_hook_answers_every_domain
     *
     * @return void 반환값 없음
     */
    public function test_core_channel_hook_adds_candidates_for_any_domain_prefix(): void
    {
        $receivedPrefixes = [];

        HookManager::addFilter(
            'core.notification.channels',
            function (array $channels, string $type, ?object $notifiable, string $hookPrefix) use (&$receivedPrefixes) {
                $receivedPrefixes[] = $hookPrefix;
                $channels[] = 'sms';

                return $channels;
            }
        );

        $user = new User(['email' => 'test@example.com', 'name' => 'Test User']);

        foreach (['core.auth', 'vendor-anything', 'future.domain'] as $prefix) {
            $channels = $this->createConcreteNotification($prefix, 'welcome')->via($user);
            $this->assertSame(['mail', 'sms'], $channels, "{$prefix} 알림에도 코어 훅 후보가 더해져야 합니다.");
        }

        $this->assertSame(['core.auth', 'vendor-anything', 'future.domain'], $receivedPrefixes);

        HookManager::clearFilter('core.notification.channels');
    }

    /**
     * 도메인 훅은 코어 훅 뒤에 돌아, 채널 제공 확장이 더한 후보를 자기 설정으로 좁힐 수 있다.
     *
     * @scenario recipient_type=member, channel=mail
     *
     * @effects domain_channel_hook_runs_after_core_hook
     *
     * @return void 반환값 없음
     */
    public function test_domain_channel_hook_runs_after_core_hook(): void
    {
        HookManager::addFilter('core.notification.channels', function (array $channels) {
            $channels[] = 'sms';

            return $channels;
        });
        HookManager::addFilter('test.prefix.notification.channels', function (array $channels) {
            // 도메인 설정에서 sms 를 꺼 둔 상황 — 코어 훅이 더한 후보를 받아 뺀다
            return array_values(array_diff($channels, ['sms']));
        });

        $user = new User(['email' => 'test@example.com', 'name' => 'Test User']);

        $this->assertSame(['mail'], $this->createConcreteNotification('test.prefix', 'welcome')->via($user));

        HookManager::clearFilter('core.notification.channels');
        HookManager::clearFilter('test.prefix.notification.channels');
    }

    /**
     * 테스트용 구체 알림 클래스를 생성합니다.
     *
     * @param string $hookPrefix 훅 접두사
     * @param string $notificationType 알림 유형
     * @return BaseNotification 테스트용 구체 알림
     */
    private function createConcreteNotification(
        string $hookPrefix = 'test.prefix',
        string $notificationType = 'test_type'
    ): BaseNotification {
        return new class($hookPrefix, $notificationType) extends BaseNotification
        {
            /**
             * 테스트용 훅 접두사와 알림 유형을 받습니다.
             *
             * @param  string  $hookPrefix  훅 접두사
             * @param  string  $notificationType  알림 유형
             */
            public function __construct(
                private string $hookPrefix,
                private string $notificationType
            ) {}

            /**
             * 테스트용 훅 접두사를 반환합니다.
             *
             * @return string 훅 접두사
             */
            protected function getHookPrefix(): string
            {
                return $this->hookPrefix;
            }

            /**
             * 테스트용 알림 유형을 반환합니다.
             *
             * @return string 알림 유형
             */
            protected function getNotificationType(): string
            {
                return $this->notificationType;
            }

            /**
             * 테스트용 빈 메일 처리입니다.
             *
             * @param  object  $notifiable  알림 수신자
             * @return void 렌더된 메일 메시지
             */
            public function toMail(object $notifiable): void
            {
                // 테스트용 빈 구현
            }
        };
    }
}
