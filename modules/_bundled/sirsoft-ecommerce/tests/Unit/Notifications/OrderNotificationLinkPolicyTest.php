<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Notifications;

use App\Notifications\GuestNotifiable;
use App\Services\NotificationRecipientCapability;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 알림 링크 정책 (2층 — 클레임·게시판과 동형)
 *
 * **이 파일에는 알림 유형 이름이 판정에 쓰이지 않는다.** 링크를 관리자 주소로 올릴지는
 * 「이 사람이 그 화면을 열 수 있는가」(`orders.read`)로만 갈린다 — 유형 이름으로 가르면
 * 관리자 수신 알림이 늘 때마다 그 목록을 손으로 늘려야 하고, 빠뜨린 유형은 운영자를
 * 마이페이지로 보낸다(그것이 이번 계획이 고친 N1 결함의 모양이다).
 *
 * 정책:
 * - 주문 화면을 열 수 있는 수신자 → `/admin/ecommerce/orders/{번호}`
 * - 그 밖의 회원 · 비회원 → 관리자 주소가 **포함되지 않는다**
 */
class OrderNotificationLinkPolicyTest extends ModuleTestCase
{
    /** 링크 승격 판정에 쓰는 권한 — 리스너와 같은 값이어야 이 테스트가 그 축을 잰다. */
    private const ORDER_READ_PERMISSION = 'sirsoft-ecommerce.orders.read';

    protected EcommerceNotificationDataListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = app(EcommerceNotificationDataListener::class);
    }

    /**
     * 링크는 **수신자**를 따른다 — 알림 유형이 아니라.
     *
     * @effects order_links_follow_the_recipient_not_the_notification_type
     */
    public function test_order_links_follow_the_recipient_not_the_notification_type(): void
    {
        $staff = $this->createAdminUser([self::ORDER_READ_PERMISSION]);
        $customer = $this->createUser();
        $guest = new GuestNotifiable('guest@example.test', '비회원');

        $orderNumber = 'MS20260914000001';
        $data = ['order_url' => config('app.url').'/mypage/orders/'.$orderNumber];
        $context = ['order_number' => $orderNumber];

        // 유형은 리스너가 소관을 가리는 데만 쓴다 — 판정은 권한이 한다.
        $types = ['order_confirmed', 'order_completed', 'new_order_admin'];

        foreach ($types as $type) {
            $staffData = $this->listener->personalizeOrderData($data, $staff, $type, $context);
            $this->assertSame(
                config('app.url').'/admin/ecommerce/orders/'.$orderNumber,
                $staffData['order_url'],
                "{$type}: 주문 화면을 열 수 있는 운영자는 자기가 일할 수 있는 화면으로 가야 합니다."
            );

            foreach (['회원' => $customer, '비회원' => $guest] as $label => $recipient) {
                $recipientData = $this->listener->personalizeOrderData($data, $recipient, $type, $context);
                $this->assertStringNotContainsString(
                    '/admin/',
                    $recipientData['order_url'],
                    "{$type}: {$label} 수신자에게 관리자 주소를 보내면 예외도 404 도 없이 빈 화면이 열립니다."
                );
            }
        }
    }

    /**
     * 주문번호가 비면 승격하지 않는다 — 번호 없는 관리자 주소는 404 다.
     *
     * @effects empty_order_number_is_not_promoted
     */
    public function test_empty_order_number_is_not_promoted(): void
    {
        $staff = $this->createAdminUser([self::ORDER_READ_PERMISSION]);
        $data = ['order_url' => config('app.url').'/mypage/orders'];

        $result = $this->listener->personalizeOrderData($data, $staff, 'order_confirmed', ['order_number' => '']);

        $this->assertStringNotContainsString('/admin/', $result['order_url']);
    }

    /**
     * 주문 권한이 없는 운영자(예: 게시판 관리자)는 승격 대상이 아니다.
     *
     * 권한을 보지 않고 「관리자면 관리자 주소」로 가르면, 그 화면을 못 여는 사람에게
     * 관리자 주소를 준다 — 눌러도 403 이고 사유는 화면에 없다.
     *
     * @effects staff_without_order_permission_is_not_promoted
     */
    public function test_staff_without_the_order_permission_is_not_promoted(): void
    {
        $otherStaff = $this->createAdminUser(['sirsoft-board.posts.read']);
        $data = ['order_url' => config('app.url').'/mypage/orders/MS1'];

        $result = $this->listener->personalizeOrderData($data, $otherStaff, 'order_confirmed', ['order_number' => 'MS1']);

        $this->assertStringNotContainsString('/admin/', $result['order_url']);
    }

    /**
     * `order_url` 이 없는 알림은 건드리지 않는다.
     *
     * 없는 키를 만들어 넣으면 그 알림의 본문이 선언하지 않은 변수를 갖게 되고,
     * 1층 계약 테스트가 그것을 미선언 플레이스홀더로 잡는다.
     *
     * @effects personalize_leaves_notifications_without_an_order_url_alone
     */
    public function test_personalize_leaves_data_without_an_order_url_alone(): void
    {
        $staff = $this->createAdminUser([self::ORDER_READ_PERMISSION]);
        $data = ['name' => '{recipient_name}'];

        $result = $this->listener->personalizeOrderData($data, $staff, 'order_confirmed', ['order_number' => 'MS1']);

        $this->assertSame($data, $result);
    }

    /**
     * 판정이 수신자 수만큼 권한을 재조회하지 않는다 (관리자 수신 알림의 N+1).
     *
     * @effects order_capability_memoizes_per_request
     */
    public function test_capability_memoizes_the_permission_lookup(): void
    {
        $staff = $this->createAdminUser([self::ORDER_READ_PERMISSION]);
        $capability = app(NotificationRecipientCapability::class);

        $first = $capability->can($staff, self::ORDER_READ_PERMISSION);
        $second = $capability->can($staff, self::ORDER_READ_PERMISSION);

        $this->assertTrue($first);
        $this->assertSame($first, $second);
    }
}
