<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\PayKginicis\Listeners\RegisterPgProviderListener;
use Plugins\Sirsoft\PayKginicis\Services\CbtCvsOperationsService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;
use Tests\Concerns\ForgesRequestHost;

/**
 * KG 이니시스 결제 결과 이동·PG 전송 주소가 요청 Host·Referer 를 따르지 않는지 검증합니다.
 *
 * 탐침은 상태를 바꾸지 않는 경로만 쓴다 — 본문 없는 결제 콜백(검증 실패), 결제창 실패 결과(PC·모바일)의
 * 클레임 결제 복귀, 설정·요약 조회.
 */
class KginicisHostTrustTest extends PluginTestCase
{
    use ForgesRequestHost;

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
     * PC·모바일·CBT 결제 콜백의 검증 실패는 Referer·위조 Host 가 아니라 사이트 주소 결제 실패 화면으로 간다.
     *
     * 회귀: PC·모바일 콜백의 검증 실패가 Laravel 기본 `previous()` 로 Referer(`https://evil.example/landing`)
     * 또는 요청 Host 루트로 이동했다.
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
    public function test_invalid_callbacks_redirect_to_site_fail_page(string $root, array $server): void
    {
        $this->trustProxyForForgedHost($server);
        $server += ['HTTP_REFERER' => 'https://evil.example/landing'];
        $expected = self::forgedHostSite().'/shop/checkout?error=invalid_params';

        foreach (['/payment/callback', '/payment/mobile/callback'] as $path) {
            $response = $this->withServerVariables($server)->post($root.'/plugins/sirsoft-pay_kginicis'.$path, []);
            $this->assertSame($expected, $response->headers->get('Location'), $path);
        }

        $cbt = $this->withServerVariables($server)->post($root.'/plugins/sirsoft-pay_kginicis/payment/cbt/callback', ['oid' => ['x']]);
        $this->assertSame($expected, $cbt->headers->get('Location'), 'cbt/callback');
    }

    /**
     * 결제창 실패 결과의 클레임 결제 신청서 복귀도 사이트 주소다.
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
        $order = OrderFactory::new()->create(['user_id' => User::factory()->create()->id, 'order_number' => 'ORD-HOST-3', 'currency' => 'KRW']);
        $return = OrderReturnFactory::new()->create([
            'order_id' => $order->id,
            'return_number' => 'RT-HOST-3',
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => 3000,
        ]);
        ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-HOST-3',
            'pg_provider' => 'kginicis',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ]);

        $response = $this->withServerVariables($server)->post($root.'/plugins/sirsoft-pay_kginicis/payment/callback', [
            'resultCode' => 'V801',
            'resultMsg' => 'probe',
            'orderNumber' => 'CP-HOST-3',
        ]);

        $this->assertOnForgedHostSite($response->headers->get('Location'), '/mypage/claims/RT-HOST-3?');
    }

    /**
     * 모바일 결제창 실패·사용자 취소 결과의 클레임 결제 신청서 복귀도 사이트 주소다.
     *
     * 모바일 표준 응답에는 P_OID 가 없어 결제번호를 P_NEXT_URL 쿼리(orderId)로 되돌려 받는다.
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
        $order = OrderFactory::new()->create(['user_id' => User::factory()->create()->id, 'order_number' => 'ORD-HOST-4', 'currency' => 'KRW']);
        $return = OrderReturnFactory::new()->create([
            'order_id' => $order->id,
            'return_number' => 'RT-HOST-4',
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => 3000,
        ]);
        $payment = ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-HOST-4',
            'pg_provider' => 'kginicis',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ]);
        $mobileCallback = $root.'/plugins/sirsoft-pay_kginicis/payment/mobile/callback?orderId=CP-HOST-4';

        $failed = $this->withServerVariables($server)->post($mobileCallback, [
            'P_STATUS' => '01',
            'P_RMESG1' => 'probe',
        ]);
        $this->assertSame(
            self::forgedHostSite().ClaimPaymentRedirect::fail($payment, '01'),
            $failed->headers->get('Location'),
        );

        $cancelled = $this->withServerVariables($server)->post($mobileCallback, [
            'P_STATUS' => '01',
            'P_RMESG1' => '사용자가 결제를 취소하였습니다',
        ]);
        $this->assertSame(
            self::forgedHostSite().ClaimPaymentRedirect::cancelled($payment),
            $cancelled->headers->get('Location'),
        );
    }

    /**
     * 프런트가 PG 로 보내는 결과·통보 주소와 CBT 입금통보 주소는 사이트 주소로 만든 절대 URL 이다.
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
    public function test_client_config_urls_are_site_absolute(string $root, array $server): void
    {
        $this->bindForgedRequest($root, $server, '/api/plugins/sirsoft-ecommerce/client-config');

        $config = app(RegisterPgProviderListener::class)->getClientConfig([], 'kginicis');
        $site = self::forgedHostSite().'/plugins/sirsoft-pay_kginicis/payment';

        $this->assertSame($site.'/callback', $config['callback_urls']['callback'] ?? null);
        $this->assertSame($site.'/close', $config['callback_urls']['close'] ?? null);
        $this->assertSame($site.'/mobile/callback', $config['callback_urls']['mobile_callback'] ?? null);
        $this->assertSame($site.'/mobile/vbank-notify', $config['callback_urls']['mobile_vbank_notify'] ?? null);
        $this->assertSame($site.'/cbt/callback', $config['callback_urls']['cbt_callback'] ?? null);
        $this->assertSame($site.'/cbt/cvs-notify', $config['cbt_extra_data']['payment']['cvs']['notiUrl'] ?? null);
    }

    /**
     * 브라우저가 G7Core.api 로 직접 부르는 주소(서명·닫힘 보고·CBT 토큰·해시·모바일 서명)는 API 라우트 주소다.
     *
     * 절대 URL 은 ApiClient 가 /api 접두를 붙이지 않으므로, 웹 경로(/plugins/...)로 내려 주면 라우트가 없는
     * 주소로 POST 가 나가 결제창이 열리지 않는다(#639 회귀 잠금).
     *
     * @scenario host_header=forged, sink=pg_callback_urls
     *
     * @effects browser_api_callback_urls_point_to_api_routes
     *
     * @return void 반환값 없음
     */
    public function test_browser_api_callback_urls_point_to_registered_api_routes(): void
    {
        $config = app(RegisterPgProviderListener::class)->getClientConfig([], 'kginicis');
        $site = rtrim((string) config('app.url'), '/');

        foreach (['signature', 'close_report', 'cbt_checkout_token', 'cbt_hash_data', 'mobile_signature'] as $key) {
            $url = $config['callback_urls'][$key] ?? '';
            $this->assertStringStartsWith($site.'/api/plugins/sirsoft-pay_kginicis/payment/', $url, $key);

            $route = app('router')->getRoutes()->match(
                Request::create(substr($url, strlen($site)), 'POST')
            );
            $this->assertContains('POST', $route->methods(), $key);
        }
    }

