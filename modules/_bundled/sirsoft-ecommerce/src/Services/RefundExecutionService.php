<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundOptionStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;

/**
 * 환불 실행 서비스
 *
 * PG 환불 훅 실행과 쿠폰/마일리지/재고 복원, 현금영수증 동기화를 담당합니다.
 * 취소(OrderCancellationService)와 클레임(교환/반품) 양쪽이 같은 규칙으로 환불을
 * 실행하도록 OrderCancellationService 에서 추출한 공용 서비스입니다.
 *
 * 이 클래스는 "환불을 실행하는 방법"만 안다 — 무엇을 환불할지(계산)는
 * OrderAdjustmentService 가, 환불 레코드의 상태 전이와 출처 기록은 호출부가 정한다.
 */
class RefundExecutionService
{
    /**
     * @param  StockService  $stockService  재고 서비스
     * @param  EcommerceSettingsService  $settingsService  이커머스 설정 서비스
     * @param  CashReceiptService  $cashReceiptService  현금영수증 발급/취소 서비스
     */
    public function __construct(
        protected StockService $stockService,
        protected EcommerceSettingsService $settingsService,
        protected CashReceiptService $cashReceiptService,
    ) {}

    /**
     * 환불을 실행하고 주문·결제의 환불 누적을 반영합니다.
     *
     * 클레임(교환/반품) 환불의 진입점이다. PG 환불이 필요한 결제수단이면 훅을 실행한 뒤
     * 주문의 총 환불 누적과 결제행의 취소 누적을 갱신한다.
     *
     * 취소(OrderCancellationService)는 이 메서드를 쓰지 않는다 — 취소는 orderUpdates 로
     * total_paid_amount 자체를 재계산값으로 덮어써 잔여 한도를 이미 좁히므로, 여기서
     * total_refunded_amount 까지 누적하면 OrderAdjustmentService 의 잔여 한도
     * (total_paid_amount - total_refunded_amount)가 같은 금액을 두 번 차감한다.
     * 반면 클레임 환불은 total_paid_amount 를 깎지 않으므로 이 누적이 유일한 근거다.
     * 누적 지점을 이 메서드 하나로 고정해 두 경로가 서로의 값을 덧쓰지 않게 한다.
     *
     * 쿠폰/마일리지/재고 복원은 여기서 부르지 않는다 — 클레임은 복원 시점이 환불 시점과
     * 다르다(반품 재고는 회수 도착 시 관리자 선택). 호출부가 자기 시점에 개별 호출한다.
     *
     * @param  Order  $order  주문
     * @param  OrderRefund  $refund  환불 레코드
     * @param  AdjustmentResult  $result  계산 결과
     * @param  string|null  $reason  사유
     * @param  bool  $executePg  PG 환불 훅 실행 여부 (운영자 수동 환불이면 false)
     *
     * @throws \Exception PG 환불 실패 시
     */
    public function execute(
        Order $order,
        OrderRefund $refund,
        AdjustmentResult $result,
        ?string $reason = null,
        bool $executePg = true,
    ): void {
        $order->loadMissing('payment');

        // PG사 선택이 필요한 결제수단(카드/가상계좌/계좌이체/휴대폰)만 PG 환불 훅에 진입한다.
        // 무통장입금(dbank)·포인트·예치금·무료는 PG 리스너가 인식하지 못해 success=false → 예외.
        if ($executePg && $order->payment && $result->refundAmount > 0
            && $order->payment->needsPgProvider()) {
            $this->executePgRefund($order, $refund, $result, $reason);
        }

        $this->accumulateRefundTotals($order, $result);
        $this->accumulatePaymentCancellation($order, $result);
    }

    /**
     * 주문의 총 환불 누적을 갱신합니다.
     *
     * total_refunded_amount / total_refunded_points_amount 는 OrderAdjustmentService 가
     * 잔여 환불 한도(remainingPg / remainingPoints)의 상한으로 읽는 값이다.
     * 누적 규칙은 execute() 의 docblock 을 따른다.
     *
     * @param  Order  $order  주문
     * @param  AdjustmentResult  $result  계산 결과
     */
    protected function accumulateRefundTotals(Order $order, AdjustmentResult $result): void
    {
        $order->update([
            'total_refunded_amount' => (float) $order->total_refunded_amount + (float) $result->refundAmount,
            'total_refunded_points_amount' => (float) $order->total_refunded_points_amount
                + (float) $result->refundPointsAmount,
        ]);
    }

