<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;

class PaymentRefundListener implements HookListenerInterface
{
    private const PG_PROVIDER_ID = 'nicepayments';

    private const MONEY_EPSILON = 0.0001;

    /**
     * 구독할 훅 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.payment.refund' => [
                'method' => 'processRefund',
                'type' => 'filter',
                'priority' => 10,
            ],
            // 부분취소 지원 여부 선언 (코어 P6). 제약을 아는 자리는 PG 플러그인이다 —
            // 코어가 수단·PG 조합표를 들고 있으면 플러그인이 늘 때마다 그 표가 낡는다.
            'sirsoft-ecommerce.payment.supports_partial_cancel' => [
                'method' => 'supportsPartialCancel',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 이 결제가 부분취소를 지원하는지 판정합니다. (코어 P6)
     *
     * 코어가 부분취소 불가를 미리 말하지 않으면 운영자는 수량을 줄여 [취소]를 누르고 **원인 없는
     * 실패**를 받는다. 그래서 화면이 "전액취소만 가능" 을 먼저 알리도록 아래 결제는 false 를 돌려준다.
     *
     * - 가상계좌: 이 플러그인 경로의 제약이다. 입금된 가상계좌 환불은 환불계좌가 필요해 일반 취소 훅이
     *   처리하지 않고(`VBANK_REQUIRES_BANK_INFO`), 우회 경로인 관리자 가상계좌 환불 API 는 전액취소 전용이다.
     * - 에스크로: 결과코드 2052 「에스크로 거래는 부분취소 불가」(P-D7).
     * - 휴대폰: 통신사 월 마감 때문에 결제한 달에만 취소된다 — 한국 시간으로 달이 바뀐 결제는 false(P-D7, 토스와 같은 판정).
     *
     * @param  bool  $supported  현재까지의 판정
     * @param  OrderPayment|null  $payment  결제행
     * @return bool 부분취소 지원 여부
     */
    public function supportsPartialCancel(bool $supported, $payment = null): bool
    {
        if (! $supported || ! $payment instanceof OrderPayment) {
            return $supported;
        }

        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $supported;
        }

