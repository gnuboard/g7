<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;

class PaymentRefundListener implements HookListenerInterface
{
    private const PG_PROVIDER_ID = 'nhnkcp';

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
     * NHN KCP 공개 문서(developer.kcp.co.kr guide/mobile-payment·reference/escrow·guide/account)의 제약:
     * - 휴대폰 결제는 결제한 달에만 취소된다 — 한국 시간으로 달이 바뀐 결제는 false(토스와 같은 판정).
     * - 에스크로 결제는 구매확인 전 부분취소 금액 필드가 없어 일반 부분취소를 막는다.
     * - 입금된 가상계좌는 이 플러그인이 자동 환불하지 않는다(`processRefund` 참고) — 부분취소도 막는다.
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

        if ((bool) $payment->is_escrow || $this->isSettledVirtualAccount($payment)) {
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
     * 입금이 끝난 가상계좌 결제인지 판정합니다.
     *
     * 코어는 환불 훅 전에 결제 상태를 바꾸므로 입금 여부는 `paid_at`(입금 확인 시 기록)으로 본다.
     *
     * @param  OrderPayment  $payment  결제행
     * @return bool 입금된 가상계좌면 true
     */
    private function isSettledVirtualAccount(OrderPayment $payment): bool
    {
        return ($payment->isVirtualAccount() || $payment->vbank_number !== null) && $payment->paid_at !== null;
    }

    /**
     * 복합과세(TG03) 승인 결제의 부분취소 과세 분리 금액을 계산합니다. (P-D8)
     *
     * 승인 때와 같은 조건(주문 비과세액 > 0)이면 TG03 으로 승인됐다. 취소 금액을 주문의 비과세:과세 비율로 나누고,
     * 과세분의 부가세는 승인 때와 같은 비율(주문 부가세 ÷ 과세 총액)로 뗀다. 세 금액의 합은 취소 금액과 같다.
     *
     * @param  Order  $order  주문
     * @param  int  $cancelAmt  이번 부분취소 금액
     * @return array<string, string> {tax_flag, mod_tax_mny, mod_vat_mny, mod_free_mny} (복합과세가 아니면 빈 배열)
     */
    private function buildPartialTaxFields(Order $order, int $cancelAmt): array
    {
        $taxFree = (int) round((float) ($order->total_tax_free_amount ?? 0));
        $taxable = (int) round((float) ($order->total_tax_amount ?? 0));
        if ($taxFree <= 0 || $cancelAmt <= 0 || $taxFree + $taxable <= 0) {
            return [];
        }

        $freeShare = min($cancelAmt, (int) round($cancelAmt * $taxFree / ($taxFree + $taxable)));
        $taxablePart = $cancelAmt - $freeShare;
        $vatTotal = (int) round((float) ($order->total_vat_amount ?? 0));
        $vat = $taxablePart > 0 && $taxable > 0 && $vatTotal > 0
            ? min($taxablePart, (int) round($taxablePart * $vatTotal / $taxable))
            : 0;

        return [
            'tax_flag' => 'TG03',
            'mod_tax_mny' => (string) ($taxablePart - $vat),
            'mod_vat_mny' => (string) $vat,
            'mod_free_mny' => (string) $freeShare,
        ];
    }

    /**
     * 자기 결제의 미입금 가상계좌를 발급 취소합니다. (P-D1)
     *
     * KCP 는 입금 전 가상계좌도 전체 취소(`STSC`)로 발급을 취소한다 — 클레임 결제의 발급 취소
     * (`ClaimPaymentApproveListener::cancelIssuedQuietly()`)와 같은 호출이다. 입금 전이라 환불계좌는 싣지 않는다.
     *
     * @param  array  $result  결과 {handled, success, error_message}
     * @param  Order  $order  취소된 주문
     * @param  OrderPayment  $payment  결제행
     * @param  int  $issuedAmount  발급 금액 (결제 통화 최소 단위)
     * @return array 결과 (자기 결제면 handled=true)
     */
    public function closeVbank(array $result, Order $order, OrderPayment $payment, int $issuedAmount = 0): array
    {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        $tno = (string) $payment->transaction_id;
        if ($tno === '' || $issuedAmount <= 0) {
            return ['handled' => true, 'success' => false, 'error_message' => $tno === '' ? 'missing_tno' : 'missing_amount'];
        }

        try {
            $apiService = app(NhnKcpApiService::class);
            $this->restorePaymentCredentials($apiService, $payment);
            $apiService->cancelPayment($tno, (string) $order->order_number, $issuedAmount, __('sirsoft-ecommerce::messages.vbank_close_reason'), false, $issuedAmount, null);

            return ['handled' => true, 'success' => true, 'error_message' => null];
        } catch (\Throwable $e) {
            Log::warning('KCP: 가상계좌 발급 취소 실패', [
                'order_id' => $order->id,
                'tno' => $tno,
                'error' => $e->getMessage(),
            ]);

            return ['handled' => true, 'success' => false, 'error_message' => $e->getMessage()];
        }
    }

    /**
     * 환불 처리 훅 핸들러 (sirsoft-ecommerce.payment.refund 필터)
     *
     * KCP 결제 건 (pg_provider=nhnkcp) 만 처리. before_cancel/after_cancel
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

        $tno = $payment->transaction_id;
        if (! $tno) {
            return [
                'success' => false,
                'handled' => true,
                'error_code' => 'MISSING_TNO',
                'error_message' => __('sirsoft-pay_nhnkcp::messages.refund.missing_tid'),
                'transaction_id' => null,
            ];
        }

        // 입금된 가상계좌 환불(P-D9): 이 플러그인이 싣던 환불계좌 필드는 공개 사양 어디와도 맞지 않고, 전체취소(STSC)는
        // 입금된 가상계좌를 환불하지 않는다. 결제사로 보내지 않고 계좌 환불로 처리하라고 돌려준다(NICE 와 같은 방식).
        if ($this->isSettledVirtualAccount($payment)) {
            return [
                'success' => false,
                'handled' => true,
                'error_code' => 'VBANK_REQUIRES_BANK_TRANSFER',
                'error_message' => __('sirsoft-pay_nhnkcp::messages.refund.vbank_requires_bank_transfer'),
                'transaction_id' => null,
            ];
        }

        try {
            $lock = Cache::lock('nhnkcp:refund:'.$payment->id, 30);
            if (! $lock->get()) {
                return [
                    'success' => false,
                    'handled' => true,
                    'error_code' => 'REFUND_IN_PROGRESS',
                    'error_message' => __('sirsoft-pay_nhnkcp::messages.refund.in_progress'),
                    'transaction_id' => null,
                ];
            }

            try {
                $apiService = app(NhnKcpApiService::class);
                $this->restorePaymentCredentials($apiService, $payment);

                $cancelMsg = $reason ?? __('sirsoft-pay_nhnkcp::messages.refund.default_reason');
                // 결제 통화 실환불액을 최소 단위로 반올림한다(P-D10) — 잘라 내면 소수 환산 주문에서 1원 적게 돌려준다(토스와 같은 규칙).
                $cancelAmt = (int) round($refundAmount);
                $ordrIdxx = (string) $order->order_number;

                // $refundAmount 는 코어가 결제 통화(order_currency)로 환산해 전달한 실환불액이다.
                // 누적 취소액도 결제 통화 기준(mc_cancelled_amount[order_currency])으로 맞춰야
                // base≠결제통화 주문에서 부분취소 판정·총액이 어긋나 PG 실환불이 잘못 전송되지 않는다.
                $paidAmount = (int) $payment->paid_amount_local;
                $cumulativeCancelled = (int) round((float) $this->cancelledLocalAmount($payment));
                $previousCancelled = max(0, $cumulativeCancelled - $cancelAmt);
                $totalAmt = max($cancelAmt, $paidAmount - $previousCancelled);
                $isPartial = $previousCancelled > 0 || $cancelAmt < $paidAmount;
                $response = $apiService->cancelPayment(
                    $tno,
                    $ordrIdxx,
                    $cancelAmt,
                    $cancelMsg,
                    $isPartial,
                    $totalAmt,
                    // 입금된 가상계좌는 위에서 돌려보냈다 — 여기 오는 결제는 환불계좌가 필요 없다.
                    null,
                    $isPartial ? $this->buildPartialTaxFields($order, $cancelAmt) : [],
                );

                Log::info('KCP: refund success', [
                    'order_id' => $order->id,
                    'tno' => $tno,
                    'cancel_amt' => $cancelAmt,
                ]);

                return [
                    'success' => true,
                    'handled' => true,
                    'error_code' => null,
                    'error_message' => null,
                    // 부분취소 응답의 tno 는 원거래 번호다 — 회차는 부분취소 일련번호(mod_pcan_seq_no)로만 구분된다(P-D11).
                    'transaction_id' => $this->refundTransactionId($response, (string) $tno, $isPartial),
                ];
            } finally {
                $lock->release();
            }
        } catch (\Exception $e) {
            Log::error('KCP: refund failed', [
                'order_id' => $order->id,
                'tno' => $tno,
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
     * 환불 결과에 남길 거래 ID 를 정합니다.
     *
     * @param  array  $response  KCP 취소 응답
     * @param  string  $tno  원거래 번호
     * @param  bool  $isPartial  부분취소 여부
     * @return string 거래 ID (부분취소면 `{tno}-{mod_pcan_seq_no}`)
     */
    private function refundTransactionId(array $response, string $tno, bool $isPartial): string
    {
        $responseTno = (string) ($response['tno'] ?? $tno);
        $seq = trim((string) ($response['mod_pcan_seq_no'] ?? ''));

        return $isPartial && $seq !== '' ? $responseTno.'-'.$seq : $responseTno;
    }

    /**
     * 결제 시점의 사이트 자격증명으로 API 서비스를 맞춥니다.
     *
     * @param  NhnKcpApiService  $apiService  KCP API 서비스
     * @param  OrderPayment  $payment  결제행
     * @return void 반환값 없음
     */
    private function restorePaymentCredentials(NhnKcpApiService $apiService, OrderPayment $payment): void
    {
        $meta = $payment->payment_meta ?? [];
        if (! array_key_exists('is_test_mode', $meta) || empty($meta['site_cd'])) {
            return;
        }

        $apiService->useStoredCredentials((bool) $meta['is_test_mode'], (string) $meta['site_cd']);
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
}
