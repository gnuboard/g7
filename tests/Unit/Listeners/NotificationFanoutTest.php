<?php

namespace Tests\Unit\Listeners;

use App\Enums\NotificationDeliveryStatus;
use App\Extension\HookArgumentSerializer;
use App\Extension\HookContextCapture;
use App\Extension\HookManager;
use App\Jobs\DispatchHookListenerJob;
use App\Listeners\NotificationHookListener;
use App\Models\NotificationDefinition;
use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\GenericNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Helpers\MocksChannelReadiness;
use Tests\TestCase;

/**
 * 알림 수신자 분할 발송·중복 방지 테스트 (#139)
 *
 * 수신자가 묶음 크기를 넘으면 수신자 참조만 실은 자식 잡으로 나뉘고, 같은 묶음이 다시 돌아도 수신자마다 한 번만
 * 가며, 실패한 채널은 재시도가 다시 보내고, 탈퇴 회원 건너뜀은 한 번만 기록되는지 검증한다.
 */
class NotificationFanoutTest extends TestCase
{
    use DatabaseTransactions;
    use MocksChannelReadiness;

    /**
     * 큐를 가짜로 두고 채널을 준비 완료로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->mockAllChannelsReady();
    }

    /**
     * 수신자가 묶음 크기 이하면 자식 잡 없이 그 자리에서 보낸다.
     *
     * @scenario recipients=within_chunk, rerun=none
     *
     * @effects inline_send_within_chunk
     *
     * @return void 반환값 없음
     */
    public function test_recipients_within_chunk_size_are_sent_inline_without_child_jobs(): void
    {
        config(['notification.fanout.chunk_size' => 100]);
        $users = User::factory()->count(3)->create();
        $definition = $this->definition('fanout_inline', $users);

        $this->listener()->dispatchForDefinition($definition);

        Queue::assertNotPushed(DispatchHookListenerJob::class, fn (DispatchHookListenerJob $job) => $job->method === 'dispatchChunk');
        $this->assertSame(3, $this->notificationCount($users));
    }

    /**
     * 묶음 크기를 넘으면 결정적인 크기의 자식 잡으로 나뉘고 모두 같은 이벤트 ID 를 싣는다.
     *
     * @scenario recipients=over_chunk, rerun=none
     *
     * @effects deterministic_child_chunks
     *
     * @return void 반환값 없음
     */
    public function test_recipients_over_chunk_size_dispatch_deterministic_child_jobs(): void
    {
        config(['notification.fanout.chunk_size' => 3]);
        $users = User::factory()->count(8)->create();
        $definition = $this->definition('fanout_chunks', $users);

        $this->listener()->dispatchForDefinition($definition);

        $children = $this->childJobs();
        $this->assertCount(3, $children);
        $this->assertSame([3, 3, 2], $children->map(fn (array $args) => count($args[3]))->all());
        $this->assertCount(1, $children->pluck(2)->unique(), '자식 잡의 이벤트 ID 가 서로 다르다');
        $this->assertSame(0, $this->notificationCount($users), '부모가 직접 보냈다');
        $this->assertSame(
            $users->sortBy('id', SORT_NATURAL)->map(fn (User $u) => 'user:'.$u->id)->values()->all(),
            $children->flatMap(fn (array $args) => array_column($args[3], 'key'))->sort(SORT_NATURAL)->values()->all(),
        );
        Queue::assertPushed(DispatchHookListenerJob::class, fn (DispatchHookListenerJob $job) => $job->method === 'dispatchChunk' && $job->tries === 5);
    }

    /**
     * 자식 잡 payload 에는 모델이 아니라 수신자 참조(스칼라 배열)만 실린다.
     *
     * @scenario recipients=over_chunk, rerun=none
     *
     * @scale n=100000 dataset=role_users
     *
     * @effects child_payload_carries_refs
     *
     * @return void 반환값 없음
     */
    public function test_child_payload_carries_recipient_refs_not_models(): void
    {
        config(['notification.fanout.chunk_size' => 2]);
        $users = User::factory()->count(5)->create();
        $definition = $this->definition('fanout_refs', $users);

        $this->listener()->dispatchForDefinition($definition);

        $job = Queue::pushed(DispatchHookListenerJob::class)->first(fn (DispatchHookListenerJob $j) => $j->method === 'dispatchChunk');
        $refs = $job->serializedArgs[3];
        $this->assertIsArray($refs);
        foreach ($refs as $ref) {
            $this->assertSame(['key', 'type', 'id'], array_keys($ref));
            $this->assertSame('user', $ref['type']);
            $this->assertIsInt($ref['id']);
        }
        $this->assertStringNotContainsString('password', json_encode($job->serializedArgs[3]));
    }

