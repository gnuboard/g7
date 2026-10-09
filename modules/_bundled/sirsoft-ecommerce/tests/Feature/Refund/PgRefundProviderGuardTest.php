<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Refund;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\RefundExecutionService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * PG 환불의 「이 주문의 결제 플러그인」 판정 (P-D3 · P-D4 · P-D6)
 *
 * - P-D6 PG 리스너 존재 판정이 전역이었다 — 다른 PG 플러그인 하나만 켜져 있어도 꺼진 플러그인의 주문이 PG 환불로
 *   접수됐고, 실행은 「PG 환불 실패(Unknown error)」 로만 끝났다.
 * - P-D3 신청 때 리스너가 없어 계좌 환불로 폴백된 카드 주문은, 플러그인을 다시 켜도 PG 를 부르지 않고 완료됐다.
 * - P-D4 환불수단이 PG 인데 결제수단이 PG 를 거치지 않으면 PG 단계를 건너뛰고 「환불완료」 로 남았다.
 *
 * 시나리오 매니페스트: `tests/scenarios/pg-refund-provider-guard.yaml`
 */
class PgRefundProviderGuardTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 통화 설정과 클레임 사유를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
    }

    /**
     * 통화 설정을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 결제수단·제공자를 갈아 끼운 결제완료 주문을 만듭니다.
     *
     * @param  string  $method  결제수단 ID
     * @param  string  $provider  PG 제공자 ID
     * @return Order 주문
     */
    private function orderPaidWith(string $method, string $provider): Order
    {
        $order = $this->paidClaimOrder(29000, 1);
        $order->payment->forceFill(['payment_method' => $method, 'pg_provider' => $provider])->save();

        return $order->fresh(['options', 'payment', 'shippings']);
    }

    /**
     * 켜진 PG 플러그인처럼 제공자를 등록합니다.
     *
     * @param  string  $providerId  제공자 ID
     * @return void 반환값 없음
     */
    private function registerProvider(string $providerId): void
    {
        HookManager::addFilter('sirsoft-ecommerce.payment.registered_pg_providers', function (array $providers) use ($providerId) {
            $providers[] = ['id' => $providerId, 'name' => $providerId];

            return $providers;
        });
    }

    /**
     * 자기 제공자의 결제만 처리하는 PG 환불 리스너를 등록합니다. (실제 플러그인 리스너와 같은 계약)
     *
     * @param  string  $providerId  처리할 제공자 ID
     * @param  array<int, string>  $calls  호출된 주문번호 기록
     * @return void 반환값 없음
     */
    private function registerRefundListener(string $providerId, array &$calls): void
    {
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', function (array $result, $order, $payment) use ($providerId, &$calls) {
            if ($payment->pg_provider !== $providerId) {
                return $result;
            }
            $calls[] = $order->order_number;

            return ['success' => true, 'handled' => true, 'error_code' => null, 'error_message' => null, 'transaction_id' => 'TX-'.$providerId];
        });
    }

    /**
     * 다른 PG 플러그인만 켜져 있으면 이 주문은 PG 환불 대상이 아니다 — 신청은 계좌 환불로 접수된다.
     *
     * @scenario guard=provider_check
     *
     * @effects pg_refund_requires_the_orders_own_plugin
     *
     * @return void 반환값 없음
     */
    public function test_another_plugin_alone_does_not_count_as_this_orders_pg(): void
    {
        $calls = [];
        $this->registerProvider('other_pg');
        $this->registerRefundListener('other_pg', $calls);
        $order = $this->orderPaidWith('card', 'tosspayments');

        $service = app(RefundExecutionService::class);
        $this->assertFalse($service->hasPgRefundListener($order), '꺼진 플러그인의 주문이 PG 환불 대상으로 판정됐다');
        $this->assertSame(RefundMethodEnum::BANK, $service->determineRefundMethod($order));

        $this->registerProvider('tosspayments');
        $this->assertTrue($service->hasPgRefundListener($order), '자기 플러그인이 켜졌으면 PG 환불 대상이다');
    }

    /**
     * PG 환불로 저장된 신청서를 실행할 때 이 주문의 플러그인이 응답하지 않으면 「플러그인 비활성」 으로 422.
     *
     * @scenario guard=unhandled_execution
     *
     * @effects unhandled_pg_refund_reports_inactive_plugin
     *
     * @return void 반환값 없음
     */
    public function test_unhandled_pg_refund_reports_an_inactive_plugin(): void
    {
        $calls = [];
        $this->registerRefundListener('other_pg', $calls);
        $order = $this->orderPaidWith('card', 'tosspayments');

        try {
            app(OrderCancellationService::class)->cancelOrder(order: $order);
            $this->fail('응답한 플러그인이 없는데 환불이 완료됐다');
        } catch (OrderCancellationException $e) {
            $this->assertSame(
                __('sirsoft-ecommerce::exceptions.pg_provider_inactive', ['provider' => 'tosspayments']),
                $e->getMessage(),
            );
        }

        $this->assertSame([], $calls);
    }

    /**
     * 계좌 환불로 폴백된 신청서는, 운영자가 수단을 고르지 않았고 플러그인이 다시 켜졌으면 실행 때 PG 를 부른다.
     *
     * @scenario guard=fallback_reresolve
     *
     * @effects fallback_refund_returns_to_pg_when_the_plugin_is_back
     *
     * @return void 반환값 없음
     */
    public function test_fallback_bank_refund_goes_to_pg_once_the_plugin_is_back(): void
    {
        $order = $this->orderPaidWith('card', 'tosspayments');
        $refund = app(OrderCancellationService::class)->cancelOrder(order: $order)->orderRefund;
        $this->assertSame(RefundMethodEnum::BANK, $refund->refund_method, '리스너가 없으면 계좌 환불로 접수된다(P14)');
        $this->assertFalse((bool) $refund->refund_method_overridden);

        $calls = [];
        $this->registerProvider('tosspayments');
        $this->registerRefundListener('tosspayments', $calls);
        $refund->forceFill(['refund_status' => RefundStatusEnum::APPROVED])->save();

        $executed = app(OrderRefundRequestService::class)->executeRefund($refund->fresh());

        $this->assertSame([$order->order_number], $calls, '플러그인이 돌아왔는데 PG 를 부르지 않았다');
        $this->assertSame(RefundMethodEnum::PG, $executed->refund_method);
        $this->assertSame(RefundStatusEnum::COMPLETED, $executed->refund_status);
    }

    /**
     * 운영자가 계좌 환불을 직접 고른 신청서는 다시 판정하지 않는다 — 손으로 송금한 건을 PG 로 또 돌려주지 않는다.
     *
     * @scenario guard=fallback_reresolve
     *
     * @effects operator_chosen_refund_method_is_kept
     *
     * @return void 반환값 없음
     */
    public function test_operator_chosen_bank_refund_is_not_reresolved(): void
    {
        $order = $this->orderPaidWith('card', 'tosspayments');
        $refund = app(OrderCancellationService::class)->cancelOrder(order: $order)->orderRefund;

        $calls = [];
        $this->registerProvider('tosspayments');
        $this->registerRefundListener('tosspayments', $calls);
        $refund->forceFill(['refund_status' => RefundStatusEnum::APPROVED])->save();

        $executed = app(OrderRefundRequestService::class)->executeRefund($refund->fresh(), [
            'refund_method' => RefundMethodEnum::BANK->value,
            'refund_bank_code' => '004',
            'refund_bank_account' => '12345678901',
            'refund_bank_holder' => '홍길동',
        ]);

        $this->assertSame([], $calls, '운영자가 고른 계좌 환불인데 PG 를 불렀다(이중 환불)');
        $this->assertSame(RefundMethodEnum::BANK, $executed->refund_method);
        $this->assertTrue((bool) $executed->refund_method_overridden);
    }

    /**
     * 결제수단이 PG 를 거치지 않는데 수단이 PG 이면 실행을 422 로 막는다 — 돈이 나가지 않은 「PG 환불 완료」 를 만들지 않는다.
     *
     * @scenario guard=method_mismatch
     *
     * @effects pg_refund_on_a_non_pg_payment_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_pg_refund_on_a_non_pg_payment_is_rejected(): void
    {
        $order = $this->orderPaidWith('dbank', '');
        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_number' => 'RF-PD4-'.$order->id,
            'refund_status' => RefundStatusEnum::APPROVED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 29000,
        ]);
        $result = new AdjustmentResult(refundAmount: 29000.0);

        try {
            app(RefundExecutionService::class)->execute($order, $refund, $result);
            $this->fail('PG 를 거치지 않는 결제에 PG 환불이 통과했다');
        } catch (OrderCancellationException $e) {
            $this->assertSame(__('sirsoft-ecommerce::exceptions.pg_refund_unsupported'), $e->getMessage());
        }

        $this->assertNotSame(RefundStatusEnum::COMPLETED, $refund->fresh()->refund_status);
    }
}
