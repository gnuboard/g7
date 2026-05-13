<?php

namespace Tests\Unit\Listeners;

use App\Extension\HookManager;
use App\Listeners\CoreNotificationDataListener;
use App\Listeners\NotificationHookListener;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationDefinitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * 알림 발송 통합 테스트
 *
 * NotificationHookListener + NotificationRecipientResolver + CoreNotificationDataListener
 * 전체 파이프라인을 검증합니다.
 * 다양한 수신 대상 조건(trigger_user, role, specific_users, 복합, exclude 등)으로 테스트합니다.
 */
class NotificationDispatchIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private NotificationHookListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->listener = app(NotificationHookListener::class);
    }

    /**
     * 알림 정의와 템플릿을 생성하는 헬퍼
     */
    private function createDefinitionWithTemplate(string $type, string $hook, array $recipients, string $hookPrefix = 'core.test'): NotificationDefinition
    {
        $definition = NotificationDefinition::create([
            'type' => $type,
            'hook_prefix' => $hookPrefix,
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => $type],
            'variables' => [],
            'channels' => ['database'],
            'hooks' => [$hook],
            'is_active' => true,
            'is_default' => true,
        ]);

        NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => 'database',
            'subject' => ['ko' => '테스트 알림'],
            'body' => ['ko' => '테스트 본문'],
            'recipients' => $recipients,
            'is_active' => true,
            'is_default' => true,
        ]);

        return $definition;
    }

    /**
     * 동적 훅 등록 + 훅 발화 헬퍼
     */
    private function registerAndFire(string $hook, ...$args): void
    {
        app(NotificationDefinitionService::class)->invalidateAllCache();
        $this->listener->registerDynamicHooks();
        HookManager::doAction($hook, ...$args);
    }

    // ──────────────────────────────────────────────
    // 1. trigger_user: 이벤트 유발자에게 발송
    // ──────────────────────────────────────────────

    public function test_trigger_user_sends_to_event_user(): void
    {
        $user = User::factory()->create();

        $this->createDefinitionWithTemplate(
            'test_trigger',
            'core.test.after_trigger',
            [['type' => 'trigger_user']]
        );

        // extract_data 필터 등록 (trigger_user 컨텍스트 제공)
        HookManager::addFilter('core.test.notification.extract_data', function ($default, $type, $args) {
            $user = $args[0] ?? null;

            return [
                'notifiable' => null,
                'notifiables' => null,
                'data' => ['name' => $user->name ?? ''],
                'context' => [
                    'trigger_user_id' => $user?->id,
                    'trigger_user' => $user,
                ],
            ];
        }, priority: 20);

        $this->registerAndFire('core.test.after_trigger', $user);

        Notification::assertSentTo($user, \App\Notifications\GenericNotification::class);
    }

    // ──────────────────────────────────────────────
    // 2. role: 특정 역할의 모든 사용자에게 발송
    // ──────────────────────────────────────────────

    public function test_role_sends_to_all_role_users(): void
    {
        $role = Role::factory()->create(['identifier' => 'test_role_dispatch']);
        $admin1 = User::factory()->create();
        $admin2 = User::factory()->create();
        $role->users()->attach([$admin1->id, $admin2->id]);

        $this->createDefinitionWithTemplate(
            'test_role',
            'core.test.after_role',
            [['type' => 'role', 'value' => 'test_role_dispatch']]
        );

        HookManager::addFilter('core.test.notification.extract_data', function ($default, $type, $args) {
            return [
                'notifiable' => null,
                'notifiables' => null,
                'data' => ['name' => '{recipient_name}'],
                'context' => [],
            ];
        }, priority: 20);

        $this->registerAndFire('core.test.after_role', null);

        Notification::assertSentTo($admin1, \App\Notifications\GenericNotification::class);
        Notification::assertSentTo($admin2, \App\Notifications\GenericNotification::class);
    }

    // ──────────────────────────────────────────────
    // 3. specific_users: UUID로 지정된 사용자에게 발송
    // ──────────────────────────────────────────────

    public function test_specific_users_sends_to_designated_users(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $other = User::factory()->create();

        $this->createDefinitionWithTemplate(
            'test_specific',
            'core.test.after_specific',
            [['type' => 'specific_users', 'value' => [$user1->uuid, $user2->uuid]]]
        );

        HookManager::addFilter('core.test.notification.extract_data', function ($default) {
            return array_merge($default, ['data' => ['name' => 'test']]);
        }, priority: 20);

        $this->registerAndFire('core.test.after_specific', null);

        Notification::assertSentTo($user1, \App\Notifications\GenericNotification::class);
        Notification::assertSentTo($user2, \App\Notifications\GenericNotification::class);
        Notification::assertNotSentTo($other, \App\Notifications\GenericNotification::class);
    }

    // ──────────────────────────────────────────────
    // 4. role + exclude_trigger_user: 역할 사용자 중 유발자 제외
    // ──────────────────────────────────────────────

    public function test_role_excludes_trigger_user(): void
    {
        $triggerUser = User::factory()->create();
        $otherAdmin = User::factory()->create();
        $role = Role::factory()->create(['identifier' => 'test_role_excl']);
        $role->users()->attach([$triggerUser->id, $otherAdmin->id]);

        $this->createDefinitionWithTemplate(
            'test_excl',
            'core.test.after_excl',
            [['type' => 'role', 'value' => 'test_role_excl', 'exclude_trigger_user' => true]]
        );

        HookManager::addFilter('core.test.notification.extract_data', function ($default, $type, $args) {
            $user = $args[0] ?? null;

            return [
                'notifiable' => null,
                'notifiables' => null,
                'data' => ['name' => '{recipient_name}'],
                'context' => [
                    'trigger_user_id' => $user?->id,
                ],
            ];
        }, priority: 20);

        $this->registerAndFire('core.test.after_excl', $triggerUser);

        Notification::assertSentTo($otherAdmin, \App\Notifications\GenericNotification::class);
        Notification::assertNotSentTo($triggerUser, \App\Notifications\GenericNotification::class);
    }

    // ──────────────────────────────────────────────
    // 5. 복합: trigger_user + role (중복 제거)
    // ──────────────────────────────────────────────

    public function test_multiple_rules_combine_and_deduplicate(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create(['identifier' => 'test_role_multi']);
        $role->users()->attach([$user->id]);

        $this->createDefinitionWithTemplate(
            'test_multi',
            'core.test.after_multi',
            [
                ['type' => 'trigger_user'],
                ['type' => 'role', 'value' => 'test_role_multi'],
            ]
        );

        HookManager::addFilter('core.test.notification.extract_data', function ($default, $type, $args) {
            $user = $args[0] ?? null;

            return [
                'notifiable' => null,
                'notifiables' => null,
                'data' => ['name' => $user->name ?? ''],
                'context' => [
                    'trigger_user_id' => $user?->id,
                    'trigger_user' => $user,
                ],
            ];
        }, priority: 20);

        $this->registerAndFire('core.test.after_multi', $user);

        // 동일 사용자이므로 1건만 발송
        Notification::assertSentTo($user, \App\Notifications\GenericNotification::class, function ($notification) {
            return true;
        });
        Notification::assertCount(1);
    }

    // ──────────────────────────────────────────────
    // 6. extract_data 필터 미등록 시 recipients만으로 발송
    // ──────────────────────────────────────────────

    public function test_sends_without_extract_data_filter(): void
    {
        $role = Role::factory()->create(['identifier' => 'test_no_filter']);
        $admin = User::factory()->create();
        $role->users()->attach([$admin->id]);

        $this->createDefinitionWithTemplate(
            'test_no_filter',
            'core.test.after_no_filter',
            [['type' => 'role', 'value' => 'test_no_filter']],
            'core.test_nofilter'
        );

        // extract_data 필터를 등록하지 않음
        $this->registerAndFire('core.test.after_no_filter', null);

        // 수신자는 결정되지만 data가 빈 상태로 발송
        Notification::assertSentTo($admin, \App\Notifications\GenericNotification::class);
    }

    // ──────────────────────────────────────────────
    // 7. 비활성 정의는 발송하지 않음
    // ──────────────────────────────────────────────

    public function test_inactive_definition_does_not_send(): void
    {
        $user = User::factory()->create();

        $definition = NotificationDefinition::create([
            'type' => 'test_inactive',
            'hook_prefix' => 'core.test',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '비활성'],
            'variables' => [],
            'channels' => ['database'],
            'hooks' => ['core.test.after_inactive'],
            'recipients' => [['type' => 'trigger_user']],
            'is_active' => false,
            'is_default' => true,
        ]);

        NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => 'database',
            'subject' => ['ko' => '테스트'],
            'body' => ['ko' => '테스트'],
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->registerAndFire('core.test.after_inactive', $user);

        Notification::assertNothingSent();
    }

    // ──────────────────────────────────────────────
    // 8. recipients 미설정 시 레거시 notifiable 사용
    // ──────────────────────────────────────────────

    public function test_legacy_notifiable_fallback_when_no_recipients(): void
    {
        $user = User::factory()->create();

        $this->createDefinitionWithTemplate(
            'test_legacy',
            'core.test.after_legacy',
            [] // recipients 미설정
        );

        HookManager::addFilter('core.test.notification.extract_data', function ($default, $type, $args) {
            $user = $args[0] ?? null;

            return [
                'notifiable' => $user,
                'notifiables' => null,
                'data' => ['name' => $user->name ?? ''],
                'context' => [],
            ];
        }, priority: 20);

        $this->registerAndFire('core.test.after_legacy', $user);

        Notification::assertSentTo($user, \App\Notifications\GenericNotification::class);
    }

    // ──────────────────────────────────────────────
    // 9. 코어 CoreNotificationDataListener — welcome
    // ──────────────────────────────────────────────

    public function test_core_welcome_extract_data(): void
    {
        $user = User::factory()->create();
        $listener = new CoreNotificationDataListener();

        $result = $listener->extractData(
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            'welcome',
            [$user]
        );

        $this->assertEquals($user->name, $result['data']['name']);
        $this->assertEquals(config('app.name'), $result['data']['app_name']);
        $this->assertEquals($user->id, $result['context']['trigger_user_id']);
        $this->assertSame($user, $result['context']['trigger_user']);
    }

    // ──────────────────────────────────────────────
    // 10. 코어 CoreNotificationDataListener — reset_password
    // ──────────────────────────────────────────────

    public function test_core_reset_password_extract_data(): void
    {
        $user = User::factory()->create();
        $listener = new CoreNotificationDataListener();

        $result = $listener->extractData(
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            'reset_password',
            [$user, ['reset_url' => 'https://example.com/reset?token=abc']]
        );

        $this->assertEquals('https://example.com/reset?token=abc', $result['data']['action_url']);
        $this->assertEquals($user->id, $result['context']['trigger_user_id']);
    }

    // ──────────────────────────────────────────────
    // 11. 코어 CoreNotificationDataListener — password_changed
    // ──────────────────────────────────────────────

    public function test_core_password_changed_extract_data(): void
    {
        $user = User::factory()->create();
        $listener = new CoreNotificationDataListener();

        $result = $listener->extractData(
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            'password_changed',
            [$user]
        );

        $this->assertEquals($user->name, $result['data']['name']);
        $this->assertEquals($user->id, $result['context']['trigger_user_id']);
        $this->assertStringContainsString('/login', $result['data']['action_url']);
    }

    // ──────────────────────────────────────────────
    // 12. 코어 CoreNotificationDataListener — 미지원 타입은 기본값 반환
    // ──────────────────────────────────────────────

    public function test_core_unknown_type_returns_default(): void
    {
        $listener = new CoreNotificationDataListener();
        $default = ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];

        $result = $listener->extractData($default, 'unknown_type', []);

        $this->assertSame($default, $result);
    }

    // ──────────────────────────────────────────────
    // 13. role 폴백: 역할 사용자 없으면 superAdmin에게 발송
    // ──────────────────────────────────────────────

    public function test_role_falls_back_to_super_admin(): void
    {
        Role::factory()->create(['identifier' => 'empty_role_dispatch']);
        $superAdmin = User::factory()->create(['is_super' => true]);

        $this->createDefinitionWithTemplate(
            'test_fallback',
            'core.test.after_fallback',
            [['type' => 'role', 'value' => 'empty_role_dispatch']]
        );

        HookManager::addFilter('core.test.notification.extract_data', function ($default) {
            return array_merge($default, ['data' => ['name' => 'test']]);
        }, priority: 20);

        $this->registerAndFire('core.test.after_fallback', null);

        Notification::assertSentTo($superAdmin, \App\Notifications\GenericNotification::class);
    }
}
