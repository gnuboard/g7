<?php

namespace Tests\Unit\Notifications;

use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Services\NotificationChannelService;
use App\Services\NotificationTemplateService;
use Mockery;
use Tests\TestCase;

/**
 * GenericNotification::via() 채널 결정 테스트
 *
 * 채널 활성화 + readiness + 템플릿 존재 검증을 단계별로 확인합니다.
 * 핵심 회귀: 활성 템플릿이 없는 채널은 제외되어야 함 (빈 subject/body 방지).
 */
class GenericNotificationViaTest extends TestCase
{
    private User $user;

    private NotificationDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();

        // 이전 테스트의 캐시된 템플릿 결과가 간섭하지 않도록 무효화
        $templateService = app(NotificationTemplateService::class);
        $templateService->invalidateCache('test_via_check', 'mail');
        $templateService->invalidateCache('test_via_check', 'database');
        $templateService->invalidateCache('nonexistent_type_xyz', 'mail');

        $this->user = User::factory()->create();

        // 테스트용 알림 정의 — mail + database 채널 활성화
        $this->definition = NotificationDefinition::updateOrCreate(
            ['type' => 'test_via_check'],
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

        // 기본: 모든 채널을 활성으로 mocking (공유 storage/app/settings/notifications.json의
        // 실제 값과 격리). 개별 테스트에서 mockChannelEnabled(['xxx' => false])로 재정의 가능.
        $this->mockChannelEnabled([]);
    }

    protected function tearDown(): void
    {
        // 캐시 무효화 후 데이터 정리
        $templateService = app(NotificationTemplateService::class);
        $templateService->invalidateCache('test_via_check', 'mail');
        $templateService->invalidateCache('test_via_check', 'database');
        $templateService->invalidateCache('nonexistent_type_xyz', 'mail');

        NotificationTemplate::where('definition_id', $this->definition->id)->delete();
        $this->definition->delete();

        Mockery::close();
        parent::tearDown();
    }

    /**
     * 확장 설정에서 mail 채널이 OFF면 via()가 mail을 제외한다 (database만 반환).
     */
    public function test_via_excludes_channel_disabled_by_extension_toggle(): void
    {
        $this->createTemplate('mail');
        $this->createTemplate('database');

        $this->mockChannelEnabled([
            'mail' => false,
            'database' => true,
        ]);

        $notification = new GenericNotification('test_via_check', 'core.test');
        $channels = $notification->via($this->user);

        $this->assertNotContains('mail', $channels);
        $this->assertContains('database', $channels);
    }

    /**
     * 확장 설정에서 모든 채널이 OFF면 빈 배열 반환.
     */
    public function test_via_returns_empty_when_all_channels_disabled_by_extension(): void
    {
        $this->createTemplate('mail');
        $this->createTemplate('database');

        $this->mockChannelEnabled([
            'mail' => false,
            'database' => false,
        ]);

        $notification = new GenericNotification('test_via_check', 'core.test');
        $channels = $notification->via($this->user);

        $this->assertEmpty($channels);
    }

    /**
     * $channel 직접 지정 발송 경로에도 확장 토글이 적용된다.
     */
    public function test_via_with_explicit_channel_honors_extension_toggle(): void
    {
        $this->createTemplate('mail');

        $this->mockChannelEnabled(['mail' => false]);

        $notification = new GenericNotification(
            type: 'test_via_check',
            hookPrefix: 'core.test',
            data: [],
            extensionType: 'core',
            extensionIdentifier: 'core',
            channel: 'mail'
        );
        $channels = $notification->via($this->user);

        $this->assertEmpty($channels);
    }

    /**
     * $channel 직접 지정 + 확장 토글 ON → 해당 채널이 그대로 반환.
     */
    public function test_via_with_explicit_channel_returns_when_enabled(): void
    {
        $this->createTemplate('mail');

        $this->mockChannelEnabled(['mail' => true]);

        $notification = new GenericNotification(
            type: 'test_via_check',
            hookPrefix: 'core.test',
            data: [],
            extensionType: 'core',
            extensionIdentifier: 'core',
            channel: 'mail'
        );
        $channels = $notification->via($this->user);

        $this->assertSame(['mail'], $channels);
    }

