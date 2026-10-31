<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Plugins\Sirsoft\Tosspayments\Services\TossPaymentsApiService;

/**
 * 클레임 추가결제 환급 리스너
 *
 * 이커머스 모듈의 `claim_payment.refund` 필터를 구독해 토스페이먼츠 결제 취소 API 로
 * 클레임 결제 건을 환급합니다. 계약은 이커머스 모듈 `modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md`
 * "결제 플러그인 연동 계약 (필터 훅)" 절이 단일 출처입니다.
 *
 * 클레임 결제는 즉시 확정 수단만 지원하므로 주문 환불의 가상계좌 환불계좌(R3)·에스크로
 * 부분취소 차단(E4) 분기는 없다. 면세 안분(R2)은 클레임 청구액(배송비·옵션 차액)에 과세
 * 분류 원천이 없어 적용하지 않는다.
 */
class ClaimPaymentRefundListener implements HookListenerInterface
{
    private const PG_PROVIDER_ID = 'tosspayments';

    /**
     * 구독할 훅 매핑을 반환합니다.
     *
     * @return array 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.claim_payment.refund' => [
                'method' => 'processRefund',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — 개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인수
     */
    public function handle(...$args): void {}

    /**
     * 클레임 결제 건의 PG 환급을 처리합니다.
     *
     * @param  array  $result  환급 결과 (기본값)
     * @param  ClaimPayment  $payment  환급 대상 결제 건
     * @param  float  $amount  환급액 (결제 통화 — 동결 환산액이 없으면 기준통화)
     * @param  string|null  $reason  환급 사유
     * @param  string  $idempotencyKey  모듈이 조립한 멱등키
     * @return array 환급 결과 {handled, success, transaction_id, error_code, error_message}
     */
    public function processRefund(
        array $result,
        ClaimPayment $payment,
        float $amount,
        ?string $reason,
        string $idempotencyKey,
    ): array {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        $paymentKey = $payment->transaction_id;

        if (! $paymentKey) {
            return $this->failure('MISSING_PAYMENT_KEY', __('sirsoft-tosspayments::messages.refund.missing_payment_key'));
        }

        $cancelAmount = (int) round($amount);
        $isPartial = $this->isPartialRefund($payment, $cancelAmount);

        try {
            $response = $this->getApiService()->cancelPayment(
                $paymentKey,
                $reason ?? __('sirsoft-tosspayments::messages.refund.default_reason'),
                // 전액 환급은 cancelAmount 를 넣지 않는다 (토스: 미지정 = 전액취소) — 부분 환급
                // 이력이 있으면 잔액 전액이라도 금액을 명시해야 이중 취소가 되지 않는다.
                $isPartial ? $cancelAmount : null,
                null,
                null,
                $idempotencyKey,
            );

            $cancels = $response['cancels'] ?? [];
            $lastCancel = is_array($cancels) && $cancels !== [] ? end($cancels) : [];

            Log::info('토스페이먼츠 클레임 결제 환급 성공', [
                'payment_number' => $payment->payment_number,
                'payment_key' => $paymentKey,
                'cancel_amount' => $isPartial ? $cancelAmount : null,
                'is_partial' => $isPartial,
            ]);

            return [
                'handled' => true,
                'success' => true,
                'transaction_id' => (string) ($lastCancel['transactionKey'] ?? $paymentKey),
                'error_code' => null,
                'error_message' => null,
            ];
        } catch (\Exception $e) {
            Log::error('토스페이먼츠 클레임 결제 환급 실패', [
                'payment_number' => $payment->payment_number,
                'payment_key' => $paymentKey,
                'cancel_amount' => $cancelAmount,
                'error' => $e->getMessage(),
            ]);

            return $this->failure('PG_API_ERROR', $e->getMessage());
        }
    }

    /**
     * 이번 환급이 부분취소인지 판정합니다.
     *
     * 결제 통화 기준으로 본다 — 모듈이 넘긴 `$amount` 가 결제 통화이므로, 비교 대상 총액도
     * 결제 시점 동결 환산액(`mc_amount[결제통화]`)을 우선 쓴다. 이전 환급 이력이 있으면 잔액
     * 전액이라도 부분취소로 보내야 한다 (토스는 금액 미지정 시 결제 전액을 취소한다).
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  int  $cancelAmount  이번 환급액 (결제 통화)
     * @return bool 부분취소 여부
     */
    private function isPartialRefund(ClaimPayment $payment, int $cancelAmount): bool
    {
        if ((float) $payment->refunded_amount > 0) {
            return true;
        }

        return $cancelAmount < (int) round($this->paidLocalTotal($payment));
    }

    /**
     * 결제 통화 기준 청구 총액을 반환합니다. (판정 SSoT 는 모듈)
     *
     * 환산 조건은 모듈이 `$amount` 를 넘길 때 쓰는 것과 같아야 한다 — 사본을 두면 모듈이
     * 조건을 바꿀 때 이쪽만 낡아 부분/전액 취소 판정이 뒤집힌다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @return float 결제 통화 기준 총액
     */
    private function paidLocalTotal(ClaimPayment $payment): float
    {
        return app(ClaimPaymentService::class)->resolvePaymentCurrencyTotal($payment);
    }

    /**
     * 실패 반환값을 조립합니다.
     *
     * @param  string  $code  실패 코드
     * @param  string|null  $message  실패 사유 (관리자 진단용)
     * @return array 실패 결과
     */
    private function failure(string $code, ?string $message): array
    {
        return [
            'handled' => true,
            'success' => false,
            'transaction_id' => null,
            'error_code' => $code,
            'error_message' => $message,
        ];
    }

    /**
     * API 서비스 인스턴스를 가져옵니다.
     *
     * @return TossPaymentsApiService API 서비스
     */
    protected function getApiService(): TossPaymentsApiService
    {
        return app(TossPaymentsApiService::class);
    }
}
