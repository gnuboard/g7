<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Notification;

use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Listeners\NotificationLogListener;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Services\NotificationDefinitionService;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Plugins\Sirsoft\MessageBizppurio\Enums\BizppurioTemplateStatus;
use Plugins\Sirsoft\MessageBizppurio\Jobs\SendMessageJob;
use Plugins\Sirsoft\MessageBizppurio\Listeners\LinkNotificationLogListener;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Repositories\BizppurioDispatchRepository;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioTemplateRepositoryInterface;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkPayloadMapper;
use Plugins\Sirsoft\MessageBizppurio\Services\DispatchLinkContext;
use Plugins\Sirsoft\MessageBizppurio\Services\MessagePayloadBuilder;
use Plugins\Sirsoft\MessageBizppurio\Services\SmsChannelDriver;
use Plugins\Sirsoft\MessageBizppurio\Services\SmsTypeResolver;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 큐 드라이버가 database 여도 비즈뿌리오 발송 이력이 코어 발송 로그에 연결된다
 *
 * 연결은 「드라이버 send(refkey 기억) → after_channel_send → 코어 로그 생성 → after_log_sent → refkey 로 연결」 이
 * 수신자·채널마다 한 사이클로 이어져야 한다. 코어 로그 기록과 연결 리스너가 큐로 가면 refkey 를 든 요청 단위
 * 표식(scoped)이 작업 경계에서 사라져 연결이 한 건도 되지 않는다.
 *
 * 실제 SMS 드라이버로 디스패치 행과 refkey 를 만들고, 디스패처(NotificationDispatcher::sendToNotifiable)가 내는
 * 훅을 같은 순서로 낸다. 외부 API 호출은 SendMessageJob 이 맡으므로 그 작업만 대역으로 둔다.
 */
class NotificationLogLinkQueuedTest extends PluginTestCase
{
    /**
     * 큐를 database 로 바꾸고 코어 로그·연결 리스너를 운영과 같은 등록기로 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        Bus::fake([SendMessageJob::class]);

        // 부팅 때 큐 모드로 등록된 같은 리스너를 걷어 내고 현재 구독 정의로 다시 등록한다 (중복 실행 방지)
        foreach ([...array_keys(NotificationLogListener::getSubscribedHooks()), ...array_keys(LinkNotificationLogListener::getSubscribedHooks())] as $hook) {
            HookManager::clearAction($hook);
        }
        HookListenerRegistrar::clear();
        HookListenerRegistrar::register(NotificationLogListener::class, 'core');
        HookListenerRegistrar::register(LinkNotificationLogListener::class, 'sirsoft-message_bizppurio');
    }

    /**
     * 실제 SMS 드라이버를 만듭니다 (템플릿·설정만 대역, refkey 표식은 컨테이너 인스턴스).
     *
     * @return SmsChannelDriver 드라이버
     */
    private function makeDriver(): SmsChannelDriver
    {
        $row = new BizppurioTemplate;
        $row->notification_type = 'order_confirmed';
        $row->status = BizppurioTemplateStatus::Draft;
        $row->sms_body = ['ko' => '주문이 완료되었습니다.'];
        $row->sms_only = true;
        $row->is_active = true;

        $templates = Mockery::mock(BizppurioTemplateRepositoryInterface::class);
        $templates->shouldReceive('findByType')->andReturn($row);

        $pluginSettings = Mockery::mock(PluginSettingsService::class);
        $pluginSettings->shouldReceive('get')->andReturnUsing(fn (string $id, string $key, mixed $default = null) => $key === 'is_test_mode' ? true : $default);

        $definitionService = Mockery::mock(NotificationDefinitionService::class);
        $definitionService->shouldReceive('resolve')->andReturn(null);

        return new SmsChannelDriver(
            $definitionService,
            $templates,
            new AlimtalkPayloadMapper,
            new SmsTypeResolver,
            app(MessagePayloadBuilder::class),
            new BizppurioDispatchRepository,
            app(DispatchLinkContext::class),
            $pluginSettings,
        );
    }

    /**
     * 한 수신자에게 디스패처와 같은 순서로 발송합니다 (드라이버 send → after_channel_send).
     *
     * @param  SmsChannelDriver  $driver  드라이버
     * @param  User  $member  수신자
     * @return void 반환값 없음
     */
    private function sendTo(SmsChannelDriver $driver, User $member): void
    {
        $notification = new GenericNotification('order_confirmed', 'sirsoft-ecommerce', [], 'module', 'sirsoft-ecommerce');

        $driver->send($member, $notification);

        HookManager::doAction('core.notification.after_channel_send', 'sms', [
            'notification_type' => 'order_confirmed',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
            'recipient_identifier' => $member->mobile,
            'recipient_name' => $member->name,
            'recipient_user_id' => $member->id,
            'subject' => null,
            'body' => '주문이 완료되었습니다.',
        ]);
    }

    /**
     * 코어 로그 3훅과 연결 2훅이 sync 다. (실패-먼저)
     *
     * @scenario queue=sync
     *
     * @effects dispatch_linked_to_own_log
     *
     * @return void 반환값 없음
     */
    public function test_log_and_link_listeners_are_synchronous(): void
    {
        foreach ([NotificationLogListener::class, LinkNotificationLogListener::class] as $listener) {
            foreach ($listener::getSubscribedHooks() as $hook => $config) {
                $this->assertTrue(($config['sync'] ?? false) === true, class_basename($listener)." 의 {$hook} 가 sync 가 아닙니다.");
            }
        }
    }

    /**
     * 두 수신자에게 보낸 뒤 워커를 돌려도, 각 발송 이력이 자기 코어 로그 id 로 연결돼 있다. (실패-먼저)
     *
     * @scenario queue=database
     *
     * @effects dispatch_linked_to_own_log
     *
     * @return void 반환값 없음
     */
    public function test_each_dispatch_is_linked_to_its_own_log_on_database_queue(): void
    {
        $first = User::factory()->create(['mobile' => '010-7240-0001']);
        $second = User::factory()->create(['mobile' => '010-7240-0002']);
        $driver = $this->makeDriver();

        $this->sendTo($driver, $first);
        $this->sendTo($driver, $second);

        // 워커 기본 메모리 한도(128MB)는 테스트 프로세스가 이미 넘어, 첫 작업 뒤 워커가 멈추고 나머지 작업이 남는다
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 4096]);

        foreach ([$first, $second] as $member) {
            $log = NotificationLog::query()->where('recipient_user_id', $member->id)->where('channel', 'sms')->first();
            $this->assertNotNull($log, "{$member->mobile} 의 코어 발송 로그가 없습니다.");

            $dispatch = BizppurioDispatch::query()->where('to_number', preg_replace('/\D/', '', $member->mobile))->first();
            $this->assertNotNull($dispatch, "{$member->mobile} 의 비즈뿌리오 발송 이력이 없습니다.");
            $this->assertSame((int) $log->id, (int) $dispatch->notification_log_id, "{$member->mobile} 의 발송 이력이 자기 코어 로그에 연결되지 않았습니다.");
        }
    }
}
