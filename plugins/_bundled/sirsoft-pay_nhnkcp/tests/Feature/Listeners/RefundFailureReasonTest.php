<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Listeners;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpApiException;
use Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpRejectedException;
use Plugins\Sirsoft\PayNhnkcp\Listeners\PaymentRefundListener;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * 결제 환불 실패 결과의 사유 선택 (#858)
 *
 * 쇼핑몰은 이 사유를 「PG 환불 처리에 실패했습니다. (…)」 안내에 끼워 회원·비회원·관리자 응답으로 낸다.
 * NHN KCP 가 돌려준 거절 사유(res_msg — 서비스가 NhnKcpRejectedException 으로 던짐)는 그대로 싣고, CLI 실행 실패
 * 문구(서버 절대 경로)는 일반 문구로 바꾼다. KCP 는 CLI 연동이라 서비스를 목으로 두고 던지는 예외 종류로 가른다
 * (res_cd 판정 → 거절 예외 변환은 `NhnKcpApiServiceTest::test_register_escrow_delivery_throws_rejected_exception_on_non_0000_res_cd`
 * 와 같은 판정 블록이 취소에도 있다).
 *
 * 시나리오 매니페스트: `tests/scenarios/refund-failure-reason.yaml`
 */
class RefundFailureReasonTest extends PluginTestCase
{
    /** CLI 실행 실패 원문 — 서버 절대 경로가 담긴다 */
    private const CLI_ERROR = 'KCP CLI 바이너리 누락: /var/www/html/plugins/sirsoft-pay_nhnkcp/bin/pp_cli';

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
     * 취소 호출이 주어진 예외를 던지게 하고 환불을 실행합니다.
     *
     * @param  \Throwable  $exception  취소 호출이 던질 예외
     * @return array<string, mixed> 리스너 결과
     */
    private function refundThrowing(\Throwable $exception): array
    {
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-KCP858-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'currency' => 'KRW',
            'total_amount' => 30000,
            'total_paid_amount' => 30000,
        ]);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => 'card',
            'pg_provider' => 'nhnkcp',
            'transaction_id' => '2099123'.str_pad((string) $order->id, 7, '0', STR_PAD_LEFT),
            'paid_amount_local' => 30000,
            'cancelled_amount' => 0,
            'currency' => 'KRW',
            'is_escrow' => false,
            'paid_at' => now(),
            'payment_meta' => ['site_cd' => 'T0000', 'is_test_mode' => true],
        ]);
        $order = $order->fresh(['payment']);

        $api = $this->createMock(NhnKcpApiService::class);
        $api->method('cancelPayment')->willThrowException($exception);
        $this->app->instance(NhnKcpApiService::class, $api);

        return app(PaymentRefundListener::class)->processRefund(['success' => false], $order, $order->payment, 30000.0, '고객 요청');
    }

    /**
     * CLI 실행 실패 문구(서버 경로)는 결과에 싣지 않고 일반 문구를 싣는다.
     *
     * @scenario failure=transport
     *
     * @effects refund_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_cli_failure_returns_generic_reason(): void
    {
        $result = $this->refundThrowing(new NhnKcpApiException(self::CLI_ERROR));

        $this->assertFalse($result['success']);
        $this->assertSame('PG_API_ERROR', $result['error_code']);
        $this->assertSame(__('sirsoft-pay_nhnkcp::messages.refund.pg_failed'), $result['error_message']);
        $this->assertStringNotContainsString('/var/www', $result['error_message']);
    }

    /**
     * NHN KCP 가 거절하면 그 사유(res_msg)를 그대로 싣는다.
     *
     * @scenario failure=pg_rejected
     *
     * @effects refund_failure_keeps_pg_reason
     *
     * @return void 반환값 없음
     */
    public function test_pg_rejection_keeps_result_message(): void
    {
        $result = $this->refundThrowing(new NhnKcpRejectedException('이미 취소된 거래입니다.'));

        $this->assertSame('이미 취소된 거래입니다.', $result['error_message']);
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

        $this->assertSame(self::CLI_ERROR, $this->refundThrowing(new NhnKcpApiException(self::CLI_ERROR))['error_message']);
    }
}