    /**
     * 비회원 수신자는 직렬화 가능한 표준 배열로 실린다(게스트 객체는 큐 직렬화에서 사라지므로).
     *
     * @scenario recipients=over_chunk, rerun=none
     *
     * @effects child_payload_carries_refs
     *
     * @return void 반환값 없음
     */
    public function test_guest_recipient_is_passed_as_serializable_ref(): void
    {
        config(['notification.fanout.chunk_size' => 1]);
        $user = User::factory()->create();
        $definition = $this->definition('fanout_guest', collect([$user]), [['type' => 'trigger_user'], ['type' => 'specific_users', 'value' => [$user->uuid]]]);
        HookManager::addFilter('core.test.notification.extract_data', fn ($default) => [
            ...$default,
            'context' => ['guest_recipient' => ['email' => 'guest139@example.test', 'name' => '게스트', 'locale' => 'ko']],
        ], 20);

        $this->listener()->dispatchForDefinition($definition);

        $guestRefs = $this->childJobs()->flatMap(fn (array $args) => $args[3])->where('type', 'guest')->values();
        $this->assertCount(1, $guestRefs);
        $this->assertSame(['email' => 'guest139@example.test', 'name' => '게스트', 'locale' => 'ko'], $guestRefs[0]['guest']);
        $this->assertStringStartsWith('guest:', $guestRefs[0]['key']);
    }

    /**
     * 참조로 바꿀 수 없는 수신자(모델이 아닌 객체)가 있으면 종전처럼 그 자리에서 보낸다.
     *
     * @scenario recipients=over_chunk, rerun=none
     *
     * @effects inline_send_within_chunk
     *
     * @return void 반환값 없음
     */
    public function test_non_model_recipient_forces_inline_send(): void
    {
        config(['notification.fanout.chunk_size' => 1]);
        $definition = $this->definition('fanout_legacy', collect(), []);
        $a = new FanoutStubNotifiable;
        $b = new FanoutStubNotifiable;
        HookManager::addFilter('core.test.notification.extract_data', fn ($default) => [...$default, 'notifiables' => [$a, $b]], 20);

        $this->listener()->dispatchForDefinition($definition);

        Queue::assertNotPushed(DispatchHookListenerJob::class, fn (DispatchHookListenerJob $job) => $job->method === 'dispatchChunk');
        $this->assertSame(1, $a->received);
        $this->assertSame(1, $b->received);
    }

    /**
     * 같은 자식 잡이 두 번 돌아도(재전달·재예약) 수신자마다 한 번만 간다.
     *
     * @scenario recipients=over_chunk, rerun=duplicate_child
     *
     * @effects fanout_children_deliver_once
     *
     * @return void 반환값 없음
     */
    public function test_duplicate_child_execution_sends_each_recipient_once(): void
    {
        config(['notification.fanout.chunk_size' => 3]);
        $users = User::factory()->count(8)->create();
        $definition = $this->definition('fanout_duplicate', $users);
        $this->listener()->dispatchForDefinition($definition);

        $jobs = Queue::pushed(DispatchHookListenerJob::class)->filter(fn (DispatchHookListenerJob $j) => $j->method === 'dispatchChunk');
        foreach ([1, 2] as $round) {
            foreach ($jobs as $job) {
                unserialize(serialize($job))->handle();
            }
        }

        foreach ($users as $user) {
            $this->assertSame(1, $this->notificationCount(collect([$user])), "회원 {$user->id}");
        }
        $this->assertSame(8, NotificationDelivery::query()->where('status', NotificationDeliveryStatus::Sent->value)->where('notification_type', 'fanout_duplicate')->count());
    }

