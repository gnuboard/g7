<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Characterization;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\OrderAccess;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentLookup;
use Modules\Sirsoft\Ecommerce\Tests\Feature\Contracts\Support\RetryRestoreScenarios;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 재결제 복구 특성화 대조 — SignData 경로가 이커머스 결제 계약(restoreForRetry)으로 옮긴 뒤에도 녹화값과 같다 (#916, 부록 A5-1)
 *
 * 녹화 파일(`modules/_bundled/sirsoft-ecommerce/tests/Fixtures/payment-retry-restore/nicepayments-*.json`)은 계약 전환 전의
 * 이 플러그인 복구 코드(`PaymentCallbackController::restoreRetryableNicepayOrder`)를 그대로 돌려 남긴 DB 원문이다. 같은 사전
 * 상태(결제 실패 처리 → 결제 취소 이력 → 나이스 실패 meta)에서 실제 SignData 요청으로 복구한 결과가 바이트까지 같아야 한다.
 * 의도된 차이는 하나다 — 결제 실패 처리가 취소로 바꾼 주문 상품이 주문 대기로 돌아온다(녹화는 종전 결함 그대로 취소).
 */
class RetryRestoreCharacterizationTest extends PluginTestCase
{
    private const TEST_MID = 'nicepay00m';

    private const TEST_MERCHANT_KEY = 'EYzu8jGGMfqaDEp76gSckuvnaHHu+bC4opsSN6lHv3b2lurNYkVXrZ7Z1AoqQnXI3eLuaUFyoRNC6FkrzVjceg==';

    /**
     * 시계를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 녹화된 나이스페이먼츠 시나리오 목록입니다.
     *
     * @return array<string, array{0: string}> 시나리오
     */
    public static function recordedScenarios(): array
    {
        $cases = [];
        foreach (RetryRestoreScenarios::all()['nicepayments'] as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    /**
     * 주문 소유자의 SignData 요청이 실패 주문을 결제 대기로 되돌린 결과가 녹화값과 DB 원문까지 같다.
     *
     * @param  string  $scenario  시나리오 이름
     *
     * @scenario case=sign_data_retry_restore_matches_recorded_legacy_bytes
     *
     * @effects nice_sign_data_retry_restore_matches_legacy_recording
     *
     * @return void 반환값 없음
     */
    #[DataProvider('recordedScenarios')]
    public function test_sign_data_retry_restore_matches_recorded_legacy_bytes(string $scenario): void
    {
        $fixture = RetryRestoreScenarios::fixturePath('nicepayments', $scenario);
        $this->assertFileExists($fixture, '녹화 파일이 없습니다 — 이커머스 녹화기(G7_CHARACTERIZE=record)를 먼저 돌리세요.');
        $expected = json_decode((string) file_get_contents($fixture), true)['snapshot'];

        $order = RetryRestoreScenarios::prepare('nicepayments', $scenario);
        $this->mockPluginSettings();
        Sanctum::actingAs(User::findOrFail($order->user_id));

        $amount = app(PaymentLookup::class)->resolveChargeAmount($order->order_number)->minorUnitAmount;
        $address = app(OrderAccess::class)->getShippingAddress($order->order_number);

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => $amount,
            'moid' => $order->order_number,
            'buyer_email' => $address?->ordererEmail,
            'buyer_phone' => $address?->ordererPhone,
        ]);

        $response->assertOk()->assertJsonStructure(['ediDate', 'signData', 'mid']);

        $actual = RetryRestoreScenarios::snapshot($order);

        // 의도된 차이: 주문 상품 상태(녹화는 종전 결함 그대로 cancelled, 계약은 주문 대기로 되돌린다)
        $this->assertSame(array_fill(0, count($expected['option_statuses']), 'cancelled'), $expected['option_statuses']);
        $this->assertSame(array_fill(0, count($actual['option_statuses']), 'pending_order'), $actual['option_statuses']);
        unset($expected['option_statuses'], $actual['option_statuses']);

        $this->assertSame($expected, $actual);
    }

    /**
     * 플러그인 설정 서비스를 테스트 설정으로 대체합니다.
     *
     * @return void 반환값 없음
     */
    private function mockPluginSettings(): void
    {
        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')->willReturn([
            'is_test_mode' => true,
            'test_mid' => self::TEST_MID,
            'test_merchant_key' => self::TEST_MERCHANT_KEY,
            'live_mid' => '',
            'live_merchant_key' => '',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ]);

        $this->app->instance(PluginSettingsService::class, $settingsMock);
    }
}
