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
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\Concerns\AppliesRefundBankAccount;

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
    use AppliesRefundBankAccount;

    /**
     * @param  StockService  $stockService  재고 서비스
     * @param  EcommerceSettingsService  $settingsService  이커머스 설정 서비스
     * @param  CashReceiptService  $cashReceiptService  현금영수증 발급/취소 서비스
     * @param  OrderPaymentRepositoryInterface  $orderPaymentRepository  주문 결제 저장소 (송금 대상 계좌 갱신)
     */
    public function __construct(
        protected StockService $stockService,
        protected EcommerceSettingsService $settingsService,
        protected CashReceiptService $cashReceiptService,
        protected OrderPaymentRepositoryInterface $orderPaymentRepository,
    ) {}

    /**
     * 환불을 실행하고 주문·결제의 환불 누적을 반영합니다.
     *
     * 클레임(교환/반품/취소·환불) 환불의 진입점이다. 결제행의 취소 누적을 메모리에 먼저 채우고
     * — PG 리스너가 그 값을 읽어 전액/부분 취소를 가른다 — PG 환불이 필요한 결제수단이면 훅을
     * 실행한 뒤, 성공했을 때에만 주문의 총 환불 누적과 결제행을 저장한다.
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

        // 송금 대상 계좌를 이 환불의 계좌로 맞춘다 — PG 환불 API 의 refundReceiveAccount 와
        // 무통장 수동 이체가 읽는 자리는 결제행 하나뿐이라, 구매자가 신청서에서 고친 계좌는
        // 실행 직전 여기에 반영되어야 실제 송금에 닿는다. 신청 접수만으로 덮어쓰지 않는 이유는
        // AppliesRefundBankAccount 독블록에 있다.
        $this->applyRefundBank($order, $this->refundBankInfoOf($refund));

        // 결제행의 취소 누적은 **채우기와 저장을 나눈다**.
        //
        // 결제행은 PG 훅의 입력이기도 하다 — 리스너는 이 행을 읽고 "이번이 잔액 전부인가" 를
        // 판정해 전액취소/부분취소 코드를 고른다(NICE 의 `isPartial` 등). 채우지 않고 부르면
        // 마지막 잔액을 환불하는데도 취소액이 아직 0 이어서 "남은 돈이 있다" 로 보이고, PG 에는
        // **부분취소**로 나간다. 카드사는 원거래를 승인 상태로 남기고 장부만 전액환불로 찍혀
        // 대사가 갈린다.
        //
        // 그렇다고 먼저 저장하면 "실패한 환불이 장부를 깎는" 반대쪽 위험이 생긴다. 호출부가
        // 트랜잭션을 열지 않은 채 부르면 롤백이 없기 때문이다. 그래서 메모리에만 채워 훅에
        // 보이게 하고, 실제 기록은 PG 가 받아들인 뒤에 한다.
        $paymentUpdates = $order->payment !== null
            ? $this->buildCancelledAmountUpdates($order->payment, $result)
            : [];

        if ($paymentUpdates !== []) {
            $order->payment->fill($paymentUpdates);
        }

        // PG사 선택이 필요한 결제수단(카드/가상계좌/계좌이체/휴대폰)만 PG 환불 훅에 진입한다.
        // 무통장입금(dbank)·포인트·예치금·무료는 PG 리스너가 인식하지 못해 success=false → 예외.
        if ($executePg && $order->payment && $result->refundAmount > 0
            && $order->payment->needsPgProvider()) {
            $this->executePgRefund($order, $refund, $result, $reason);
        }

        $this->accumulateRefundTotals($order, $result);

        if ($paymentUpdates !== []) {
            $this->orderPaymentRepository->savePendingChanges($order->payment);
        }
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
     * 환불 레코드가 든 환불 계좌를 돌려줍니다. (세 값이 모두 있을 때만)
     *
     * 일부만 채워 반영하면 은행만 바뀌고 계좌번호는 옛 값으로 남은 조합이 송금 대상이 된다.
     *
     * @param  OrderRefund  $refund  환불 레코드
     * @return array{bank_code: string, account_number: string, holder: string}|null 환불 계좌
     */
    protected function refundBankInfoOf(OrderRefund $refund): ?array
    {
        $bankCode = (string) ($refund->refund_bank_code ?? '');
        $account = (string) ($refund->refund_bank_account ?? '');
        $holder = (string) ($refund->refund_bank_holder ?? '');

        if ($bankCode === '' || $account === '' || $holder === '') {
            return null;
        }

        return [
            'bank_code' => $bankCode,
            'account_number' => $account,
            'holder' => $holder,
        ];
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

        $method = $order->payment->refundMethod();

        // 결제 플러그인이 제거된 뒤의 주문 (P14).
        //
        // 플러그인을 지우면 `payment.refund` 훅을 구독하던 리스너가 사라진다. 그런데 결제행은
        // 여전히 "PG 환불" 이라고 말하므로 코어는 PG 경로로 들어가고, 아무도 응답하지 않아
        // `success=false` → 예외 → 트랜잭션 롤백이 된다. 운영자는 **그 주문의 결제완료 건을
        // 영영 취소할 수 없고**, 화면에는 원인 없는 오류만 뜬다.
        //
        // 리스너가 없으면 계좌 환불로 떨어뜨린다 — 운영자가 손으로 송금하고 [환불하기]로
        // 완료를 찍는 경로는 살아 있다. 조용히 바꾸지 않고 로그를 남겨 이유가 드러나게 한다.
        if ($method === RefundMethodEnum::PG && ! $this->hasPgRefundListener($order)) {
            Log::warning('PG 환불 리스너 없음 — 계좌 환불로 폴백', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_method' => (string) $order->payment->payment_method,
                'pg_provider' => $order->payment->pg_provider,
            ]);

            return RefundMethodEnum::BANK;
        }

        return $method;
    }

    /**
     * 이 주문의 PG 환불에 응답할 리스너가 실제로 등록돼 있는지 판정합니다. (P14)
     *
     * `payment.refund` 는 필터 훅이라 구독자가 없으면 기본값(`success=false`)이 그대로
     * 돌아온다 — 실패와 미등록을 결과만으로 구분할 수 없다. 그래서 **부르기 전에** 등록
     * 여부를 본다.
     *
     * @param  Order  $order  주문
     * @return bool 리스너가 있으면 true
     */
    public function hasPgRefundListener(Order $order): bool
    {
        // 판정은 **구독자 유무 하나**로 한다. 결제행의 `pg_provider` 가 지금 등록된 제공자
        // 목록에 있는지까지 보고 싶어지지만, 그러면 거짓 양성이 생긴다 — 제공자 ID 와 리스너
        // 등록 이름이 다른 플러그인, 여러 수단을 한 리스너로 처리하는 플러그인이 전부 걸린다.
        // 여기서 잡으려는 것은 "**아무도 응답하지 않는다**" 는 명백한 경우(플러그인 제거)이고,
        // 그 밖의 실패는 PG 거절로 드러나 사유와 함께 422 로 나간다(P13).
        return ! empty(HookManager::getFilters()['sirsoft-ecommerce.payment.refund'] ?? []);
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
        $previousStatus = $refund->refund_status instanceof RefundStatusEnum
            ? $refund->refund_status->value
            : (string) $refund->refund_status;

        $refund->update(['refund_status' => RefundStatusEnum::PROCESSING]);

        // 이 전이는 예전에 어떤 줄도 남기지 않았다(B1). PG 왕복 도중 예외로 끊기면 신청서는
        // `processing` 에 멈추는데, 그 상태로 들어간 경위가 이력에 없어 운영자는 "승인에서
        // 갑자기 처리중이 된" 신청서를 보게 된다. 알림은 억제한다 — 고객에게는 완료 1건만
        // 알리는 것이 §4-2 의 규율이고, 처리중은 내부 단계다.
        HookManager::doAction(
            'sirsoft-ecommerce.claim.refund.status_changed',
            $refund->fresh(),
            $previousStatus,
            RefundStatusEnum::PROCESSING->value,
            ['suppress_notification' => true],
        );

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

            // 실패 근거는 **애플리케이션 로그에 남긴다**(P13). 위 `pg_error_code/message` 갱신은
            // 곧 이어지는 예외가 트랜잭션째 롤백하므로 DB 에 남지 않는다 — 타임아웃이면 돈은
            // 나갔는데 주문은 정상으로 복구되고, 그 사실을 가리키는 근거가 로그 말고는 없다.
            // 주문·환불 번호를 함께 적어 운영자가 PG 콘솔과 대조할 수 있게 한다.
            Log::error('PG 환불 실패', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'refund_id' => $refund->id,
                'refund_number' => $refund->refund_number,
                'refund_amount_local' => $refundLocal,
                'currency' => $orderCurrency,
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
