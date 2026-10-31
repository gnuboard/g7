<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPaymentRepositoryInterface;

/**
 * 입금 전에 취소된 주문의 가상계좌를 PG 에서 닫습니다. (P-D1 · Q11)
 *
 * 입금 전 취소는 돌려줄 돈이 없어 환불 단계를 거치지 않고, 그래서 PG 를 부르지 않았다. 발급된 가상계좌는 PG 에
 * 그대로 남아, 고객이 나중에 입금하면 취소된 주문에 돈이 들어온다. 취소가 커밋된 뒤 이 주문의 결제 플러그인에게
 * 계좌를 닫게 한다(필터 `sirsoft-ecommerce.payment.close_vbank` — 플러그인은 자기 결제일 때만 `handled=true`).
 *
 * 닫지 못해도 주문 취소는 그대로다 — 고객 돈이 아직 들어오지 않았다. 대신 결제행에 `vbank_close_failed` 를 남겨
 * 관리자 주문 상세가 「PG 관리자 화면에서 가상계좌를 직접 닫으세요」 를 띄운다. 뒤늦은 입금은
 * `DepositAfterCancelRecorder` 가 기록·알린다.
 */
class UnpaidVbankCloser
{
    /**
     * @param  OrderPaymentRepositoryInterface  $paymentRepository  결제 Repository
     * @param  CurrencyConversionService  $currencyService  통화 서비스 (발급 금액 환산)
     */
    public function __construct(
        protected OrderPaymentRepositoryInterface $paymentRepository,
        protected CurrencyConversionService $currencyService,
    ) {}

    /**
     * 이 결제가 닫아야 할 미입금 가상계좌인지 판정합니다.
     *
     * @param  OrderPayment|null  $payment  결제행
     * @return bool 가상계좌·PG 결제·입금 기록 없음·아직 닫지 않음이면 true
     */
    public function shouldClose(?OrderPayment $payment): bool
    {
        if ($payment === null || ! $payment->isVirtualAccount() || $payment->paid_at !== null) {
            return false;
        }

        // 거래 식별값은 플러그인마다 자리가 다르다(나이스는 입금 전 `payment_meta.vbank_tid`) — 플러그인이 찾는다.
        if (blank($payment->pg_provider)) {
            return false;
        }

        $meta = $payment->payment_meta ?? [];

        return empty($meta['vbank_closed_at']);
    }

    /**
     * 가상계좌를 닫고 결과를 결제행에 기록합니다.
     *
     * 플러그인에는 발급 금액(결제 통화 최소 단위 — 취소 전 입금 예정액)을 함께 넘긴다. 전액 취소 API 가 금액을 요구하는
     * PG(나이스·KG·KCP)가 있는데, 취소 뒤 주문의 입금 예정액은 이미 0 이다.
     *
     * @param  Order  $order  취소된 주문
     * @param  array  $snapshot  취소 전 주문 속성 (`order.after_cancel` 의 세 번째 인자)
     * @return array{handled: bool, success: bool, error_message: string|null}|null 플러그인 응답 (대상 아니면 null)
     */
    public function close(Order $order, array $snapshot = []): ?array
    {
        $payment = $order->payment()->first();

        if (! $this->shouldClose($payment)) {
            return null;
        }

        $result = HookManager::applyFilters(
            'sirsoft-ecommerce.payment.close_vbank',
            ['handled' => false, 'success' => false, 'error_message' => null],
            $order,
            $payment,
            $this->issuedAmount($order, $snapshot),
        );

        $handled = ! empty($result['handled']);
        $success = $handled && ! empty($result['success']);
        $meta = $payment->payment_meta ?? [];

        if ($success) {
            $meta['vbank_closed_at'] = Carbon::now()->toIso8601String();
            unset($meta['vbank_close_failed'], $meta['vbank_close_error']);
        } else {
            // 플러그인이 꺼져 있어 아무도 받지 않은 것도 「닫지 못함」 이다 — 계좌는 PG 에 열려 있다.
            $meta['vbank_close_failed'] = true;
            $meta['vbank_close_error'] = $handled
                ? (string) ($result['error_message'] ?? '')
                : 'plugin_inactive';
            $meta['vbank_close_attempted_at'] = Carbon::now()->toIso8601String();

            Log::warning('입금 전 취소 주문의 가상계좌를 PG 에서 닫지 못했습니다 — PG 관리자 화면에서 직접 닫아야 합니다', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'pg_provider' => $payment->pg_provider,
                'handled' => $handled,
                'error' => $meta['vbank_close_error'],
            ]);
        }

        $this->paymentRepository->savePendingChanges($payment->forceFill(['payment_meta' => $meta]));

        return ['handled' => $handled, 'success' => $success, 'error_message' => $result['error_message'] ?? null];
    }

    /**
     * 취소 전 입금 예정액을 결제 통화 최소 단위로 환산합니다.
     *
     * @param  Order  $order  취소된 주문
     * @param  array  $snapshot  취소 전 주문 속성
     * @return int 발급 금액 (환산할 수 없으면 0)
     */
    private function issuedAmount(Order $order, array $snapshot): int
    {
        $due = (float) ($snapshot['total_due_amount'] ?? $order->total_amount);

        try {
            return $this->currencyService->resolveSnapshotPaymentCharge($due, $order->currency_snapshot ?? [])['minor_unit_amount'];
        } catch (\InvalidArgumentException) {
            return 0;
        }
    }
}
