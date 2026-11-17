<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Notifications;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 알림 링크와 밖으로 나가는 링크의 주소(`G7_PUBLIC_LINK_URL` → `core.public_link_url`) 테스트 (#888)
 *
 * 공개 링크 주소를 정하면 회원·운영자에게 가는 주문·클레임 알림의 링크와 사이트 링크가 그 주소이고, 비워 두면
 * 지금처럼 사이트 주소인지 잠근다.
 */
class PublicLinkUrlNotificationTest extends ModuleTestCase
{
    /**
     * 사이트 주소·상점 접두를 고정하고 사용자 화면 URL 을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://site.example.test',
            'g7_settings.core.general.user_screen_url' => '',
            'g7_settings.modules.sirsoft-ecommerce.basic_info.route_path' => 'shop',
            'g7_settings.modules.sirsoft-ecommerce.basic_info.no_route' => false,
            'core.public_link_url' => null,
        ]);
    }

    /**
     * 공개 링크 주소를 정하면 회원용·운영자용 주문 알림과 운영자용 클레임 알림의 링크가 그 주소입니다.
     *
     * @scenario case=configured
     *
     * @effects order_and_claim_links_use_public_link_url
     *
     * @return void 반환값 없음
     */
    public function test_configured_links_use_public_link_url(): void
    {
        config(['core.public_link_url' => 'https://public.example.test']);

        [$member, $staffData] = $this->orderNotificationData();
        $this->assertSame('https://public.example.test', $member['site_url']);
        $this->assertStringStartsWith('https://public.example.test/', $member['order_url']);
        $this->assertSame('https://public.example.test', $staffData['site_url']);
        $this->assertStringStartsWith('https://public.example.test/admin/ecommerce/orders/', $staffData['order_url']);

        $listener = app(EcommerceNotificationDataListener::class);
        $adminOrder = $listener->extractData($this->emptyResult(), 'new_order_admin', [$this->order()])['data'];
        $this->assertSame('https://public.example.test', $adminOrder['site_url']);

        $claimStaff = $this->claimStaffData();
        $this->assertSame('https://public.example.test', $claimStaff['site_url']);
        $this->assertStringStartsWith('https://public.example.test/admin/ecommerce/claims', $claimStaff['claim_url']);
    }

    /**
     * 공개 링크 주소를 정하지 않으면 주문·클레임 알림 링크는 지금처럼 사이트 주소입니다.
     *
     * @scenario case=unset
     *
     * @effects order_and_claim_links_keep_site_url
     *
     * @return void 반환값 없음
     */
    public function test_unset_keeps_site_url(): void
    {
        [$member, $staffData] = $this->orderNotificationData();
        $this->assertSame('https://site.example.test', $member['site_url']);
        $this->assertSame('https://site.example.test', $staffData['site_url']);
        $this->assertStringStartsWith('https://site.example.test/admin/ecommerce/orders/', $staffData['order_url']);

        $claimStaff = $this->claimStaffData();
        $this->assertSame('https://site.example.test', $claimStaff['site_url']);
        $this->assertStringStartsWith('https://site.example.test/admin/ecommerce/claims', $claimStaff['claim_url']);
    }

    /**
     * 회원 주문 알림 데이터와 주문 화면 권한이 있는 운영자에게 맞춘 데이터를 만듭니다.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [회원 데이터, 운영자 데이터]
     */
    private function orderNotificationData(): array
    {
        $listener = app(EcommerceNotificationDataListener::class);
        $member = $listener->extractData($this->emptyResult(), 'order_confirmed', [$this->order()])['data'];
        $staff = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $staffData = $listener->personalizeOrderData($member, $staff, 'order_confirmed', ['order_number' => 'O-888']);

        return [$member, $staffData];
    }

    /**
     * 클레임 화면 권한이 있는 운영자에게 맞춘 클레임 알림 데이터를 만듭니다.
     *
     * @return array<string, mixed> 운영자 데이터
     */
    private function claimStaffData(): array
    {
        $staff = $this->createAdminUser(['sirsoft-ecommerce.claims.read']);

        return app(ClaimNotificationDataListener::class)->personalizeClaimData(
            ['claim_url' => '', 'site_url' => ''],
            $staff,
            'claim_withdrawn',
            ['claim_number' => 'C-888'],
        );
    }

    /**
     * 저장하지 않은 주문 하나를 만듭니다.
     *
     * @return Order 주문
     */
    private function order(): Order
    {
        $order = new Order(['order_number' => 'O-888', 'total_amount' => 1000, 'total_paid_amount' => 1000]);
        $order->setAttribute('user_id', 1);
        $order->setRelation('user', new User(['name' => '회원']));
        $order->setRelation('shippingAddress', new OrderAddress(['orderer_name' => '회원', 'orderer_email' => 'm@example.test']));

        return $order;
    }

    /**
     * extract_data 기본 구조를 반환합니다.
     *
     * @return array{notifiable: null, notifiables: null, data: array, context: array} 기본 구조
     */
    private function emptyResult(): array
    {
        return ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
    }
}
