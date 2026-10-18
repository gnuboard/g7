<?php

namespace Tests\Unit\Listeners;

use App\Enums\NotificationLogStatus;
use App\Extension\HookManager;
use App\Listeners\CoreNotificationDataListener;
use App\Listeners\NotificationHookListener;
use App\Models\NotificationDefinition;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Notifications\GuestNotifiable;
use App\Services\NotificationDefinitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Helpers\MocksChannelReadiness;
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
    use MocksChannelReadiness;
    use RefreshDatabase;

    private NotificationHookListener $listener;

    /**
     * 메일 채널을 준비 완료 상태로 고정하고 알림을 가짜로 바꾼 뒤 리스너를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        // 메일 발송을 단언하므로 mail 채널을 준비 완료 상태로 만든다
        // (기본 테스트 설정은 from_address 가 플레이스홀더라 readiness 가 false 다)
        $this->enableMailChannelReadiness();
        Notification::fake();

        // 수신자 해석·발송 경로가 관심사다 — mail 채널은 SMTP 설정이 비면 not-ready 라
        // 그 축을 고정하지 않으면 mail 정의를 쓰는 케이스만 환경에 따라 갈린다.
        $this->mockAllChannelsReady();
        $this->listener = app(NotificationHookListener::class);
    }

    /**
     * 알림 정의와 템플릿을 생성하는 헬퍼
     *
     * @param  string  $type  알림 타입
     * @param  string  $hook  발화 훅 이름
     * @param  array<int, array<string, mixed>>  $recipients  수신자 규칙 목록
     * @param  string  $hookPrefix  훅 접두사
     * @return NotificationDefinition 생성된 알림 정의
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
     *
     * @param  string  $hook  발화할 훅 이름
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
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

    /**
     * trigger_user 규칙이 이벤트를 일으킨 회원에게 알림을 보내는지 검증합니다.
     *
     * @effects trigger_user_sends_to_event_user
     *
     * @return void 반환값 없음
     */
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

        Notification::assertSentTo($user, GenericNotification::class);
    }

    // ──────────────────────────────────────────────
    // 2. role: 특정 역할의 모든 사용자에게 발송
    // ──────────────────────────────────────────────

    /**
     * role 규칙이 해당 역할을 가진 모든 회원에게 알림을 보내는지 검증합니다.
     *
     * @effects role_sends_to_all_role_users
     *
     * @return void 반환값 없음
     */
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

        Notification::assertSentTo($admin1, GenericNotification::class);
        Notification::assertSentTo($admin2, GenericNotification::class);
    }

    // ──────────────────────────────────────────────
    // 3. specific_users: UUID로 지정된 사용자에게 발송
    // ──────────────────────────────────────────────

    /**
     * specific_users 규칙이 지정한 회원에게만 알림을 보내는지 검증합니다.
     *
     * @effects specific_users_sends_to_designated_users
     *
     * @return void 반환값 없음
     */
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

        Notification::assertSentTo($user1, GenericNotification::class);
        Notification::assertSentTo($user2, GenericNotification::class);
        Notification::assertNotSentTo($other, GenericNotification::class);
    }

    // ──────────────────────────────────────────────
    // 4. role + exclude_trigger_user: 역할 사용자 중 유발자 제외
    // ──────────────────────────────────────────────

    /**
     * role 규칙에 유발자 제외가 켜져 있으면 이벤트 유발자를 빼고 보내는지 검증합니다.
     *
     * @effects role_excludes_trigger_user
     *
     * @return void 반환값 없음
     */
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

        Notification::assertSentTo($otherAdmin, GenericNotification::class);
        Notification::assertNotSentTo($triggerUser, GenericNotification::class);
    }

    // ──────────────────────────────────────────────
    // 5. 복합: trigger_user + role (중복 제거)
    // ──────────────────────────────────────────────

    /**
     * 여러 규칙의 수신자를 합치고 같은 회원은 한 번만 받는지 검증합니다.
     *
     * @effects multiple_rules_combine_and_deduplicate
     *
     * @return void 반환값 없음
     */
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
        Notification::assertSentTo($user, GenericNotification::class, function ($notification) {
            return true;
        });
        Notification::assertCount(1);
    }

    // ──────────────────────────────────────────────
    // 6. extract_data 필터 미등록 시 recipients만으로 발송
    // ──────────────────────────────────────────────

    /**
     * 데이터 추출 필터가 없어도 알림이 발송되는지 검증합니다.
     *
     * @effects sends_without_extract_data_filter
     *
     * @return void 반환값 없음
     */
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
        Notification::assertSentTo($admin, GenericNotification::class);
    }

    // ──────────────────────────────────────────────
    // 7. 비활성 정의는 발송하지 않음
    // ──────────────────────────────────────────────

    /**
     * 비활성 알림 정의는 발송하지 않는지 검증합니다.
     *
     * @effects inactive_definition_does_not_send
     *
     * @return void 반환값 없음
     */
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

    /**
     * 수신자 규칙이 없을 때 종전 notifiable 인자로 폴백하는지 검증합니다.
     *
     * @effects legacy_notifiable_fallback_when_no_recipients
     *
     * @return void 반환값 없음
     */
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

        Notification::assertSentTo($user, GenericNotification::class);
    }

    // ──────────────────────────────────────────────
    // 9. 코어 CoreNotificationDataListener — welcome
    // ──────────────────────────────────────────────

    /**
     * 코어 가입 환영 알림의 데이터 추출 결과를 검증합니다.
     *
     * @effects core_welcome_extract_data
     *
     * @return void 반환값 없음
     */
    public function test_core_welcome_extract_data(): void
    {
        $user = User::factory()->create();
        $listener = new CoreNotificationDataListener;

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

    /**
     * 코어 비밀번호 재설정 알림의 데이터 추출 결과를 검증합니다.
     *
     * @effects core_reset_password_extract_data
     *
     * @return void 반환값 없음
     */
    public function test_core_reset_password_extract_data(): void
    {
        $user = User::factory()->create();
        $listener = new CoreNotificationDataListener;

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

    /**
     * 코어 비밀번호 변경 알림의 데이터 추출 결과를 검증합니다.
     *
     * @effects core_password_changed_extract_data
     *
     * @return void 반환값 없음
     */
    public function test_core_password_changed_extract_data(): void
    {
        $user = User::factory()->create();
        $listener = new CoreNotificationDataListener;

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

    /**
     * 모르는 알림 타입은 기본 데이터를 그대로 돌려주는지 검증합니다.
     *
     * @effects core_unknown_type_returns_default
     *
     * @return void 반환값 없음
     */
    public function test_core_unknown_type_returns_default(): void
    {
        $listener = new CoreNotificationDataListener;
        $default = ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];

        $result = $listener->extractData($default, 'unknown_type', []);

        $this->assertSame($default, $result);
    }

    // ──────────────────────────────────────────────
    // 13. role 폴백: 역할 사용자 없으면 superAdmin에게 발송
    // ──────────────────────────────────────────────

    /**
     * 역할에 회원이 없으면 최고관리자에게 폴백하는지 검증합니다.
     *
     * @effects role_falls_back_to_super_admin
     *
     * @return void 반환값 없음
     */
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

        Notification::assertSentTo($superAdmin, GenericNotification::class);
    }

    // ──────────────────────────────────────────────
    // 9. 비회원(guest) 발송: trigger_user 폴백 → GuestNotifiable
    // ──────────────────────────────────────────────

    /**
     * mail 채널 템플릿을 가진 알림 정의를 생성하는 헬퍼.
     *
     * @param  string  $type  알림 타입
     * @param  string  $hook  발화 훅 이름
     * @param  array<int, array<string, mixed>>  $recipients  수신자 규칙 목록
     * @return NotificationDefinition 생성된 알림 정의
     */
    private function createMailDefinition(string $type, string $hook, array $recipients): NotificationDefinition
    {
        $definition = NotificationDefinition::create([
            'type' => $type,
            'hook_prefix' => 'core.test',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => $type],
            'variables' => [],
            'channels' => ['mail', 'database'],
            'hooks' => [$hook],
            'is_active' => true,
            'is_default' => true,
        ]);

        foreach (['mail', 'database'] as $ch) {
            NotificationTemplate::create([
                'definition_id' => $definition->id,
                'channel' => $ch,
                'subject' => ['ko' => '제목', 'en' => 'Subject'],
                'body' => ['ko' => '본문', 'en' => 'Body'],
                'recipients' => $recipients,
                'is_active' => true,
                'is_default' => true,
            ]);
        }

        return $definition;
    }

    /**
     * 비회원 수신자가 메일 알림을 받는지 검증합니다.
     *
     * @effects guest_recipient_receives_mail_notification
     *
     * @return void 반환값 없음
     */
    public function test_guest_recipient_receives_mail_notification(): void
    {
        $this->createMailDefinition(
            'test_guest_send',
            'core.test.after_guest',
            [['type' => 'trigger_user']]
        );

        // 비회원 컨텍스트: trigger_user_id 없음 + guest_recipient 표준 키
        HookManager::addFilter('core.test.notification.extract_data', function ($default) {
            return [
                'notifiable' => null,
                'notifiables' => null,
                'data' => ['name' => '비회원주문자'],
                'context' => [
                    'trigger_user_id' => null,
                    'guest_recipient' => ['email' => 'guest@example.com', 'name' => '비회원주문자', 'locale' => 'ko'],
                ],
            ];
        }, priority: 20);

        $this->registerAndFire('core.test.after_guest', null);

        // 비회원은 GuestNotifiable 수신자로 발송된다 (회원과 동일한 notify 경로).
        // fake 는 같은 클래스 + getKey() 로 매칭하므로 동일 이메일 게스트로 단언한다.
        Notification::assertSentTo(
            new GuestNotifiable('guest@example.com'),
            GenericNotification::class
        );
    }

    /**
     * 이메일이 없는 비회원 수신자에게는 아무것도 보내지 않는지 검증합니다.
     *
     * @effects guest_recipient_without_email_sends_nothing
     *
     * @return void 반환값 없음
     */
    public function test_guest_recipient_without_email_sends_nothing(): void
    {
        $this->createMailDefinition(
            'test_guest_noemail',
            'core.test.after_guest_noemail',
            [['type' => 'trigger_user']]
        );

        HookManager::addFilter('core.test.notification.extract_data', function ($default) {
            return [
                'notifiable' => null,
                'notifiables' => null,
                'data' => ['name' => 'x'],
                'context' => [
                    'trigger_user_id' => null,
                    'guest_recipient' => ['email' => ''],
                ],
            ];
        }, priority: 20);

        $this->registerAndFire('core.test.after_guest_noemail', null);

        Notification::assertNothingSent();
    }

    // ──────────────────────────────────────────────
    // 14. personalize_data seam — 수신자별 치환
    // ──────────────────────────────────────────────

    /**
     * 개인화 필터가 수신자마다 호출되어 값을 바꿀 수 있는지 검증합니다.
     *
     * @effects personalize_data_filter_can_change_values_per_recipient
     *
     * @return void 반환값 없음
     */
    public function test_personalize_filter_receives_each_recipient_and_can_change_values(): void
    {
        $role = Role::factory()->create(['identifier' => 'test_role_personalize']);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $role->users()->attach([$first->id, $second->id]);

        $this->createDefinitionWithTemplate(
            'test_personalize',
            'core.test.after_personalize',
            [['type' => 'role', 'value' => 'test_role_personalize']]
        );

        HookManager::addFilter('core.test.notification.extract_data', function ($default) {
            return array_merge($default, [
                'data' => ['name' => '{recipient_name}', 'action_url' => '/mypage'],
                'context' => ['claim_id' => 77],
            ]);
        }, priority: 20);

        $seen = [];
        HookManager::addFilter(
            'core.test.notification.personalize_data',
            function ($data, $notifiable, $type, $context, $args) use (&$seen) {
                $seen[] = $notifiable->id;
                // 컨텍스트 스칼라만 읽는다 — 큐 재로드 시점 오염 방지 규약.
                $data['action_url'] = '/admin/claims/'.$context['claim_id'];

                return $data;
            },
            priority: 20
        );

        // 실제로 나간 값은 after_send 가 나른다(알림 객체의 data 는 private).
        $delivered = [];
        HookManager::addAction('core.notification.after_send', function ($notifiable, $definition, $data) use (&$delivered) {
            $delivered[$notifiable->id] = $data;
        }, priority: 20);

        $this->registerAndFire('core.test.after_personalize', null);

        sort($seen);
        $expected = [$first->id, $second->id];
        sort($expected);
        $this->assertSame($expected, $seen, 'personalize 는 수신자마다 한 번씩 돌아야 합니다.');

        foreach ([$first, $second] as $recipient) {
            Notification::assertSentTo($recipient, GenericNotification::class);
            $this->assertSame('/admin/claims/77', $delivered[$recipient->id]['action_url'] ?? null);
            $this->assertSame(
                $recipient->name,
                $delivered[$recipient->id]['name'] ?? null,
                '{recipient_name} 치환이 수신자마다 따로 일어나야 합니다.'
            );
        }
    }

    /**
     * 구독자가 없으면 데이터가 그대로여야 한다 — seam 을 연 것만으로 동작이 바뀌면 안 된다.
     *
     * @effects personalize_seam_is_a_noop_without_subscribers
     *
     * @return void 반환값 없음
     */
    public function test_personalize_seam_is_a_noop_without_subscribers(): void
    {
        $user = User::factory()->create();

        $this->createDefinitionWithTemplate(
            'test_personalize_noop',
            'core.test.after_personalize_noop',
            [['type' => 'trigger_user']]
        );

        HookManager::addFilter('core.test.notification.extract_data', function ($default, $type, $args) {
            $user = $args[0] ?? null;

            return array_merge($default, [
                'data' => ['name' => '{recipient_name}', 'action_url' => '/mypage'],
                'context' => ['trigger_user_id' => $user?->id, 'trigger_user' => $user],
            ]);
        }, priority: 20);

        $delivered = null;
        HookManager::addAction('core.notification.after_send', function ($notifiable, $definition, $data) use (&$delivered) {
            $delivered = $data;
        }, priority: 20);

        $this->registerAndFire('core.test.after_personalize_noop', $user);

        Notification::assertSentTo($user, GenericNotification::class);
        $this->assertSame('/mypage', $delivered['action_url'] ?? null, '구독자가 없으면 값이 그대로여야 합니다.');
        $this->assertSame($user->name, $delivered['name'] ?? null);
    }

    /**
     * after_send 는 **실제로 나간 값**을 받아야 한다 — 원본을 받으면 로그가 다른 것을 본다.
     *
     * @effects after_send_receives_the_personalized_payload
     *
     * @return void 반환값 없음
     */
    public function test_after_send_receives_the_personalized_payload(): void
    {
        $user = User::factory()->create();

        $this->createDefinitionWithTemplate(
            'test_after_send_payload',
            'core.test.after_send_payload',
            [['type' => 'trigger_user']]
        );

        HookManager::addFilter('core.test.notification.extract_data', function ($default, $type, $args) {
            $user = $args[0] ?? null;

            return array_merge($default, [
                'data' => ['name' => '{recipient_name}', 'action_url' => '/mypage'],
                'context' => ['trigger_user_id' => $user?->id, 'trigger_user' => $user],
            ]);
        }, priority: 20);

        HookManager::addFilter(
            'core.test.notification.personalize_data',
            function ($data) {
                $data['action_url'] = '/admin/somewhere';

                return $data;
            },
            priority: 20
        );

        $observed = null;
        HookManager::addAction('core.notification.after_send', function ($notifiable, $definition, $data) use (&$observed) {
            $observed = $data;
        }, priority: 20);

        $this->registerAndFire('core.test.after_send_payload', $user);

        $this->assertIsArray($observed, 'after_send 가 발화하지 않았습니다.');
        $this->assertSame('/admin/somewhere', $observed['action_url'] ?? null);
        $this->assertSame($user->name, $observed['name'] ?? null);
    }

    // ──────────────────────────────────────────────
    // 15. 수신자 0명은 조용하지 않다 — skipped 로 남는다
    // ──────────────────────────────────────────────

    /**
     * 수신자가 0명이면 건너뜀으로 기록되는지 검증합니다.
     *
     * @effects zero_recipients_are_logged_as_skipped
     *
     * @return void 반환값 없음
     */
    public function test_zero_recipients_are_recorded_as_skipped(): void
    {
        $this->createDefinitionWithTemplate(
            'test_zero_recipient',
            'core.test.after_zero',
            [['type' => 'specific_users', 'value' => ['00000000-0000-0000-0000-000000000000']]]
        );

        HookManager::addFilter('core.test.notification.extract_data', function ($default) {
            return array_merge($default, ['data' => ['name' => 'x']]);
        }, priority: 20);

        $this->registerAndFire('core.test.after_zero', null);

        Notification::assertNothingSent();

        $log = NotificationLog::query()->where('notification_type', 'test_zero_recipient')->first();
        $this->assertNotNull($log, '수신자 0명이 발송 이력에 남지 않으면 운영자가 원인을 볼 수 없습니다.');
        $this->assertSame(NotificationLogStatus::Skipped, $log->status);
        $this->assertStringContainsString('specific_users:1', (string) $log->error_message);
    }

    /**
     * 비회원 이메일이 비어 있으면 별도 사유로 기록되는지 검증합니다.
     *
     * @effects empty_guest_email_is_logged_with_its_own_reason
     *
     * @return void 반환값 없음
     */
    public function test_empty_guest_email_is_recorded_with_its_own_reason(): void
    {
        $this->createDefinitionWithTemplate(
            'test_zero_guest',
            'core.test.after_zero_guest',
            [['type' => 'trigger_user']]
        );

        HookManager::addFilter('core.test.notification.extract_data', function ($default) {
            return array_merge($default, [
                'data' => ['name' => 'x'],
                'context' => ['trigger_user_id' => null, 'guest_recipient' => ['email' => '']],
            ]);
        }, priority: 20);

        $this->registerAndFire('core.test.after_zero_guest', null);

        $log = NotificationLog::query()->where('notification_type', 'test_zero_guest')->first();
        $this->assertNotNull($log);
        $this->assertSame(
            __('notification.skipped.guest_email_empty', ['rules' => 'trigger_user']),
            (string) $log->error_message,
            '빈 이메일과 "아무도 안 잡힘" 은 운영자가 취할 조치가 다르므로 사유를 갈라야 합니다.'
        );
    }

    /**
     * 「건너뜀」 사유가 규칙을 사람 말로 적는다 — 관계 이름과 「이벤트 유발자 제외」 여부까지.
     *
     * 자기 글에 자기 댓글을 단 경우처럼 유발자 제외로 0명이 되면, 종전 사유는 `related_user` 만 적어
     * 관계도 제외 여부도 읽을 수 없었다(2026-10-06 실측 O-G7). 기계 표기(괄호 안 규칙 코드)는 그대로 남긴다.
     *
     * @effects zero_recipient_reason_names_rules_in_words
     *
     * @return void 반환값 없음
     */
    public function test_zero_recipient_reason_names_relation_and_trigger_exclusion(): void
    {
        app()->setLocale('ko');
        $author = User::factory()->create();

        $this->createDefinitionWithTemplate(
            'test_zero_related',
            'core.test.after_zero_related',
            [['type' => 'related_user', 'relation' => 'post_author', 'exclude_trigger_user' => true]]
        );

        HookManager::addFilter('core.test.notification.extract_data', function ($default) use ($author) {
            return array_merge($default, [
                'data' => ['name' => 'x'],
                'context' => ['trigger_user_id' => $author->id, 'trigger_user' => $author, 'related_users' => ['post_author' => $author]],
            ]);
        }, priority: 20);

        $this->registerAndFire('core.test.after_zero_related', null);

        $log = NotificationLog::query()->where('notification_type', 'test_zero_related')->first();
        $this->assertNotNull($log);
        $message = (string) $log->error_message;
        $this->assertStringContainsString('관련 사용자', $message);
        $this->assertStringContainsString('이벤트 유발자 제외', $message);
        $this->assertStringContainsString('post_author', $message, '관계 이름이 없으면 키라도 남아야 한다');
        $this->assertStringContainsString('related_user:post_author', $message, '기계 표기(규칙 코드)는 유지한다');
    }

    /**
     * 역할 규칙으로 0명이 된 건너뜀 사유는 역할 식별자 대신 역할 이름을 적는다(식별자는 규칙 코드에 남긴다).
     *
     * @effects zero_recipient_reason_names_role_by_display_name
     *
     * @return void 반환값 없음
     */
    public function test_zero_recipient_reason_names_role_by_display_name(): void
    {
        app()->setLocale('ko');
        Role::factory()->create([
            'identifier' => 'test_role_nobody',
            'name' => ['ko' => '빈 검수 역할', 'en' => 'Empty QA role'],
        ]);

        $this->createDefinitionWithTemplate(
            'test_zero_role',
            'core.test.after_zero_role',
            [['type' => 'role', 'value' => 'test_role_nobody']]
        );

        $this->registerAndFire('core.test.after_zero_role', null);

        $log = NotificationLog::query()->where('notification_type', 'test_zero_role')->first();
        $this->assertNotNull($log);
        $message = (string) $log->error_message;
        $this->assertStringContainsString('역할(빈 검수 역할)', $message, '역할 식별자가 아니라 역할 이름을 적어야 한다');
        $this->assertStringContainsString('role:test_role_nobody', $message, '기계 표기(규칙 코드)는 유지한다');
    }
}