    /**
     * 채널 발송이 실패하면 선점을 풀어 같은 사건의 재시도가 다시 보낸다.
     *
     * @scenario recipients=within_chunk, rerun=channel_failure_retry
     *
     * @effects failed_channel_releases_claim
     *
     * @return void 반환값 없음
     */
    public function test_failed_channel_send_releases_claim_so_retry_resends(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition('fanout_failed', collect([$user]));
        app()->instance(HookContextCapture::EVENT_ID_BINDING, 'ev-failed-retry');
        $failing = new class extends DatabaseChannel
        {
            /**
             * 발송을 실패시킵니다.
             *
             * @param  mixed  $notifiable  수신자
             * @param  mixed  $notification  알림
             * @return mixed 반환 없음 (예외)
             */
            public function send($notifiable, $notification)
            {
                throw new RuntimeException('database channel down');
            }
        };
        app()->instance(DatabaseChannel::class, $failing);

        $this->listener()->dispatchForDefinition($definition);
        $this->assertSame(0, $this->notificationCount(collect([$user])));
        $this->assertSame(0, NotificationDelivery::query()->where('notification_type', 'fanout_failed')->count(), '실패한 선점이 남았다');

        app()->forgetInstance(DatabaseChannel::class);
        app(ChannelManager::class)->forgetDrivers(); // 채널 매니저가 실패 채널 인스턴스를 캐시해 둔다
        $this->listener()->dispatchForDefinition($definition);
        $this->assertSame(1, $this->notificationCount(collect([$user])));
        app()->forgetInstance(HookContextCapture::EVENT_ID_BINDING);
    }

    /**
     * 탈퇴 회원 건너뜀은 같은 사건이 다시 돌아도 한 번만 기록된다.
     *
     * @scenario recipients=within_chunk, rerun=withdrawn_rerun
     *
     * @effects withdrawn_skip_recorded_once
     *
     * @return void 반환값 없음
     */
    public function test_withdrawn_recipient_skip_is_recorded_once_across_reruns(): void
    {
        $user = User::factory()->create(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        $definition = $this->definition('fanout_withdrawn', collect([$user]), null, 'mail');
        app()->instance(HookContextCapture::EVENT_ID_BINDING, 'ev-withdrawn');

        $this->listener()->dispatchForDefinition($definition);
        $this->listener()->dispatchForDefinition($definition);

        $this->assertSame(1, DB::table('notification_logs')->where('notification_type', 'fanout_withdrawn')->where('recipient_user_id', $user->id)->where('status', 'skipped')->count());
        $this->assertSame(NotificationDeliveryStatus::Skipped, NotificationDelivery::query()->where('notification_type', 'fanout_withdrawn')->first()?->status);
        app()->forgetInstance(HookContextCapture::EVENT_ID_BINDING);
    }

    /**
     * 알림 정의와 템플릿 하나를 만듭니다.
     *
     * @param  string  $type  알림 유형
     * @param  Collection<int, User>  $users  특정 사용자 수신자
     * @param  array<int, array<string, mixed>>|null  $recipients  수신자 규칙 (null 이면 특정 사용자)
     * @param  string  $channel  채널
     * @return NotificationDefinition 알림 정의
     */
    private function definition(string $type, Collection $users, ?array $recipients = null, string $channel = 'database'): NotificationDefinition
    {
        $definition = NotificationDefinition::create([
            'type' => $type,
            'hook_prefix' => 'core.test',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => $type],
            'variables' => [],
            'channels' => [$channel],
            'hooks' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => $channel,
            'subject' => ['ko' => '분할 발송'],
            'body' => ['ko' => '본문'],
            'recipients' => $recipients ?? [['type' => 'specific_users', 'value' => $users->pluck('uuid')->all()]],
            'is_active' => true,
            'is_default' => true,
        ]);

        return $definition;
    }

    /**
     * 리스너를 만듭니다.
     *
     * @return NotificationHookListener 리스너
     */
    private function listener(): NotificationHookListener
    {
        return app(NotificationHookListener::class);
    }

    /**
     * 큐에 쌓인 자식 잡의 인자(역직렬화 전 원형)를 모읍니다.
     *
     * @return Collection<int, array<int, mixed>> 자식 잡별 인자
     */
    private function childJobs(): Collection
    {
        return Queue::pushed(DispatchHookListenerJob::class)
            ->filter(fn (DispatchHookListenerJob $job) => $job->method === 'dispatchChunk')
            ->map(fn (DispatchHookListenerJob $job) => HookArgumentSerializer::deserialize($job->serializedArgs))
            ->values();
    }

    /**
     * 주어진 회원들이 받은 알림함 행 수를 셉니다.
     *
     * @param  Collection<int, User>  $users  회원
     * @return int 행 수
     */
    private function notificationCount(Collection $users): int
    {
        return DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $users->pluck('id'))->count();
    }
}

/**
 * 모델이 아닌 수신자 스텁 — notify() 호출 횟수만 센다
 */
class FanoutStubNotifiable
{
    /** @var int 받은 알림 수 */
    public int $received = 0;

    /**
     * 알림을 받습니다.
     *
     * @param  GenericNotification  $notification  알림
     * @return void 반환값 없음
     */
    public function notify(GenericNotification $notification): void
    {
        $this->received++;
    }
}