    /**
     * 결제행의 취소 누적을 갱신합니다.
     *
     * 취소 경로의 updatePayment 와 같은 규칙(buildCancelledAmountUpdates)을 쓰되,
     * 취소 고유 필드(cancel_history / cancel_reason / payment_status)는 건드리지 않는다.
     * 클레임 환불은 결제 자체를 취소 상태로 전이시키지 않기 때문이다.
     *
     * @param  Order  $order  주문
     * @param  AdjustmentResult  $result  계산 결과
     */
    protected function accumulatePaymentCancellation(Order $order, AdjustmentResult $result): void
    {
        $payment = $order->payment;

        if (! $payment) {
            return;
        }

        $payment->update($this->buildCancelledAmountUpdates($payment, $result));
    }

    /**
     * 환불 수단을 결정합니다.
     *
     * 확장 결제수단(간편결제 등)도 카탈로그 선언을 통해 올바른 환불수단으로 해석된다.
     * enum match 로 두면 확장 ID 가 default → BANK 로 떨어져 카드 취소가 누락된다(#475).
     * 취소와 클레임이 같은 규칙으로 환불 수단을 정하도록 이 서비스가 SSoT 다.
     *
     * @param  Order  $order  주문
     * @return RefundMethodEnum 환불 수단
     */
    public function determineRefundMethod(Order $order): RefundMethodEnum
    {
        if (! $order->payment) {
            return RefundMethodEnum::BANK;
        }

        return $order->payment->refundMethod();
    }

    /**
     * 환불 상세(옵션별) 레코드의 필드 배열을 만듭니다.
     *
     * 취소 발 환불과 클레임 발 환불이 같은 안분 규칙(원 옵션 할인 비율 기반 차감)을 쓰도록
     * 계산을 한 곳에 둔다. 반환값에는 order_refund_id 가 없다 — 그 값은 환불 레코드를 만든
     * 호출부가 채운다.
     *
     * @param  Order  $order  주문
     * @param  array  $items  대상 아이템 [{order_option_id, cancel_quantity}]
     * @param  AdjustmentResult  $result  계산 결과
     * @return array<int, array<string, mixed>> 환불 옵션 행 배열
     */
    public function buildRefundOptionRows(Order $order, array $items, AdjustmentResult $result): array
    {
        $order->loadMissing('options');

        $rows = [];

        foreach ($items as $item) {
            $option = $order->options->find($item['order_option_id']);

            if (! $option) {
                continue;
            }

            // adjustedItems 에서 해당 옵션의 취소 금액 조회
            $adjustedItem = collect($result->adjustedItems)
                ->firstWhere('order_option_id', $item['order_option_id']);

            $quantity = (int) $item['cancel_quantity'];
            $subtotalAmount = (float) $option->unit_price * $quantity;
            $cancelAmount = $adjustedItem['cancel_amount'] ?? $subtotalAmount;

            // 할인 차감분 계산 (원래 옵션 할인 비율 기반)
            $discountAmount = 0;
            if ($option->subtotal_price > 0) {
                $discountRatio = (float) $option->subtotal_discount_amount / (float) $option->subtotal_price;
                $discountAmount = round($subtotalAmount * $discountRatio, 2);
            }

            $refundAmount = $cancelAmount - $discountAmount;

            $rows[] = [
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'option_status' => RefundOptionStatusEnum::REQUESTED,
                'quantity' => $quantity,
                'unit_price' => (float) $option->unit_price,
                'subtotal_amount' => $subtotalAmount,
                'discount_amount' => $discountAmount,
                'refund_amount' => max(0, $refundAmount),
            ];
        }

        return $rows;
    }

    /**
     * 결제행의 취소 누적 컬럼 갱신값을 만듭니다.
     *
     * base 통화 누적(cancelled_amount)과 결제 통화 누적(mc_cancelled_amount)은 별개다.
     * base ≠ 결제 통화일 때 PG 실환불·누적은 결제 통화로 정합되어야 한다.
     *
     * @param  OrderPayment  $payment  결제행
     * @param  AdjustmentResult  $result  계산 결과
     * @return array<string, mixed> 갱신값 (cancelled_amount / mc_cancelled_amount)
     */
    public function buildCancelledAmountUpdates(OrderPayment $payment, AdjustmentResult $result): array
    {
        $mcCancelled = $payment->mc_cancelled_amount ?? [];
        foreach ($result->mcRefundAmount ?? [] as $code => $amount) {
            $mcCancelled[$code] = (float) ($mcCancelled[$code] ?? 0) + (float) $amount;
        }

        return [
            'cancelled_amount' => (float) $payment->cancelled_amount + $result->refundAmount,
            'mc_cancelled_amount' => $mcCancelled,
        ];
    }

