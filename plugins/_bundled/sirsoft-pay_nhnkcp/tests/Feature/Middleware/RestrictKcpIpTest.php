<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Middleware;

use App\Services\PluginSettingsService;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * RestrictKcpIp 미들웨어 회귀 테스트
 *
 * 적용 라우트: vbank-notify, escrow-common-notify (KCP 서버 발신 webhook)
 *   - 테스트 모드 (is_test_mode=true): 우회 — 개발/모의입금 흐름 허용
 *   - 운영 모드 (is_test_mode=false): 화이트리스트 외 IP 403 차단
 *
 * 그누보드5 settle_kcp_common.php 의 $default['de_card_test'] 분기와 동일.
 */
class RestrictKcpIpTest extends PluginTestCase
{
    private const KCP_WHITELIST_IP = '203.238.36.58';

    private const KCP_PANGYO_IP = '103.215.144.173';

    private const UNAUTHORIZED_IP = '1.2.3.4';

    private function mockSettings(bool $isTestMode): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn([
            'is_test_mode' => $isTestMode,
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ]);
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    private function mockSettingsWithoutMode(): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn([
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ]);
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    private function postVbankNotify(string $remoteIp): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remoteIp])
            ->post('/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify', [
                'tno' => 'KCP_TNO_TEST',
                'order_no' => 'ORD-IP-TEST',
                'tx_cd' => 'TX00',
                'op_cd' => '50',
                'ipgm_mnyx' => 30000,
            ]);
    }

    /**
     * @effects pay_ip_guard_matched_for_webhook_routes
     */
    public function test_blocks_unauthorized_ip_in_live_mode(): void
    {
        $this->mockSettings(isTestMode: false);

        $response = $this->postVbankNotify(self::UNAUTHORIZED_IP);

        $response->assertForbidden();
    }

    /**
     * self-gate 타게팅 정밀도 회귀: IP 가드는 브라우저 POST 콜백(payment.callback)에는
     * 부착되지 않아야 한다. 콜백은 사용자 IP 라 화이트리스트로 차단하면 결제 실패.
     * 미인가 IP 로 콜백을 호출해도 IP 가드에 의한 403 이 발생하지 않음을 단언한다
     * (컨트롤러는 검증 실패로 302 redirect 하지만 핵심은 403(IP 차단)이 아니라는 것).
     *
     * @effects pay_ip_guard_not_matched_for_browser_callback
     */
    public function test_ip_guard_not_applied_to_browser_callback_route(): void
    {
        $this->mockSettings(isTestMode: false);

        $response = $this->withServerVariables(['REMOTE_ADDR' => self::UNAUTHORIZED_IP])
            ->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', [
                'res_cd' => 'XXXX',
                'ordr_idxx' => 'ORD-CALLBACK-TEST',
            ]);

        // IP 가드가 붙었다면 403 이었을 것 — 붙지 않았으므로 403 이 아니어야 한다.
        $this->assertNotSame(403, $response->getStatusCode(), 'IP 가드가 브라우저 콜백에 잘못 부착되어 결제 콜백을 차단했습니다 (정밀도 회귀).');
    }

    public function test_missing_test_mode_setting_fails_closed_for_unauthorized_ip(): void
    {
        $this->mockSettingsWithoutMode();

        $response = $this->postVbankNotify(self::UNAUTHORIZED_IP);

        $response->assertForbidden();
    }

    public function test_allows_whitelisted_kcp_ip_in_live_mode(): void
    {
        $this->mockSettings(isTestMode: false);

        $response = $this->postVbankNotify(self::KCP_WHITELIST_IP);

        // 미들웨어 통과 후 컨트롤러까지 도달 → 200 + KCP result HTML 응답.
        // (주문 미존재로 컨트롤러는 result=0000 응답하지만 핵심은 403 이 아니라는 것)
        $response->assertOk();
        $this->assertStringContainsString('name="result"', $response->getContent());
    }

    public function test_allows_pangyo_idc_ip_in_live_mode(): void
    {
        $this->mockSettings(isTestMode: false);

        $response = $this->postVbankNotify(self::KCP_PANGYO_IP);

        $response->assertOk();
    }

    /**
     * 테스트 모드: 모든 IP 허용 — 개발 환경 + KCP testadmin 모의입금 webhook 흐름 보장
     * (그누보드5 settle_kcp_common.php 의 $default['de_card_test'] 우회 동일).
     */
    public function test_bypasses_check_in_test_mode(): void
    {
        $this->mockSettings(isTestMode: true);

        $response = $this->postVbankNotify(self::UNAUTHORIZED_IP);

        $response->assertOk();
    }

    /**
     * 화이트리스트 8개 IP 전수 통과 (운영 모드).
     */
    public function test_all_whitelisted_ips_pass_in_live_mode(): void
    {
        $this->mockSettings(isTestMode: false);

        $whitelist = [
            '203.238.36.58',
            '203.238.36.160',
            '203.238.36.161',
            '203.238.36.173',
            '203.238.36.178',
            '103.215.144.173',
            '103.215.144.174',
            '103.215.145.30',
        ];

        foreach ($whitelist as $ip) {
            $response = $this->postVbankNotify($ip);
            $response->assertStatus(200, "Whitelisted IP {$ip} should not be blocked");
        }
    }
}
