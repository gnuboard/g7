<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

class PaymentRefundListener implements HookListenerInterface
{
    private const PG_PROVIDER_ID = 'kginicis';

    /**
     * 구독할 훅 매핑을 반환합니다.
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
            // 부분취소 지원 여부 선언(P-D7) — 결제사가 거절할 부분취소를 접수 단계에서 먼저 알린다.
            'sirsoft-ecommerce.payment.supports_partial_cancel' => [
                'method' => 'supportsPartialCancel',
                'type' => 'filter',
                'priority' => 10,
            ],
            // 입금 전에 취소된 주문의 가상계좌 발급 취소(P-D1) — 이커머스가 취소 커밋 뒤 부른다.
            'sirsoft-ecommerce.payment.close_vbank' => [
                'method' => 'closeVbank',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — 개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 이 결제가 부분취소를 지원하는지 판정합니다. (P-D7)
     *
     * KG이니시스 취소 매뉴얼(https://manual.inicis.com/pay/cancel.html)의 제약:
     * - 휴대폰 결제는 「당월 거래만 취소가 가능」하다 — 한국 시간으로 달이 바뀐 결제는 false(토스와 같은 판정).
     * - 에스크로 결제는 배송등록 뒤 구매거절 절차로만 돌려줄 수 있어 일반 부분취소를 막는다.
     * - 입금된 가상계좌는 `/v2/pg/partialRefund/vacct` 로 부분환불되므로 막지 않는다. 입금 전 가상계좌는 이커머스가 이미 막는다.
     *
     * @param  bool  $supported  현재까지의 판정
     * @param  OrderPayment|null  $payment  결제행
     * @return bool 부분취소 지원 여부
     */
    public function supportsPartialCancel(bool $supported, $payment = null): bool
    {
        if (! $supported || ! $payment instanceof OrderPayment || $payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $supported;
        }

        if ((bool) $payment->is_escrow) {
            return false;
        }

        $method = $payment->payment_method instanceof \BackedEnum ? $payment->payment_method->value : (string) $payment->payment_method;
        if ($method === 'phone' && $payment->paid_at !== null) {
            return $payment->paid_at->copy()->timezone('Asia/Seoul')->format('Y-m')
                === now()->copy()->timezone('Asia/Seoul')->format('Y-m');
        }

        return true;
    }

