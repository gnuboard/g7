<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 결제 플러그인을 껐다 켠 카드 주문의 환불 수단 — 화면 경로 (S3-D25·D26·D27, 2026-10-07 실측)
 *
 * 실측(나이스 플러그인 끔·켬, 카드 주문 738·739):
 *  - D25: PG 로 접수된 신청서(RF20261007-1325339567)를 플러그인이 꺼진 뒤 [환불하기] 하면 422 가
 *    「이 주문의 결제수단으로는 선택하신 환불수단을 쓸 수 없습니다.」 라고만 말했다. 1.3.0 은 이 경우
 *    「결제 플러그인이 꺼져 있습니다 — 활성화하거나 계좌 환불로 바꾸세요」 로 안내한다고 약속했다.
 *  - D26: 플러그인이 꺼진 동안 계좌로 폴백 접수된 신청서(RF20261007-1408283405)를 플러그인을 다시 켠 뒤
 *    [환불하기] 하면 PG 를 부르지 않고 계좌 환불로 완료됐다. 화면은 저장값(계좌)을 실행 본문에 그대로 싣고,
 *    서버는 본문에 수단이 있으면 바뀌지 않았어도 「운영자가 고른 수단」 으로 찍어 재판정을 건너뛰었다.
 *  - D27: [금액 수정] 칸의 최종 환불 합계가 「22500 KRW」 처럼 숫자와 통화 코드를 이어 붙인 날 표기였다
 *    — 화면 시험 `claimRefundInputTotalFormat.test.ts` 가 맡는다.
 */
class RefundMethodPluginToggleTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 통화·사유 시드를 준비합니다.
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
     * 통화 설정을 지워 다음 시험으로 새지 않게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 카드로 결제된 주문을 만듭니다.
     *
     * @return Order 주문
     */
    private function cardOrder(): Order
    {
        $order = $this->paidClaimOrder(29000, 1);
        $order->payment->forceFill(['payment_method' => 'card', 'pg_provider' => 'tosspayments'])->save();

        return $order->fresh(['options', 'payment', 'shippings']);
    }

    /**
     * 그 주문의 결제 플러그인을 켭니다(제공자 등록 + 환불 리스너).
     *
     * @param  array  $calls  PG 환불 호출 기록
     * @return void 반환값 없음
     */
    private function pluginOn(array &$calls): void
    {
        HookManager::addFilter('sirsoft-ecommerce.payment.registered_pg_providers', function (array $providers) {
            $providers[] = ['id' => 'tosspayments', 'name' => 'tosspayments'];

            return $providers;
        });
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', function (array $result, $order, $payment) use (&$calls) {
            if ($payment->pg_provider !== 'tosspayments') {
                return $result;
            }
            $calls[] = $order->order_number;

            return ['success' => true, 'handled' => true, 'error_code' => null, 'error_message' => null, 'transaction_id' => 'TX-T'];
        });
    }

    /**
     * 플러그인이 꺼진 동안 접수돼 계좌로 폴백된 승인 신청서를 만듭니다.
     *
     * @param  Order  $order  카드 주문
     * @return OrderRefund 환불신청서
     */
    private function fallbackRefund(Order $order): OrderRefund
    {
        $refund = app(OrderCancellationService::class)->cancelOrder(order: $order)->orderRefund;
        $refund->forceFill(['refund_status' => RefundStatusEnum::APPROVED])->save();

        return $refund->fresh();
    }

    /**
     * 신청서 상세 응답을 만듭니다.
     *
     * @param  OrderRefund  $refund  환불신청서
     * @return array 응답 배열
     */
    private function detail(OrderRefund $refund): array
    {
        return (new ClaimResource($refund->fresh(['order.payment'])))->toArray(request());
    }

    /**
     * 플러그인이 돌아오면 상세는 실행 때 쓰일 수단(PG)을 보인다
     *
     * @scenario case=fallback_after_plugin_returns
     *
     * @effects detail_reports_the_method_execution_will_use
     *
     * @return void 반환값 없음
     */
    public function test_detail_reports_pg_for_a_fallback_refund_once_the_plugin_returns(): void
    {
        $order = $this->cardOrder();
        $refund = $this->fallbackRefund($order);
        $this->assertSame(RefundMethodEnum::BANK, $refund->refund_method);

        $calls = [];
        $this->pluginOn($calls);

        $data = $this->detail($refund);

        $this->assertSame('pg', $data['refund_method'] ?? null, '다시 켠 뒤에도 상세가 계좌 환불로 보여 창이 계좌를 요구한다');
        $this->assertSame(RefundMethodEnum::PG->label(), $data['refund_method_label'] ?? null);
    }

    /**
     * 화면이 보인 수단을 그대로 실행하면 PG 로 진행한다
     *
     * @scenario case=fallback_after_plugin_returns
     *
     * @effects untouched_screen_execution_goes_to_pg
     *
     * @return void 반환값 없음
     */
    public function test_executing_the_method_the_screen_showed_goes_to_pg(): void
    {
        $order = $this->cardOrder();
        $refund = $this->fallbackRefund($order);

        $calls = [];
        $this->pluginOn($calls);
        $shown = $this->detail($refund)['refund_method'];

        $executed = app(OrderRefundRequestService::class)->executeRefund($refund, [
            'refund_amount' => (float) $refund->refund_amount,
            'refund_points_amount' => 0,
            'refund_method' => $shown,
        ]);

        $this->assertSame([$order->order_number], $calls, '플러그인이 돌아왔는데 PG 를 부르지 않았다');
        $this->assertSame(RefundMethodEnum::PG, $executed->refund_method);
        $this->assertSame(RefundStatusEnum::COMPLETED, $executed->refund_status);
    }

    /**
     * 실효 수단과 같은 값을 본문에 실은 것은 운영자 선택이 아니다
     *
     * @scenario case=plugin_still_off
     *
     * @effects unchanged_method_is_not_an_operator_choice
     *
     * @return void 반환값 없음
     */
    public function test_saving_the_unchanged_method_does_not_pin_it_as_an_operator_choice(): void
    {
        $order = $this->cardOrder();
        $refund = $this->fallbackRefund($order);

        // 플러그인이 꺼진 채 화면이 보인 수단(계좌) 그대로 [금액 저장]
        app(OrderRefundRequestService::class)->updateAmounts($refund, [
            'refund_amount' => (float) $refund->refund_amount,
            'refund_points_amount' => 0,
            'refund_method' => RefundMethodEnum::BANK->value,
        ]);
        $this->assertFalse((bool) $refund->fresh()->refund_method_overridden, '고치지 않은 수단을 운영자가 고른 수단으로 박았다');

        $calls = [];
        $this->pluginOn($calls);
        $executed = app(OrderRefundRequestService::class)->executeRefund($refund->fresh());

        $this->assertSame([$order->order_number], $calls);
        $this->assertSame(RefundMethodEnum::PG, $executed->refund_method);
    }

    /**
     * 꺼진 플러그인의 PG 접수 건을 저장하면 플러그인이 꺼졌다고 알린다
     *
     * @scenario case=pg_accepted_plugin_off
     *
     * @effects inactive_plugin_reason_on_save
     *
     * @return void 반환값 없음
     */
    public function test_saving_pg_on_an_inactive_plugin_says_the_plugin_is_off(): void
    {
        $order = $this->cardOrder();
        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_number' => 'RF-S3D25-'.$order->id,
            'refund_status' => RefundStatusEnum::APPROVED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 29000,
        ]);

        try {
            app(OrderRefundRequestService::class)->updateAmounts($refund, [
                'refund_amount' => 29000,
                'refund_points_amount' => 0,
                'refund_method' => RefundMethodEnum::PG->value,
            ]);
            $this->fail('꺼진 플러그인의 PG 저장이 통과했다');
        } catch (OrderClaimException $e) {
            $this->assertSame(
                __('sirsoft-ecommerce::exceptions.pg_provider_inactive', ['provider' => 'tosspayments']),
                $e->getMessage(),
                '플러그인이 꺼졌다는 이유와 해결책 대신 일반 거절 문장이 나간다'
            );
        }
    }

    /**
     * 꺼진 플러그인의 PG 접수 건을 실행하면 플러그인이 꺼졌다고 알린다
     *
     * @scenario case=pg_accepted_plugin_off
     *
     * @effects inactive_plugin_reason_on_execute
     *
     * @return void 반환값 없음
     */
    public function test_executing_pg_on_an_inactive_plugin_says_the_plugin_is_off(): void
    {
        $admin = $this->createAdminUser([
            'sirsoft-ecommerce.claims.read',
            'sirsoft-ecommerce.claims.update-status',
            'sirsoft-ecommerce.claims.update-amounts',
            'sirsoft-ecommerce.claims.execute-refund',
            'sirsoft-ecommerce.orders.update',
        ]);
        $order = $this->cardOrder();
        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_number' => 'RF-S3D25X-'.$order->id,
            'refund_status' => RefundStatusEnum::APPROVED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 29000,
        ]);

        $response = $this->actingAs($admin)->postJson("/api/modules/sirsoft-ecommerce/admin/refunds/{$refund->id}/execute", [
            'refund_amount' => 29000,
            'refund_points_amount' => 0,
            'refund_method' => 'pg',
            'refund_bank' => ['bank_code' => '004', 'account_number' => '12345678901234', 'holder' => '홍길동'],
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            __('sirsoft-ecommerce::exceptions.pg_provider_inactive', ['provider' => 'tosspayments']),
            $response->json('errors.refund_method.0'),
        );
    }
}
