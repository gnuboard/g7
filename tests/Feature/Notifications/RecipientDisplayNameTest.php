<?php

namespace Tests\Feature\Notifications;

use App\DisplayName\DisplayNameAreaRegistry;
use App\Extension\HookManager;
use App\Listeners\CoreNotificationDataListener;
use App\Listeners\CorePrivacyNotificationDataListener;
use App\Listeners\NotificationHookListener;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Notifications\GuestNotifiable;
use App\Notifications\RecipientDisplayName;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Helpers\MocksChannelReadiness;
use Tests\TestCase;

/**
 * 알림의 받는 사람 이름(`{recipient_name}`)이 채널의 표시 이름 영역으로 풀리는지 잰다.
 *
 * 채널마다 설정이 다르면 같은 사람에게 채널마다 다른 이름이 나가고, 큐 분할 발송(묶음 잡)을 거쳐도 같은 값이 나간다.
 * 뒤따르는 필터 `{hook_prefix}.notification.personalize_data` 의 인자는 그대로다.
 */
class RecipientDisplayNameTest extends TestCase
{
    use MocksChannelReadiness;
    use RefreshDatabase;

    private const HOOK_PREFIX = 'core.test490';

    /**
     * 역할과 코어 회원 항목을 시딩하고 알림을 가짜로 바꾼다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CoreFieldDefinitionSeeder::class);

        Notification::fake();
        $this->mockAllChannelsReady();

        HookManager::addFilter(self::HOOK_PREFIX.'.notification.extract_data', fn ($default) => array_merge($default, [
            'data' => ['name' => '{recipient_name}', 'greeting_name' => NotificationHookListener::RECIPIENT_NAME_PLACEHOLDER, 'app_name' => 'G7'],
            'context' => ['order_id' => 77],
        ]), priority: 20);
    }

    /**
     * 테스트가 건 구독자를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(self::HOOK_PREFIX.'.notification.extract_data');
        HookManager::clearFilter(self::HOOK_PREFIX.'.notification.personalize_data');
        parent::tearDown();
    }

    /**
     * 표시 이름 영역 설정을 바꿉니다.
     *
     * @param  array<string, array<string, string>>  $areas  영역 키 => 설정
     * @return void 반환값 없음
     */
    private function areas(array $areas): void
    {
        config(['g7_settings.core.member.display_name_areas' => $areas]);
    }

    /**
     * 메일과 사이트 알림 템플릿을 가진 알림 정의를 만듭니다.
     *
     * @param  array<int, string>  $uuids  수신자 회원 UUID
     * @param  array<int, string>  $channels  템플릿을 만들 채널
     * @return NotificationDefinition 알림 정의
     */
    private function definition(array $uuids, array $channels = ['mail', 'database']): NotificationDefinition
    {
        $definition = NotificationDefinition::create([
            'type' => 'issue490_recipient_'.uniqid(), 'hook_prefix' => self::HOOK_PREFIX, 'extension_type' => 'core', 'extension_identifier' => 'core',
            'name' => ['ko' => '받는 사람 이름'], 'variables' => [], 'channels' => $channels, 'hooks' => [], 'is_active' => true, 'is_default' => true,
        ]);

        foreach ($channels as $channel) {
            NotificationTemplate::create([
                'definition_id' => $definition->id, 'channel' => $channel, 'subject' => ['ko' => '{name}님'], 'body' => ['ko' => '{name}님 안녕하세요'],
                'recipients' => [['type' => 'specific_users', 'value' => $uuids]], 'is_active' => true, 'is_default' => true,
            ]);
        }

        return $definition;
    }

    /**
     * 회원이 받은 알림을 채널 => 알림 데이터로 모읍니다.
     *
     * @param  User  $user  회원
     * @return array<string, array<string, mixed>> 채널 => 알림 데이터
     */
    private function deliveredTo(User $user): array
    {
        $byChannel = [];
        foreach (Notification::sent($user, GenericNotification::class) as $notification) {
            $channel = (new \ReflectionProperty(GenericNotification::class, 'channel'))->getValue($notification);
            $byChannel[(string) $channel] = $notification->getData();
        }

        return $byChannel;
    }