    /**
     * 환불액을 결제 통화(order_currency)로 환산해 반환합니다.
     *
     * 계산 단계에서 스냅샷 환율로 산출한 mc_refund_amount[order_currency] 를 우선 사용하고,
     * 없으면(결제 통화 환율 누락 등) base refundAmount 로 폴백합니다.
     *
     * @param  AdjustmentResult  $result  환불 계산 결과
     * @param  string|null  $orderCurrency  결제 통화 코드
     * @return float 결제 통화 환산 환불액
     */
    public function resolveOrderCurrencyRefundAmount(AdjustmentResult $result, ?string $orderCurrency): float
    {
        if ($orderCurrency !== null && isset($result->mcRefundAmount[$orderCurrency])) {
            return (float) $result->mcRefundAmount[$orderCurrency];
        }

        return (float) $result->refundAmount;
    }

    /**
     * PG 환불을 실행합니다.
     *
     * @param  Order  $order  주문
     * @param  OrderRefund  $refund  환불 레코드
     * @param  AdjustmentResult  $result  계산 결과
     * @param  string|null  $reason  사유
     *
     * @throws \Exception PG 환불 실패 시
     */
    public function executePgRefund(
        Order $order,
        OrderRefund $refund,
        AdjustmentResult $result,
        ?string $reason,
    ): void {
        $refund->update(['refund_status' => RefundStatusEnum::PROCESSING]);

        // PG 실환불 금액은 결제 통화(order_currency)여야 한다. base≠결제 통화일 때 base 금액을 그대로
        // 전송하면 PG 가 결제 통화 단위로 오인해 실환불액이 틀어진다(예: base JPY 500 → KRW 500 환불).
        // 호출부가 이미 mc_cancelled_amount 를 결제 통화로 누적했으므로, 리스너는 결제 통화 기준
        // (paid_amount_local / mc_cancelled_amount[order_currency])으로 부분취소 누적을 계산한다.
        $orderCurrency = $order->payment->currency ?: ($order->currency_snapshot['order_currency'] ?? null);
        $refundLocal = $this->resolveOrderCurrencyRefundAmount($result, $orderCurrency);

        // $refund 는 이 환불 시도의 고유 레코드다. PG 리스너가 멱등키(Idempotency-Key)를 조립할 때
        // 사용한다 — 네트워크 재시도로 같은 취소가 두 번 도달해도 PG 가 중복 청구를 차단한다.
        // 인자 추가는 하위호환이다: 기존 리스너(5-파라미터)는 초과 인자를 그대로 무시한다.
        $pgResult = HookManager::applyFilters(
            'sirsoft-ecommerce.payment.refund',
            ['success' => false, 'error_code' => null, 'error_message' => null, 'transaction_id' => null],
            $order,
            $order->payment,
            $refundLocal,
            $reason,
            $refund
        );

        if (! empty($pgResult['success'])) {
            $refund->update([
                'pg_transaction_id' => $pgResult['transaction_id'] ?? null,
                'refund_status' => RefundStatusEnum::COMPLETED,
                'refunded_at' => Carbon::now(),
            ]);
        } else {
            $refund->update([
                'pg_error_code' => $pgResult['error_code'] ?? null,
                'pg_error_message' => $pgResult['error_message'] ?? null,
            ]);

            Log::error('PG 환불 실패', [
                'order_id' => $order->id,
                'refund_id' => $refund->id,
                'error_code' => $pgResult['error_code'] ?? null,
                'error_message' => $pgResult['error_message'] ?? null,
            ]);

            throw new OrderCancellationException(__('sirsoft-ecommerce::exceptions.pg_refund_failed', [
                'error' => $pgResult['error_message'] ?? 'Unknown error',
            ]));
        }
    }

