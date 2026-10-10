<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\PayNhnkcp\Listeners\RegisterPgProviderListener;
use Plugins\Sirsoft\PayNhnkcp\Services\KcpSoapService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;
use Tests\Concerns\ForgesRequestHost;

/**
 * NHN KCP 결제 결과 이동·PG 등록 주소가 요청 Host·클라이언트 값을 따르지 않는지 검증합니다.
 */
class NhnkcpHostTrustTest extends PluginTestCase
{
    use ForgesRequestHost;

    private const CALLBACK_PATH = '/plugins/sirsoft-pay_nhnkcp/payment/callback';

    /**
     * 사이트 주소를 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->pinForgedHostSite();
    }

    /**
     * 신뢰 프록시 상태를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->resetForgedHostProxies();
        parent::tearDown();
    }

    /**
     * 결제 콜백 검증 실패의 이동은 위조 Host·외부 Referer 와 무관하게 사이트 주소다.
     *
     * @scenario host_header=forged, sink=payment_result_redirect
     *
     * @effects payment_result_redirect_uses_site_address
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_invalid_callback_redirects_to_site_address(string $root, array $server): void
    {
        $this->trustProxyForForgedHost($server);

        $response = $this->withServerVariables($server + ['HTTP_REFERER' => 'https://evil.example/landing'])
            ->post($root.self::CALLBACK_PATH, []);

        $this->assertSame(self::forgedHostSite().'/shop/checkout?error=invalid_params', $response->headers->get('Location'));
    }

    /**
     * 클레임 결제 결제창 실패의 신청서 복귀도 사이트 주소다.
     *
     * @scenario host_header=forged, sink=claim_result_redirect
     *
     * @effects claim_result_redirect_uses_site_address
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_claim_window_failure_redirects_to_site_address(string $root, array $server): void
    {
        $this->trustProxyForForgedHost($server);
        $order = OrderFactory::new()->create(['user_id' => User::factory()->create()->id, 'order_number' => 'ORD-HOST-2', 'currency' => 'KRW']);
        $return = OrderReturnFactory::new()->create([
            'order_id' => $order->id,
            'return_number' => 'RT-HOST-2',
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => 3000,
        ]);
        ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-HOST-2',
            'pg_provider' => 'nhnkcp',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ]);

        $response = $this->withServerVariables($server)->post($root.self::CALLBACK_PATH, [
            'res_cd' => '9999',
            'res_msg' => 'probe',
            'ordr_idxx' => 'CP-HOST-2',
        ]);

        $this->assertOnForgedHostSite($response->headers->get('Location'), '/mypage/claims/RT-HOST-2?');
    }

    /**
     * 모바일 결제창 실패·사용자 취소 결과의 클레임 결제 신청서 복귀도 사이트 주소다.
     *
     * KCP 모바일은 결과를 같은 Ret_URL 로 돌려주지만 취소·실패에는 enc_data·enc_info 가 실리지 않는다.
     *
     * @scenario host_header=forged, sink=claim_result_redirect
     *
     * @effects claim_result_redirect_uses_site_address
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_mobile_claim_window_failure_redirects_to_site_address(string $root, array $server): void
    {
        $this->trustProxyForForgedHost($server);
        $order = OrderFactory::new()->create(['user_id' => User::factory()->create()->id, 'order_number' => 'ORD-HOST-3', 'currency' => 'KRW']);
        $return = OrderReturnFactory::new()->create([
            'order_id' => $order->id,
            'return_number' => 'RT-HOST-3',
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => 3000,
        ]);
        $payment = ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-HOST-3',
            'pg_provider' => 'nhnkcp',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ]);

        $failed = $this->withServerVariables($server)->post($root.self::CALLBACK_PATH, [
            'res_cd' => '8001',
            'res_msg' => 'probe',
            'ordr_idxx' => 'CP-HOST-3',
        ]);
        $this->assertSame(
            self::forgedHostSite().ClaimPaymentRedirect::fail($payment, '8001'),
            $failed->headers->get('Location'),
        );

        $cancelled = $this->withServerVariables($server)->post($root.self::CALLBACK_PATH, [
            'res_cd' => '3001',
            'res_msg' => 'cancelled by user',
            'ordr_idxx' => 'CP-HOST-3',
        ]);
        $this->assertSame(
            self::forgedHostSite().ClaimPaymentRedirect::cancelled($payment),
            $cancelled->headers->get('Location'),
        );
    }

    /**
     * 모바일 승인키 발급은 클라이언트가 보낸 ret_url 이 아니라 서버가 사이트 주소로 만든 Ret_URL 을 KCP 에 등록한다.
     *
     * @scenario host_header=forged, sink=pg_callback_urls
     *
     * @effects pg_registered_return_url_is_server_site_address
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_mobile_approval_ignores_client_ret_url(string $root, array $server): void
    {
        $this->trustProxyForForgedHost($server);
        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn(['is_test_mode' => true, 'test_site_cd' => 'T0000', 'test_site_key' => 'TEST_KEY']);
        $this->app->instance(PluginSettingsService::class, $settings);

        $captured = [];
        $soap = $this->createMock(KcpSoapService::class);
        $soap->method('getApprovalKey')->willReturnCallback(function (...$args) use (&$captured) {
            $captured = $args;

            return ['approval_key' => 'APPROVAL_HOST', 'pay_url' => 'https://testpay.kcp.co.kr/php/mobile/mc_pay_form.php'];
        });
        $soap->method('getSiteCd')->willReturn('T0000');
        $soap->method('getEscrowSiteCd')->willReturn('T0000');
        $this->app->instance(KcpSoapService::class, $soap);

        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-HOST-MOB',
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'subtotal_amount' => 10000,
            'total_amount' => 10000,
            'total_due_amount' => 10000,
            'total_paid_amount' => 0,
            'total_tax_amount' => 10000,
            'total_vat_amount' => 909,
            'total_tax_free_amount' => 0,
            'currency' => 'KRW',
            'currency_snapshot' => [
                'base_currency' => 'KRW',
                'order_currency' => 'KRW',
                'base_unit' => 1,
                'exchange_rates' => ['KRW' => ['rate' => 1, 'rounding_unit' => '1', 'rounding_method' => 'round', 'decimal_places' => 0, 'base_unit' => 1]],
            ],
        ]);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
        ]);

        $response = $this->actingAs($user)->withServerVariables($server)
            ->postJson($root.'/api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key', [
                'order_number' => $order->order_number,
                'amount' => 10000,
                'good_name' => '테스트 상품',
                'pay_method' => 'card',
                'ret_url' => 'https://evil.example/collect',
            ]);

        $response->assertOk();
        $siteCallback = self::forgedHostSite().self::CALLBACK_PATH;
        $this->assertSame($siteCallback, $response->json('data.fields.Ret_URL'));
        $this->assertContains($siteCallback, $captured, 'SOAP 승인키 요청의 Ret_URL 이 사이트 주소여야 합니다.');
        $this->assertNotContains('https://evil.example/collect', $captured);
    }

    /**
     * 프런트가 PG 로 보내는 결과 수신 주소(Ret_URL)는 사이트 주소로 만든 절대 URL 이다.
     *
     * @scenario host_header=forged, sink=pg_callback_urls
     *
     * @effects client_config_callback_urls_are_site_absolute
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_client_config_callback_urls_are_site_absolute(string $root, array $server): void
    {
        $this->bindForgedRequest($root, $server, '/api/plugins/sirsoft-ecommerce/client-config');

        $config = app(RegisterPgProviderListener::class)->getClientConfig([], 'nhnkcp');

        $this->assertSame(self::forgedHostSite().self::CALLBACK_PATH, $config['callback_urls']['callback'] ?? null);
    }

    /**
     * 관리자에게 안내하는 입금통보 주소는 접속한 주소가 아니라 사이트 주소다.
     *
     * @scenario host_header=forged, sink=admin_notify_url
     *
     * @effects admin_notify_url_uses_site_address
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_admin_vbank_notify_url_uses_site_address(string $root, array $server): void
    {
        $this->trustProxyForForgedHost($server);
        $admin = $this->createAdminUser(['sirsoft-ecommerce.settings.read']);

        $response = $this->actingAs($admin)->withServerVariables($server)
            ->getJson($root.'/api/plugins/sirsoft-pay_nhnkcp/admin/vbank-notify-url');

        $response->assertOk()
            ->assertJsonPath('data.url', self::forgedHostSite().'/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify')
            ->assertJsonPath('data.escrow_common_notify_url', self::forgedHostSite().'/plugins/sirsoft-pay_nhnkcp/payment/escrow-common-notify');
    }

    /**
     * 테스트 모드 모의입금 안내의 입금통보 주소도 접속한 주소가 아니라 사이트 주소다.
     *
     * @scenario host_header=forged, sink=mock_deposit_notify_url
     *
     * @effects mock_deposit_notify_url_uses_site_address
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_mock_deposit_notify_url_uses_site_address(string $root, array $server): void
    {
        $this->trustProxyForForgedHost($server);
        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn(['is_test_mode' => true]);
        $this->app->instance(PluginSettingsService::class, $settings);

        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-KCP-HOST-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'total_amount' => 1000,
            'total_due_amount' => 1000,
            'total_paid_amount' => 0,
            'paid_at' => null,
        ]);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'nhnkcp',
            'transaction_id' => 'KCP_TNO_HOST_001',
            'vbank_number' => '12345678901234',
            'paid_amount_local' => 0,
            'paid_at' => null,
            'payment_meta' => [],
        ]);

        $response = $this->actingAs($user)->withServerVariables($server)
            ->getJson($root."/api/plugins/sirsoft-pay_nhnkcp/user/orders/{$order->order_number}/vbank-mock-deposit-info");

        $response->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.notify_url', self::forgedHostSite().'/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify');
    }

    /**
     * PG 서버가 부르는 입금통보·에스크로 공통통보 주소의 잘못된 요청은 직전 페이지(Referer)·요청 Host 로 돌려보내지 않고 422 로 끝난다.
     *
     * @scenario host_header=forged, sink=pg_server_notify
     *
     * @effects invalid_server_notify_returns_422_without_redirect
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_invalid_server_notify_returns_422_without_redirect(string $root, array $server): void
    {
        $this->trustProxyForForgedHost($server);

        foreach ([
            '/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify',
            '/plugins/sirsoft-pay_nhnkcp/payment/escrow-common-notify',
        ] as $path) {
            $response = $this->withServerVariables($server + ['HTTP_REFERER' => 'https://evil.example/landing'])
                ->post($root.$path, []);

            $this->assertSame(422, $response->getStatusCode(), $path);
            $this->assertNull($response->headers->get('Location'), $path);
        }
    }
}
