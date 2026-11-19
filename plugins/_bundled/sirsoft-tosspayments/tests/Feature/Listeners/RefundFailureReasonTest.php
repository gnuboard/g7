<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature\Listeners;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\Tosspayments\Listeners\PaymentRefundListener;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 결제 환불 실패 결과의 사유 선택 (#858)
 *
 * 쇼핑몰은 이 사유를 「PG 환불 처리에 실패했습니다. (…)」 안내에 끼워 회원·비회원·관리자 응답으로 낸다.
 * 토스페이먼츠가 오류 코드와 함께 돌려준 거절 사유(`message`)는 그대로 싣고, 통신 예외 원문(결제대행사 API 주소·cURL 문구)과
 * 본문을 해석하지 못한 HTTP 오류는 일반 문구로 바꾼다. 실제 API 서비스를 쓰고 HTTP 만 가짜로 바꾼다.
 *
 * 시나리오 매니페스트: `tests/scenarios/refund-failure-reason.yaml`
 */
class RefundFailureReasonTest extends PluginTestCase
{
    /** 통신 예외 원문 — 결제대행사 API 주소와 cURL 문구가 담긴다 */
    private const TRANSPORT_ERROR = 'cURL error 28: Operation timed out for https://api.tosspayments.com/v1/payments/test_payment_key/cancel';

    /**
     * 운영 모드·한국어 로케일로 시작합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        app()->setLocale('ko');
    }

    /**
     * 토스 카드 결제 주문을 전액 환불하고 결과를 돌려줍니다.
     *
     * @return array<string, mixed> 리스너 결과
     */
    private function refund(): array
    {
        $order = Order::factory()->create();
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'pg_provider' => 'tosspayments',
            'transaction_id' => 'test_payment_key',
            'paid_amount_local' => 10000,
            'cancelled_amount' => 0,
            'currency' => 'KRW',
            'is_escrow' => false,
        ]);
        $order = $order->fresh(['payment']);

        return app(PaymentRefundListener::class)->processRefund(['success' => false], $order, $order->payment, 10000.0, '고객 요청');
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
        $this->assertSame(__('sirsoft-tosspayments::messages.refund.pg_failed'), $result['error_message']);
        $this->assertStringNotContainsString('tosspayments.com', $result['error_message']);
    }

    /**
     * 본문을 해석하지 못한 HTTP 오류(오류 코드 없음)도 일반 문구를 싣는다.
     *
     * @scenario failure=http_error
     *
     * @effects refund_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_http_error_without_pg_code_returns_generic_reason(): void
    {
        Http::fake(['*' => Http::response('<html>Bad Gateway</html>', 502)]);

        $this->assertSame(__('sirsoft-tosspayments::messages.refund.pg_failed'), $this->refund()['error_message']);
    }

    /**
     * 토스페이먼츠가 오류 코드와 함께 거절하면 그 사유를 그대로 싣는다.
     *
     * @scenario failure=pg_rejected
     *
     * @effects refund_failure_keeps_pg_reason
     *
     * @return void 반환값 없음
     */
    public function test_pg_rejection_keeps_result_message(): void
    {
        Http::fake(['*' => Http::response(['code' => 'ALREADY_CANCELED_PAYMENT', 'message' => '이미 취소된 결제 입니다.'], 400)]);

        $this->assertSame('이미 취소된 결제 입니다.', $this->refund()['error_message']);
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
