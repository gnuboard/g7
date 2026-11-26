<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit;

use App\Extension\HookManager;
use App\Models\User;
use App\Support\ScreenUrl;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Traits\HandlesOrderCreation;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Support\ClaimUrl;
use Modules\Sirsoft\Ecommerce\Support\EcommerceScreenUrl;
use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 화면 주소 래퍼(EcommerceScreenUrl)·클레임 주소·주문 응답 테스트 (F1-8~F1-11)
 *
 * 사람이 여는 주소는 상점 주소 접두(ShopPathResolver)를 매번 읽어 코어 화면 주소 헬퍼를 거치고, 관리자 링크는
 * 관리자 범위(사이트 주소), 주문 생성 응답의 이동 주소는 래퍼를, 결제 수단 식별 `pg_provider` 는 그대로인지 잠근다.
 */
class EcommerceScreenUrlTest extends ModuleTestCase
{
    /**
     * 사이트·사용자 화면 URL·상점 접두를 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://site.example.test',
            'g7_settings.core.general.user_screen_url' => 'https://front.example.test',
            'g7_settings.modules.sirsoft-ecommerce.basic_info.route_path' => 'store',
            'g7_settings.modules.sirsoft-ecommerce.basic_info.no_route' => false,
        ]);
    }

    /**
     * 필터를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(ScreenUrl::FILTER);

        parent::tearDown();
    }

    /**
     * 상점 키는 상점 접두를 쓰고, 마이페이지 키는 접두 없이, 절대 주소는 사용자 화면 URL 입니다.
     *
     * @scenario case=wrapper
     *
     * @effects shop_keys_use_shop_prefix, mypage_keys_absolute_user_screen
     *
     * @return void 반환값 없음
     */
    public function test_wrapper_paths(): void
    {
        $prefix = ShopPathResolver::base();

        $this->assertSame($prefix.'/products/P-001', EcommerceScreenUrl::product('P-001'));
        $this->assertSame($prefix.'/orders/O-1/complete', EcommerceScreenUrl::orderComplete('O-1'));
        $this->assertSame($prefix.'/guest/orders', EcommerceScreenUrl::guestOrders());
        $this->assertSame('https://front.example.test/mypage/orders/O-1', EcommerceScreenUrl::mypageOrderDetail('O-1', absolute: true));
        $this->assertSame('https://site.example.test/admin/ecommerce/orders/O-1', EcommerceScreenUrl::admin('ecommerce.admin.order', '/admin/ecommerce/orders/{order_number}', ['order_number' => 'O-1'], absolute: true));
    }

    /**
     * 클레임 주소는 래퍼를 거쳐 필터를 따르고, 상수는 G7 기본 경로로 남습니다.
     *
     * @scenario case=claim
     *
     * @effects claim_urls_filtered, claim_constants_default
     *
     * @return void 반환값 없음
     */
    public function test_claim_urls(): void
    {
        HookManager::addFilter(ScreenUrl::FILTER, fn (string $path, string $key, array $params) => $key === 'mypage.claim.detail' ? '/account/returns/'.$params['claim_number'] : $path);

        $this->assertSame('/account/returns/C-9', ClaimUrl::memberDetail('C-9'));
        $this->assertSame('/mypage/claims', ClaimUrl::memberList());
        $this->assertSame('/admin/ecommerce/claims/C-9', ClaimUrl::adminDetail('C-9'));

        // 상수의 자리 이름은 화면 주소 헬퍼·필터 훅과 같은 snake_case 하나다 — 상수를 보고 필터를 쓴 개발자가 치환을 놓치지 않게
        $this->assertSame('/mypage/claims/{claim_number}', ClaimUrl::MEMBER_DETAIL);
        $this->assertSame('{shopBase}/guest/claims/{claim_number}', ClaimUrl::GUEST_DETAIL);
        $this->assertSame('/admin/ecommerce/claims/{claim_number}', ClaimUrl::ADMIN_DETAIL);
        $this->assertSame('/mypage/orders/{order_number}', ClaimUrl::MEMBER_ORDER_DETAIL);

        HookManager::addFilter(ScreenUrl::FILTER, fn (string $path, string $key, array $params) => $key === 'ecommerce.admin.claim.detail' ? '/ops/claims/'.($params['claim_number'] ?? 'MISSING') : $path);
        $this->assertSame('/ops/claims/C-9', ClaimUrl::adminDetail('C-9'));
    }