    /**
     * 채널의 표시 이름 설정이 다르면 같은 사람에게 채널마다 다른 이름이 나간다. 설정이 없는 채널은 알림 공통, 사이트 기본을 따른다.
     *
     * @scenario case=channel_names_differ
     *
     * @effects recipient_name_follows_channel_area, recipient_name_differs_between_channels, every_placeholder_value_is_replaced
     *
     * @return void 반환값 없음
     */
    public function test_recipient_name_follows_each_channel_area(): void
    {
        $user = User::factory()->create(['name' => '홍길동', 'nickname' => '길동이', 'email' => 'gildong@example.com']);
        $this->areas([
            'core:site' => ['mode' => 'fixed', 'kind' => 'name'],
            'core:notification:database' => ['mode' => 'fixed', 'kind' => 'nickname'],
            'core:notification:mail' => ['mode' => 'fixed', 'kind' => 'masked_name'],
        ]);

        app(NotificationHookListener::class)->dispatchForDefinition($this->definition([$user->uuid]));

        $delivered = $this->deliveredTo($user);
        $this->assertSame('홍*동', $delivered['mail']['name']);
        $this->assertSame('길동이', $delivered['database']['name']);
        $this->assertSame('길동이', $delivered['database']['greeting_name'], '이름 칸이 아닌 자리표시자가 바뀌지 않았다');
        $this->assertSame('G7', $delivered['mail']['app_name']);

        // 채널 설정을 지우면 알림 공통 영역을 따른다
        Notification::fake();
        $this->areas([
            'core:site' => ['mode' => 'fixed', 'kind' => 'name'],
            'core:notification' => ['mode' => 'fixed', 'kind' => 'name_nickname'],
        ]);
        app(NotificationHookListener::class)->dispatchForDefinition($this->definition([$user->uuid]));
        $delivered = $this->deliveredTo($user);
        $this->assertSame('홍길동(길동이)', $delivered['mail']['name']);
        $this->assertSame('홍길동(길동이)', $delivered['database']['name']);
    }

    /**
     * 설정을 바꾸지 않은 사이트에서는 받는 사람 이름이 종전처럼 회원 이름이다.
     *
     * @scenario case=default_settings_unchanged
     *
     * @effects default_recipient_name_equals_name
     *
     * @return void 반환값 없음
     */
    public function test_default_settings_send_the_member_name(): void
    {
        $user = User::factory()->create(['name' => '홍길동', 'nickname' => '길동이']);

        app(NotificationHookListener::class)->dispatchForDefinition($this->definition([$user->uuid]));

        $delivered = $this->deliveredTo($user);
        $this->assertSame('홍길동', $delivered['mail']['name']);
        $this->assertSame('홍길동', $delivered['database']['name']);
    }

    /**
     * 수신자가 묶음 크기를 넘어 큐 분할 발송을 거쳐도 받는 사람마다 같은 규칙의 이름이 나간다.
     *
     * @scenario case=fanout_chunks
     *
     * @effects fanout_chunk_resolves_same_recipient_name, chunk_job_entry_resolves_same_recipient_name
     *
     * @return void 반환값 없음
     */
    public function test_fanout_chunks_resolve_the_same_names(): void
    {
        config(['notification.fanout.chunk_size' => 2]);
        $this->areas([
            'core:site' => ['mode' => 'fixed', 'kind' => 'name'],
            'core:notification:database' => ['mode' => 'fixed', 'kind' => 'nickname'],
        ]);

        $users = collect(range(1, 5))->map(fn (int $i) => User::factory()->create(['name' => "회원{$i}", 'nickname' => "닉{$i}"]));
        $definition = $this->definition($users->pluck('uuid')->all(), ['database']);

        app(NotificationHookListener::class)->dispatchForDefinition($definition);

        foreach ($users as $index => $user) {
            $this->assertSame('닉'.($index + 1), $this->deliveredTo($user)['database']['name'] ?? null, "회원 {$user->id} 의 이름이 묶음 발송에서 달라졌다");
        }

        // 묶음 잡의 진입점을 직접 불러도(큐 워커가 부르는 모양) 같은 값이다
        Notification::fake();
        $extra = User::factory()->create(['name' => '추가회원', 'nickname' => '추가닉']);
        $template = $definition->templates()->where('channel', 'database')->firstOrFail();
        app(NotificationHookListener::class)->dispatchChunk(
            $definition, (int) $template->id, 'issue490-chunk-event', [['type' => 'user', 'id' => $extra->id]],
            ['name' => '{recipient_name}'], ['order_id' => 77], [],
        );
        $this->assertSame('추가닉', $this->deliveredTo($extra)['database']['name'] ?? null);
    }