    /**
     * 쿠폰을 복원합니다.
     *
     * @param  Order  $order  주문
     * @param  AdjustmentResult  $result  계산 결과
     */
    public function restoreCoupons(Order $order, AdjustmentResult $result): void
    {
        if (! empty($result->restoredCouponIssueIds)) {
            try {
                HookManager::doAction(
                    'sirsoft-ecommerce.coupon.restore',
                    $order,
                    $result->restoredCouponIssueIds
                );
            } catch (\Exception $e) {
                Log::warning('쿠폰 복원 실패', [
                    'order_id' => $order->id,
                    'coupon_ids' => $result->restoredCouponIssueIds,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 마일리지를 복원합니다.
     *
     * 멱등 축은 두 갈래다 — 취소 발 복원은 `order_cancel_id`, 클레임 환불 실행(S6b)의 사용
     * 마일리지 환급은 `order_refund_id`. 복원 리스너가 취소 축을 우선 해석하므로 두 축을
     * 동시에 넘기지 않는다.
     *
     * @param  Order  $order  주문
     * @param  AdjustmentResult  $result  계산 결과
     * @param  OrderCancel|null  $orderCancel  취소 레코드 (취소 발 복원 멱등 기준)
     * @param  OrderRefund|null  $orderRefund  환불 레코드 (클레임 환불 실행 멱등 기준)
     */
    public function restoreMileage(
        Order $order,
        AdjustmentResult $result,
        ?OrderCancel $orderCancel = null,
        ?OrderRefund $orderRefund = null,
    ): void {
        // 실제 차감이 일어난 주문만 복원한다. 결제수단별 차감 시점이 payment_complete 인데
        // 결제 전 취소되어 마일리지가 차감된 적 없는 주문(is_mileage_deducted=false)은 복원 대상이 아니다.
        // 가드가 없으면 사용 의도(total_points_used_amount)만으로 신규 lot 이 발행되어 유령 적립이 발생한다.
        if (! $order->is_mileage_deducted) {
            return;
        }

        if ($result->refundPointsAmount > 0) {
            try {
                HookManager::doAction(
                    'sirsoft-ecommerce.mileage.restore',
                    $result->refundPointsAmount,
                    $order,
                    $orderCancel?->id,
                    $orderRefund?->id
                );
            } catch (\Exception $e) {
                Log::warning('마일리지 복원 실패', [
                    'order_id' => $order->id,
                    'points_amount' => $result->refundPointsAmount,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 재고를 복원합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $cancelItems  복원 대상 (order_option_id / cancel_quantity)
     */
    public function restoreStock(Order $order, array $cancelItems): void
    {
        $shouldRestore = $this->settingsService->getSetting(
            'order_settings.stock_restore_on_cancel',
            true
        );

        if (! $shouldRestore) {
            return;
        }

        foreach ($cancelItems as $item) {
            $option = $order->options->find($item['order_option_id']);
            if (! $option || ! $option->is_stock_deducted) {
                continue;
            }

            try {
                // 복원 + is_stock_deducted 플래그 정리를 한 트랜잭션에서 원자적으로 처리.
                // (재고 리스너 제거로 플래그 리셋 주체가 Service 경로로 단일화됨)
                $this->stockService->restoreOptionStockForOrderOption(
                    $option,
                    $item['cancel_quantity']
                );
            } catch (\Exception $e) {
                Log::warning('재고 복원 실패', [
                    'order_id' => $order->id,
                    'option_id' => $option->id,
                    'quantity' => $item['cancel_quantity'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 환불 확정 후 현금영수증을 현재 금액에 맞춰 동기화합니다.
     *
     * 발급 이력이 없으면 no-op 이고, 잔여 발급액이 0 이면 전액취소만 수행하며,
     * 그 외에는 전체취소 후 잔여 과세금액 기준으로 재발급한다 (부분취소 API 미사용).
     *
     * 어떤 실패도 호출부로 전파하지 않는다 — 환불은 이미 DB 에 확정됐고 되돌릴 수 없다.
     * 실패는 이력 테이블(issue_status=FAILED)과 로그에 남아 관리자 수동 재발급으로 복구된다.
     *
     * 로그 레벨이 error 인 이유 — CashReceiptService 는 예상 가능한 실패(프로바이더가 success=false
     * 반환, 복호화 불가)를 스스로 삼키고 warning + FAILED 이력으로 남긴다. 따라서 여기까지 예외가
     * 올라왔다면 리스너가 계약을 어긴 것이고, 프로바이더 쪽 영수증이 실제로 취소됐는지조차 알 수 없다
     * (국세청 기록과 DB 가 어긋날 수 있는 상태). 취소/재고/쿠폰 복원 실패(warning)와 달리 즉시
     * 사람이 개입해야 하므로 PG 환불 실패와 같은 error 로 둔다.
     *
     * @param  Order  $order  환불이 반영된 주문
     * @param  string|null  $reason  사유
     */
    public function syncCashReceipt(Order $order, ?string $reason): void
    {
        try {
            $this->cashReceiptService->syncFromOrder($order, $reason);
        } catch (\Throwable $e) {
            // 식별번호·PG raw 응답은 담지 않는다 (개인정보 로그 미노출).
            Log::error('현금영수증 동기화 실패 — 주문 취소는 확정됨 (관리자 수동 재발급 필요)', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
