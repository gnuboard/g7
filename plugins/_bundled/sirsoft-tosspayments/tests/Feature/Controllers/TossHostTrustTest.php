<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\Tosspayments\Controllers\PaymentCallbackController;
use Plugins\Sirsoft\Tosspayments\Listeners\RegisterPgProviderListener;
use Plugins\Sirsoft\Tosspayments\Services\TossPaymentsApiService;
use Plugins\Sirsoft\Tosspayments\Support\ShopRedirectUrl;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;
use Tests\Concerns\ForgesRequestHost;

/**
 * 토스페이먼츠 결과 이동·PG 전송 주소가 요청 Host·Referer 를 따르지 않는지 검증합니다.
 *
 * 탐침은 상태를 바꾸지 않는 경로만 쓴다 — 주문이 없는 실패 콜백, 파라미터 없는 성공 콜백, 잘못된
 * 입력의 실패 콜백, 사용자 취소 코드의 클레임 결제 실패 콜백, 콜백 락이 잡힌 클레임 결제 성공 콜백.
 */
class TossHostTrustTest extends PluginTestCase
{
    use ForgesRequestHost;

    private const FAIL_PATH = '/plugins/sirsoft-tosspayments/payment/fail';

    private const SUCCESS_PATH = '/plugins/sirsoft-tosspayments/payment/success';

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
     * 플러그인 설정을 기본 복귀 주소로 고정합니다.
     *
     * @return void 반환값 없음
     */
    private function mockPluginSettings(): void
    {
        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')->willReturn([
            'is_test_mode' => true,
            'test_secret_key' => 'test_sk_mock_key',
            'redirect_success_url' => ShopRedirectUrl::DEFAULT_SUCCESS_URL,
            'redirect_fail_url' => ShopRedirectUrl::DEFAULT_FAIL_URL,
        ]);

        $this->app->instance(PluginSettingsService::class, $settingsMock);
    }

    /**
     * 주문 결제 실패·검증 실패 이동은 위조 Host·외부 Referer 와 무관하게 사이트 주소다.
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
    public function test_order_result_redirects_use_site_address(string $root, array $server): void
    {
        $this->mockPluginSettings();
        $this->trustProxyForForgedHost($server);
        $server += ['HTTP_REFERER' => 'https://evil.example/landing'];

        $fail = $this->withServerVariables($server)
            ->get($root.self::FAIL_PATH.'?code=PROBE704&orderId=PROBE704-NONE');
        $this->assertOnForgedHostSite($fail->headers->get('Location'), '/shop/checkout?');

        $success = $this->withServerVariables($server)->get($root.self::SUCCESS_PATH);
        $this->assertSame(self::forgedHostSite().'/shop/checkout?error=invalid_params', $success->headers->get('Location'));

        $invalid = $this->withServerVariables($server)
            ->get($root.self::FAIL_PATH.'?code[]=x&orderId[]=x');
        $this->assertSame(self::forgedHostSite().'/shop/checkout?error=invalid_params', $invalid->headers->get('Location'));
    }

    /**
     * 클레임 결제 실패 콜백(사용자 취소)의 신청서 복귀도 사이트 주소다.
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
    public function test_claim_result_redirect_uses_site_address(string $root, array $server): void
    {
        $this->mockPluginSettings();
        $this->trustProxyForForgedHost($server);

        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-HOST-1',
            'currency' => 'KRW',
        ]);
        $return = OrderReturnFactory::new()->create([
            'order_id' => $order->id,
            'return_number' => 'RT-HOST-1',
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => 3000,
        ]);
        $payment = ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-HOST-1',
            'pg_provider' => 'tosspayments',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ]);

        $response = $this->withServerVariables($server)->get($root.self::FAIL_PATH.'?'.http_build_query([
            'code' => 'PAY_PROCESS_CANCELED',
            'message' => 'cancelled by user',
            'orderId' => $payment->payment_number,
        ]));

        $this->assertOnForgedHostSite($response->headers->get('Location'), '/mypage/claims/RT-HOST-1?');
    }

    /**
     * 같은 결제번호의 콜백이 처리 중(락 보유)일 때 클레임 결제 성공 콜백의 재시도 안내 복귀도 사이트 주소다.
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
    public function test_busy_claim_callback_redirect_uses_site_address(string $root, array $server): void
    {
        $this->mockPluginSettings();
        $this->trustProxyForForgedHost($server);
        Http::fake();

        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-HOST-2',
            'currency' => 'KRW',
        ]);
        $return = OrderReturnFactory::new()->create([
            'order_id' => $order->id,
            'return_number' => 'RT-HOST-2',
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => 3000,
        ]);
        $payment = ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-HOST-2',
            'pg_provider' => 'tosspayments',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ]);
        $this->bindImpatientCallbackController();

        $held = Cache::lock('sirsoft-tosspayments:callback:success:'.sha1($payment->payment_number), 30);
        $this->assertTrue($held->get());

        try {
            $response = $this->withServerVariables($server)->get($root.self::SUCCESS_PATH.'?'.http_build_query([
                'paymentKey' => 'pk_host_busy',
                'orderId' => $payment->payment_number,
                'amount' => 3000,
            ]));
        } finally {
            $held->release();
        }

        $this->assertSame(
            self::forgedHostSite().ClaimPaymentRedirect::fail($payment, 'callback_locked'),
            $response->headers->get('Location'),
        );
    }

    /**
     * 콜백 락 대기 시간을 0초로 줄인 컨트롤러를 라우트에 묶습니다. (락 대기 초과를 즉시 재현)
     *
     * @return void 반환값 없음
     */
    private function bindImpatientCallbackController(): void
    {
        $this->app->bind(PaymentCallbackController::class, fn ($app) => new class($app->make(OrderProcessingService::class), $app->make(PluginSettingsService::class), $app->make(TossPaymentsApiService::class), $app->make(ClaimPaymentRepositoryInterface::class)) extends PaymentCallbackController
        {
            /**
             * 락 대기 시간을 0초로 줄여 대기 초과를 즉시 재현합니다.
             *
             * @return int 초
             */
            protected function callbackLockWaitSeconds(): int
            {
                return 0;
            }
        });
    }

    /**
     * 프런트가 PG 로 보내는 결과 수신 주소(successUrl·failUrl)는 사이트 주소로 만든 절대 URL 이다.
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

        $config = app(RegisterPgProviderListener::class)->getClientConfig([], 'tosspayments');

        $this->assertSame(self::forgedHostSite().self::SUCCESS_PATH, $config['callback_urls']['success'] ?? null);
        $this->assertSame(self::forgedHostSite().self::FAIL_PATH, $config['callback_urls']['fail'] ?? null);
    }

    /**
     * 관리자에게 안내하는 입금 웹훅 주소는 접속한 주소가 아니라 사이트 주소다.
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
    public function test_admin_webhook_url_uses_site_address(string $root, array $server): void
    {
        $this->trustProxyForForgedHost($server);
        $admin = $this->createAdminUser(['sirsoft-ecommerce.settings.read']);

        $response = $this->actingAs($admin)->withServerVariables($server)
            ->getJson($root.'/api/plugins/sirsoft-tosspayments/admin/webhook-url');

        $response->assertOk()->assertJsonPath('data.url', self::forgedHostSite().'/plugins/sirsoft-tosspayments/webhook/deposit');
    }

    /**
     * PG 서버가 부르는 입금·결제 상태 웹훅 주소의 잘못된 요청은 직전 페이지(Referer)·요청 Host 로 돌려보내지 않고 422 로 끝난다.
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
            '/plugins/sirsoft-tosspayments/webhook/deposit',
            '/plugins/sirsoft-tosspayments/webhook/payment-status',
        ] as $path) {
            $response = $this->withServerVariables($server + ['HTTP_REFERER' => 'https://evil.example/landing'])
                ->post($root.$path, []);

            $this->assertSame(422, $response->getStatusCode(), $path);
            $this->assertNull($response->headers->get('Location'), $path);
        }
    }
}
