<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\TaxInvoiceStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Models\OrderTaxInvoice;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Services\RefundExecutionService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionMethod;

/**
 * 장부·예외 계약 결함 수정 검증 — 헤더 결제금액 통화·Repository 경계·세금 컬럼 화이트리스트·예외 키 (#599 validate P2-B)
 *
 * 검증 축:
 * - 헤더 결제금액 정합화가 결제 통화 금액을 기준 통화 칸에 적지 않고 소수를 보존한다(#6).
 * - 병합 시 클레임 신청상품 이전이 Repository 를 거친다(#7).
 * - 클레임 환불이 주문 헤더에 쓰는 컬럼은 세금 분류 화이트리스트뿐이다(#8).
 * - PG 환불 실패·클레임 접수 거절 예외가 다국어 키와 치환 파라미터를 싣는다(#4 · minor).
 * - 세금계산서 조회가 Repository 를 거친다(minor).
 */
class ValidateP2MoneyLedgerTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 테스트 통화·사유를 준비합니다.
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
     * 테스트 통화 설정을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 결제행은 수납 완료인데 헤더 결제금액이 0 으로 남은 배송완료 주문을 만듭니다. (S2-D4 전제)
     *
     * @param  array  $paymentOverrides  결제행 덮어쓰기
     * @return Order 주문
     */
    private function headerUnpaidOrder(array $paymentOverrides): Order
    {
        $order = $this->deliveredClaimOrder(price: 29000);

        $order->payment->forceFill(array_merge(['payment_status' => PaymentStatusEnum::PAID], $paymentOverrides))->save();
        DB::table('ecommerce_orders')->where('id', $order->id)->update([
            'order_status' => OrderStatusEnum::PENDING_PAYMENT->value,
            'total_paid_amount' => 0,
            'total_due_amount' => 29000,
        ]);

        return $order->fresh(['options', 'payment']);
    }

    // ── #6 헤더 결제금액 통화 ──

    /**
     * 기준 통화 수납액이 없는 외화 결제행의 결제 통화 금액을 헤더에 옮기지 않는지 검증합니다.
     *
     * 수정 전에는 `paid_amount_base ?: paid_amount_local` 로 떨어져 USD 50 이 헤더에 50 원으로 적혔다.
     *
     * @scenario defect=header_paid_currency
     *
     * @effects header_paid_amount_is_never_the_payment_currency_amount
     *
     * @return void 반환값 없음
     */
    public function test_외화_결제액을_기준_통화_헤더에_옮기지_않는다(): void
    {
        $order = $this->headerUnpaidOrder(['currency' => 'USD', 'paid_amount_local' => 50, 'paid_amount_base' => 0]);

        app(OrderOptionService::class)->syncParentOrderStatus($order->id);

        $this->assertEqualsWithDelta(29000.0, (float) $order->fresh()->total_paid_amount, 0.01);
    }

    /**
     * 기준 통화 수납액의 소수를 잘라내지 않는지 검증합니다.
     *
     * 수정 전에는 `(int)` 캐스팅으로 29,000.50 이 29,000 으로 잘렸다.
     *
     * @scenario defect=header_paid_currency
     *
     * @effects header_paid_amount_keeps_the_fraction
     *
     * @return void 반환값 없음
     */
    public function test_기준_통화_수납액의_소수를_보존한다(): void
    {
        $order = $this->headerUnpaidOrder(['currency' => 'KRW', 'paid_amount_local' => 29000.5, 'paid_amount_base' => 29000.5]);

        app(OrderOptionService::class)->syncParentOrderStatus($order->id);

        $this->assertEqualsWithDelta(29000.5, (float) $order->fresh()->total_paid_amount, 0.001);
    }

    // ── #7 Repository 경계 ──

    /**
     * 병합 시 클레임 신청상품 이전을 Repository 가 맡는지 검증합니다.
     *
     * 수정 전에는 서비스가 신청상품 4종 모델을 직접 질의했고 Repository 에 해당 메서드가 없었다.
     *
     * @scenario defect=repository_boundary
     *
     * @effects claim_option_transfer_goes_through_the_repository
     *
     * @return void 반환값 없음
     */
    public function test_신청상품_이전은_repository_가_맡는다(): void
    {
        $order = $this->deliveredClaimOrder(quantity: 2);
        $consumed = $order->options->first();
        $survivor = $consumed->replicate();
        $survivor->save();

        $this->makeDirectRefund($order, $consumed, 1);

        $moved = app(OrderOptionRepositoryInterface::class)->transferClaimOptionReferences($consumed->id, $survivor->id);

        $this->assertSame(1, $moved);
        $this->assertSame(1, OrderRefundOption::where('order_option_id', $survivor->id)->count());
        $this->assertSame(0, OrderRefundOption::where('order_option_id', $consumed->id)->count());

        $source = (string) file_get_contents((new \ReflectionClass(OrderOptionService::class))->getFileName());
        $this->assertStringNotContainsString('OrderRefundOption::class', $source, '서비스가 신청상품 모델을 직접 질의합니다.');
    }

    // ── #8 세금 컬럼 화이트리스트 ──

    /**
     * 클레임 환불이 재계산 결과의 금액 컬럼을 주문 헤더에 쓰지 않는지 검증합니다.
     *
     * 수정 전에는 `orderUpdates` 를 통째로 써서 `total_paid_amount` 가 재계산값(0)으로 덮였다 —
     * 잔여 한도가 두 번 깎여 다음 환불이 0 원이 된다.
     *
     * @scenario defect=tax_column_whitelist
     *
     * @effects claim_refund_writes_only_tax_columns_to_the_header
     *
     * @return void 반환값 없음
     */
    public function test_클레임_환불은_세금_컬럼만_헤더에_쓴다(): void
    {
        $order = $this->deliveredClaimOrder(price: 29000);
        $refund = $this->makeDirectRefund($order, $order->options->first(), 1);

        app(RefundExecutionService::class)->execute(
            $order,
            $refund,
            new AdjustmentResult(orderUpdates: [
                'total_paid_amount' => 0,
                'total_amount' => 0,
                'total_tax_amount' => 123,
            ]),
            null,
            executePg: false,
            recordPaymentLedger: false,
        );

        $fresh = $order->fresh();
        $this->assertEqualsWithDelta(29000.0, (float) $fresh->total_paid_amount, 0.01, '금액 컬럼이 헤더에 덮였습니다.');
        $this->assertEqualsWithDelta(123.0, (float) $fresh->total_tax_amount, 0.01, '세금 컬럼은 반영돼야 합니다.');
    }

    // ── 예외 키 계약 ──

    /**
     * PG 환불 실패 예외가 다국어 키와 거절 사유 파라미터를 싣는지 검증합니다.
     *
     * 수정 전에는 번역문만 실어, 키로 응답을 조립하는 면이 거절 사유를 잃었다.
     *
     * @scenario defect=exception_message_key
     *
     * @effects pg_refund_failure_carries_its_message_key
     *
     * @return void 반환값 없음
     */
    public function test_p_g_환불_실패_예외는_키와_사유를_싣는다(): void
    {
        $order = $this->paidClaimOrder();
        $refund = $this->makeDirectRefund($order, $order->options->first(), 1);

        // PG 가 **받아서** 거절한 경우다 — 리스너가 `handled` 를 싣지 않으면 서비스는 플러그인 비활성(P-D6)으로
        // 읽어 다른 키를 낸다. 실제 PG 리스너는 자기 결제면 handled 를 켠다(S3-D19 — 픽스처 갱신).
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn (array $default) => array_merge($default, [
            'handled' => true,
            'success' => false,
            'error_code' => 'DECLINED',
            'error_message' => 'declined by issuer',
        ]));

        try {
            app(RefundExecutionService::class)->executePgRefund($order, $refund, new AdjustmentResult(refundAmount: 1000), 'test');
            $this->fail('PG 환불 실패 예외가 나야 합니다.');
        } catch (OrderCancellationException $e) {
            $this->assertSame('sirsoft-ecommerce::exceptions.pg_refund_failed', $e->getMessageKey());
            $this->assertSame('declined by issuer', $e->getMessageParams()['error'] ?? null);
        }
    }

    /**
     * 클레임 접수 거절을 취소 실패로 바꿀 때 키·파라미터를 그대로 옮기는지 검증합니다.
     *
     * 수정 전에는 번역문을 키 자리에 넘겨 키 대신 문장이, 파라미터 대신 빈 배열이 실렸다.
     *
     * @scenario defect=exception_message_key
     *
     * @effects cancellation_failure_keeps_the_claim_key_and_params
     *
     * @return void 반환값 없음
     */
    public function test_접수_거절은_키와_파라미터를_유지한_채_취소_실패가_된다(): void
    {
        $method = new ReflectionMethod(OrderCancellationService::class, 'asCancellationFailure');

        try {
            $method->invoke(
                app(OrderCancellationService::class),
                fn () => throw OrderClaimException::optionNotClaimable(ClaimTypeEnum::REFUND, 'shipping'),
            );
            $this->fail('취소 실패 예외가 나야 합니다.');
        } catch (OrderCancellationException $e) {
            $this->assertSame('sirsoft-ecommerce::exceptions.claim_option_not_claimable', $e->getMessageKey());
            $this->assertSame('shipping', $e->getMessageParams()['status'] ?? null);
        }
    }

    // ── 세금계산서 조회 경계 ──

    /**
     * 발급 완료 세금계산서 조회를 Repository 가 맡고 발급 완료만 돌려주는지 검증합니다.
     *
     * 수정 전에는 취소 서비스가 관계 빌더로 직접 질의했고 Repository 에 해당 메서드가 없었다.
     *
     * @scenario defect=repository_boundary
     *
     * @effects issued_tax_invoices_are_read_through_the_repository
     *
     * @return void 반환값 없음
     */
    public function test_발급_완료_세금계산서는_repository_로_조회한다(): void
    {
        $order = $this->paidClaimOrder();
        OrderTaxInvoice::factory()->create([
            'order_id' => $order->id,
            'invoice_status' => TaxInvoiceStatusEnum::ISSUED,
            'invoice_number' => 'TI-ISSUED',
            'issued_at' => now(),
        ]);
        OrderTaxInvoice::factory()->create(['order_id' => $order->id]);

        $issued = app(OrderRepositoryInterface::class)->listIssuedTaxInvoices($order->id);

        $this->assertCount(1, $issued);
        $this->assertSame('TI-ISSUED', $issued->first()->invoice_number);
    }
}
