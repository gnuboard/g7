<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Notification;

use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\HookManager;
use App\Models\ActivityLog;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\NotificationDefinitionService;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Listeners\OrderActivityLogListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Tests\Support\Concerns\CapturesSentMail;

/**
 * 주문 상세 「고객에게 메일 보내기」 알림 경유 테스트 (#699 A2)
 *
 * 7.1.0 전에는 `Mail::raw()` 로 알림 밖에서 보내 이커머스 메일 채널을 꺼도 나갔고 발송 로그가 남지
 * 않았다. 이제 알림 정의 `order_custom_email` 을 거친다 — 채널 토글·발송 로그·회원/비회원 수신자·
 * 활동 로그 훅(성공 시에만)을 잠근다.
 */
class OrderCustomEmailNotificationTest extends ModuleTestCase
{
    use CapturesSentMail;

    private User $admin;

    /**
     * 주문 메일 알림 정의를 동기화하고 array 메일러로 발송을 수집합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $module = new Module('sirsoft-ecommerce', $this->getModuleBasePath());
        $declaration = collect($module->getNotificationDefinitions())->firstWhere('type', 'order_custom_email');
        $this->assertNotNull($declaration, 'order_custom_email 알림 정의가 선언되어야 한다');

        $helper = app(NotificationSyncHelper::class);
        $data = array_merge($declaration, ['extension_type' => 'module', 'extension_identifier' => 'sirsoft-ecommerce']);
        $definition = $helper->syncDefinition($data);
        foreach ($data['templates'] as $template) {
            $helper->syncTemplate($definition->id, $template);
        }
        app(NotificationDefinitionService::class)->invalidateAllCache();

        $this->useArrayMailer();
        $this->admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
    }

    /**
     * 비회원 주소로 보내면 알림 경유로 발송되고, 발송 로그(sent)와 활동 로그 훅이 남는다.
     *
     * @scenario surface=ecommerce_order_mail
     *
     * @effects order_mail_sent_through_notification
     *
     * @return void 반환값 없음
     */
    public function test_order_mail_is_sent_through_notification(): void
    {
        $hookFired = 0;
        $counter = function () use (&$hookFired) {
            $hookFired++;
        };
        HookManager::addAction('sirsoft-ecommerce.order.after_send_email', $counter);
        // 활동 로그 리스너를 실제 발행 페이로드에 붙인다 — 테스트 환경은 모듈 훅 구독을 자동 등록하지 않는다.
        $activityListener = app(OrderActivityLogListener::class);
        $logActivity = fn (array $data) => $activityListener->handleOrderAfterSendEmail($data);
        HookManager::addAction('sirsoft-ecommerce.order.after_send_email', $logActivity);

        $order = OrderFactory::new()->create();

        $this->send($order, 'guest-customer@example.test', "첫 줄\n<b>둘째 줄</b>")->assertOk();

        $mails = $this->sentMails();
        $this->assertCount(1, $mails);
        $this->assertSame('guest-customer@example.test', $mails[0]['to']);
        $this->assertStringContainsString($order->order_number, $mails[0]['subject']);
        $this->assertStringContainsString('첫 줄<br />', $mails[0]['html']);
        $this->assertStringContainsString('&lt;b&gt;둘째 줄&lt;/b&gt;', $mails[0]['html'], '관리자 입력 HTML 은 이스케이프된다');

        $log = NotificationLog::where('notification_type', 'order_custom_email')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status->value ?? $log->status);
        $this->assertNull($log->recipient_user_id, '입력 주소가 회원 이메일이 아니면 비회원으로 기록');
        $this->assertSame(1, $hookFired);

        // 활동 로그 리스너는 발행부가 실제로 넘기는 페이로드로 주문번호·주문 ID 를 남긴다(키 모양 불일치로 늘 빈 값이던 결함).
        $activity = ActivityLog::where('action', 'order.send_email')->latest('id')->first();
        $this->assertNotNull($activity, '주문 메일 발송은 활동 로그를 남긴다');
        $this->assertSame($order->order_number, $activity->description_params['order_number'] ?? null);
        $this->assertEquals($order->id, $activity->properties['order_id'] ?? null);

        HookManager::removeAction('sirsoft-ecommerce.order.after_send_email', $logActivity);

        HookManager::removeAction('sirsoft-ecommerce.order.after_send_email', $counter);
    }

    /**
     * 주문 회원의 이메일로 보내면 회원 수신자로 기록된다.
     *
     * @scenario surface=ecommerce_order_mail
     *
     * @effects order_mail_member_recipient
     *
     * @return void 반환값 없음
     */
    public function test_member_email_is_recorded_as_member(): void
    {
        $member = User::factory()->create(['email' => 'member-order@example.test']);
        $order = OrderFactory::new()->create(['user_id' => $member->id]);

        $this->send($order, 'member-order@example.test', '안내')->assertOk();

        $log = NotificationLog::where('notification_type', 'order_custom_email')->latest('id')->first();
        $this->assertSame($member->id, (int) $log->recipient_user_id);
    }

    /**
     * 이커머스 메일 채널이 꺼져 있으면 422 안내, 메일 0, 발송 로그 skipped, 활동 로그 훅 미발화.
     *
     * @scenario surface=ecommerce_order_mail
     *
     * @effects order_mail_channel_off_422
     *
     * @return void 반환값 없음
     */
    public function test_channel_off_answers_422(): void
    {
        $hookFired = 0;
        $counter = function () use (&$hookFired) {
            $hookFired++;
        };
        HookManager::addAction('sirsoft-ecommerce.order.after_send_email', $counter);
        // 이커머스 확장 단위 메일 토글 OFF (환경설정 > 알림 채널 저장값과 같은 판정 지점)
        $channelOff = function ($enabled, $type, $identifier, $channel) {
            return ($type === 'module' && $identifier === 'sirsoft-ecommerce' && $channel === 'mail') ? false : $enabled;
        };
        HookManager::addFilter('core.notification.channel_enabled', $channelOff);

        $order = OrderFactory::new()->create();
        $response = $this->send($order, 'blocked@example.test', '안내');

        $response->assertStatus(422);
        $this->assertSame(__('sirsoft-ecommerce::messages.orders.email_channel_disabled'), $response->json('message'));
        $this->assertSame([], $this->sentMails());
        $log = NotificationLog::where('notification_type', 'order_custom_email')->latest('id')->first();
        $this->assertSame('skipped', $log->status->value ?? $log->status);
        $this->assertSame(0, $hookFired);

        HookManager::removeAction('sirsoft-ecommerce.order.after_send_email', $counter);
        HookManager::removeFilter('core.notification.channel_enabled', $channelOff);
    }

    /**
     * 주문 메일 발송 API 를 호출합니다.
     *
     * @param  Order  $order  주문
     * @param  string  $email  수신 이메일
     * @param  string  $message  본문
     * @return TestResponse 응답
     */
    private function send(Order $order, string $email, string $message)
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/send-email", [
                'email' => $email,
                'message' => $message,
            ]);
    }
}
