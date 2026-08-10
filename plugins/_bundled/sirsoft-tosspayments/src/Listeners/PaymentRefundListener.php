<?php

namespace Plugins\Sirsoft\Tosspayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Plugins\Sirsoft\Tosspayments\Services\TossPaymentsApiService;

/**
 * PG 결제 환불 리스너
 *
 * 이커머스 모듈의 결제 환불 훅을 구독하여
 * 토스페이먼츠 결제 취소 API를 호출합니다.
 */
class PaymentRefundListener implements HookListenerInterface
{
    private const PG_PROVIDER_ID = 'tosspayments';

    /**
     * 구독할 훅 매핑 반환
     *
     * @return array 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.payment.refund' => [
                'method' => 'processRefund',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용)
     *
     * @param  mixed  ...$args  인수
     */
    public function handle(...$args): void
    {
        // processRefund에서 처리
    }

    /**
     * PG 결제 환불을 처리합니다.
     *
     * @param  array  $result  환불 결과 (기본값)
     * @param  Order  $order  주문
     * @param  OrderPayment  $payment  결제 정보
     * @param  float  $refundAmount  환불 금액 (결제 통화 order_currency 기준)
     * @param  string|null  $reason  환불 사유
     * @param  OrderRefund|null  $refund  이번 환불 시도의 레코드 (멱등키 조립용)
     * @return array 환불 결과 {success, error_code, error_message, transaction_id}
     */
    public function processRefund(
        array $result,
        Order $order,
        OrderPayment $payment,
        float $refundAmount,
        ?string $reason = null,
        ?OrderRefund $refund = null,
    ): array {
        // 토스페이먼츠 결제가 아닌 경우 스킵
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        $paymentKey = $payment->transaction_id;
        if (! $paymentKey) {
            return [
                'success' => false,
                'error_code' => 'MISSING_PAYMENT_KEY',
                'error_message' => __('sirsoft-tosspayments::messages.refund.missing_payment_key'),
                'transaction_id' => null,
            ];
        }

        // $refundAmount 는 코어가 결제 통화(order_currency)로 환산해 전달한 실환불액이다.
        $cancelAmount = (int) round($refundAmount);
        $isPartial = $this->isPartialCancel($payment, $cancelAmount);

        // E4: 에스크로 결제는 부분취소를 지원하지 않는다. 결제 시점 스냅샷(is_escrow)이 기준이므로
        // 플러그인 설정을 나중에 off 로 바꿔도 기존 에스크로 주문은 계속 차단된다.
        if ($isPartial && (bool) $payment->is_escrow) {
            Log::warning('토스페이먼츠 에스크로 주문의 부분취소 차단', [
                'order_id' => $order->id,
                'payment_key' => $paymentKey,
                'cancel_amount' => $cancelAmount,
            ]);

            return [
                'success' => false,
                'error_code' => 'ESCROW_PARTIAL_REFUND_NOT_ALLOWED',
                'error_message' => __('sirsoft-tosspayments::messages.refund.escrow_partial_not_allowed'),
                'transaction_id' => null,
            ];
        }

        // R3: 가상계좌는 입금된 돈을 돌려보낼 계좌가 있어야 취소가 성립한다.
        // 계좌 없이 호출하면 토스가 거부하므로 API 호출 전에 명시적으로 실패시킨다.
        $refundReceiveAccount = null;
        if ($this->requiresRefundReceiveAccount($payment)) {
            $refundReceiveAccount = $this->buildRefundReceiveAccount($payment);

            if ($refundReceiveAccount === null) {
                Log::warning('토스페이먼츠 가상계좌 환불계좌 누락', [
                    'order_id' => $order->id,
                    'payment_key' => $paymentKey,
                ]);

                return [
                    'success' => false,
                    'error_code' => 'MISSING_REFUND_ACCOUNT',
                    'error_message' => __('sirsoft-tosspayments::messages.refund.missing_refund_account'),
                    'transaction_id' => null,
                ];
            }
        }

        try {
            $apiService = $this->getApiService();

            $cancelReason = $reason ?? __('sirsoft-tosspayments::messages.refund.default_reason');

            $response = $apiService->cancelPayment(
                $paymentKey,
                $cancelReason,
                // R1: 전액취소는 cancelAmount 를 넣지 않는다 (토스: 미지정 = 전액취소).
                $isPartial ? $cancelAmount : null,
                // R2: 복합과세 상점에서 면세분이 공급가액으로 오산되지 않도록 동반 전달.
                $this->resolveTaxFreeAmount($order, $cancelAmount),
                $refundReceiveAccount,
                $this->buildIdempotencyKey($refund),
            );

            Log::info('토스페이먼츠 결제 취소 성공', [
                'order_id' => $order->id,
                'payment_key' => $paymentKey,
                'cancel_amount' => $isPartial ? $cancelAmount : null,
                'is_partial' => $isPartial,
            ]);

            // 토스 응답에서 취소 트랜잭션 ID 추출
            $cancels = $response['cancels'] ?? [];
            $lastCancel = end($cancels);
            $transactionId = $lastCancel['transactionKey'] ?? $paymentKey;

            return [
                'success' => true,
                'error_code' => null,
                'error_message' => null,
                'transaction_id' => $transactionId,
            ];
        } catch (\Exception $e) {
            Log::error('토스페이먼츠 결제 취소 실패', [
                'order_id' => $order->id,
                'payment_key' => $paymentKey,
                'cancel_amount' => (int) $refundAmount,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error_code' => 'PG_API_ERROR',
                'error_message' => $e->getMessage(),
                'transaction_id' => null,
            ];
        }
    }

    /**
     * 이번 취소가 부분취소인지 판정합니다. (R1)
     *
     * 결제 통화 기준으로 누적 취소액을 본다. 이미 부분취소한 이력이 있으면 잔액 전부를
     * 취소하더라도 부분취소로 보내야 한다 — 토스는 cancelAmount 미지정 시 "결제 전액" 을
     * 취소하므로, 이미 취소된 금액까지 두 번 취소하게 되기 때문이다.
     *
     * @param  OrderPayment  $payment  결제 레코드
     * @param  int  $cancelAmount  이번 취소 금액 (결제 통화)
     * @return bool 부분취소 여부
     */
    private function isPartialCancel(OrderPayment $payment, int $cancelAmount): bool
    {
        $paidAmount = (int) round((float) $payment->paid_amount_local);
        $cumulativeCancelled = (int) round($this->cancelledLocalAmount($payment));

        // 코어가 이번 환불분을 이미 누적했을 수 있으므로 이전 누적분만 분리한다.
        $previousCancelled = max(0, $cumulativeCancelled - $cancelAmount);

        return $previousCancelled > 0 || $cancelAmount < $paidAmount;
    }

    /**
     * 결제 통화(order_currency) 기준 누적 취소액을 반환합니다.
     *
     * @param  OrderPayment  $payment  결제 레코드
     * @return float 결제 통화 기준 누적 취소액
     */
    private function cancelledLocalAmount(OrderPayment $payment): float
    {
        $currency = $payment->currency;
        $mc = $payment->mc_cancelled_amount ?? [];

        if ($currency !== null && isset($mc[$currency])) {
            return (float) $mc[$currency];
        }

        return (float) $payment->cancelled_amount;
    }

    /**
     * 취소 금액 중 면세 금액을 산출합니다. (R2)
     *
     * 면세/과세 분류의 SSoT 는 주문(total_tax_free_amount / total_tax_amount)이다.
     * 부분취소면 취소 비율만큼 면세분을 안분한다. 면세분이 없으면 null 을 반환해
     * 요청 본문에서 taxFreeAmount 자체를 생략시킨다.
     *
     * @param  Order  $order  주문
     * @param  int  $cancelAmount  이번 취소 금액
     * @return int|null 취소분에 해당하는 면세 금액 (없으면 null)
     */
    private function resolveTaxFreeAmount(Order $order, int $cancelAmount): ?int
    {
        $taxFree = (int) round((float) $order->total_tax_free_amount);

        if ($taxFree <= 0 || $cancelAmount <= 0) {
            return null;
        }

        $taxable = (int) round((float) $order->total_tax_amount);
        $classified = $taxFree + $taxable;

        if ($classified <= 0) {
            return null;
        }

        if ($cancelAmount >= $classified) {
            return $taxFree;
        }

        // 반올림 잔차는 과세 쪽에 귀속시켜 합계를 보존한다 (코어 calculateIssuableAmount 와 동일 규약).
        $share = (int) round($cancelAmount * $taxFree / $classified);

        return max(0, min($share, $cancelAmount));
    }

    /**
     * 환불계좌가 필요한 결제인지 판정합니다. (R3)
     *
     * @param  OrderPayment  $payment  결제 레코드
     * @return bool 가상계좌 결제 여부
     */
    private function requiresRefundReceiveAccount(OrderPayment $payment): bool
    {
        return $payment->isVirtualAccount();
    }

    /**
     * 토스 취소 API 의 refundReceiveAccount 페이로드를 조립합니다. (R3)
     *
     * @param  OrderPayment  $payment  결제 레코드
     * @return array|null 환불계좌 (3필드 중 하나라도 없으면 null)
     */
    private function buildRefundReceiveAccount(OrderPayment $payment): ?array
    {
        $bank = $payment->refund_bank_code;
        $account = $payment->refund_bank_account;
        $holder = $payment->refund_bank_holder;

        if (empty($bank) || empty($account) || empty($holder)) {
            return null;
        }

        return [
            'bank' => (string) $bank,
            'accountNumber' => (string) $account,
            'holderName' => (string) $holder,
        ];
    }

    /**
     * 취소 요청의 멱등키를 조립합니다.
     *
     * 환불 레코드는 환불 시도마다 고유하므로, 네트워크 재시도로 같은 취소가 두 번
     * 도달해도 토스가 중복 청구를 차단한다. 레코드가 없으면(레거시 호출) 키를 생략한다.
     *
     * @param  OrderRefund|null  $refund  환불 레코드
     * @return string|null 멱등키 (없으면 null)
     */
    private function buildIdempotencyKey(?OrderRefund $refund): ?string
    {
        if ($refund === null || $refund->id === null) {
            return null;
        }

        return 'g7-refund-'.$refund->id;
    }

    /**
     * API 서비스 인스턴스를 가져옵니다.
     */
    protected function getApiService(): TossPaymentsApiService
    {
        return app(TossPaymentsApiService::class);
    }
}
