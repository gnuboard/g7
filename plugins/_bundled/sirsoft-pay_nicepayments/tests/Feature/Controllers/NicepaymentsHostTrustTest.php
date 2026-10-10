<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Controllers;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\PayNicepayments\Listeners\RegisterPgProviderListener;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;
use Tests\Concerns\ForgesRequestHost;

/**
 * 나이스페이먼츠 결과 이동·PG 전송 주소가 요청 Host·Referer 를 따르지 않는지 검증합니다.
 *
 * 플러그인 자체 절대화 헬퍼(UrlHelper)를 코어 SiteUrl 로 수렴한 뒤의 회귀도 이 테스트가 소유한다
 * (종전 헬퍼의 위조 Host 단위 케이스는 코어 `SiteUrlTest` 로 옮겼다).
 */
class NicepaymentsHostTrustTest extends PluginTestCase
{
    use ForgesRequestHost;

    private const CALLBACK_PATH = '/plugins/sirsoft-pay_nicepayments/payment/callback';

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
     * 인증 단계 실패의 클레임 결제 신청서 복귀도 사이트 주소다.
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
    public function test_claim_auth_failure_redirects_to_site_address(string $root, array $server): void
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
        ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-HOST-4',
            'pg_provider' => 'nicepayments',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ]);

        $response = $this->withServerVariables($server)->post($root.self::CALLBACK_PATH, [
            'AuthResultCode' => '9999',
            'AuthResultMsg' => 'probe',
            'Moid' => 'CP-HOST-4',
        ]);

        $this->assertOnForgedHostSite($response->headers->get('Location'), '/mypage/claims/RT-HOST-4?');
    }

    /**
     * 프런트가 PG 로 보내는 결과 수신 주소(ReturnURL)는 사이트 주소로 만든 절대 URL 이다.
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
    public function test_client_config_callback_url_is_site_absolute(string $root, array $server): void
    {
        $this->bindForgedRequest($root, $server, '/api/plugins/sirsoft-ecommerce/client-config');

        $config = app(RegisterPgProviderListener::class)->getClientConfig([], 'nicepayments');

        $this->assertSame(self::forgedHostSite().self::CALLBACK_PATH, $config['callback_url'] ?? null);
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
            ->getJson($root.'/api/plugins/sirsoft-pay_nicepayments/admin/vbank-notify-url');

        $response->assertOk()->assertJsonPath('data.url', self::forgedHostSite().'/plugins/sirsoft-pay_nicepayments/payment/vbank-notify');
    }

    /**
     * PG 서버가 부르는 입금통보·결제 서명 주소의 잘못된 요청은 직전 페이지(Referer)·요청 Host 로 돌려보내지 않고 422 로 끝난다.
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
            '/plugins/sirsoft-pay_nicepayments/payment/vbank-notify',
            '/plugins/sirsoft-pay_nicepayments/payment/sign-data',
        ] as $path) {
            $response = $this->withServerVariables($server + ['HTTP_REFERER' => 'https://evil.example/landing'])
                ->post($root.$path, []);

            $this->assertSame(422, $response->getStatusCode(), $path);
            $this->assertNull($response->headers->get('Location'), $path);
        }
    }
}
