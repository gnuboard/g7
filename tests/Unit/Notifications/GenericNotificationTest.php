<?php

namespace Tests\Unit\Notifications;

use App\Contracts\Notifications\ChannelReadinessCheckerInterface;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Services\NotificationDefinitionService;
use App\Services\NotificationTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Tests\TestCase;

/**
 * GenericNotification 테스트
 *
 * DB 기반 범용 알림 클래스의 via(), toMail(), toArray() 동작을 검증합니다.
 */
class GenericNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // readiness mock: 모든 채널 ready
        $this->app->singleton(ChannelReadinessCheckerInterface::class, function () {
            return new class implements ChannelReadinessCheckerInterface {
                public function isReady(string $channelId): bool
                {
                    return true;
                }

                public function check(string $channelId): array
                {
                    return ['ready' => true, 'reason' => null];
                }

                public function checkAll(array $channelIds): array
                {
                    return array_fill_keys($channelIds, ['ready' => true, 'reason' => null]);
                }
            };
        });
    }

    /**
     * Notification을 상속하는지 확인
     */
    public function test_extends_laravel_notification(): void
    {
        $notification = new GenericNotification('welcome', 'core.auth', ['name' => 'Test']);

        $this->assertInstanceOf(Notification::class, $notification);
    }

    /**
     * getType()이 올바른 타입을 반환하는지 확인
     */
    public function test_get_type_returns_correct_type(): void
    {
        $notification = new GenericNotification('welcome', 'core.auth');

        $this->assertEquals('welcome', $notification->getType());
    }

    /**
     * getData()가 전달된 데이터를 반환하는지 확인
     */
    public function test_get_data_returns_provided_data(): void
    {
        $data = ['name' => 'Test', 'app_name' => 'G7'];
        $notification = new GenericNotification('welcome', 'core.auth', $data);

        $this->assertEquals($data, $notification->getData());
    }

    /**
     * via()가 notification_definitions 테이블에서 채널을 조회하는지 확인
     */
    public function test_via_reads_channels_from_definition(): void
    {
        NotificationDefinition::create([
            'type' => 'test_notification',
            'hook_prefix' => 'core.test',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'variables' => [],
            'channels' => ['mail', 'database'],
            'hooks' => ['core.test.after_action'],
            'is_active' => true,
            'is_default' => true,
        ]);

        // 캐시를 무효화하여 최신 데이터 반영
        app(NotificationDefinitionService::class)->invalidateCache('test_notification');

        $notification = new GenericNotification('test_notification', 'core.test');
        $user = new User(['email' => 'test@example.com']);

        $channels = $notification->via($user);

        $this->assertEquals(['mail', 'database'], $channels);
    }

    /**
     * 정의가 없을 때 via()가 기본 ['mail'] 채널을 반환하는지 확인
     */
    public function test_via_returns_mail_default_when_no_definition(): void
    {
        $notification = new GenericNotification('nonexistent_type', 'core.auth');
        $user = new User(['email' => 'test@example.com']);

        $channels = $notification->via($user);

        $this->assertEquals(['mail'], $channels);
    }

    /**
     * toArray()가 database 채널 템플릿을 사용하는지 확인
     */
    public function test_to_array_uses_database_channel_template(): void
    {
        $definition = NotificationDefinition::create([
            'type' => 'test_db',
            'hook_prefix' => 'core.test',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'variables' => [],
            'channels' => ['database'],
            'hooks' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => 'database',
            'subject' => ['ko' => '{name}님 알림', 'en' => 'Notification for {name}'],
            'body' => ['ko' => '{app_name}에서 알림입니다', 'en' => 'Notification from {app_name}'],
            'is_active' => true,
            'is_default' => true,
        ]);

        app(NotificationTemplateService::class)->invalidateCache('test_db', 'database');

        $notification = new GenericNotification('test_db', 'core.test', [
            'name' => '홍길동',
            'app_name' => 'G7',
        ]);

        $user = new User(['email' => 'test@example.com', 'name' => '홍길동']);
        $user->forceFill(['locale' => 'ko']);

        $result = $notification->toArray($user);

        $this->assertEquals('test_db', $result['type']);
        $this->assertEquals('홍길동님 알림', $result['subject']);
        $this->assertEquals('G7에서 알림입니다', $result['body']);
    }

    /**
     * toArray()가 템플릿 없을 때 기본 데이터를 반환하는지 확인
     */
    public function test_to_array_returns_basic_data_when_no_template(): void
    {
        $data = ['name' => 'Test'];
        $notification = new GenericNotification('no_template', 'core.test', $data);

        $user = new User(['email' => 'test@example.com']);

        $result = $notification->toArray($user);

        $this->assertEquals('no_template', $result['type']);
        $this->assertEquals($data, $result['data']);
    }
}
