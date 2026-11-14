<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Listeners;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayKginicis\Exceptions\KgInicisRejectedException;
use Plugins\Sirsoft\PayKginicis\Listeners\PaymentRefundListener;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 결제 환불 실패 결과의 사유 선택 (#858)
 *
 * 쇼핑몰은 이 사유를 「PG 환불 처리에 실패했습니다. (…)」 안내에 끼워 회원·비회원·관리자 응답으로 낸다.
 * KG이니시스가 돌려준 거절 사유(resultMsg)는 그대로 싣고, 통신 예외 원문(결제대행사 API 주소·cURL 문구)과
 * HTTP 오류 문구는 일반 문구로 바꾼다. 실제 API 서비스를 쓰고 HTTP 만 가짜로 바꿔 서비스가 던지는 예외 종류까지 검증한다.
 *
 * 시나리오 매니페스트: `tests/scenarios/refund-failure-reason.yaml`
 */
class RefundFailureReasonTest extends PluginTestCase
{
    /** 통신 예외 원문 — 결제대행사 API 주소와 cURL 문구가 담긴다 */
    private const TRANSPORT_ERROR = 'cURL error 7: Failed to connect to stginiapi.inicis.com port 443 for https://stginiapi.inicis.com/v2/pg/refund';

    /**
     * 운영 모드·한국어 로케일과 테스트 상점 설정의 실제 API 서비스로 시작합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        app()->setLocale('ko');

        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn([
            'is_test_mode' => true,
            'test_mid' => 'INIpayTest',
            'test_sign_key' => 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS',
            'test_iniapi_key' => 'ItEQKi3rY7uvDS8l',
            'test_iniapi_iv' => 'HYb3yQ4f65QL89==',
            'live_mid' => '',
            'live_sign_key' => '',
            'live_iniapi_key' => '',
            'live_iniapi_iv' => '',
        ]);
        $this->app->instance(KgInicisApiService::class, new KgInicisApiService($settings));
    }

    /**
     * 환불을 실행하고 결과를 돌려줍니다.
     *
     * @return array<string, mixed> 리스너 결과
     */
    private function refund(): array
    {
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-KG858-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'currency' => 'KRW',
            'total_amount' => 30000,
            'total_paid_amount' => 30000,
        ]);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => 'card',
            'pg_provider' => 'kginicis',
            'transaction_id' => 'StdpayCARDINIpayTest'.$order->id,
            'paid_amount_local' => 30000,
            'paid_amount_base' => 30000,
            'cancelled_amount' => 0,
            'currency' => 'KRW',
            'is_escrow' => false,
            'paid_at' => now(),
        ]);
        $order = $order->fresh(['payment']);

        return app(PaymentRefundListener::class)->processRefund(['success' => false], $order, $order->payment, 30000.0, '고객 요청');
    }

    /**
     * 통신 예외 원문은 결과에 싣지 않고 일반 문구를 싣는다.
     *
     * @scenario failure=transport
     *
     * @effects refund_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_transport_failure_returns_generic_reason(): void
    {
        Http::fake(fn () => throw new ConnectionException(self::TRANSPORT_ERROR));

        $result = $this->refund();

        $this->assertFalse($result['success']);
        $this->assertSame('PG_API_ERROR', $result['error_code']);
        $this->assertSame(__('sirsoft-pay_kginicis::messages.refund.pg_failed'), $result['error_message']);
        $this->assertStringNotContainsString('inicis.com', $result['error_message']);
    }

    /**
     * HTTP 오류 문구(`HTTP 503`)도 결과에 싣지 않는다.
     *
     * @scenario failure=http_error
     *
     * @effects refund_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_http_error_returns_generic_reason(): void
    {
        Http::fake(['*' => Http::response('Service Unavailable', 503)]);

        $this->assertSame(__('sirsoft-pay_kginicis::messages.refund.pg_failed'), $this->refund()['error_message']);
    }

    /**
     * KG이니시스가 거절하면 그 사유(resultMsg)를 그대로 싣는다 — 서비스는 거절을 KgInicisRejectedException 으로 던진다.
     *
     * @scenario failure=pg_rejected
     *
     * @effects refund_failure_keeps_pg_reason
     *
     * @return void 반환값 없음
     */
    public function test_pg_rejection_keeps_result_message(): void
    {
        Http::fake(['*' => Http::response(['resultCode' => '01', 'resultMsg' => '[500626]기취소 거래'], 200)]);

        $this->assertSame('[500626]기취소 거래', $this->refund()['error_message']);
    }

    /**
     * 사유 없는 거절은 영어 내부 문구 대신 번역 문구로 던진다.
     *
     * @scenario failure=pg_rejected
     *
     * @effects refund_failure_keeps_pg_reason
     *
     * @return void 반환값 없음
     */
    public function test_pg_rejection_without_message_throws_translated_reason(): void
    {
        Http::fake(['*' => Http::response(['resultCode' => '01'], 200)]);

        try {
            app(KgInicisApiService::class)->cancelPayment('StdpayCARDINIpayTest1', 'Card', null, '취소');
            $this->fail('거절 응답이면 예외를 던져야 한다');
        } catch (KgInicisRejectedException $e) {
            $this->assertSame('KG이니시스가 결제 취소를 거절했습니다.', $e->getMessage());
        }
    }

    /**
     * 디버그 모드는 코어 관례대로 원문을 싣는다.
     *
     * @scenario failure=debug
     *
     * @effects refund_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_debug_mode_keeps_raw_message(): void
    {
        config(['app.debug' => true]);
        Http::fake(fn () => throw new ConnectionException(self::TRANSPORT_ERROR));

        $this->assertSame(self::TRANSPORT_ERROR, $this->refund()['error_message']);
    }
}
