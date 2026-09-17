<?php

namespace Plugins\Sirsoft\Tosspayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPaymentRepositoryInterface;
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
     * @return void 반환값 없음
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
                $this->resolveTaxFreeAmount($order, $payment, $cancelAmount),
                $refundReceiveAccount,
                $this->buildIdempotencyKey($refund),
            );

            // S1-D14: 취소 응답은 갱신된 cancels[] 를 담고 온다. 이걸 스냅샷에 반영해야
            // 다음 회차가 「이미 소진한 면세분」을 알 수 있다. PG 가 실제로 기록한 값이므로
            // 우리가 보낸 값이 아니라 **받아들여진 값**이 장부에 쌓인다.
            $this->syncCancelSnapshot($payment, $response);

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
     * 면세/과세 분류의 비율은 주문(total_tax_free_amount / total_tax_amount)에서 온다.
     * 부분취소면 취소 비율만큼 면세분을 안분한다. 면세분이 없으면 null 을 반환해
     * 요청 본문에서 taxFreeAmount 자체를 생략시킨다.
     *
     * S1-D14: 그러나 주문의 면세/과세 합계는 환불이 완료될 때마다 **잔여로 줄어든다**.
     * 그래서 회차별 안분분을 그대로 보내면 합이 승인 시 등록된 면세액을 넘어설 수 있고,
     * 토스는 그순간 「취소 할 수 없는 금액 입니다.」로 거부한다. 따라서 이번 회차의 면세분은
     * **잔여 면세 예산**(승인 면세액 − 이미 소진분)을 넘지 못하게 막는다.
     *
     * 예산의 근거는 불변인 승인 스냅샷(payment_meta.pg_raw_response.taxFreeAmount)과
     * 그 안의 PG 취소 장부(cancels[].taxFreeAmount)다 — 줄어드는 주문 합계가 아니다.
     * 잔여 예산을 알 수 없는 결제(스냅샷 부재)는 종전대로 안분값을 그대로 둠 — 첫 취소는
     * 주문 합계가 아직 승인 시점과 같아 결과가 동일하다.
     *
     * @param  Order  $order  주문
     * @param  OrderPayment  $payment  결제 레코드 (승인 면세액·취소 장부의 출처)
     * @param  int  $cancelAmount  이번 취소 금액
     * @return int|null 취소분에 해당하는 면세 금액 (없으면 null)
     */
    private function resolveTaxFreeAmount(Order $order, OrderPayment $payment, int $cancelAmount): ?int
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
            // 잔여 분류액을 통째로 취소하는 회차 — 남은 면세분 전부가 대상이다.
            $share = $taxFree;
        } else {
            // 반올림 잔차는 과세 쪽에 귀속시켜 합계를 보존한다 (코어 calculateIssuableAmount 와 동일 규약).
            $share = (int) round($cancelAmount * $taxFree / $classified);
        }

        $share = max(0, min($share, $cancelAmount));

        $budget = $this->remainingTaxFreeBudget($payment);

        if ($budget !== null) {
            $share = min($share, $budget);
        }

        return max(0, $share);
    }

    /**
     * 승인 면세액 중 아직 취소하지 않은 잔여 예산을 반환합니다. (S1-D14)
     *
     * 승인 응답 스냅샷은 결제 시점에 한 번 기록되고 이후 갱신되지 않는다 — 그럼으로써
     * `taxFreeAmount` 는 「승인 시 등록된 면세액」이라는 **불변의 상한**이 된다.
     * 이미 소진한 면세분은 같은 스냅샷의 취소 장부에서 더한다.
     *
     * 스냅샷에 면세액이 없으면(면세 무관 결제·구버전 데이터) null 을 돌려 상한을 걸지 않는다.
     *
     * @param  OrderPayment  $payment  결제 레코드
     * @return int|null 잔여 면세 예산 (알 수 없으면 null)
     */
    private function remainingTaxFreeBudget(OrderPayment $payment): ?int
    {
        $raw = $payment->payment_meta['pg_raw_response'] ?? null;

        if (! is_array($raw) || ! isset($raw['taxFreeAmount'])) {
            return null;
        }

        $approved = (int) round((float) $raw['taxFreeAmount']);

        if ($approved <= 0) {
            return null;
        }

        $consumed = 0;
        foreach (($raw['cancels'] ?? []) as $cancel) {
            $consumed += (int) round((float) ($cancel['taxFreeAmount'] ?? 0));
        }

        return max(0, $approved - $consumed);
    }

    /**
     * 취소 응답으로 승인 스냅샷의 취소 장부를 갱신합니다. (S1-D14)
     *
     * 승인 시 기록한 `payment_meta.pg_raw_response` 는 종전에 한 번만 쓰이고 이후 갱신되지
     * 않았다. 그러면 순차 부분취소에서 「이미 소진한 면세분」을 알 길이 없다.
     *
     * 승인 금액·승인 면세액은 상한의 근거라 **덮어쓰지 않고**, 장부(cancels)만 갱신한다.
     *
     * @param  OrderPayment  $payment  결제 레코드
     * @param  array  $response  토스 취소 API 응답
     * @return void 반환값 없음
     */
    private function syncCancelSnapshot(OrderPayment $payment, array $response): void
    {
        if (! isset($response['cancels']) || ! is_array($response['cancels'])) {
            return;
        }

        $meta = $payment->payment_meta ?? [];
        $raw = $meta['pg_raw_response'] ?? [];

        if (! is_array($raw)) {
            return;
        }

        $raw['cancels'] = $response['cancels'];

        // 승인 면세액이 스냅샷에 없던 결제도 취소 응답으로 한 번은 채워 넣는다.
        if (! isset($raw['taxFreeAmount']) && isset($response['taxFreeAmount'])) {
            $raw['taxFreeAmount'] = $response['taxFreeAmount'];
        }

        $meta['pg_raw_response'] = $raw;

        // Listener 에서 모델 영속 메서드를 직접 부르지 않고 Repository 를 경유한다.
        app(OrderPaymentRepositoryInterface::class)
            ->savePendingChanges($payment->forceFill(['payment_meta' => $meta]));
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
     *
     * @return TossPaymentsApiService 토스페이먼츠 API 서비스
     */
    protected function getApiService(): TossPaymentsApiService
    {
        return app(TossPaymentsApiService::class);
    }
}