    /**
     * KG 이니시스 결제 환불을 처리합니다.
     *
     * @param  array  $result  환불 결과 (기본값)
     * @param  Order  $order  주문
     * @param  OrderPayment  $payment  결제 정보
     * @param  float  $refundAmount  환불 금액 (결제 통화 order_currency 기준)
     * @param  string|null  $reason  환불 사유
     * @return array 환불 결과 {success, error_code, error_message, transaction_id}
     */
    public function processRefund(
        array $result,
        Order $order,
        OrderPayment $payment,
        float $refundAmount,
        ?string $reason = null,
    ): array {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        $tid = $payment->transaction_id;
        if (! $tid) {
            return [
                'success' => false,
                'handled' => true,
                'error_code' => 'MISSING_TID',
                'error_message' => __('sirsoft-pay_kginicis::messages.refund.missing_tid'),
                'transaction_id' => null,
            ];
        }

        try {
            $apiService = app(KgInicisApiService::class);

            $cancelMsg = $reason ?? __('sirsoft-pay_kginicis::messages.refund.default_reason');
            // $refundAmount 는 코어가 결제 통화(order_currency)로 환산해 전달한 실환불액이다.
            // 결제 통화 실환불액을 최소 단위로 반올림한다(P-D10) — 잘라 내면 소수 환산 주문에서 1원 적게 돌려준다(토스와 같은 규칙).
            $cancelAmt = (int) round($refundAmount);
            $payMethod = $payment->payment_meta['pay_method'] ?? 'Card';

            // 결제 통화 기준 누적·총액. paid_amount_local 과 mc_cancelled_amount[order_currency] 는
            // 모두 결제 통화 단위이므로 base≠결제 통화에서도 부분취소 누적이 PG 실청구와 정합한다.
            $paidAmount = (int) $payment->paid_amount_local;
            $cumulativeCancelled = (int) round((float) $this->cancelledLocalAmount($payment));
            $previousCancelled = max(0, $cumulativeCancelled - $cancelAmt);
            $totalAmount = max($cancelAmt, $paidAmount - $previousCancelled);
            $isPartial = $previousCancelled > 0 || $cancelAmt < $paidAmount;

            $isCbt = $this->isCbtPayment($payment);
            if ($isCbt) {
                $this->useStoredCbtCredentials($apiService, $payment);
            } else {
                $this->useStoredStandardCredentials($apiService, $payment, $tid);
            }

            $response = $isCbt
                ? $apiService->refundCbtPayment(
                    $tid,
                    $isPartial ? $cancelAmt : null,
                    $cancelMsg,
                    $isPartial ? $totalAmount : null,
                )
                : $apiService->cancelPayment(
                    $tid,
                    $payMethod,
                    $isPartial ? $cancelAmt : null,
                    $cancelMsg,
                    $isPartial ? $totalAmount : null,
                    // 가상계좌 환불계좌 — 없으면 PG 가 vbank 취소를 거절한다(P5).
                    // 계좌의 정본은 결제행이며, 신청서에 적힌 계좌는 환불 실행 직전에
                    // 코어(`AppliesRefundBankAccount`)가 이 행에 반영한다.
                    $payment->isVirtualAccount() ? [
                        'bank_code' => $payment->refund_bank_code,
                        'account_number' => $payment->refund_bank_account,
                        'holder' => $payment->refund_bank_holder,
                    ] : null,
                );

            Log::info('KG Inicis: refund success', [
                'order_id' => $order->id,
                'tid' => $tid,
                'cancel_amt' => $cancelAmt,
            ]);

            return [
                'success' => true,
                'handled' => true,
                'error_code' => null,
                'error_message' => null,
                // 부분취소 응답의 회차 거래번호는 `prtcTid` 다(P-D11) — `tid` 는 원거래 번호라 회차를 구분하지 못한다.
                'transaction_id' => $response['prtcTid'] ?? $response['refundTid'] ?? ($response['tid'] ?? $tid),
            ];
        } catch (\Exception $e) {
            Log::error('KG Inicis: refund failed', [
                'order_id' => $order->id,
                'tid' => $tid,
                'cancel_amt' => (int) round($refundAmount),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'handled' => true,
                'error_code' => 'PG_API_ERROR',
                'error_message' => $e->getMessage(),
                'transaction_id' => null,
            ];
        }
    }

    /**
     * 자기 결제의 미입금 가상계좌를 발급 취소합니다. (P-D1)
     *
     * KG이니시스는 입금 전 가상계좌를 일반 취소 API(전액 `refund`)로 채번 취소한다(https://manual.inicis.com/rtpay/cancel.html).
     * 입금 전이라 환불계좌는 싣지 않는다.
     *
     * @param  array  $result  결과 {handled, success, error_message}
     * @param  Order  $order  취소된 주문
     * @param  OrderPayment  $payment  결제행
     * @param  int  $issuedAmount  발급 금액 (사용하지 않음 — 전액 취소)
     * @return array 결과 (자기 결제면 handled=true)
     */
    public function closeVbank(array $result, Order $order, OrderPayment $payment, int $issuedAmount = 0): array
    {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        $tid = trim((string) $payment->transaction_id);
        if ($tid === '') {
            return ['handled' => true, 'success' => false, 'error_message' => 'missing_tid'];
        }

        try {
            $apiService = app(KgInicisApiService::class);
            $this->useStoredStandardCredentials($apiService, $payment, $tid);
            $apiService->cancelPayment($tid, (string) ($payment->payment_meta['pay_method'] ?? 'Vacct'), null, __('sirsoft-ecommerce::messages.vbank_close_reason'));

            return ['handled' => true, 'success' => true, 'error_message' => null];
        } catch (\Throwable $e) {
            Log::warning('KG Inicis: 가상계좌 채번 취소 실패', [
                'order_id' => $order->id,
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);

            return ['handled' => true, 'success' => false, 'error_message' => $e->getMessage()];
        }
    }

    /**
     * 결제 통화(order_currency) 기준 누적 취소액을 반환합니다.
     *
     * 코어가 결제 통화로 누적한 mc_cancelled_amount[order_currency] 를 우선 사용하고,
     * 없으면(레거시 결제) base 누적 cancelled_amount 로 폴백합니다.
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
     * 해외 결제(CBT) 건인지 판정합니다.
     *
     * @param  OrderPayment  $payment  결제행
     * @return bool 해외 결제면 true
     */
    private function isCbtPayment(OrderPayment $payment): bool
    {
        $meta = $payment->payment_meta ?? [];

        return (bool) ($meta['is_cbt'] ?? false)
            || ($meta['cbt_type'] ?? null) !== null
            || strtoupper((string) ($meta['pay_method'] ?? '')) === 'CBT';
    }

    /**
     * 결제 시점의 표준 상점 자격증명으로 API 서비스를 맞춥니다.
     *
     * @param  KgInicisApiService  $apiService  KG이니시스 API 서비스
     * @param  OrderPayment  $payment  결제행
     * @param  string  $tid  거래번호
     * @return void 반환값 없음
     */
    private function useStoredStandardCredentials(KgInicisApiService $apiService, OrderPayment $payment, string $tid): void
    {
        $meta = $payment->payment_meta ?? [];
        $raw = is_array($meta['pg_raw_response'] ?? null) ? $meta['pg_raw_response'] : [];
        $mid = $this->resolvePaymentMid($meta, $raw, $tid);

        if ($mid === null) {
            return;
        }

        $isTestMode = $meta['is_test_mode'] ?? ! str_starts_with($mid, 'SIR');

        $apiService->useStoredCredentials((bool) $isTestMode, $mid);
    }

    /**
     * 결제 시점에 사용된 표준 MID를 payment_meta/raw/TID 순서로 복원한다.
     *
     * @param  array  $meta  결제행 payment_meta
     * @param  array  $raw  PG 원 응답
     * @param  string  $tid  거래번호
     * @return ?string 상점 아이디 (찾지 못하면 null)
     */
    private function resolvePaymentMid(array $meta, array $raw, string $tid): ?string
    {
        if (! empty($meta['mid']) && is_string($meta['mid'])) {
            return $meta['mid'];
        }

        foreach (['mid', 'MID'] as $key) {
            if (! empty($raw[$key]) && is_string($raw[$key])) {
                return $raw[$key];
            }
        }

        if (strlen($tid) >= 20) {
            $candidate = substr($tid, 10, 10);
            if (preg_match('/^[A-Za-z0-9]{10}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 결제 시점의 해외 결제(CBT) 자격증명으로 API 서비스를 맞춥니다.
     *
     * @param  KgInicisApiService  $apiService  KG이니시스 API 서비스
     * @param  OrderPayment  $payment  결제행
     * @return void 반환값 없음
     */
    private function useStoredCbtCredentials(KgInicisApiService $apiService, OrderPayment $payment): void
    {
        $meta = $payment->payment_meta ?? [];

        $apiService->useStoredCbtCredentials(
            (bool) ($meta['is_test_mode'] ?? true),
            (string) ($meta['cbt_mid'] ?? ($meta['mid'] ?? '')),
        );
    }
}