    /**
     * 관리자에게 안내하는 입금통보 주소와 CBT 운영 요약의 통보 주소는 사이트 주소다.
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
    public function test_admin_notify_urls_use_site_address(string $root, array $server): void
    {
        $this->trustProxyForForgedHost($server);
        $admin = $this->createAdminUser(['sirsoft-ecommerce.settings.read']);

        $response = $this->actingAs($admin)->withServerVariables($server)
            ->getJson($root.'/api/plugins/sirsoft-pay_kginicis/admin/vbank-notify-url');

        $response->assertOk()
            ->assertJsonPath('data.url', self::forgedHostSite().'/plugins/sirsoft-pay_kginicis/payment/vbank-notify')
            ->assertJsonPath('data.mobile_url', self::forgedHostSite().'/plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify');

        $order = OrderFactory::new()->create(['order_number' => 'ORD-HOST-CVS', 'currency' => 'JPY']);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
        ]);
        $this->bindForgedRequest($root, $server, '/api/plugins/sirsoft-pay_kginicis/admin/cbt/cvs/ORD-HOST-CVS');

        $summary = app(CbtCvsOperationsService::class)->summary('ORD-HOST-CVS');

        $this->assertSame(self::forgedHostSite().'/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify', $summary['notify_url'] ?? null);
    }

    /**
     * PG 서버가 부르는 PC·모바일 가상계좌·CBT 편의점 입금통보 주소의 잘못된 요청은 직전 페이지(Referer)·요청 Host 로 돌려보내지 않고 422 로 끝난다.
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
            '/plugins/sirsoft-pay_kginicis/payment/vbank-notify' => [],
            '/plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify' => [],
            // CBT 편의점 통보는 모든 필드가 선택이라 빈 본문은 통과한다 — 규칙 필드에 배열을 넣어 실패시킨다.
            '/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify' => ['tid' => ['x']],
        ] as $path => $payload) {
            $response = $this->withServerVariables($server + ['HTTP_REFERER' => 'https://evil.example/landing'])
                ->post($root.$path, $payload);

            $this->assertSame(422, $response->getStatusCode(), $path);
            $this->assertNull($response->headers->get('Location'), $path);
        }
    }
}