    /**
     * 주문 생성 응답 — 이동 주소는 래퍼를 거치고, PG 결제면 `pg_provider` 를 그대로 싣습니다.
     *
     * @scenario case=order_response
     *
     * @effects order_redirect_url_wrapped, pg_provider_kept
     *
     * @return void 반환값 없음
     */
    public function test_order_response_redirect_and_pg_provider(): void
    {
        HookManager::addFilter(ScreenUrl::FILTER, fn (string $path, string $key) => $key === 'ecommerce.order.complete' ? 'https://front.example.test/thanks' : $path);

        $builder = new class
        {
            use HandlesOrderCreation;

            /**
             * 시험용으로 응답 조립을 엽니다.
             *
             * @param  Order  $order  주문
             * @return array<string, mixed> 응답 데이터
             */
            public function build(Order $order): array
            {
                return $this->buildOrderResponseData($order, false, 'tosspayments', []);
            }
        };

        $order = new Order;
        $order->order_number = 'O-792';
        $data = $builder->build($order);

        $this->assertSame('https://front.example.test/thanks', $data['redirect_url']);
        $this->assertFalse($data['requires_pg_payment']);

        $source = (string) file_get_contents((new \ReflectionClass(HandlesOrderCreation::class))->getFileName());
        $this->assertStringContainsString("\$responseData['pg_provider'] = \"sirsoft-{\$pgProvider}\";", $source, 'pg_provider 응답 키는 외부 화면 결제 연동의 계약이다');
    }

    /**
     * 회원에게 가는 이커머스 알림의 {site_url} 은 사용자 화면 URL, 운영자에게 가는 알림은 사이트 주소입니다
     *. 주문 알림은 수신자가 주문 화면을 열 수 있는 운영자면 사이트 주소로 바뀝니다.
     *
     * @scenario case=notification_site_url
     *
     * @effects member_notification_site_url_user_screen, admin_notification_site_url_site
     *
     * @return void 반환값 없음
     */
    public function test_notification_site_url_follows_recipient(): void
    {
        $listener = app(EcommerceNotificationDataListener::class);
        $default = ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
        $order = new Order(['order_number' => 'O-792', 'total_amount' => 1000, 'total_paid_amount' => 1000]);
        $order->setAttribute('user_id', 1);
        $order->setRelation('user', new User(['name' => '회원']));
        $order->setRelation('shippingAddress', new OrderAddress(['orderer_name' => '회원', 'orderer_email' => 'm@example.test']));
        $inquiry = new ProductInquiry;
        $inquiry->setRelation('user', null);
        $inquiry->setRelation('product', null);
        $inquiry->setRelation('inquirable', null);

        $member = $listener->extractData($default, 'order_confirmed', [$order]);
        $this->assertSame('https://front.example.test', $member['data']['site_url']);
        $this->assertSame('https://front.example.test', $listener->extractData($default, 'inquiry_replied', [$inquiry])['data']['site_url']);
        $this->assertSame('https://front.example.test', $listener->extractData($default, 'mileage_expiring_soon', [new User(['name' => '회원']), 100, 'KRW', '2026-12-31', 500])['data']['site_url']);
        $this->assertSame('https://site.example.test', $listener->extractData($default, 'new_order_admin', [$order])['data']['site_url']);

        $staff = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $customer = $this->createUser();
        $context = ['order_number' => 'O-792'];
        $this->assertSame('https://site.example.test', $listener->personalizeOrderData($member['data'], $staff, 'order_confirmed', $context)['site_url']);
        $this->assertSame('https://front.example.test', $listener->personalizeOrderData($member['data'], $customer, 'order_confirmed', $context)['site_url']);
    }
}
