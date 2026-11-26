<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Listeners;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNicepayments\Listeners\PaymentRefundListener;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 결제 환불 실패 결과의 사유 선택
 *
 * 쇼핑몰은 이 사유를 「PG 환불 처리에 실패했습니다. (…)」 안내에 끼워 회원·비회원·관리자 응답으로 낸다.
 * 나이스페이먼츠가 돌려준 거절 사유(ResultMsg)는 그대로 싣고, 통신 예외 원문(결제대행사 API 주소·cURL 문구)과
 * HTTP 오류 문구는 일반 문구로 바꾼다. 실제 API 서비스를 쓰고 HTTP 만 가짜로 바꿔 서비스가 던지는 예외 종류까지 검증한다.
 *
 * 시나리오 매니페스트: `tests/scenarios/refund-failure-reason.yaml`
 */
class RefundFailureReasonTest extends PluginTestCase
{
    /** 통신 예외 원문 — 결제대행사 API 주소와 cURL 문구가 담긴다 */
    private const TRANSPORT_ERROR = 'cURL error 28: Operation timed out after 15001 milliseconds for https://pg-api.nicepay.co.kr/webapi/cancel_process.jsp';

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
            'test_mid' => 'nicepay00m',
            'test_merchant_key' => 'EYzu8jGGMfqaDEp76gSckuvnaHHu+bC4opsSN6lHv3b2lurNYkVXrZ7Z1AoqQnXI3eLuaUFyoRNC6FkrzVjceg==',
            'live_mid' => '',
            'live_merchant_key' => '',
        ]);
        $this->app->instance(NicePaymentsApiService::class, new NicePaymentsApiService($settings));
    }

    /**
     * 나이스페이먼츠 카드 결제완료 주문을 만듭니다.
     *
     * @return Order 결제행이 달린 주문
     */
    private function paidOrder(): Order
    {
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-NICE858-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 30000,
            'total_paid_amount' => 30000,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nicepayments',
            'paid_amount_local' => 30000,
            'cancelled_amount' => 0,
            'paid_at' => now(),
            'transaction_id' => 'NICE_REFUND_TID_858',
        ]);

        return $order->fresh(['payment']);
    }

    /**
     * 환불을 실행하고 결과를 돌려줍니다.
     *
     * @return array<string, mixed> 리스너 결과
     */
    private function refund(): array
    {
        $order = $this->paidOrder();

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
        $this->assertSame(__('sirsoft-pay_nicepayments::messages.refund.pg_failed'), $result['error_message']);
        $this->assertStringNotContainsString('nicepay.co.kr', $result['error_message']);
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

        $result = $this->refund();

        $this->assertSame(__('sirsoft-pay_nicepayments::messages.refund.pg_failed'), $result['error_message']);
    }

    /**
     * 나이스페이먼츠가 거절하면 그 사유(ResultMsg)를 그대로 싣는다.
     *
     * @scenario failure=pg_rejected
     *
     * @effects refund_failure_keeps_pg_reason
     *
     * @return void 반환값 없음
     */
    public function test_pg_rejection_keeps_result_message(): void
    {
        $body = mb_convert_encoding((string) json_encode(['ResultCode' => '2013', 'ResultMsg' => '취소 가능 기간이 지났습니다.'], JSON_UNESCAPED_UNICODE), 'EUC-KR', 'UTF-8');
        Http::fake(['*' => Http::response($body, 200)]);

        $result = $this->refund();

        $this->assertFalse($result['success']);
        $this->assertSame('취소 가능 기간이 지났습니다.', $result['error_message']);
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
