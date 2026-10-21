<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Notification;

use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Jobs\DispatchHookListenerJob;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Services\PluginSettingsService;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Mockery;
use Plugins\Sirsoft\MessageBizppurio\Enums\BizppurioTemplateStatus;
use Plugins\Sirsoft\MessageBizppurio\Listeners\LinkNotificationLogListener;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioTemplateRepositoryInterface;
use Plugins\Sirsoft\MessageBizppurio\Services\DispatchLinkContext;
use Plugins\Sirsoft\MessageBizppurio\Services\SmsChannelDriver;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 큐 워커에서 발송해도 코어 알림 로그가 비즈뿌리오 dispatch 에 연결되는지 검증 (#139).
 *
 * 큐 드라이버가 database 이면 발송은 워커에서 일어난다. 종전에는 코어 발송 로그 기록과 연결 리스너가 각각
 * 별도 큐 잡으로 밀려, 드라이버가 발송 프로세스에 남긴 refkey 표식(요청 단위 싱글턴)을 찾지 못해 연결이
 * 늘 비었다(notification_log_id NULL). 두 훅이 발송 프로세스 안에서 동기로 돌면 같은 잡 안에서
 * 「remember → 발송 → 로그 → consume」이 끝난다.
 *
 * 연결 리스너는 실제 등록 경로(HookListenerRegistrar)로 붙인다 — 선언의 `sync` 가 그대로 반영되는지까지 본다.
 */
class NotificationLogLinkQueuedWorkerTest extends PluginTestCase
{
    /**
     * database 큐와 워커 실패 리스너 초기화.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        // queue:work 는 실패 기록 리스너를 프로세스에 한 번만 붙인다 — 테스트마다 새 앱이므로 다시 붙게 한다
        (new \ReflectionProperty(WorkCommand::class, 'hasRegisteredListeners'))->setValue(null, false);
    }

    /**
     * 큐 워커에서 발송해도 로그가 dispatch 에 연결된다.
     *
     * @scenario process=queue_worker, dispatch_state=pending
     *
     * @effects queued_send_links_log
     *
     * @return void 반환값 없음
     */
    public function test_큐_워커에서_발송해도_로그가_dispatch에_연결된다(): void
    {
        HookListenerRegistrar::register(LinkNotificationLogListener::class, 'plugin:sirsoft-message_bizppurio');
        $refkey = 'rk_q139_'.uniqid();
        $failedBefore = DB::table('failed_jobs')->count();

        // 채널 드라이버 send() 와 같은 순서 — dispatch 생성·refkey 표식 → 코어 발송 완료 훅 — 를 워커 안에서 밟는다
        dispatch(function () use ($refkey) {
            BizppurioDispatch::create([
                'refkey' => $refkey,
                'channel' => 'sms',
                'to_number' => '01011112222',
                'content' => '본문',
                'notification_type' => 'welcome',
                'status' => 'pending',
                'source' => 'auto',
                'sent_at' => now(),
            ]);
            app(DispatchLinkContext::class)->remember($refkey);

            HookManager::doAction('core.notification.after_channel_send', 'sms', [
                'notification_type' => 'welcome',
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'recipient_identifier' => '01011112222',
                'recipient_name' => '홍길동',
                'recipient_user_id' => null,
            ]);
        });
        $this->assertSame(0, $this->pendingListenerJobs(), '발송 전부터 리스너 잡이 쌓여 있다');

        Artisan::call('queue:work', [
            'connection' => 'database',
            '--stop-when-empty' => true,
            '--memory' => 4096,
            '--sleep' => 0,
        ]);

        $dispatch = BizppurioDispatch::query()->where('refkey', $refkey)->first();
        $this->assertNotNull($dispatch, '워커가 발송 잡을 처리하지 않았다');
        $this->assertNotNull($dispatch->notification_log_id, '워커에서 발송한 건의 로그가 dispatch 에 연결되지 않았다');
        $this->assertDatabaseHas('notification_logs', [
            'id' => $dispatch->notification_log_id,
            'channel' => 'sms',
            'recipient_identifier' => '01011112222',
        ]);
        $this->assertSame($failedBefore, DB::table('failed_jobs')->count(), '워커 잡이 실패했다');
    }

    /**
     * 워커가 잡 사이에 요청 단위 인스턴스를 비워도, 앞 잡에서 만든 채널 드라이버는 **지금** 컨텍스트에 refkey 를 남긴다.
     *
     * 코어 채널 매니저는 드라이버 인스턴스를 프로세스 동안 재사용하고, 큐 워커는 잡마다 scoped 인스턴스를 비운다.
     * 드라이버가 생성자에서 받은 컨텍스트를 쥐고 있으면 두 번째 잡부터 refkey 가 옛 컨텍스트에 남아 연결 리스너(새
     * 컨텍스트를 받음)가 빈손이 된다 — 실측(E-02)에서 분할 2개 중 두 번째 잡의 수신자만 연결이 비었다.
     *
     * @scenario process=queue_worker, dispatch_state=pending
     *
     * @effects driver_uses_current_link_context
     *
     * @return void 반환값 없음
     */
    public function test_잡_사이에_컨텍스트가_비워져도_드라이버는_현재_컨텍스트에_refkey를_남긴다(): void
    {
        Bus::fake();
        $template = new BizppurioTemplate;
        $template->notification_type = 'order_confirmed';
        $template->status = BizppurioTemplateStatus::Draft;
        $template->sms_body = ['ko' => '주문이 완료되었습니다.'];
        $template->is_active = true;
        $templates = Mockery::mock(BizppurioTemplateRepositoryInterface::class);
        $templates->shouldReceive('findByType')->andReturn($template);
        $this->app->instance(BizppurioTemplateRepositoryInterface::class, $templates);
        $settings = Mockery::mock(PluginSettingsService::class);
        $settings->shouldReceive('get')->andReturn(true);
        $this->app->instance(PluginSettingsService::class, $settings);
        $member = User::factory()->create(['mobile' => '010-1234-5678']);

        // 첫 잡에서 채널 매니저가 만든 드라이버(이후 재사용) → 워커가 다음 잡 전에 scoped 인스턴스를 비운다
        $driver = app(SmsChannelDriver::class);
        $this->app->forgetScopedInstances();

        $driver->send($member, new GenericNotification('order_confirmed', 'sirsoft-ecommerce', [], 'module', 'sirsoft-ecommerce'));

        $dispatch = BizppurioDispatch::query()->latest('id')->first();
        $this->assertNotNull($dispatch);
        $this->assertSame($dispatch->refkey, app(DispatchLinkContext::class)->consume(), '드라이버가 비워진 옛 컨텍스트에 refkey 를 남겼다');
        Mockery::close();
    }

    /**
     * 큐에 남은 훅 리스너 잡 수를 셉니다.
     *
     * @return int 잡 수
     */
    private function pendingListenerJobs(): int
    {
        return DB::table('jobs')->where('payload', 'like', '%'.addslashes(class_basename(DispatchHookListenerJob::class)).'%')->count();
    }
}