    /**
     * 뒤따르는 개인화 필터는 종전과 같은 인자 다섯 개를 받고(채널이 더해지지 않는다), 이름이 이미 풀린 데이터를 받는다.
     *
     * @scenario case=personalize_filter_contract
     *
     * @effects personalize_filter_arguments_unchanged, personalize_filter_sees_resolved_name
     *
     * @return void 반환값 없음
     */
    public function test_personalize_filter_arguments_are_unchanged(): void
    {
        $user = User::factory()->create(['name' => '홍길동', 'nickname' => '길동이']);
        $this->areas(['core:site' => ['mode' => 'fixed', 'kind' => 'name'], 'core:notification' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $definition = $this->definition([$user->uuid], ['database']);

        $calls = [];
        HookManager::addFilter(self::HOOK_PREFIX.'.notification.personalize_data', function (...$arguments) use (&$calls) {
            $calls[] = $arguments;

            return $arguments[0];
        }, priority: 20);

        app(NotificationHookListener::class)->dispatchForDefinition($definition, 'hook-arg');

        $this->assertCount(1, $calls);
        [$data, $notifiable, $type, $context, $args] = $calls[0];
        $this->assertCount(5, $calls[0], '개인화 필터의 인자 수가 바뀌었다');
        $this->assertSame('길동이', $data['name']);
        $this->assertTrue($notifiable->is($user));
        $this->assertSame($definition->type, $type);
        $this->assertSame(77, $context['order_id']);
        $this->assertSame(['hook-arg'], $args);
    }

    /**
     * 코어 자료 추출 리스너는 받는 사람 이름을 직접 읽지 않고 자리표시자를 넣는다.
     *
     * @scenario case=core_listeners_use_placeholder
     *
     * @effects core_auth_listener_emits_recipient_placeholder, core_privacy_listener_emits_recipient_placeholder
     *
     * @return void 반환값 없음
     */
    public function test_core_data_listeners_emit_the_placeholder(): void
    {
        $user = User::factory()->create(['name' => '홍길동']);
        $default = ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];

        foreach (['welcome', 'reset_password', 'password_changed', 'password_expiry_notice', 'password_expired'] as $type) {
            $result = (new CoreNotificationDataListener)->extractData($default, $type, [$user, []]);
            $this->assertSame('{recipient_name}', $result['data']['name'] ?? null, "{$type} 알림이 이름을 직접 실었다");
            $this->assertSame($user->id, $result['context']['trigger_user_id'] ?? null);
        }

        $privacy = app(CorePrivacyNotificationDataListener::class);
        foreach (['dormant_pre_notice', 'dormant_converted', 'dormant_withdraw_notice'] as $type) {
            $result = $privacy->extractData($default, $type, [$user, []]);
            $this->assertSame('{recipient_name}', $result['data']['name'] ?? null, "{$type} 알림이 이름을 직접 실었다");
        }
    }

    /**
     * 이름 계산기: 등록되지 않은 채널은 알림 공통 영역, 받는 사람이 아닌 회원은 알림 공통 영역, 발송 이력은 관리자 영역, 비회원은 받은 이름 그대로.
     *
     * @scenario case=name_service
     *
     * @effects unregistered_channel_uses_notification_area, other_member_uses_notification_area, log_snapshot_uses_admin_area, guest_recipient_keeps_given_name
     *
     * @return void 반환값 없음
     */
    public function test_name_service_areas(): void
    {
        $user = User::factory()->create(['name' => '홍길동', 'nickname' => '길동이', 'email' => 'gildong@example.com']);
        $this->areas([
            'core:site' => ['mode' => 'fixed', 'kind' => 'name'],
            'core:admin' => ['mode' => 'fixed', 'kind' => 'masked_id'],
            'core:notification' => ['mode' => 'fixed', 'kind' => 'nickname'],
            'core:notification:mail' => ['mode' => 'fixed', 'kind' => 'masked_name'],
        ]);
        $names = app(RecipientDisplayName::class);

        $this->assertSame(DisplayNameAreaRegistry::AREA_NOTIFICATION, $names->areaForChannel('vendor_push'));
        $this->assertSame(DisplayNameAreaRegistry::AREA_NOTIFICATION, $names->areaForChannel(null));
        $this->assertSame('core:notification:mail', $names->areaForChannel('mail'));

        $this->assertSame('홍*동', $names->forChannel($user, 'mail'));
        $this->assertSame('길동이', $names->forChannel($user, 'vendor_push'));
        $this->assertSame('길동이', $names->ofMember($user));
        $this->assertSame('손님글쓴이', $names->ofMember(null, '손님글쓴이'));
        $this->assertSame('gil***', $names->forLog($user));

        $guest = new GuestNotifiable('guest@example.com', '비회원 주문자');
        $this->assertSame('비회원 주문자', $names->forChannel($guest, 'mail'));
        $this->assertSame('비회원 주문자', $names->forLog($guest));
        $this->assertNull($names->forChannel(new GuestNotifiable('noname@example.com'), 'mail'));
    }
}