        if ($payment->isVirtualAccount() || (bool) $payment->is_escrow) {
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
     * 기본 핸들러 (미사용 — 개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 환불 처리 훅 핸들러 (sirsoft-ecommerce.payment.refund 필터)
     *
     * NicePay 결제 건 (pg_provider=nicepayments) 만 처리. 가상계좌 입금 완료 건은
     * 환불계좌 정보가 필요해 별도 어드민 API 로 우회. before_cancel/after_cancel
     * 액션 훅을 발행하여 외부 확장 지점 제공.
     *
     * @param  array  $result  이전 필터 누적 결과
     * @param  Order  $order  대상 주문
     * @param  OrderPayment  $payment  대상 결제 정보
     * @param  float  $refundAmount  환불 금액
     * @param  string|null  $reason  환불 사유 (없으면 기본 메시지)
     * @return array success / error_code / error_message / transaction_id
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

        // 가상계좌 입금 완료 건은 환불 계좌 정보가 필요하므로 일반 훅으로 처리 불가
        // payment_status 는 훅 호출 전 updatePayment() 에서 이미 CANCELLED 로 변경되므로
        // 입금 여부 판별에는 paid_at (입금 확인 시 설정) 을 사용한다
        if ($payment->vbank_number !== null
            && $payment->paid_at !== null) {
            return [
                'success' => false,
                'handled' => true,
                'error_code' => 'VBANK_REQUIRES_BANK_INFO',
                'error_message' => __('sirsoft-pay_nicepayments::messages.errors.vbank_completed_requires_bank_info'),
                'transaction_id' => null,
            ];
        }

        $tid = $payment->transaction_id;
        if (! $tid) {
            return [
                'success' => false,
                'handled' => true,
                'error_code' => 'MISSING_TID',
                'error_message' => __('sirsoft-pay_nicepayments::messages.refund.missing_tid'),
                'transaction_id' => null,
            ];
        }

        try {
            $apiService = app(NicePaymentsApiService::class);
            $this->useStoredCredentials($apiService, $payment);

            $cancelMsg = $reason ?? __('sirsoft-pay_nicepayments::messages.refund.default_reason');
            // 결제 통화 실환불액을 최소 단위로 반올림한다(P-D10) — 잘라 내면 소수 환산 주문에서 1원 적게 돌려준다(토스와 같은 규칙).
            $cancelAmt = (int) round($refundAmount);

            if ($cancelAmt <= 0) {
                return [
                    'success' => false,
                    'handled' => true,
                    'error_code' => 'INVALID_REFUND_AMOUNT',
                    'error_message' => __('sirsoft-pay_nicepayments::messages.errors.invalid_refund_amount', ['amount' => ecommerce_format_price($cancelAmt, $order->currency)]),
                    'transaction_id' => null,
                ];
            }

            $moid = (string) $order->order_number;

            $isPartial = $this->shouldUsePartialCancelCode($payment, $cancelAmt);
            $response = $apiService->cancelPayment($tid, $moid, $cancelAmt, $cancelMsg, $isPartial ? 1 : 0);

            Log::info('NicePayments: refund success', [
                'order_id' => $order->id,
                'tid' => $tid,
                'cancel_amt' => $cancelAmt,
            ]);

            // 취소 응답의 TID 는 원거래 ID 다 — 부분취소 회차는 취소번호(CancelNum)로만 구분된다(P-D11).
            $responseTid = (string) ($response['TID'] ?? $tid);
            $cancelNum = trim((string) ($response['CancelNum'] ?? ''));

            return [
                'success' => true,
                'handled' => true,
                'error_code' => null,
                'error_message' => null,
                'transaction_id' => $isPartial && $cancelNum !== '' ? $responseTid.'-'.$cancelNum : $responseTid,
            ];
        } catch (\Exception $e) {
            Log::error('NicePayments: refund failed', [
                'order_id' => $order->id,
                'tid' => $tid,
                'cancel_amt' => (int) round($refundAmount),
                'error' => $e->getMessage(),
            ]);

            HookManager::doAction('sirsoft-pay_nicepayments.payment.refund_failed', $order, $payment, [
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
     * 결제 시점의 상점 자격증명으로 API 서비스를 맞춥니다.
     *
     * @param  NicePaymentsApiService  $apiService  나이스페이 API 서비스
     * @param  OrderPayment  $payment  결제행
     * @return void 반환값 없음
     */
    private function useStoredCredentials(NicePaymentsApiService $apiService, OrderPayment $payment): void
    {
        $meta = $payment->payment_meta ?? [];
        $mid = trim((string) ($meta['mid'] ?? ''));
        if ($mid === '' || ! array_key_exists('is_test_mode', $meta)) {
            return;
        }

        $apiService->useStoredCredentials((bool) $meta['is_test_mode'], $mid);
    }

    /**
     * 코어는 PG 환불 훅 호출 전에 누적 취소액을 먼저 반영한다.
     * 따라서 남은 잔액 취소 시 getCancellableAmount() 만 보면 전액취소처럼 보일 수 있으므로,
     * 이번 환불 이전에 이미 취소된 결제 통화 금액이 있었는지도 함께 확인한다.
     *
     * @param  OrderPayment  $payment  결제행
     * @param  int  $cancelAmt  이번 취소 금액 (결제 통화)
     * @return bool 부분취소 코드를 써야 하면 true
     */
    private function shouldUsePartialCancelCode(OrderPayment $payment, int $cancelAmt): bool
    {
        $cancelledBeforeThisRefund = max(0.0, $payment->cancelledLocalAmount() - $cancelAmt);

        return $cancelledBeforeThisRefund > self::MONEY_EPSILON
            || $payment->getCancellableAmount() > self::MONEY_EPSILON;
    }
}