    /**
     * NotificationChannelService::isChannelEnabledForExtension()의 응답을 mocking.
     *
     * @param array<string, bool> $channelMap
     */
    private function mockChannelEnabled(array $channelMap): void
    {
        $mock = Mockery::mock(NotificationChannelService::class);
        $mock->shouldReceive('isChannelEnabledForExtension')
            ->andReturnUsing(function ($type, $identifier, $channel) use ($channelMap) {
                return $channelMap[$channel] ?? true;
            });
        $this->app->instance(NotificationChannelService::class, $mock);
    }

    /**
     * 양쪽 채널 모두 활성 템플릿이 있으면 두 채널 모두 반환
     */
    public function test_via_returns_both_channels_when_both_have_templates(): void
    {
        $this->createTemplate('mail');
        $this->createTemplate('database');

        $notification = new GenericNotification('test_via_check', 'core.test');
        $channels = $notification->via($this->user);

        $this->assertContains('mail', $channels);
        $this->assertContains('database', $channels);
        $this->assertCount(2, $channels);
    }

    /**
     * mail 템플릿만 있으면 mail만 반환, database 제외
     */
    public function test_via_excludes_channel_without_template(): void
    {
        $this->createTemplate('mail');
        // database 템플릿 없음

        $notification = new GenericNotification('test_via_check', 'core.test');
        $channels = $notification->via($this->user);

        $this->assertContains('mail', $channels);
        $this->assertNotContains('database', $channels);
    }

    /**
     * database 템플릿만 있으면 database만 반환 (mail은 readiness도 영향)
     */
    public function test_via_returns_only_database_when_mail_has_no_template(): void
    {
        // mail 템플릿 없음
        $this->createTemplate('database');

        $notification = new GenericNotification('test_via_check', 'core.test');
        $channels = $notification->via($this->user);

        // mail은 템플릿 없음 또는 readiness 실패로 제외
        $this->assertNotContains('mail', $channels);
        $this->assertContains('database', $channels);
    }

    /**
     * 비활성 템플릿은 없는 것으로 취급 — 채널 제외
     */
    public function test_via_excludes_channel_with_inactive_template(): void
    {
        $this->createTemplate('mail');
        $this->createTemplate('database', isActive: false);

        $notification = new GenericNotification('test_via_check', 'core.test');
        $channels = $notification->via($this->user);

        $this->assertContains('mail', $channels);
        $this->assertNotContains('database', $channels);
    }

    /**
     * 모든 채널에 템플릿이 없으면 빈 배열 반환
     */
    public function test_via_returns_empty_when_no_templates(): void
    {
        // 템플릿 없음

        $notification = new GenericNotification('test_via_check', 'core.test');
        $channels = $notification->via($this->user);

        $this->assertEmpty($channels);
    }

    /**
     * 알림 정의가 없는 type은 기본 mail 채널이지만 템플릿도 없으므로 빈 배열
     */
    public function test_via_unknown_type_returns_empty(): void
    {
        $notification = new GenericNotification('nonexistent_type_xyz', 'core.test');
        $channels = $notification->via($this->user);

        $this->assertEmpty($channels);
    }

    /**
     * 테스트용 템플릿 생성 헬퍼
     *
     * @param string $channel
     * @param bool $isActive
     * @return NotificationTemplate
     */
    private function createTemplate(string $channel, bool $isActive = true): NotificationTemplate
    {
        return NotificationTemplate::updateOrCreate(
            ['definition_id' => $this->definition->id, 'channel' => $channel],
            [
                'subject' => ['ko' => '테스트 제목', 'en' => 'Test Subject'],
                'body' => ['ko' => '테스트 본문', 'en' => 'Test Body'],
                'is_active' => $isActive,
                'is_default' => false,
            ]
        );
    }
}
