<?php

namespace Tests\Unit\Notifications;

use App\Contracts\Notifications\ChannelReadinessCheckerInterface;
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

        // 기본: 모든 채널을 readiness OK로 mocking. mail readiness 는 실제 settings 디스크의
        // mail.json(from_address/SMTP 등)에 의존하는데, TestCase 전역 settings fake 로 비어 있어
        // 실 환경에서는 항상 not-ready 로 떨어진다. via() 채널 결정 로직만 결정적으로 검증하기 위해
        // readiness 게이트를 mock 으로 격리한다. readiness 자체는 ChannelReadinessServiceTest 가 검증.
        $this->mockReadiness([]);
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
     * getAvailableChannels() 는 기본적으로 mail/database 만 반환하도록 mock 한다
     * (비활성 확장 채널 제외 회귀 테스트가 available 목록을 재정의할 수 있도록).
     *
     * @param  array<string, bool>  $channelMap
     * @param  array<int, string>|null  $availableIds  available 채널 id 목록(미지정 시 mail/database)
     */
    private function mockChannelEnabled(array $channelMap, ?array $availableIds = null): void
    {
        $availableIds ??= ['mail', 'database'];

        $mock = Mockery::mock(NotificationChannelService::class);
        $mock->shouldReceive('isChannelEnabledForExtension')
            ->andReturnUsing(function ($type, $identifier, $channel) use ($channelMap) {
                return $channelMap[$channel] ?? true;
            });
        $mock->shouldReceive('getAvailableChannels')
            ->andReturn(array_map(static fn (string $id) => ['id' => $id], $availableIds));
        $mock->shouldReceive('isChannelAvailable')
            ->andReturnUsing(fn (string $channel) => in_array($channel, $availableIds, true));
        $this->app->instance(NotificationChannelService::class, $mock);
    }

    /**
     * ChannelReadinessCheckerInterface::isReady()/check() 응답을 mocking.
     *
     * 미지정 채널은 ready=true 기본. 개별 테스트에서 mockReadiness(['mail' => false]) 로
     * 특정 채널을 not-ready 로 재정의할 수 있다.
     *
     * @param  array<string, bool>  $readyMap  채널별 readiness 맵
     */
    private function mockReadiness(array $readyMap): void
    {
        $mock = Mockery::mock(ChannelReadinessCheckerInterface::class);
        $mock->shouldReceive('isReady')
            ->andReturnUsing(fn ($channel) => $readyMap[$channel] ?? true);
        $mock->shouldReceive('check')
            ->andReturnUsing(fn ($channel) => [
                'ready' => $readyMap[$channel] ?? true,
                'reason' => ($readyMap[$channel] ?? true) ? null : 'notification.readiness.mail_from_address_not_configured',
            ]);
        $this->app->instance(ChannelReadinessCheckerInterface::class, $mock);
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
     * readiness 실패 채널은 제외 — 템플릿·확장 토글이 OK여도 미설정 mail 은 발송 대상에서 빠진다.
     */
    public function test_via_excludes_channel_that_is_not_ready(): void
    {
        $this->createTemplate('mail');
        $this->createTemplate('database');

        $this->mockReadiness(['mail' => false, 'database' => true]);

        $notification = new GenericNotification('test_via_check', 'core.test');
        $channels = $notification->via($this->user);

        $this->assertNotContains('mail', $channels);
        $this->assertContains('database', $channels);
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
     * 사용 가능한 채널 목록에 없는 채널(비활성 확장이 제공하던 채널)은 via() 가 제외한다.
     *
     * 회귀: 메시징 확장이 비활성이면 그 확장의 채널(sms/alimtalk)이 getAvailableChannels()
     * 에서 빠진다. 하지만 저장된 채널 설정·템플릿은 남아 있어, via() 가 available 목록을 확인하지
     * 않으면 죽은 채널을 발송 후보로 넣고 "건너뜀" 로그까지 남긴다. available 목록에 없는 채널은
     * 로그 없이 제외되어야 한다 (채널 지정 경로).
     */
    public function test_via_with_explicit_channel_excludes_channel_not_in_available_list(): void
    {
        // available = mail/database 만 (sms 는 비활성 확장이라 목록에 없음)
        $this->mockChannelEnabled(['sms' => true], availableIds: ['mail', 'database']);

        $notification = new GenericNotification(
            type: 'test_via_check',
            hookPrefix: 'core.test',
            data: [],
            extensionType: 'core',
            extensionIdentifier: 'core',
            channel: 'sms'
        );
        $channels = $notification->via($this->user);

        $this->assertEmpty($channels, '사용 가능 목록에 없는 sms 는 발송 후보에서 빠져야 한다.');
        // 로그도 남지 않아야 한다 (건너뜀 기록 X)
        $this->assertDatabaseMissing('notification_logs', ['channel' => 'sms']);
    }

    /**
     * 다채널 자동 결정 경로에서도 available 목록에 없는 채널은 로그 없이 제외된다.
     */
    public function test_via_multichannel_excludes_channel_not_in_available_list(): void
    {
        $this->createTemplate('mail');
        $this->createTemplate('database');

        // 정의 채널에 sms 를 포함시키되 available 목록에는 없게 한다
        $this->definition->update(['channels' => ['mail', 'database', 'sms']]);
        $this->mockChannelEnabled([], availableIds: ['mail', 'database']);

        $notification = new GenericNotification('test_via_check', 'core.test');
        $channels = $notification->via($this->user);

        $this->assertNotContains('sms', $channels, '사용 가능 목록에 없는 sms 는 제외되어야 한다.');
        $this->assertContains('mail', $channels);
        $this->assertContains('database', $channels);
        $this->assertDatabaseMissing('notification_logs', ['channel' => 'sms']);
    }

    /**
     * 테스트용 템플릿 생성 헬퍼
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
